<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Reserva en línea (página pública, en inglés para negocios de EE.UU.):
 *  1. El cliente elige un paquete publicado (paquetes.publico) y una fecha: horarios() le muestra las horas libres.
 *     Una hora está libre si ALGUNO de los equipos del paquete (paquete_espacios) no tiene otro evento separado o retenido,
 *     contando el montaje y desmontaje del negocio (viaje e instalación).
 *  2. crear(): cliente + evento + cotización del paquete aceptada + plan de 2 pagos (depósito y saldo). Queda "cotizado"
 *     y la hora se retiene solo hasta el final del día (si no paga, se libera).
 *  3. El cliente paga en su portal con Stripe el depósito o el total. Al confirmarse el pago la fecha queda separada.
 *     Si mientras pagaba otro cliente tomó ese equipo, asegurarEquipo() lo pasa a otro equipo libre del mismo paquete.
 * Los mensajes son en inglés porque los ve el cliente final.
 */
final class Reservas
{
    public const OCASIONES = ['cumpleanos' => 'Birthday party', 'otro' => 'Family celebration', 'graduacion' => 'School or camp',
                              'corporativo' => 'Corporate or brand event'];
    public const MAX_POR_HORA = 5;

    private Mapas $mapas;

    public function __construct(private PDO $db, ?Mapas $mapas = null)
    {
        $this->mapas = $mapas ?? new Mapas($db);
    }

    /** ¿Las horas dependen de la dirección de la fiesta? (viaje con mapa encendido y base marcada) */
    public function usaMapa(array $negocio): bool
    {
        return Mapas::activo($negocio);
    }

    /**
     * (201) Viaje de UNA ida desde la base hasta la fiesta, con el margen del negocio:
     * ['min' => minutos, 'lat' =>, 'lng' =>, 'metodo' =>, 'km' =>]. Sin mapa: min 0.
     * Lanza RuntimeException (en inglés, lo ve el cliente) si la dirección no se encuentra o queda muy lejos.
     */
    public function viaje(array $negocio, string $direccion, string $zip, ?string $fecha = null): array
    {
        if (!$this->usaMapa($negocio)) return ['min' => 0, 'lat' => null, 'lng' => null, 'metodo' => null, 'km' => null];
        $tel = trim((string) ($negocio['telefono'] ?: ($negocio['whatsapp'] ?? '')));
        $llamar = $tel !== '' ? " Please call or text us at $tel." : ' Please call or text us.';
        if (mb_strlen(trim($direccion)) < 6) throw new RuntimeException('Please write the party address to see open times.');
        $p = $this->mapas->punto($negocio, $direccion, $zip);
        if (!$p) throw new RuntimeException('We could not find that address on the map. Please check the street, city and ZIP code.' . $llamar);
        $v = $this->mapas->viaje($negocio, $p['lat'], $p['lng'], $fecha);
        $min = (int) ($v['min'] ?? 0) + max(0, (int) $negocio['viaje_extra_min']);
        if ($min > (int) $negocio['viaje_max_min']) {
            throw new RuntimeException("That address is outside our online booking area (about $min minutes away)." . $llamar);
        }
        return ['min' => $min, 'lat' => $p['lat'], 'lng' => $p['lng'], 'metodo' => $v['metodo'] ?? null, 'km' => $v['km'] ?? null];
    }

    public function negocio(string $slug): ?array
    {
        $st = $this->db->prepare('SELECT * FROM negocios WHERE slug = ?');
        $st->execute([$slug]);
        $n = $st->fetch();
        return $n && $n['reserva_en_linea'] ? $n : null;
    }

