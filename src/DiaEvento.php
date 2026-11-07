<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Día del evento: cronograma (escaleta) con modo en vivo, personal asignado, proveedores,
 * mobiliario reservado (con disponibilidad por fecha y daños) y transmisión en vivo.
 * Todo se filtra por negocio: cada método recibe $negocioId y revisa que el evento sea suyo.
 */
final class DiaEvento
{
    public const ROLES = [
        'mesero' => 'Mesero', 'capitan' => 'Capitán de meseros', 'cocina' => 'Cocina', 'bartender' => 'Bartender',
        'seguridad' => 'Seguridad', 'limpieza' => 'Limpieza', 'coordinador' => 'Coordinación', 'parqueo' => 'Parqueo', 'otro' => 'Otro',
    ];
    public const CATEGORIAS_MOBILIARIO = [
        'mobiliario' => 'Mobiliario', 'manteleria' => 'Mantelería', 'vajilla' => 'Vajilla', 'cristaleria' => 'Cristalería',
        'decoracion' => 'Decoración', 'equipos' => 'Equipos', 'carpas' => 'Carpas', 'otros' => 'Otros',
    ];
    public const TRANSMISION = ['no' => 'No tiene', 'solicitada' => 'Pedida a QF Producciones', 'confirmada' => 'Confirmada'];
    /** Estados en los que el evento va de verdad (cuentan para el mobiliario). */
    public const RESERVAN = ['separado', 'confirmado', 'realizado'];
    public const PLANTILLAS = ['boda' => 'Boda', 'quince' => 'Quince años', 'corporativo' => 'Corporativo', 'otro' => 'Otro evento'];

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /** Evento del negocio (o error). */
    public function evento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT e.*, c.nombre AS cliente, c.telefono AS cliente_telefono
                                    FROM eventos e JOIN clientes c ON c.id = e.cliente_id WHERE e.id = ? AND e.negocio_id = ?');
        $st->execute([$eventoId, $negocioId]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    public function negocio(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        return $n;
    }

    /** "18:30" válido o error. Vacío -> null si se permite. */
    public static function hora(?string $h, bool $opcional = false): ?string
    {
        $h = trim((string) $h);
        if ($h === '' && $opcional) return null;
        $h = substr($h, 0, 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) {
            throw new RuntimeException('Revisa la hora (ejemplo: 19:30).');
        }
        return $h;
    }

    /** "sábado 14 de noviembre de 2026" (igual que fecha_larga(), pero sin depender del arranque web). */
    public static function fechaLarga(?string $fecha): string
    {
        if (!$fecha) return '';
        $d = new DateTimeImmutable($fecha);
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    private static function minutos(string $hora): int
    {
        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }

    /**
     * Fecha y hora real de una hora del evento: si es de madrugada (más de 6 horas antes del inicio),
     * es del día siguiente. Ej.: evento de 18:00 a 02:00 -> la "hora loca" de las 00:30 es al otro día.
     */
    public static function momento(string $fecha, string $horaInicio, string $hora): DateTimeImmutable
    {
        $d = new DateTimeImmutable($fecha . ' ' . substr($hora, 0, 5));
        if (self::minutos($hora) < self::minutos($horaInicio) - 360) $d = $d->modify('+1 day');
        return $d;
    }

    /** Duración del evento en minutos (si termina después de medianoche, cuenta el día siguiente). */
    public static function duracionEvento(string $horaInicio, string $horaFin): int
    {
        $d = self::minutos($horaFin) - self::minutos($horaInicio);
        return $d <= 0 ? $d + 1440 : $d;
    }

    /** Enlace de WhatsApp: al número si hay, si no para elegir el contacto. */
    public static function wa(?string $telefono, string $mensaje): string
    {
        return WhatsApp::enlace($telefono, $mensaje) ?? 'https://wa.me/?text=' . rawurlencode($mensaje);
    }

    /** Mapa del negocio: el enlace que puso el dueño o una búsqueda en Google Maps con la dirección. */
    public static function mapa(array $negocio): ?string
    {
        if (!empty($negocio['mapa_url']) && preg_match('#^https?://#i', (string) $negocio['mapa_url'])) return $negocio['mapa_url'];
        $q = trim(implode(', ', array_filter([$negocio['nombre'] ?? '', $negocio['direccion'] ?? '', $negocio['ciudad'] ?? ''])));
        return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
    }

    private function cambio(int $negocioId, int $eventoId, string $accion, ?array $usuario): void
    {
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $eventoId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($accion, 0, 400)]);
    }