    /** Paquetes publicados con su precio (suma de sus productos), duración, equipos y foto. */
    public function paquetes(int $negocioId): array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.slug, p.nombre, p.descripcion, p.duracion_min, p.maximo_invitados, p.foto_url, p.orden,
                    COALESCE((SELECT sum(COALESCE(pi.precio, pr.precio) * pi.cantidad * CASE WHEN pr.unidad = 'persona' THEN GREATEST(p.minimo_invitados, 1) ELSE 1 END)
                                FROM paquete_items pi JOIN productos pr ON pr.id = pi.producto_id WHERE pi.paquete_id = p.id), 0) AS precio,
                    ARRAY(SELECT pe.espacio_id FROM paquete_espacios pe JOIN espacios es ON es.id = pe.espacio_id
                           WHERE pe.paquete_id = p.id AND es.activo ORDER BY es.orden, es.id) AS espacios
               FROM paquetes p
              WHERE p.negocio_id = ? AND p.activo AND p.publico AND p.slug IS NOT NULL
           ORDER BY p.orden, p.id");
        $st->execute([$negocioId]);
        $lista = [];
        foreach ($st->fetchAll() as $p) {
            $p['espacios'] = array_map('intval', array_filter(explode(',', trim((string) $p['espacios'], '{}'))));
            $p['precio'] = round((float) $p['precio'], 2);
            if ($p['espacios']) $lista[] = $p;
        }
        return $lista;
    }

    public function paquete(int $negocioId, string $slug): ?array
    {
        foreach ($this->paquetes($negocioId) as $p) {
            if ($p['slug'] === $slug) return $p;
        }
        return null;
    }

    /** Primer día que se puede reservar (aviso mínimo del negocio). */
    public function primerDia(array $negocio, ?DateTimeImmutable $hoy = null): string
    {
        $hoy ??= new DateTimeImmutable('today');
        return $hoy->modify('+' . max(0, (int) $negocio['reserva_dias_min']) . ' days')->format('Y-m-d');
    }

    /**
     * Horas de inicio libres de un paquete en una fecha: [['hora' => '15:00', 'texto' => '3:00 PM', 'espacio_id' => 3], ...]
     */
    public function horarios(array $negocio, array $paquete, string $fecha, ?DateTimeImmutable $hoy = null, int $viajeMin = 0): array
    {
        if (!Ventas::fechaValida($fecha)) return [];
        $hoy ??= new DateTimeImmutable('today');
        if ($fecha < $this->primerDia($negocio, $hoy) || $fecha > $hoy->modify('+2 years')->format('Y-m-d')) return [];
        $eventos = new Eventos($this->db);
        $desde = new DateTimeImmutable("$fecha " . substr((string) $negocio['reserva_hora_desde'], 0, 5));
        $hasta = new DateTimeImmutable("$fecha " . substr((string) $negocio['reserva_hora_hasta'], 0, 5));
        $paso = max(10, (int) $negocio['reserva_paso_min']);
        $dur = max(15, (int) $paquete['duracion_min']);
        $libres = [];
        for ($t = $desde; $t <= $hasta; $t = $t->modify("+$paso minutes")) {
            $hi = $t->format('H:i');
            $hf = $t->modify("+$dur minutes")->format('H:i');
            $espacio = $this->equipoLibre($eventos, (int) $negocio['id'], $paquete['espacios'], $fecha, $hi, $hf, null, $hoy, $viajeMin);
            if ($espacio !== null) $libres[] = ['hora' => $hi, 'texto' => $t->format('g:i A'), 'espacio_id' => $espacio];
        }
        return $libres;
    }

    /** Primer equipo de la lista sin otro evento separado o retenido a esa hora (ni día cerrado). */
    private function equipoLibre(Eventos $eventos, int $negocioId, array $espacios, string $fecha, string $hi, string $hf, ?int $excluir, DateTimeImmutable $hoy, int $viajeMin = 0): ?int
    {
        foreach ($espacios as $id) {
            $ocupado = false;
            foreach ($eventos->choques($negocioId, $fecha, $hi, $hf, [$id], $excluir, $hoy, $viajeMin) as $c) {
                if (in_array($c['nivel'], ['bloqueado', 'retenido'], true)) {
                    $ocupado = true;
                    break;
                }
            }
            if (!$ocupado) return (int) $id;
        }
        return null;
    }

    public static function telefono(string $tel): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $tel);
        if (strlen($d) === 10 && $d[0] !== '0' && $d[0] !== '1') return '1' . $d;   // (954) 555-0123
        if (strlen($d) === 11 && $d[0] === '1') return $d;                          // +1 954 555 0123
        return null;
    }

    /**
     * Crea la reserva. $d: paquete (slug), fecha, hora, nombre, telefono, email, direccion, zip, ninos, ocasion, homenajeado,
     * notas, acepto (condiciones), sitio_web (trampa). Devuelve el id del evento.
     */
    public function crear(array $negocio, array $d, string $ip = '', ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $nid = (int) $negocio['id'];
        if (trim((string) ($d['sitio_web'] ?? '')) !== '') throw new RuntimeException('We could not save your booking.');
        if ((new Ventas($this->db))->consultasRecientes($nid, $ip) >= self::MAX_POR_HORA) {
            throw new RuntimeException('You already sent several bookings. Please call or text us to finish.');
        }
        $paq = $this->paquete($nid, (string) ($d['paquete'] ?? ''));
        if (!$paq) throw new RuntimeException('Please choose a party.');
        $fecha = (string) ($d['fecha'] ?? '');
        if (!Ventas::fechaValida($fecha)) throw new RuntimeException('Please choose a date.');
        $hora = substr((string) ($d['hora'] ?? ''), 0, 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw new RuntimeException('Please choose a start time.');
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Please write your full name.');
        $tel = self::telefono((string) ($d['telefono'] ?? ''));
        if ($tel === null) throw new RuntimeException('Please write a US mobile number with area code, like (954) 555-0123.');
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please check your email address.');
        $direccion = trim((string) ($d['direccion'] ?? ''));
        if (mb_strlen($direccion) < 6) throw new RuntimeException('Please write the party address.');
        $zip = (string) preg_replace('/\D/', '', (string) ($d['zip'] ?? ''));
        if (strlen($zip) !== 5) throw new RuntimeException('Please write a 5-digit ZIP code.');
        $ninos = (int) ($d['ninos'] ?? 0);
        $max = (int) ($paq['maximo_invitados'] ?? 0);
        if ($ninos < 1) throw new RuntimeException('Please tell us about how many kids will join.');
        if ($max > 0 && $ninos > $max) {
            throw new RuntimeException("This party is for up to $max kids. For a bigger group, please ask us for a custom quote.");
        }
        $ocasion = (string) ($d['ocasion'] ?? 'cumpleanos');
        if (!isset(self::OCASIONES[$ocasion])) $ocasion = 'otro';
        if (empty($d['acepto'])) throw new RuntimeException('Please accept the booking terms to continue.');
        $homenajeado = mb_substr(trim((string) ($d['homenajeado'] ?? '')), 0, 80);
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 1000);

        $viaje = $this->viaje($negocio, $direccion, $zip, $fecha);
        $libres = $this->horarios($negocio, $paq, $fecha, $hoy, $viaje['min']);
        $espacioId = null;
        foreach ($libres as $l) {
            if ($l['hora'] === $hora) $espacioId = $l['espacio_id'];
        }
        if ($espacioId === null) throw new RuntimeException('Sorry, that time was just taken. Please pick another time.');
        $fin = (new DateTimeImmutable("$fecha $hora"))->modify('+' . (int) $paq['duracion_min'] . ' minutes')->format('H:i');

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT id FROM clientes WHERE negocio_id = ? AND regexp_replace(COALESCE(telefono,''), '\\D', '', 'g') = ? ORDER BY id LIMIT 1");
            $st->execute([$nid, $tel]);
            $clienteId = $st->fetchColumn();
            if ($clienteId === false) {
                $st = $this->db->prepare("INSERT INTO clientes (negocio_id, nombre, telefono, email, direccion, origen) VALUES (?,?,?,?,?, 'pagina') RETURNING id");
                $st->execute([$nid, mb_substr($nombre, 0, 150), $tel, $email, mb_substr($direccion, 0, 250)]);
                $clienteId = (int) $st->fetchColumn();
            } else {
                $clienteId = (int) $clienteId;
                $this->db->prepare('UPDATE clientes SET email = COALESCE(email, ?) WHERE id = ?')->execute([$email, $clienteId]);
            }
            $eventos = new Eventos($this->db);
            $titulo = $paq['nombre'] . ($homenajeado !== '' ? ' · ' . $homenajeado : ' · ' . $nombre);
            $texto = 'Online booking. ' . self::OCASIONES[$ocasion] . ($homenajeado !== '' ? " for $homenajeado" : '') . ".\nAddress: $direccion, $zip"
                   . ($notas !== '' ? "\nNotes: $notas" : '');
            $evId = $eventos->crear($nid, [
                'cliente_id' => $clienteId, 'tipo' => $ocasion, 'fecha' => $fecha, 'hora_inicio' => $hora, 'hora_fin' => $fin,
                'invitados' => $ninos, 'espacios' => [$espacioId], 'origen' => 'pagina', 'notas' => $texto,
                'nombre' => mb_substr($titulo, 0, 150), 'viaje_min' => $viaje['min'],
            ], null, $hoy);
            $this->db->prepare("UPDATE eventos SET origen = 'pagina', reserva_paquete_id = ?, direccion = ?, codigo_postal = ?, detalles_cliente = ?,
                                       viaje_metodo = ?, lat = ?, lng = ? WHERE id = ?")
                     ->execute([$paq['id'], mb_substr($direccion, 0, 250), $zip, $notas ?: null,
                                $viaje['metodo'] ? mb_substr((string) $viaje['metodo'], 0, 40) : null, $viaje['lat'], $viaje['lng'], $evId]);
            if ($viaje['metodo']) {
                $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Sistema', ?)")
                         ->execute([$nid, $evId, 'Viaje desde la base: ' . $viaje['min'] . ' min de ida con el margen (' . $viaje['metodo']
                                    . ($viaje['km'] ? ', ' . number_format((float) $viaje['km'], 1, ',', '.') . ' km' : '') . ')']);
            }
            $cot = new Cotizaciones($this->db);
            $cotId = $cot->crear($nid, $evId, (int) $paq['id'], null, $hoy);
            $cot->aceptar($nid, $cotId, $nombre, $ip, null, $hoy);
            (new Pagos($this->db))->generarPlan($nid, $evId, 2, null, $hoy);
            // Retiene la hora solo hoy: si no paga, se libera mañana
            $this->db->prepare('UPDATE eventos SET retenida_hasta = ? WHERE id = ?')->execute([$hoy->format('Y-m-d'), $evId]);
            $this->db->prepare('INSERT INTO consultas_web (negocio_id, evento_id, ip) VALUES (?,?,?)')->execute([$nid, $evId, mb_substr($ip, 0, 45)]);
            $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Cliente', ?)")
                     ->execute([$nid, $evId, 'Reservó en línea: ' . $paq['nombre'] . ' el ' . $fecha . ' a las ' . $hora . ' (falta el pago)']);
            (new Notificaciones($this->db))->alNegocio($nid,
                "Reserva en línea: $nombre — {$paq['nombre']} el " . Ventas::fechaTexto($fecha) . " a las $hora, $ninos niños. Falta que pague el depósito.", $evId);
            if ($propia) $this->db->commit();
            return $evId;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Lo que el cliente puede pagar ahora: depósito (lo que falta de la primera cuota) y el total pendiente. */
    public function montos(int $eventoId): array
    {
        $pagos = new Pagos($this->db);
        $cuotas = $pagos->cuotas($eventoId);
        $saldo = round((float) $pagos->resumen($eventoId)['saldo'], 2);
        $deposito = $cuotas ? round(max(0.0, (float) $cuotas[0]['saldo']), 2) : 0.0;
        return ['deposito' => min($deposito, $saldo), 'total' => $saldo];
    }

    /**
     * Antes de separar la fecha de una reserva en línea: si su equipo ya lo tomó otro evento a esa hora, la pasa a otro
     * equipo libre del mismo paquete. Devuelve true si quedó con un equipo libre.
     */
    public function asegurarEquipo(int $eventoId, ?DateTimeImmutable $hoy = null): bool
    {
        $st = $this->db->prepare('SELECT negocio_id, fecha, hora_inicio, hora_fin, estado, reserva_paquete_id FROM eventos WHERE id = ?');
        $st->execute([$eventoId]);
        $ev = $st->fetch();
        if (!$ev || !$ev['reserva_paquete_id'] || !in_array($ev['estado'], ['consulta', 'cotizado'], true)) return true;
        $eventos = new Eventos($this->db);
        $actuales = array_map('intval', array_column($eventos->espacios($eventoId), 'id'));
        $hi = substr((string) $ev['hora_inicio'], 0, 5);
        $hf = substr((string) $ev['hora_fin'], 0, 5);
        $hoy ??= new DateTimeImmutable('today');
        $nid = (int) $ev['negocio_id'];
        $viaje = $eventos->viajeDe($eventoId);
        $bloqueado = fn(array $ids) => (bool) array_filter($eventos->choques($nid, $ev['fecha'], $hi, $hf, $ids, $eventoId, $hoy, $viaje),
                                                            fn($c) => $c['nivel'] === 'bloqueado');
        if ($actuales && !$bloqueado($actuales)) return true;
        $st = $this->db->prepare('SELECT pe.espacio_id FROM paquete_espacios pe JOIN espacios es ON es.id = pe.espacio_id
                                   WHERE pe.paquete_id = ? AND es.activo ORDER BY es.orden, es.id');
        $st->execute([$ev['reserva_paquete_id']]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
            if (in_array($id, $actuales, true) || $bloqueado([$id])) continue;
            [$desde, $hasta] = $eventos->periodo($nid, $ev['fecha'], $hi, $hf, $eventos->viajeDe($eventoId), $id);
            $this->db->prepare('DELETE FROM evento_espacios WHERE evento_id = ?')->execute([$eventoId]);
            $this->db->prepare('INSERT INTO evento_espacios (negocio_id, evento_id, espacio_id, periodo, bloquea) VALUES (?,?,?, tsrange(?::timestamp, ?::timestamp), false)')
                     ->execute([$nid, $eventoId, $id, $desde, $hasta]);
            $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Sistema', ?)")
                     ->execute([$nid, $eventoId, 'El equipo reservado ya estaba ocupado a esa hora: se pasó a otro equipo libre del mismo paquete']);
            return true;
        }
        return false;
    }
}