    /** Marca del negocio para las páginas públicas (vista_publica). */
    public static function marca(array $negocio): array
    {
        return ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];
    }

    /** ¿Las páginas públicas del día del evento (invitación, puerta, en vivo, portal del proveedor) están activas? Cuenta al día y con el módulo encendido. */
    public static function publicoActivo(PDO $db, array $negocio, string $modulo): bool
    {
        return !in_array($negocio['estado'], ['suspendido', 'cancelado'], true) && (new Modulos($db))->activo((int) $negocio['id'], $modulo);
    }

    // ------------------------------------------------------------------
    // Cronograma (escaleta)
    // ------------------------------------------------------------------

    public function cronograma(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        return $this->bloques($eventoId);
    }

    private function bloques(int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM cronograma WHERE evento_id = ? ORDER BY orden, id');
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    /** Agrega (o edita si $id) un bloque: hora, duracion_min, actividad, responsable, notas. */
    public function guardarBloque(int $negocioId, int $eventoId, ?int $id, array $d): int
    {
        $ev = $this->evento($negocioId, $eventoId);
        $hora = self::hora($d['hora'] ?? '');
        $dur = (int) ($d['duracion_min'] ?? 15);
        if ($dur < 1 || $dur > 600) throw new RuntimeException('La duración va de 1 a 600 minutos.');
        $act = trim((string) ($d['actividad'] ?? ''));
        if (mb_strlen($act) < 2) throw new RuntimeException('Escribe qué pasa en ese momento (por ejemplo: Vals).');
        $resp = mb_substr(trim((string) ($d['responsable'] ?? '')), 0, 100) ?: null;
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null;
        // Proveedor responsable del bloque (060): solo si viene en el formulario; debe ser un proveedor de este evento
        $conProv = array_key_exists('evento_proveedor_id', $d);
        $prov = $conProv ? ((int) $d['evento_proveedor_id'] ?: null) : null;
        if ($prov) {
            $st = $this->db->prepare('SELECT nombre FROM evento_proveedores WHERE id = ? AND evento_id = ?');
            $st->execute([$prov, $eventoId]);
            $nomProv = $st->fetchColumn();
            if ($nomProv === false) throw new RuntimeException('Ese proveedor no está en este evento.');
            $resp ??= mb_substr((string) $nomProv, 0, 100);
        }
        if ($id) {
            $st = $this->db->prepare('UPDATE cronograma SET hora = ?, duracion_min = ?, actividad = ?, responsable = ?, notas = ?'
                                     . ($conProv ? ', evento_proveedor_id = ' . ($prov ?: 'NULL') : '') . ' WHERE id = ? AND evento_id = ?');
            $st->execute([$hora, $dur, mb_substr($act, 0, 150), $resp, $notas, $id, $eventoId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró ese momento del cronograma.');
            return $id;
        }
        // Lo ubica según la hora, entre los que ya están
        $this->renumerar($eventoId);
        $orden = 0;
        $nuevo = self::momento($ev['fecha'], $ev['hora_inicio'], $hora);
        foreach ($this->bloques($eventoId) as $i => $b) {
            if (self::momento($ev['fecha'], $ev['hora_inicio'], $b['hora']) <= $nuevo) $orden = $i + 1;
        }
        $this->db->prepare('UPDATE cronograma SET orden = orden + 1 WHERE evento_id = ? AND orden >= ?')->execute([$eventoId, $orden]);
        $st = $this->db->prepare('INSERT INTO cronograma (evento_id, orden, hora, duracion_min, actividad, responsable, notas, evento_proveedor_id) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$eventoId, $orden, $hora, $dur, mb_substr($act, 0, 150), $resp, $notas, $prov]);
        return (int) $st->fetchColumn();
    }

    /** Deja el orden como 0, 1, 2... sin huecos. */
    private function renumerar(int $eventoId): void
    {
        $this->db->prepare('UPDATE cronograma c SET orden = x.n - 1 FROM (SELECT id, row_number() OVER (ORDER BY orden, id) AS n FROM cronograma WHERE evento_id = ?) x WHERE x.id = c.id')
                 ->execute([$eventoId]);
    }

    public function borrarBloque(int $negocioId, int $eventoId, int $id): void
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare('DELETE FROM cronograma WHERE id = ? AND evento_id = ?');
        $st->execute([$id, $eventoId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró ese momento del cronograma.');
        $this->renumerar($eventoId);
    }

    /** Sube (-1) o baja (+1) un bloque una posición. */
    public function moverBloque(int $negocioId, int $eventoId, int $id, int $direccion): void
    {
        $this->evento($negocioId, $eventoId);
        $this->renumerar($eventoId);
        $ids = array_map(fn($b) => (int) $b['id'], $this->bloques($eventoId));
        $i = array_search($id, $ids, true);
        if ($i === false) throw new RuntimeException('No se encontró ese momento del cronograma.');
        $j = $i + ($direccion < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($ids)) return;
        $up = $this->db->prepare('UPDATE cronograma SET orden = ? WHERE id = ?');
        $up->execute([$j, $ids[$i]]);
        $up->execute([$i, $ids[$j]]);
    }

    /** Ordena todo por hora (respetando que lo de madrugada va al final). */
    public function ordenarPorHora(int $negocioId, int $eventoId): void
    {
        $ev = $this->evento($negocioId, $eventoId);
        $b = $this->bloques($eventoId);
        usort($b, fn($x, $y) => [self::momento($ev['fecha'], $ev['hora_inicio'], $x['hora']), $x['orden']]
                               <=> [self::momento($ev['fecha'], $ev['hora_inicio'], $y['hora']), $y['orden']]);
        $up = $this->db->prepare('UPDATE cronograma SET orden = ? WHERE id = ?');
        foreach ($b as $i => $x) $up->execute([$i, $x['id']]);
    }

    /** Plantilla sugerida según el tipo de evento. */
    public static function plantillaDe(string $tipo): string
    {
        return match ($tipo) {
            'boda', 'aniversario' => 'boda',
            'quince' => 'quince',
            'corporativo', 'graduacion' => 'corporativo',
            default => 'otro',
        };
    }

    /**
     * Momentos de ejemplo: [minutos desde el inicio, duración, actividad, responsable]. 'baile' = se estira hasta el cierre.
     * @return list<array{0:int,1:int|string,2:string,3:string}>
     */
    public static function ejemplo(string $plantilla): array
    {
        return match ($plantilla) {
            'boda' => [
                [0, 30, 'Recepción de invitados', 'Coordinación y meseros'],
                [30, 45, 'Ceremonia', 'Oficiante'],
                [75, 60, 'Cóctel y fotos con los novios', 'Meseros y fotógrafo'],
                [135, 10, 'Entrada de los novios', 'DJ'],
                [145, 10, 'Vals', 'DJ'],
                [155, 60, 'Cena', 'Cocina y meseros'],
                [215, 10, 'Brindis', 'Padrinos'],
                [225, 15, 'Partir la torta', 'Cocina'],
                [240, 45, 'Hora loca', 'DJ y animadores'],
                [285, 'baile', 'Baile', 'DJ'],
                [-15, 15, 'Cierre y despedida', 'Coordinación'],
            ],
            'quince' => [
                [0, 30, 'Recepción de invitados', 'Coordinación y meseros'],
                [30, 10, 'Entrada de la quinceañera', 'DJ'],
                [40, 20, 'Ceremonia de las 15 velas y cambio de zapato', 'Coordinación'],
                [60, 15, 'Vals con el papá y los chambelanes', 'DJ'],
                [75, 10, 'Brindis', 'Familia'],
                [85, 60, 'Cena', 'Cocina y meseros'],
                [145, 15, 'Partir la torta', 'Cocina'],
                [160, 45, 'Hora loca', 'DJ y animadores'],
                [205, 'baile', 'Baile', 'DJ'],
                [-15, 15, 'Cierre y despedida', 'Coordinación'],
            ],
            'corporativo' => [
                [0, 30, 'Registro y bienvenida', 'Coordinación'],
                [30, 15, 'Palabras de bienvenida', 'Organizador'],
                [45, 60, 'Presentación / agenda del evento', 'Organizador'],
                [105, 30, 'Coffee break o cóctel', 'Meseros'],
                [135, 30, 'Reconocimientos', 'Organizador'],
                [165, 60, 'Cena', 'Cocina y meseros'],
                [225, 'baile', 'Música y show', 'DJ'],
                [-15, 15, 'Cierre', 'Coordinación'],
            ],
            default => [
                [0, 30, 'Recepción de invitados', 'Coordinación y meseros'],
                [30, 15, 'Bienvenida', 'Coordinación'],
                [45, 60, 'Comida', 'Cocina y meseros'],
                [105, 15, 'Brindis y torta', 'Cocina'],
                [120, 'baile', 'Música y baile', 'DJ'],
                [-15, 15, 'Cierre y despedida', 'Coordinación'],
            ],
        };
    }

    /** Carga el cronograma de ejemplo desde la hora de inicio del evento. Con $reemplazar borra lo que había. */
    public function cargarEjemplo(int $negocioId, int $eventoId, ?string $plantilla = null, bool $reemplazar = false): int
    {
        $ev = $this->evento($negocioId, $eventoId);
        $plantilla = isset(self::PLANTILLAS[(string) $plantilla]) ? (string) $plantilla : self::plantillaDe($ev['tipo']);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            if ($this->bloques($eventoId)) {
                if (!$reemplazar) throw new RuntimeException('Este evento ya tiene cronograma. Para cargar el de ejemplo, elige reemplazar el actual.');
                $this->db->prepare('DELETE FROM cronograma WHERE evento_id = ?')->execute([$eventoId]);
            }
            $total = self::duracionEvento($ev['hora_inicio'], $ev['hora_fin']);
            $inicio = new DateTimeImmutable('2000-01-01 ' . substr($ev['hora_inicio'], 0, 5));
            $ins = $this->db->prepare('INSERT INTO cronograma (evento_id, orden, hora, duracion_min, actividad, responsable) VALUES (?,?,?,?,?,?)');
            $n = 0;
            foreach (self::ejemplo($plantilla) as [$desde, $dur, $act, $resp]) {
                if ($desde < 0) $desde = max(0, $total + $desde);          // cuenta desde el final
                if ($dur === 'baile') $dur = max(30, $total - 15 - $desde);
                if ($desde >= $total && $n > 0) continue;                  // evento corto: no cabe
                $ins->execute([$eventoId, $n++, $inicio->modify("+$desde minutes")->format('H:i'), min(600, (int) $dur), $act, $resp]);
            }
            if ($propia) $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Cronograma en vivo
    // ------------------------------------------------------------------

    /**
     * Estado en vivo: bloques con su hora planificada y real, cuál va ahora, cuál sigue y el atraso.
     * atraso = minutos que empezó tarde el bloque actual (o el último que empezó). Negativo = va adelantado.
     */
    public function vivo(int $eventoId, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $st = $this->db->prepare('SELECT fecha, hora_inicio, hora_fin FROM eventos WHERE id = ?');
        $st->execute([$eventoId]);
        $ev = $st->fetch();
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $bloques = [];
        $actual = null; $siguiente = null; $ultimoEmpezado = null;
        foreach ($this->bloques($eventoId) as $i => $b) {
            $plan = self::momento($ev['fecha'], $ev['hora_inicio'], $b['hora']);
            $b['plan'] = $plan;
            $b['plan_fin'] = $plan->modify('+' . (int) $b['duracion_min'] . ' minutes');
            $b['estado'] = $b['inicio_real'] === null ? 'pendiente' : ($b['fin_real'] === null ? 'ahora' : 'hecho');
            $b['atraso'] = $b['inicio_real'] !== null
                ? (int) round(((new DateTimeImmutable($b['inicio_real']))->getTimestamp() - $plan->getTimestamp()) / 60) : null;
            $b['indice'] = $i;
            $bloques[] = $b;
            if ($b['inicio_real'] !== null) $ultimoEmpezado = $i;
            if ($b['estado'] === 'ahora' && $actual === null) $actual = $i;
            if ($b['estado'] === 'pendiente' && $siguiente === null) $siguiente = $i;
        }
        $atraso = $ultimoEmpezado !== null ? (int) $bloques[$ultimoEmpezado]['atraso'] : 0;
        // Si todavía no empieza nada y ya pasó la hora del primero, también es atraso
        if ($ultimoEmpezado === null && $bloques && $ahora > $bloques[0]['plan']) {
            $atraso = (int) floor(($ahora->getTimestamp() - $bloques[0]['plan']->getTimestamp()) / 60);
        }
        $minEnBloque = null; $minRestantes = null;
        if ($actual !== null) {
            $ini = new DateTimeImmutable($bloques[$actual]['inicio_real']);
            $minEnBloque = (int) floor(($ahora->getTimestamp() - $ini->getTimestamp()) / 60);
            $minRestantes = (int) $bloques[$actual]['duracion_min'] - $minEnBloque;
        }
        $terminado = $bloques && $siguiente === null && $actual === null;
        // Si se usa el modo en vivo otro día (para probarlo), el atraso no tiene sentido
        $prueba = abs($atraso) > 720;
        return [
            'bloques' => $bloques, 'actual' => $actual, 'siguiente' => $siguiente, 'atraso' => $atraso,
            'min_en_bloque' => $minEnBloque, 'min_restantes' => $minRestantes, 'terminado' => $terminado,
            'empezado' => $ultimoEmpezado !== null, 'prueba' => $prueba,
            // Huella para saber si alguien más avanzó (la pantalla se recarga sola)
            'version' => md5(json_encode(array_map(fn($b) => [$b['id'], $b['hora'], $b['actividad'], $b['inicio_real'], $b['fin_real']], $bloques))),
        ];
    }

    /** "Empezar siguiente": termina el bloque actual y empieza el que sigue. Devuelve el nombre del que empezó (o null si terminó todo). */
    public function empezarSiguiente(int $eventoId, ?DateTimeImmutable $ahora = null): ?string
    {
        $ahora ??= new DateTimeImmutable();
        $t = $ahora->format('Y-m-d H:i:s');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM eventos WHERE id = ? FOR UPDATE')->execute([$eventoId]);
            $b = $this->bloques($eventoId);
            if (!$b) throw new RuntimeException('Este evento todavía no tiene cronograma.');
            $empezo = null;
            $actual = null; $siguiente = null;
            foreach ($b as $x) {
                if ($x['inicio_real'] !== null && $x['fin_real'] === null && $actual === null) $actual = $x;
                if ($x['inicio_real'] === null && $siguiente === null) $siguiente = $x;
            }
            if (!$actual && !$siguiente) throw new RuntimeException('El cronograma ya terminó. Si fue un error, toca "Deshacer".');
            if ($actual) $this->db->prepare('UPDATE cronograma SET fin_real = ? WHERE id = ?')->execute([$t, $actual['id']]);
            if ($siguiente) {
                $this->db->prepare('UPDATE cronograma SET inicio_real = ?, fin_real = NULL WHERE id = ?')->execute([$t, $siguiente['id']]);
                $empezo = $siguiente['actividad'];
            }
            if ($propia) $this->db->commit();
            return $empezo;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Deshace el último paso del modo en vivo. */
    public function deshacerVivo(int $eventoId): void
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM eventos WHERE id = ? FOR UPDATE')->execute([$eventoId]);
            $empezados = array_values(array_filter($this->bloques($eventoId), fn($x) => $x['inicio_real'] !== null));
            if (!$empezados) throw new RuntimeException('No hay nada que deshacer.');
            // El último que empezó (por hora real; si empataron, el de más abajo)
            usort($empezados, fn($a, $b) => [$a['inicio_real'], $a['orden']] <=> [$b['inicio_real'], $b['orden']]);
            $ultimo = end($empezados);
            if ($ultimo['fin_real'] !== null) {
                // El último paso fue terminar el evento: se vuelve a abrir el último bloque
                $this->db->prepare('UPDATE cronograma SET fin_real = NULL WHERE id = ?')->execute([$ultimo['id']]);
            } else {
                $this->db->prepare('UPDATE cronograma SET inicio_real = NULL WHERE id = ?')->execute([$ultimo['id']]);
                $anterior = count($empezados) > 1 ? $empezados[count($empezados) - 2] : null;
                if ($anterior) $this->db->prepare('UPDATE cronograma SET fin_real = NULL WHERE id = ?')->execute([$anterior['id']]);
            }
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Borra las horas reales (para volver a empezar el modo en vivo). */
    public function reiniciarVivo(int $negocioId, int $eventoId): void
    {
        $this->evento($negocioId, $eventoId);
        $this->db->prepare('UPDATE cronograma SET inicio_real = NULL, fin_real = NULL WHERE evento_id = ?')->execute([$eventoId]);
    }

    /** "12 min de atraso", "5 min adelantados", "A tiempo" o "Prueba: el evento es otro día". */
    public static function textoAtraso(array $vivo): string
    {
        if (!empty($vivo['prueba'])) return 'Prueba: el evento es otro día';
        $a = (int) $vivo['atraso'];
        return $a > 0 ? "$a min de atraso" : ($a < 0 ? abs($a) . ' min adelantados' : 'A tiempo');
    }

    // ------------------------------------------------------------------
    // Personal (lista del negocio)
    // ------------------------------------------------------------------

    public function personalLista(int $negocioId, bool $soloActivos = false): array
    {
        $st = $this->db->prepare('SELECT p.*,
                   (SELECT count(*) FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id
                     WHERE ep.personal_id = p.id AND e.fecha >= CURRENT_DATE AND e.estado NOT IN (\'perdido\',\'cancelado\')) AS proximos,
                   (SELECT COALESCE(sum(ep.pago),0) FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id
                     WHERE ep.personal_id = p.id AND NOT ep.pagado AND e.fecha < CURRENT_DATE AND COALESCE(ep.asistio, true)
                       AND e.estado NOT IN (\'perdido\',\'cancelado\')) AS por_pagar
              FROM personal p WHERE p.negocio_id = ?' . ($soloActivos ? ' AND p.activo' : '') . '
          ORDER BY p.activo DESC, p.nombre');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function persona(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM personal WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    public function personaPorToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
        $st = $this->db->prepare('SELECT * FROM personal WHERE token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /** Crea o edita una persona: nombre, identificacion, telefono, rol, pago_evento, notas, activo. */
    public function guardarPersona(int $negocioId, ?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre completo.');
        $ident = preg_replace('/\D+/', '', (string) ($d['identificacion'] ?? ''));
        if ($ident !== '' && strlen($ident) !== 10) throw new RuntimeException('La cédula debe tener 10 dígitos.');
        $tel = trim((string) ($d['telefono'] ?? ''));
        if ($tel !== '' && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Revisa el celular (ejemplo: 0991234567).');
        $rol = (string) ($d['rol'] ?? 'mesero');
        if (!isset(self::ROLES[$rol])) throw new RuntimeException('Elige el rol.');
        $pago = (float) ($d['pago_evento'] ?? 0);
        if ($pago < 0 || $pago > 99999) throw new RuntimeException('Revisa la tarifa por evento.');
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null;
        $activo = !array_key_exists('activo', $d) || (bool) $d['activo'];
        $v = [mb_substr($nombre, 0, 120), $ident ?: null, mb_substr($tel, 0, 20) ?: null, $rol, round($pago, 2), $notas, $activo ? 'true' : 'false'];
        if ($id) {
            $st = $this->db->prepare('UPDATE personal SET nombre = ?, identificacion = ?, telefono = ?, rol = ?, pago_evento = ?, notas = ?, activo = ? WHERE id = ? AND negocio_id = ?');
            $st->execute([...$v, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró a esa persona.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO personal (negocio_id, nombre, identificacion, telefono, rol, pago_evento, notas, activo) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, ...$v]);
        return (int) $st->fetchColumn();
    }

    /** Eventos de una persona (próximos o todos). */
    public function eventosDePersona(int $personalId, bool $soloProximos = true, ?DateTimeImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare("SELECT ep.*, e.codigo, e.nombre AS evento, e.fecha, e.hora_inicio, e.hora_fin, e.estado, e.tipo, e.negocio_id,
                                         co.nombre AS coordinador, co.telefono AS coordinador_telefono
                                    FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id
                               LEFT JOIN usuarios co ON co.id = e.coordinador_id
                                   WHERE ep.personal_id = ? AND e.estado NOT IN ('perdido','cancelado')"
                                 . ($soloProximos ? ' AND e.fecha >= ?::date ORDER BY e.fecha, e.hora_inicio' : ' AND e.fecha < ?::date ORDER BY e.fecha DESC LIMIT 60'));
        $st->execute([$personalId, $hoy]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Personal asignado al evento
    // ------------------------------------------------------------------

    public function personalDeEvento(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare("SELECT ep.*, p.nombre, p.telefono, p.token, p.identificacion, p.activo
                                    FROM evento_personal ep JOIN personal p ON p.id = ep.personal_id
                                   WHERE ep.evento_id = ? AND p.negocio_id = ?
                                ORDER BY array_position(ARRAY['coordinador','capitan','mesero','bartender','cocina','seguridad','limpieza','parqueo','otro']::varchar[], ep.rol), p.nombre");
        $st->execute([$eventoId, $negocioId]);
        return $st->fetchAll();
    }

    /**
     * Asigna una persona al evento. Por defecto: su rol, su tarifa, entra 2 horas antes y sale al final.
     * Nadie queda asignado dos veces (lo cuida la base de datos).
     */
    public function asignar(int $negocioId, int $eventoId, int $personalId, array $d = [], ?array $usuario = null): int
    {
        $ev = $this->evento($negocioId, $eventoId);
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Este evento está ' . Eventos::ESTADOS[$ev['estado']] . '.');
        $p = $this->persona($negocioId, $personalId);
        if (!$p) throw new RuntimeException('No se encontró a esa persona.');
        if (!$p['activo']) throw new RuntimeException($p['nombre'] . ' está desactivado. Actívalo en Personal para asignarlo.');
        $rol = (string) ($d['rol'] ?? '') ?: $p['rol'];
        if (!isset(self::ROLES[$rol])) $rol = $p['rol'];
        $entrada = self::hora($d['hora_entrada'] ?? '', true)
            ?? (new DateTimeImmutable('2000-01-01 ' . substr($ev['hora_inicio'], 0, 5)))->modify('-2 hours')->format('H:i');
        $salida = self::hora($d['hora_salida'] ?? '', true) ?? substr($ev['hora_fin'], 0, 5);
        $pago = isset($d['pago']) && $d['pago'] !== '' && $d['pago'] !== null ? (float) $d['pago'] : (float) $p['pago_evento'];
        if ($pago < 0) throw new RuntimeException('El pago no puede ser negativo.');
        try {
            $st = $this->db->prepare('INSERT INTO evento_personal (evento_id, personal_id, rol, hora_entrada, hora_salida, pago) VALUES (?,?,?,?,?,?) RETURNING id');
            $st->execute([$eventoId, $personalId, $rol, $entrada, $salida, round($pago, 2)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') throw new RuntimeException($p['nombre'] . ' ya está asignado a este evento.');
            throw $e;
        }
        $this->cambio($negocioId, $eventoId, 'Asignó a ' . $p['nombre'] . ' (' . self::ROLES[$rol] . ')', $usuario);
        return (int) $st->fetchColumn();
    }

    private function asignacion(int $negocioId, int $epId): array
    {
        $st = $this->db->prepare('SELECT ep.*, p.nombre FROM evento_personal ep JOIN personal p ON p.id = ep.personal_id
                                    JOIN eventos e ON e.id = ep.evento_id WHERE ep.id = ? AND e.negocio_id = ?');
        $st->execute([$epId, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('No se encontró esa asignación.');
        return $r;
    }

    public function editarAsignacion(int $negocioId, int $epId, array $d): void
    {
        $a = $this->asignacion($negocioId, $epId);
        $rol = (string) ($d['rol'] ?? $a['rol']);
        if (!isset(self::ROLES[$rol])) throw new RuntimeException('Elige el rol.');
        $pago = (float) ($d['pago'] ?? $a['pago']);
        if ($pago < 0) throw new RuntimeException('El pago no puede ser negativo.');
        $this->db->prepare('UPDATE evento_personal SET rol = ?, hora_entrada = ?, hora_salida = ?, pago = ? WHERE id = ?')
                 ->execute([$rol, self::hora($d['hora_entrada'] ?? '', true), self::hora($d['hora_salida'] ?? '', true), round($pago, 2), $epId]);
    }

    /** Marca confirmado / asistio / pagado. Para asistio: true, false o null (sin marcar). */
    public function marcar(int $negocioId, int $epId, string $campo, ?bool $valor, ?array $usuario = null): void
    {
        $a = $this->asignacion($negocioId, $epId);
        switch ($campo) {
            case 'confirmado':
                $this->db->prepare('UPDATE evento_personal SET confirmado = ?, respuesta = CASE WHEN ? THEN \'si\' ELSE respuesta END WHERE id = ?')
                         ->execute([$valor ? 'true' : 'false', $valor ? 'true' : 'false', $epId]);
                break;
            case 'asistio':
                $this->db->prepare('UPDATE evento_personal SET asistio = ? WHERE id = ?')->execute([$valor === null ? null : ($valor ? 'true' : 'false'), $epId]);
                break;
            case 'pagado':
                $this->db->prepare('UPDATE evento_personal SET pagado = ?, pagado_en = CASE WHEN ? THEN CURRENT_DATE END WHERE id = ?')
                         ->execute([$valor ? 'true' : 'false', $valor ? 'true' : 'false', $epId]);
                if ($valor) $this->cambio($negocioId, (int) $a['evento_id'], 'Pagó a ' . $a['nombre'] . ' (' . Pdf::dinero($a['pago']) . ')', $usuario);
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    }

    public function quitarAsignacion(int $negocioId, int $epId, ?array $usuario = null): void
    {
        $a = $this->asignacion($negocioId, $epId);
        if ($a['pagado']) throw new RuntimeException('A ' . $a['nombre'] . ' ya se le pagó por este evento. Desmarca el pago antes de quitarlo.');
        $this->db->prepare('DELETE FROM evento_personal WHERE id = ?')->execute([$epId]);
        $this->cambio($negocioId, (int) $a['evento_id'], 'Quitó a ' . $a['nombre'] . ' del personal', $usuario);
    }

    /**
     * Resumen del personal: total a pagar, pagado, meseros asignados vs sugeridos y el nivel del aviso
     * (verde = completo, amarillo = faltan), como en el ERP de QF.
     */
    public function resumenPersonal(int $negocioId, int $eventoId, ?array $lista = null): array
    {
        $ev = $this->evento($negocioId, $eventoId);
        $lista ??= $this->personalDeEvento($negocioId, $eventoId);
        $meseros = count(array_filter($lista, fn($p) => in_array($p['rol'], ['mesero', 'capitan'], true)));
        $sugeridos = (new Eventos($this->db))->meserosSugeridos($negocioId, (int) $ev['invitados']);
        $minimo = $ev['personal_minimo'] !== null ? (int) $ev['personal_minimo'] : null;
        $total = array_sum(array_map(fn($p) => (float) $p['pago'], $lista));
        $pagado = array_sum(array_map(fn($p) => $p['pagado'] ? (float) $p['pago'] : 0.0, $lista));
        $faltanMeseros = max(0, $sugeridos - $meseros);
        $faltanPersonal = $minimo !== null ? max(0, $minimo - count($lista)) : 0;
        return [
            'personas' => count($lista), 'confirmados' => count(array_filter($lista, fn($p) => $p['confirmado'])),
            'meseros' => $meseros, 'meseros_sugeridos' => $sugeridos, 'faltan_meseros' => $faltanMeseros,
            'personal_minimo' => $minimo, 'faltan_personal' => $faltanPersonal,
            'nivel' => ($faltanMeseros || $faltanPersonal) ? 'amarillo' : 'verde',
            'total' => round($total, 2), 'pagado' => round($pagado, 2), 'por_pagar' => round($total - $pagado, 2),
        ];
    }

    /** Personas que ya trabajan en OTRO evento ese mismo día: [personal_id => "EV-2026-0003 Boda de..."]. */
    public function ocupadosEseDia(int $negocioId, string $fecha, int $eventoId): array
    {
        $st = $this->db->prepare("SELECT ep.personal_id, string_agg(e.codigo || ' ' || e.nombre, ', ') AS eventos
                                    FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id
                                   WHERE e.negocio_id = ? AND e.fecha = ?::date AND e.id <> ? AND e.estado NOT IN ('perdido','cancelado')
                                GROUP BY ep.personal_id");
        $st->execute([$negocioId, $fecha, $eventoId]);
        return array_column($st->fetchAll(), 'eventos', 'personal_id');
    }

    /** Asigna los meseros que faltan para llegar a lo sugerido, entre los activos que están libres ese día. Devuelve cuántos asignó. */
    public function asignarMeserosFaltantes(int $negocioId, int $eventoId, ?array $usuario = null): int
    {
        $ev = $this->evento($negocioId, $eventoId);
        $faltan = $this->resumenPersonal($negocioId, $eventoId)['faltan_meseros'];
        if ($faltan <= 0) throw new RuntimeException('Ya tienes los meseros sugeridos para este evento.');
        $ya = array_map(fn($p) => (int) $p['personal_id'], $this->personalDeEvento($negocioId, $eventoId));
        $ocupados = $this->ocupadosEseDia($negocioId, $ev['fecha'], $eventoId);
        $n = 0;
        foreach ($this->personalLista($negocioId, true) as $p) {
            if ($n >= $faltan) break;
            if ($p['rol'] !== 'mesero' || in_array((int) $p['id'], $ya, true) || isset($ocupados[$p['id']])) continue;
            $this->asignar($negocioId, $eventoId, (int) $p['id'], [], $usuario);
            $n++;
        }
        if ($n === 0) throw new RuntimeException('No hay meseros libres ese día en tu lista de personal. Agrega más en Personal.');
        return $n;
    }

    /** Desde el enlace "Mis eventos": la persona dice si va (si) o no puede (no). */
    public function responderStaff(string $token, int $epId, bool $va): array
    {
        $p = $this->personaPorToken($token);
        if (!$p) throw new RuntimeException('Este enlace no es válido.');
        $st = $this->db->prepare('SELECT ep.*, e.fecha, e.nombre AS evento, e.negocio_id FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id WHERE ep.id = ? AND ep.personal_id = ?');
        $st->execute([$epId, $p['id']]);
        $a = $st->fetch();
        if (!$a) throw new RuntimeException('No se encontró ese evento en tu lista.');
        if ($a['fecha'] < date('Y-m-d')) throw new RuntimeException('Ese evento ya pasó.');
        $this->db->prepare('UPDATE evento_personal SET respuesta = ?, respondio_en = now(), confirmado = ? WHERE id = ?')
                 ->execute([$va ? 'si' : 'no', $va ? 'true' : 'false', $epId]);
        $this->cambio((int) $a['negocio_id'], (int) $a['evento_id'], $p['nombre'] . ($va ? ' confirmó que va' : ' avisó que NO puede ir'), null);
        if (!$va) {
            (new Notificaciones($this->db))->alNegocio((int) $a['negocio_id'], $p['nombre'] . ' avisó que no puede ir a ' . $a['evento'] . ' (' . $a['fecha'] . '). Busca un reemplazo.', (int) $a['evento_id'], ['dueno', 'coordinador']);
        }
        return $a;
    }

    /** Pagos de la persona: recibidos y pendientes (eventos ya pasados). */
    public function pagosDePersona(int $personalId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare("SELECT ep.*, e.nombre AS evento, e.fecha FROM evento_personal ep JOIN eventos e ON e.id = ep.evento_id
                                   WHERE ep.personal_id = ? AND e.estado NOT IN ('perdido','cancelado') AND e.fecha < ?::date AND COALESCE(ep.asistio, true)
                                ORDER BY e.fecha DESC LIMIT 60");
        $st->execute([$personalId, $hoy]);
        $filas = $st->fetchAll();
        $pend = array_values(array_filter($filas, fn($f) => !$f['pagado']));
        $reci = array_values(array_filter($filas, fn($f) => $f['pagado']));
        return [
            'pendientes' => $pend, 'recibidos' => $reci,
            'total_pendiente' => round(array_sum(array_map(fn($f) => (float) $f['pago'], $pend)), 2),
            'total_recibido' => round(array_sum(array_map(fn($f) => (float) $f['pago'], $reci)), 2),
        ];
    }

    public static function mensajeStaff(array $persona, array $asig, array $evento, array $negocio, string $enlace): string
    {
        $nombre = trim(explode(' ', trim((string) $persona['nombre']))[0]);
        $horas = $asig['hora_entrada'] ? ', de ' . substr($asig['hora_entrada'], 0, 5) . ($asig['hora_salida'] ? ' a ' . substr($asig['hora_salida'], 0, 5) : '') : '';
        return "Hola $nombre, te escribe {$negocio['nombre']}. ¿Puedes trabajar como " . mb_strtolower(self::ROLES[$asig['rol']] ?? $asig['rol'])
             . " en {$evento['nombre']} el " . self::fechaLarga($evento['fecha']) . "$horas? Pago: " . Pdf::dinero($asig['pago']) . ".\n\n"
             . "Confirma aquí y mira la dirección y tus horarios: $enlace";
    }

    // ------------------------------------------------------------------
    // Proveedores
    // ------------------------------------------------------------------

    public function proveedores(int $negocioId, bool $soloActivos = false): array
    {
        $st = $this->db->prepare('SELECT pr.*,
                   (SELECT count(*) FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id
                     WHERE ep.proveedor_id = pr.id AND e.fecha >= CURRENT_DATE AND e.estado NOT IN (\'perdido\',\'cancelado\')) AS proximos
              FROM proveedores pr WHERE pr.negocio_id = ?' . ($soloActivos ? ' AND pr.activo' : '') . ' ORDER BY pr.activo DESC, pr.servicio, pr.nombre');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function proveedor(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM proveedores WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    public function guardarProveedor(int $negocioId, ?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del proveedor.');
        $serv = (string) ($d['servicio'] ?? 'otros');
        if (!isset(Cotizaciones::CATEGORIAS[$serv])) throw new RuntimeException('Elige el servicio.');
        $tel = trim((string) ($d['telefono'] ?? ''));
        if ($tel !== '' && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Revisa el celular (ejemplo: 0991234567).');
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        $activo = !array_key_exists('activo', $d) || (bool) $d['activo'];
        $v = [mb_substr($nombre, 0, 150), $serv, mb_substr(trim((string) ($d['contacto'] ?? '')), 0, 100) ?: null, mb_substr($tel, 0, 20) ?: null,
              mb_substr($email, 0, 120) ?: null, mb_substr(trim((string) ($d['notas'] ?? '')), 0, 500) ?: null, $activo ? 'true' : 'false'];
        if ($id) {
            $st = $this->db->prepare('UPDATE proveedores SET nombre = ?, servicio = ?, contacto = ?, telefono = ?, email = ?, notas = ?, activo = ? WHERE id = ? AND negocio_id = ?');
            $st->execute([...$v, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el proveedor.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO proveedores (negocio_id, nombre, servicio, contacto, telefono, email, notas, activo) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, ...$v]);
        return (int) $st->fetchColumn();
    }

    public function proveedoresDeEvento(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare('SELECT ep.*, pr.contacto, pr.email FROM evento_proveedores ep LEFT JOIN proveedores pr ON pr.id = ep.proveedor_id
                                   WHERE ep.evento_id = ? ORDER BY ep.hora_llegada NULLS LAST, ep.nombre');
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    /** Agrega un proveedor al evento: del catálogo (proveedor_id) o uno que trae el cliente (nombre, servicio, teléfono). */
    public function agregarProveedorEvento(int $negocioId, int $eventoId, array $d): int
    {
        $this->evento($negocioId, $eventoId);
        $pid = !empty($d['proveedor_id']) ? (int) $d['proveedor_id'] : null;
        $traeCliente = !empty($d['lo_trae_cliente']);
        if ($pid) {
            $p = $this->proveedor($negocioId, $pid);
            if (!$p) throw new RuntimeException('No se encontró el proveedor.');
            $nombre = $p['nombre']; $serv = $p['servicio']; $tel = $p['telefono'];
            $st = $this->db->prepare('SELECT count(*) FROM evento_proveedores WHERE evento_id = ? AND proveedor_id = ?');
            $st->execute([$eventoId, $pid]);
            if ((int) $st->fetchColumn() > 0) throw new RuntimeException($p['nombre'] . ' ya está en este evento.');
        } else {
            $nombre = trim((string) ($d['nombre'] ?? ''));
            if (mb_strlen($nombre) < 2) throw new RuntimeException('Elige un proveedor de tu lista o escribe el nombre del que trae el cliente.');
            $serv = (string) ($d['servicio'] ?? 'otros');
            if (!isset(Cotizaciones::CATEGORIAS[$serv])) $serv = 'otros';
            $tel = trim((string) ($d['telefono'] ?? '')) ?: null;
            if ($tel !== null && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Revisa el celular del proveedor.');
        }
        $st = $this->db->prepare('INSERT INTO evento_proveedores (evento_id, proveedor_id, nombre, servicio, telefono, hora_llegada, lo_trae_cliente, notas)
                                  VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$eventoId, $pid, mb_substr($nombre, 0, 150), $serv, $tel ? mb_substr($tel, 0, 20) : null,
                      self::hora($d['hora_llegada'] ?? '', true), $traeCliente ? 'true' : 'false',
                      mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null]);
        return (int) $st->fetchColumn();
    }

    private function proveedorEvento(int $negocioId, int $id): array
    {
        $st = $this->db->prepare('SELECT ep.* FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id WHERE ep.id = ? AND e.negocio_id = ?');
        $st->execute([$id, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('No se encontró ese proveedor del evento.');
        return $r;
    }

    public function editarProveedorEvento(int $negocioId, int $id, array $d): void
    {
        $r = $this->proveedorEvento($negocioId, $id);
        $tel = trim((string) ($d['telefono'] ?? $r['telefono'] ?? '')) ?: null;
        if ($tel !== null && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Revisa el celular del proveedor.');
        $this->db->prepare('UPDATE evento_proveedores SET hora_llegada = ?, telefono = ?, notas = ?, lo_trae_cliente = ? WHERE id = ?')
                 ->execute([self::hora($d['hora_llegada'] ?? '', true), $tel, mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null,
                            !empty($d['lo_trae_cliente']) ? 'true' : 'false', $id]);
    }

    public function confirmarProveedorEvento(int $negocioId, int $id, bool $valor): void
    {
        $this->proveedorEvento($negocioId, $id);
        $this->db->prepare('UPDATE evento_proveedores SET confirmado = ? WHERE id = ?')->execute([$valor ? 'true' : 'false', $id]);
    }

    public function quitarProveedorEvento(int $negocioId, int $id): void
    {
        $this->proveedorEvento($negocioId, $id);
        $this->db->prepare('DELETE FROM evento_proveedores WHERE id = ?')->execute([$id]);
    }

    public static function mensajeProveedor(array $prov, array $evento, array $negocio): string
    {
        $llegada = $prov['hora_llegada'] ? ', con llegada a las ' . substr($prov['hora_llegada'], 0, 5) : '';
        $lugar = trim(implode(', ', array_filter([$negocio['direccion'] ?? '', $negocio['ciudad'] ?? ''])));
        $mapa = self::mapa($negocio);
        return "Hola " . trim(explode(' ', trim((string) $prov['nombre']))[0]) . ", te escribe {$negocio['nombre']}. "
             . "Te confirmamos {$evento['nombre']} el " . self::fechaLarga($evento['fecha']) . $llegada . '.'
             . ($lugar ? "\nLugar: {$negocio['nombre']}, $lugar." : '') . ($mapa ? "\nMapa: $mapa" : '')
             . "\n¿Nos confirmas, por favor? ¡Gracias!";
    }

    // ------------------------------------------------------------------
    // Mobiliario
    // ------------------------------------------------------------------

    public function mobiliarioLista(int $negocioId, bool $soloActivos = false): array
    {
        $st = $this->db->prepare('SELECT m.*,
                   (SELECT COALESCE(sum(em.danados),0) FROM evento_mobiliario em WHERE em.mobiliario_id = m.id) AS danados_historico
              FROM mobiliario m WHERE m.negocio_id = ?' . ($soloActivos ? ' AND m.activo' : '') . ' ORDER BY m.activo DESC, m.categoria, m.nombre');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function mueble(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM mobiliario WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    public function guardarMobiliario(int $negocioId, ?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre (por ejemplo: Silla Tiffany).');
        $cat = (string) ($d['categoria'] ?? 'mobiliario');
        if (!isset(self::CATEGORIAS_MOBILIARIO[$cat])) throw new RuntimeException('Elige la categoría.');
        $cant = (int) ($d['cantidad_total'] ?? 0);
        if ($cant < 0 || $cant > 100000) throw new RuntimeException('Revisa la cantidad que tienes.');
        $costo = (float) ($d['costo_reposicion'] ?? 0);
        if ($costo < 0) throw new RuntimeException('El costo de reposición no puede ser negativo.');
        $activo = !array_key_exists('activo', $d) || (bool) $d['activo'];
        $v = [mb_substr($nombre, 0, 120), $cat, $cant, round($costo, 2), $activo ? 'true' : 'false'];
        if ($id) {
            $st = $this->db->prepare('UPDATE mobiliario SET nombre = ?, categoria = ?, cantidad_total = ?, costo_reposicion = ?, activo = ? WHERE id = ? AND negocio_id = ?');
            $st->execute([...$v, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró ese mobiliario.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO mobiliario (negocio_id, nombre, categoria, cantidad_total, costo_reposicion, activo) VALUES (?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, ...$v]);
        return (int) $st->fetchColumn();
    }

    /**
     * Disponibilidad de cada ítem en una fecha: total − lo que reservaron OTROS eventos ese mismo día
     * (solo eventos separados, confirmados o realizados; las consultas y cotizaciones no apartan nada).
     * Incluye lo que tiene reservado $eventoId (columna "reservado").
     */
    public function disponibilidadMobiliario(int $negocioId, string $fecha, ?int $eventoId = null): array
    {
        $st = $this->db->prepare(
            "SELECT m.id, m.nombre, m.categoria, m.cantidad_total, m.costo_reposicion, m.activo,
                    COALESCE((SELECT sum(em.cantidad) FROM evento_mobiliario em JOIN eventos e ON e.id = em.evento_id
                               WHERE em.mobiliario_id = m.id AND e.negocio_id = m.negocio_id AND e.fecha = ?::date
                                 AND e.estado IN ('separado','confirmado','realizado') AND e.id <> COALESCE(?::int, 0)), 0) AS otros,
                    COALESCE(mi.cantidad, 0) AS reservado, COALESCE(mi.danados, 0) AS danados
               FROM mobiliario m
          LEFT JOIN evento_mobiliario mi ON mi.mobiliario_id = m.id AND mi.evento_id = ?::int
              WHERE m.negocio_id = ? AND (m.activo OR mi.id IS NOT NULL)
           ORDER BY array_position(ARRAY['mobiliario','manteleria','vajilla','cristaleria','decoracion','equipos','carpas','otros']::varchar[], m.categoria), m.nombre"
        );
        $st->execute([$fecha, $eventoId, $eventoId, $negocioId]);
        $res = [];
        foreach ($st->fetchAll() as $r) {
            $r['otros'] = (int) $r['otros'];
            $r['reservado'] = (int) $r['reservado'];
            $r['danados'] = (int) $r['danados'];
            $r['disponible'] = max(0, (int) $r['cantidad_total'] - $r['otros']);
            $r['faltan'] = max(0, $r['reservado'] - $r['disponible']);
            $res[] = $r;
        }
        return $res;
    }

    /**
     * Guarda lo que se reserva para el evento: [mobiliario_id => cantidad]. 0 = quitar.
     * Se guarda aunque falte (puede alquilar lo que falta), pero devuelve la lista de faltantes para avisar.
     * @return list<array{nombre:string, faltan:int, disponible:int}>
     */
    public function reservarMobiliario(int $negocioId, int $eventoId, array $cantidades): array
    {
        $ev = $this->evento($negocioId, $eventoId);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);   // dos personas a la vez
            $ins = $this->db->prepare('INSERT INTO evento_mobiliario (evento_id, mobiliario_id, cantidad) VALUES (?,?,?)
                                       ON CONFLICT (evento_id, mobiliario_id) DO UPDATE SET cantidad = EXCLUDED.cantidad,
                                              danados = LEAST(evento_mobiliario.danados, EXCLUDED.cantidad)');
            $del = $this->db->prepare('DELETE FROM evento_mobiliario WHERE evento_id = ? AND mobiliario_id = ?');
            foreach ($cantidades as $mid => $cant) {
                $mid = (int) $mid;
                $cant = (int) $cant;
                if ($cant < 0) throw new RuntimeException('Las cantidades no pueden ser negativas.');
                if (!$this->mueble($negocioId, $mid)) throw new RuntimeException('Uno de los ítems no existe.');
                if ($cant === 0) $del->execute([$eventoId, $mid]);
                else $ins->execute([$eventoId, $mid, $cant]);
            }
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $faltan = [];
        foreach ($this->disponibilidadMobiliario($negocioId, $ev['fecha'], $eventoId) as $r) {
            if ($r['faltan'] > 0) $faltan[] = ['nombre' => $r['nombre'], 'faltan' => $r['faltan'], 'disponible' => $r['disponible']];
        }
        return $faltan;
    }

    public function mobiliarioDeEvento(int $negocioId, int $eventoId): array
    {
        $ev = $this->evento($negocioId, $eventoId);
        return array_values(array_filter($this->disponibilidadMobiliario($negocioId, $ev['fecha'], $eventoId), fn($r) => $r['reservado'] > 0));
    }

    /** Después del evento: [mobiliario_id => dañados]. Devuelve el valor a cobrar (costo de reposición). */
    public function registrarDanados(int $negocioId, int $eventoId, array $danados, ?array $usuario = null): float
    {
        $ev = $this->evento($negocioId, $eventoId);
        $up = $this->db->prepare('UPDATE evento_mobiliario SET danados = ? WHERE evento_id = ? AND mobiliario_id = ?');
        $res = [];
        foreach ($this->mobiliarioDeEvento($negocioId, $eventoId) as $r) $res[(int) $r['id']] = $r;
        foreach ($danados as $mid => $n) {
            $mid = (int) $mid; $n = (int) $n;
            if (!isset($res[$mid])) continue;
            if ($n < 0) throw new RuntimeException('Los dañados no pueden ser negativos.');
            if ($n > $res[$mid]['reservado']) throw new RuntimeException("No pueden dañarse más {$res[$mid]['nombre']} de los que se usaron ({$res[$mid]['reservado']}).");
            $up->execute([$n, $eventoId, $mid]);
        }
        $valor = $this->valorDanos($eventoId);
        if ($valor > 0) $this->cambio($negocioId, $eventoId, 'Registró mobiliario dañado en ' . $ev['codigo'] . ' por ' . Pdf::dinero($valor), $usuario);
        return $valor;
    }

    public function valorDanos(int $eventoId): float
    {
        $st = $this->db->prepare('SELECT COALESCE(sum(em.danados * m.costo_reposicion), 0) FROM evento_mobiliario em JOIN mobiliario m ON m.id = em.mobiliario_id WHERE em.evento_id = ?');
        $st->execute([$eventoId]);
        return round((float) $st->fetchColumn(), 2);
    }

    /** Uso de cada ítem en los próximos eventos: [mobiliario_id => [[evento, fecha, cantidad, disponible...]]]. */
    public function usoProximo(int $negocioId, ?DateTimeImmutable $hoy = null, int $dias = 120): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare("SELECT em.mobiliario_id, em.cantidad, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha, e.estado
                                    FROM evento_mobiliario em JOIN eventos e ON e.id = em.evento_id
                                   WHERE e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date AND e.estado IN ('consulta','cotizado','separado','confirmado')
                                ORDER BY e.fecha, e.hora_inicio");
        $st->execute([$negocioId, $hoy->format('Y-m-d'), $hoy->modify("+$dias days")->format('Y-m-d')]);
        $res = [];
        foreach ($st->fetchAll() as $r) $res[(int) $r['mobiliario_id']][] = $r;
        return $res;
    }

    // ------------------------------------------------------------------
    // Transmisión en vivo (QF Producciones)
    // ------------------------------------------------------------------

    public function guardarTransmision(int $negocioId, int $eventoId, string $url, string $estado, ?array $usuario = null): void
    {
        $this->evento($negocioId, $eventoId);
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://[^\s]+\.[^\s]+$#i', $url)) throw new RuntimeException('El enlace de la transmisión debe empezar con https:// (por ejemplo, el de YouTube).');
        if (!isset(self::TRANSMISION[$estado])) throw new RuntimeException('Elige el estado de la transmisión.');
        if ($url !== '' && $estado === 'no') $estado = 'confirmada';
        $this->db->prepare('UPDATE eventos SET transmision_url = ?, transmision_estado = ?, actualizado_en = now() WHERE id = ? AND negocio_id = ?')
                 ->execute([mb_substr($url, 0, 400) ?: null, $estado, $eventoId, $negocioId]);
        $this->cambio($negocioId, $eventoId, 'Transmisión en vivo: ' . self::TRANSMISION[$estado], $usuario);
    }

    /** Marca la transmisión como pedida y devuelve el enlace de WhatsApp a QF Producciones con los datos del evento. */
    public function pedirTransmision(int $negocioId, int $eventoId, ?array $usuario = null): string
    {
        $ev = $this->evento($negocioId, $eventoId);
        $n = $this->negocio($negocioId);
        if ($ev['transmision_estado'] === 'no') {
            $this->db->prepare("UPDATE eventos SET transmision_estado = 'solicitada' WHERE id = ?")->execute([$eventoId]);
            $this->cambio($negocioId, $eventoId, 'Pidió la transmisión en vivo a QF Producciones', $usuario);
        }
        return self::wa((string) $n['whatsapp_transmision'] ?: '593983662181', self::mensajeTransmision($ev, $n));
    }

    public static function mensajeTransmision(array $ev, array $n): string
    {
        $lugar = trim(implode(', ', array_filter([$n['direccion'] ?? '', $n['ciudad'] ?? ''])));
        return "Hola QF Producciones, les escribe {$n['nombre']} (TuRenta). Queremos la transmisión en vivo para:\n"
             . "• Evento: {$ev['nombre']} (" . (Eventos::TIPOS[$ev['tipo']] ?? $ev['tipo']) . ")\n"
             . '• Fecha: ' . self::fechaLarga($ev['fecha']) . ', de ' . substr($ev['hora_inicio'], 0, 5) . ' a ' . substr($ev['hora_fin'], 0, 5) . "\n"
             . "• Lugar: {$n['nombre']}" . ($lugar ? ", $lugar" : '') . "\n"
             . "• Invitados: {$ev['invitados']}\n"
             . "• Código: {$ev['codigo']}\n"
             . '¿Nos confirman disponibilidad y precio? Gracias.';
    }

    // ------------------------------------------------------------------
    // Hoja del evento: todo junto
    // ------------------------------------------------------------------

    public function hoja(int $negocioId, int $eventoId): array
    {
        $ev = (new Eventos($this->db))->obtener($negocioId, $eventoId);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $cot = (new Cotizaciones($this->db))->aceptadaDe($eventoId);
        $personal = $this->personalDeEvento($negocioId, $eventoId);
        return [
            'evento' => $ev,
            'negocio' => $this->negocio($negocioId),
            'cronograma' => $this->bloques($eventoId),
            'personal' => $personal,
            'resumen_personal' => $this->resumenPersonal($negocioId, $eventoId, $personal),
            'proveedores' => $this->proveedoresDeEvento($negocioId, $eventoId),
            'mobiliario' => $this->mobiliarioDeEvento($negocioId, $eventoId),
            'lineas' => $cot ? $cot['items'] : [],
            'cotizacion' => $cot,
            'invitados' => (new Invitados($this->db))->contadores($negocioId, $eventoId),
            'restricciones' => (new Invitados($this->db))->restricciones($negocioId, $eventoId),
            'incidencias' => (new Incidencias($this->db))->deEvento($negocioId, $eventoId),   // 060
        ];
    }
}
