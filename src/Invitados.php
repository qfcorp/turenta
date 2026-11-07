<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Invitados con QR: lista, importar lista pegada, mesas, confirmación de asistencia (RSVP)
 * desde la invitación digital y control de ingreso en la puerta.
 * Los métodos "del negocio" reciben $negocioId; los del invitado trabajan con su token secreto.
 */
final class Invitados
{
    public const ESTADOS = ['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'no_asiste' => 'No asiste'];
    public const MAX_POR_EVENTO = 3000;

    public function __construct(private PDO $db) {}

    /** Evento del negocio (o error). */
    public function evento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM eventos WHERE id = ? AND negocio_id = ?');
        $st->execute([$eventoId, $negocioId]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    // ------------------------------------------------------------------
    // Lista
    // ------------------------------------------------------------------

    /** Lista con su mesa. $f: buscar, estado, grupo, mesa_id ('sin' = sin mesa). */
    public function lista(int $negocioId, int $eventoId, array $f = []): array
    {
        $this->evento($negocioId, $eventoId);
        $sql = 'SELECT i.*, m.nombre AS mesa FROM invitados i LEFT JOIN mesas m ON m.id = i.mesa_id WHERE i.evento_id = ?';
        $p = [$eventoId];
        if (($b = trim((string) ($f['buscar'] ?? ''))) !== '') {
            $sql .= ' AND (i.nombre ILIKE ? OR i.telefono ILIKE ? OR i.grupo ILIKE ?)';
            array_push($p, "%$b%", "%$b%", "%$b%");
        }
        if (isset(self::ESTADOS[(string) ($f['estado'] ?? '')])) { $sql .= ' AND i.estado = ?'; $p[] = $f['estado']; }
        if (($g = trim((string) ($f['grupo'] ?? ''))) !== '') { $sql .= ' AND i.grupo = ?'; $p[] = $g; }
        if (($f['mesa_id'] ?? '') === 'sin') $sql .= ' AND i.mesa_id IS NULL';
        elseif (!empty($f['mesa_id'])) { $sql .= ' AND i.mesa_id = ?'; $p[] = (int) $f['mesa_id']; }
        $orden = ($f['orden'] ?? '') === 'grupo' ? "COALESCE(i.grupo, 'zzz'), i.nombre" : 'i.nombre';
        $st = $this->db->prepare("$sql ORDER BY $orden, i.id");
        $st->execute($p);
        return $st->fetchAll();
    }

    public function invitado(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT i.*, m.nombre AS mesa FROM invitados i JOIN eventos e ON e.id = i.evento_id LEFT JOIN mesas m ON m.id = i.mesa_id
                                   WHERE i.id = ? AND e.negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    /** Invitado por su token (invitación e ingreso), con los datos del evento. */
    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $st = $this->db->prepare('SELECT i.*, m.nombre AS mesa, e.negocio_id FROM invitados i JOIN eventos e ON e.id = i.evento_id
                                  LEFT JOIN mesas m ON m.id = i.mesa_id WHERE i.token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    public function grupos(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare('SELECT grupo, count(*) AS n FROM invitados WHERE evento_id = ? AND grupo IS NOT NULL GROUP BY grupo ORDER BY grupo');
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    private function limpiar(array $d, bool $parcial = false): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($d['nombre'] ?? '')));
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del invitado.');
        $tel = trim((string) ($d['telefono'] ?? ''));
        if ($tel !== '' && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException("Revisa el celular de $nombre (ejemplo: 0991234567).");
        $pases = (int) ($d['pases'] ?? 1);
        if ($pases < 1 || $pases > 20) throw new RuntimeException("Los pases de $nombre van de 1 a 20.");
        return [
            'nombre' => mb_substr($nombre, 0, 150),
            'telefono' => $tel !== '' ? mb_substr($tel, 0, 20) : null,
            'pases' => $pases,
            'grupo' => mb_substr(trim((string) ($d['grupo'] ?? '')), 0, 60) ?: null,
            'restricciones' => mb_substr(trim((string) ($d['restricciones'] ?? '')), 0, 200) ?: null,
        ];
    }

    private function contar(int $eventoId): int
    {
        $st = $this->db->prepare('SELECT count(*) FROM invitados WHERE evento_id = ?');
        $st->execute([$eventoId]);
        return (int) $st->fetchColumn();
    }

    private function mesaDelEvento(int $eventoId, mixed $mesaId): ?int
    {
        if ($mesaId === null || $mesaId === '' || (int) $mesaId === 0) return null;
        $st = $this->db->prepare('SELECT id FROM mesas WHERE id = ? AND evento_id = ?');
        $st->execute([(int) $mesaId, $eventoId]);
        $id = $st->fetchColumn();
        if (!$id) throw new RuntimeException('Esa mesa no es de este evento.');
        return (int) $id;
    }

    /**
     * Agrega o edita un invitado: nombre, telefono, pases, grupo, restricciones, mesa_id;
     * al editar también estado y confirmados (el organizador puede marcar quién confirmó por teléfono).
     */
    public function guardar(int $negocioId, int $eventoId, ?int $id, array $d): int
    {
        $this->evento($negocioId, $eventoId);
        $v = $this->limpiar($d);
        $mesa = array_key_exists('mesa_id', $d) ? $this->mesaDelEvento($eventoId, $d['mesa_id']) : false;
        if ($id) {
            $actual = $this->invitado($negocioId, $id);
            if (!$actual || (int) $actual['evento_id'] !== $eventoId) throw new RuntimeException('No se encontró al invitado.');
            $estado = (string) ($d['estado'] ?? $actual['estado']);
            if (!isset(self::ESTADOS[$estado])) $estado = $actual['estado'];
            $conf = match ($estado) {
                'confirmado' => min($v['pases'], max(1, (int) ($d['confirmados'] ?? $actual['confirmados'] ?? $v['pases']))),
                'no_asiste' => 0,
                default => null,
            };
            if ((int) $actual['ingresaron'] > $v['pases']) throw new RuntimeException("{$v['nombre']} ya ingresó con {$actual['ingresaron']} personas: no puedes bajar los pases a {$v['pases']}.");
            $this->db->prepare('UPDATE invitados SET nombre = ?, telefono = ?, pases = ?, grupo = ?, restricciones = ?, estado = ?, confirmados = ?,
                                       respondido_en = CASE WHEN estado <> ? THEN now() ELSE respondido_en END' . ($mesa !== false ? ', mesa_id = ?' : '') . ' WHERE id = ?')
                     ->execute(array_merge([$v['nombre'], $v['telefono'], $v['pases'], $v['grupo'], $v['restricciones'], $estado, $conf, $estado],
                                           $mesa !== false ? [$mesa] : [], [$id]));
            return $id;
        }
        if ($this->contar($eventoId) >= self::MAX_POR_EVENTO) throw new RuntimeException('Este evento llegó al máximo de ' . self::MAX_POR_EVENTO . ' invitados.');
        $st = $this->db->prepare('INSERT INTO invitados (evento_id, nombre, telefono, pases, grupo, restricciones, mesa_id) VALUES (?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$eventoId, $v['nombre'], $v['telefono'], $v['pases'], $v['grupo'], $v['restricciones'], $mesa ?: null]);
        return (int) $st->fetchColumn();
    }

    public function borrar(int $negocioId, int $id): void
    {
        $i = $this->invitado($negocioId, $id);
        if (!$i) throw new RuntimeException('No se encontró al invitado.');
        if ((int) $i['ingresaron'] > 0) throw new RuntimeException("{$i['nombre']} ya ingresó al evento: no se puede borrar.");
        $this->db->prepare('DELETE FROM invitados WHERE id = ?')->execute([$id]);
    }

    /**
     * Lee una lista pegada: una línea por invitado. "Nombre; teléfono; pases; grupo".
     * Separadores: punto y coma, coma o tabulación (lo que viene de Excel o WhatsApp).
     * El teléfono y lo demás son opcionales y se reconocen por su forma:
     *   "Ana Pérez, 0991234567, 2, Familia de la novia"  ·  "Luis Mora	3"  ·  "Tía Rosa"
     * Devuelve [filas válidas, errores por línea].
     * @return array{0: list<array>, 1: list<string>}
     */
    public static function leerLista(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $filas = []; $errores = [];
        foreach (explode("\n", $texto) as $n => $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;
            // Encabezados típicos de Excel
            if ($n === 0 && preg_match('/^nombres?\b/iu', $linea) && preg_match('/tel|celular|pases|grupo/iu', $linea)) continue;
            // Viñetas y números de lista: "1. Ana", "- Ana", "• Ana"
            $linea = preg_replace('/^(\d{1,3}[\.\)]\s+|[-•*·]\s*)/u', '', $linea);
            $sep = str_contains($linea, "\t") ? "\t" : (str_contains($linea, ';') ? ';' : ',');
            $partes = array_map('trim', explode($sep, $linea));
            $partes = array_values(array_filter($partes, fn($x) => $x !== ''));
            if (!$partes) continue;
            $fila = ['nombre' => array_shift($partes), 'telefono' => '', 'pases' => 1, 'grupo' => ''];
            $resto = [];
            foreach ($partes as $p) {
                $digitos = preg_replace('/[\s\-().+]/', '', $p);
                if ($fila['telefono'] === '' && preg_match('/^\d{9,13}$/', $digitos)) { $fila['telefono'] = $p; continue; }
                if (preg_match('/^(\d{1,2})(\s*(pases?|personas?|pax))?$/iu', $p, $m) && $fila['pases'] === 1 && !isset($fila['_pases'])) {
                    $fila['pases'] = (int) $m[1]; $fila['_pases'] = true; continue;
                }
                $resto[] = $p;
            }
            unset($fila['_pases']);
            $fila['grupo'] = implode(' ', $resto);
            // "Ana Pérez 0991234567" sin separador
            if ($fila['telefono'] === '' && preg_match('/^(.*?)[\s]+(\+?\d[\d\s\-]{8,15}\d)$/u', $fila['nombre'], $m)) {
                $fila['nombre'] = trim($m[1]); $fila['telefono'] = trim($m[2]);
            }
            $linNum = $n + 1;
            if (mb_strlen($fila['nombre']) < 2 || preg_match('/^\d+$/', $fila['nombre'])) { $errores[] = "Línea $linNum: falta el nombre."; continue; }
            if ($fila['pases'] < 1 || $fila['pases'] > 20) { $errores[] = "Línea $linNum ({$fila['nombre']}): los pases van de 1 a 20."; continue; }
            if ($fila['telefono'] !== '' && WhatsApp::normalizarTelefono($fila['telefono']) === null) {
                $errores[] = "Línea $linNum ({$fila['nombre']}): el celular no se entiende, se guardó sin celular.";
                $fila['telefono'] = '';
            }
            $filas[] = $fila;
        }
        return [$filas, $errores];
    }

    /**
     * Importa la lista pegada. No repite invitados que ya están (mismo nombre o mismo celular).
     * @return array{agregados:int, repetidos:int, errores:list<string>}
     */
    public function importar(int $negocioId, int $eventoId, string $texto, string $grupoDefecto = ''): array
    {
        $this->evento($negocioId, $eventoId);
        [$filas, $errores] = self::leerLista($texto);
        if (!$filas && !$errores) throw new RuntimeException('Pega la lista: un invitado por línea.');
        $existentes = $this->lista($negocioId, $eventoId);
        $nombres = [];
        $tels = [];
        foreach ($existentes as $x) {
            $nombres[self::clave($x['nombre'])] = true;
            if ($t = WhatsApp::normalizarTelefono($x['telefono'])) $tels[$t] = true;
        }
        if (count($existentes) + count($filas) > self::MAX_POR_EVENTO) throw new RuntimeException('Son demasiados invitados para un evento (máximo ' . self::MAX_POR_EVENTO . ').');
        $agregados = 0; $repetidos = 0;
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare('INSERT INTO invitados (evento_id, nombre, telefono, pases, grupo) VALUES (?,?,?,?,?)');
            foreach ($filas as $f) {
                $k = self::clave($f['nombre']);
                $t = WhatsApp::normalizarTelefono($f['telefono']);
                if (isset($nombres[$k]) || ($t && isset($tels[$t]))) { $repetidos++; continue; }
                $nombres[$k] = true;
                if ($t) $tels[$t] = true;
                $grupo = $f['grupo'] !== '' ? $f['grupo'] : trim($grupoDefecto);
                $ins->execute([$eventoId, mb_substr($f['nombre'], 0, 150), $f['telefono'] !== '' ? mb_substr($f['telefono'], 0, 20) : null,
                               $f['pases'], $grupo !== '' ? mb_substr($grupo, 0, 60) : null]);
                $agregados++;
            }
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['agregados' => $agregados, 'repetidos' => $repetidos, 'errores' => $errores];
    }

    /** Nombre comparable: sin tildes, sin mayúsculas, sin espacios dobles. */
    private static function clave(string $nombre): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)));
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    // ------------------------------------------------------------------
    // Contadores
    // ------------------------------------------------------------------

    public function contadores(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare("SELECT count(*) AS invitados, COALESCE(sum(pases),0) AS pases,
                    count(*) FILTER (WHERE estado = 'confirmado') AS invitados_confirmados,
                    COALESCE(sum(confirmados) FILTER (WHERE estado = 'confirmado'),0) AS confirmados,
                    count(*) FILTER (WHERE estado = 'no_asiste') AS invitados_no_asisten,
                    COALESCE(sum(pases) FILTER (WHERE estado = 'no_asiste'),0) AS no_asisten,
                    count(*) FILTER (WHERE estado = 'pendiente') AS invitados_pendientes,
                    COALESCE(sum(pases) FILTER (WHERE estado = 'pendiente'),0) AS pendientes,
                    COALESCE(sum(ingresaron),0) AS ingresaron,
                    count(*) FILTER (WHERE mesa_id IS NULL AND estado <> 'no_asiste') AS sin_mesa
               FROM invitados WHERE evento_id = ?");
        $st->execute([$eventoId]);
        $c = array_map('intval', $st->fetch());
        // Esperados en la puerta: los confirmados + los pendientes (todavía pueden llegar)
        $c['esperados'] = $c['confirmados'] + $c['pendientes'];
        return $c;
    }

    /** Conteo de restricciones de comida ("vegetariano" x 4, "sin gluten" x 1...). Solo de quienes van. */
    public function restricciones(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare("SELECT restricciones, nombre, COALESCE(confirmados, pases) AS personas FROM invitados
                                   WHERE evento_id = ? AND restricciones IS NOT NULL AND estado <> 'no_asiste' ORDER BY nombre");
        $st->execute([$eventoId]);
        $conteo = [];
        foreach ($st->fetchAll() as $r) {
            foreach (preg_split('/[,;\/]| y /u', (string) $r['restricciones']) as $x) {
                $x = trim(mb_strtolower($x), " .\t");
                if ($x === '') continue;
                $k = self::clave($x);
                $conteo[$k] ??= ['texto' => $x, 'veces' => 0, 'quienes' => []];
                $conteo[$k]['veces']++;
                $conteo[$k]['quienes'][] = $r['nombre'];
            }
        }
        uasort($conteo, fn($a, $b) => $b['veces'] <=> $a['veces'] ?: strcmp($a['texto'], $b['texto']));
        return array_values($conteo);
    }

    // ------------------------------------------------------------------
    // Mesas
    // ------------------------------------------------------------------

    /** Mesas con su ocupación (pases de los que van) y aviso si se pasa. */
    public function mesas(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare("SELECT m.*, COALESCE(sum(CASE WHEN i.estado = 'confirmado' THEN i.confirmados WHEN i.estado = 'pendiente' THEN i.pases ELSE 0 END),0) AS ocupados,
                                         count(i.id) FILTER (WHERE i.estado <> 'no_asiste') AS invitados
                                    FROM mesas m LEFT JOIN invitados i ON i.mesa_id = m.id
                                   WHERE m.evento_id = ? GROUP BY m.id ORDER BY m.orden, m.id");
        $st->execute([$eventoId]);
        $res = [];
        foreach ($st->fetchAll() as $m) {
            $m['ocupados'] = (int) $m['ocupados'];
            $m['libres'] = (int) $m['capacidad'] - $m['ocupados'];
            $m['se_pasa'] = $m['libres'] < 0;
            $res[] = $m;
        }
        return $res;
    }

    /** Crea N mesas de X puestos ("Mesa 1", "Mesa 2"... siguiendo la numeración que ya hay). */
    public function crearMesas(int $negocioId, int $eventoId, int $cantidad, int $puestos, string $prefijo = 'Mesa'): int
    {
        $this->evento($negocioId, $eventoId);
        if ($cantidad < 1 || $cantidad > 200) throw new RuntimeException('Crea de 1 a 200 mesas a la vez.');
        if ($puestos < 1 || $puestos > 100) throw new RuntimeException('Cada mesa puede tener de 1 a 100 puestos.');
        $prefijo = trim($prefijo) ?: 'Mesa';
        $st = $this->db->prepare('SELECT count(*), COALESCE(max(orden), 0) FROM mesas WHERE evento_id = ?');
        $st->execute([$eventoId]);
        [$hay, $orden] = $st->fetch(PDO::FETCH_NUM);
        if ((int) $hay + $cantidad > 300) throw new RuntimeException('Un evento puede tener hasta 300 mesas.');
        $ins = $this->db->prepare('INSERT INTO mesas (evento_id, nombre, capacidad, orden) VALUES (?,?,?,?)');
        for ($i = 1; $i <= $cantidad; $i++) {
            $ins->execute([$eventoId, mb_substr($prefijo . ' ' . ((int) $hay + $i), 0, 60), $puestos, (int) $orden + $i]);
        }
        return $cantidad;
    }

    private function mesa(int $negocioId, int $mesaId): array
    {
        $st = $this->db->prepare('SELECT m.* FROM mesas m JOIN eventos e ON e.id = m.evento_id WHERE m.id = ? AND e.negocio_id = ?');
        $st->execute([$mesaId, $negocioId]);
        $m = $st->fetch();
        if (!$m) throw new RuntimeException('No se encontró la mesa.');
        return $m;
    }

    public function editarMesa(int $negocioId, int $mesaId, string $nombre, int $capacidad): void
    {
        $this->mesa($negocioId, $mesaId);
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 1) throw new RuntimeException('Escribe el nombre de la mesa.');
        if ($capacidad < 1 || $capacidad > 100) throw new RuntimeException('Cada mesa puede tener de 1 a 100 puestos.');
        $this->db->prepare('UPDATE mesas SET nombre = ?, capacidad = ? WHERE id = ?')->execute([mb_substr($nombre, 0, 60), $capacidad, $mesaId]);
    }

    /** Borra la mesa: sus invitados quedan sin mesa. */
    public function borrarMesa(int $negocioId, int $mesaId): void
    {
        $this->mesa($negocioId, $mesaId);
        $this->db->prepare('DELETE FROM mesas WHERE id = ?')->execute([$mesaId]);
    }

    /** Sienta a un invitado en una mesa (null = sin mesa). Devuelve la mesa con su ocupación, para avisar si se pasa. */
    public function asignarMesa(int $negocioId, int $invitadoId, ?int $mesaId): ?array
    {
        $i = $this->invitado($negocioId, $invitadoId);
        if (!$i) throw new RuntimeException('No se encontró al invitado.');
        $mesaId = $this->mesaDelEvento((int) $i['evento_id'], $mesaId);
        $this->db->prepare('UPDATE invitados SET mesa_id = ? WHERE id = ?')->execute([$mesaId, $invitadoId]);
        if (!$mesaId) return null;
        foreach ($this->mesas($negocioId, (int) $i['evento_id']) as $m) if ((int) $m['id'] === $mesaId) return $m;
        return null;
    }

    /** Varios a la vez: [invitado_id => mesa_id|0]. Devuelve las mesas que se pasan. */
    public function asignarMesas(int $negocioId, int $eventoId, array $asignacion): array
    {
        $this->evento($negocioId, $eventoId);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $up = $this->db->prepare('UPDATE invitados SET mesa_id = ? WHERE id = ? AND evento_id = ?');
            foreach ($asignacion as $inv => $mesa) $up->execute([$this->mesaDelEvento($eventoId, $mesa), (int) $inv, $eventoId]);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return array_values(array_filter($this->mesas($negocioId, $eventoId), fn($m) => $m['se_pasa']));
    }

    /** Plano: cada mesa con su lista de invitados (y al final los que no tienen mesa). */
    public function plano(int $negocioId, int $eventoId): array
    {
        $mesas = $this->mesas($negocioId, $eventoId);
        $porMesa = [];
        foreach ($this->lista($negocioId, $eventoId) as $i) {
            if ($i['estado'] === 'no_asiste') continue;
            $porMesa[(int) $i['mesa_id']][] = $i;
        }
        foreach ($mesas as &$m) $m['lista'] = $porMesa[(int) $m['id']] ?? [];
        unset($m);
        return ['mesas' => $mesas, 'sin_mesa' => $porMesa[0] ?? []];
    }

    // ------------------------------------------------------------------
    // Invitación: confirmar asistencia (RSVP)
    // ------------------------------------------------------------------

    /**
     * El invitado responde desde su invitación: va con N de sus pases (y sus restricciones de comida) o no podrá asistir.
     * Se puede cambiar la respuesta hasta el día del evento.
     */
    public function responder(string $token, bool $asiste, int $cuantos = 0, string $restricciones = '', string $mensaje = '', ?DateTimeImmutable $hoy = null): array
    {
        $i = $this->porToken($token);
        if (!$i) throw new RuntimeException('Esta invitación no es válida. Pide el enlace otra vez a los organizadores.');
        $st = $this->db->prepare('SELECT fecha, estado, nombre, negocio_id, confirmar_hasta FROM eventos WHERE id = ?');
        $st->execute([$i['evento_id']]);
        $ev = $st->fetch();
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Este evento fue cancelado.');
        if ($ev['fecha'] < $hoyTxt) throw new RuntimeException('Este evento ya pasó. ¡Gracias!');
        if ((int) $i['ingresaron'] > 0) throw new RuntimeException('Ya registramos tu ingreso al evento. ¡Disfruta!');
        if ($asiste) {
            if ($cuantos < 1) throw new RuntimeException('Elige cuántas personas van.');
            if ($cuantos > (int) $i['pases']) throw new RuntimeException('Tu invitación es para ' . $i['pases'] . ($i['pases'] == 1 ? ' persona.' : ' personas.'));
            $this->db->prepare("UPDATE invitados SET estado = 'confirmado', confirmados = ?, restricciones = ?, mensaje = COALESCE(?, mensaje), respondido_en = now() WHERE id = ?")
                     ->execute([$cuantos, mb_substr(trim($restricciones), 0, 200) ?: null, mb_substr(trim($mensaje), 0, 400) ?: null, $i['id']]);
        } else {
            $this->db->prepare("UPDATE invitados SET estado = 'no_asiste', confirmados = 0, mensaje = COALESCE(?, mensaje), respondido_en = now() WHERE id = ?")
                     ->execute([mb_substr(trim($mensaje), 0, 400) ?: null, $i['id']]);
        }
        return $this->porToken($token);
    }

    // ------------------------------------------------------------------
    // Puerta: control de ingreso
    // ------------------------------------------------------------------

    /**
     * Registra que entraron $cuantos personas de ese invitado (por defecto, todas las que faltan).
     * Nunca más que sus pases. Devuelve ['ok' => bool, 'mensaje' => ..., 'invitado' => ..., 'entraron' => n].
     */
    public function registrarIngreso(int $invitadoId, ?int $cuantos = null, ?int $eventoId = null): array
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT i.*, m.nombre AS mesa FROM invitados i LEFT JOIN mesas m ON m.id = i.mesa_id
                                       WHERE i.id = ? AND (?::int IS NULL OR i.evento_id = ?::int) FOR UPDATE OF i');
            $st->execute([$invitadoId, $eventoId, $eventoId]);
            $i = $st->fetch();
            if (!$i) throw new RuntimeException('No se encontró al invitado.');
            $pases = (int) $i['pases'];
            $ya = (int) $i['ingresaron'];
            $faltan = $pases - $ya;
            if ($faltan <= 0) {
                if ($propia) $this->db->commit();
                return ['ok' => false, 'mensaje' => "{$i['nombre']} ya ingresó completo ($ya de $pases).", 'invitado' => $i, 'entraron' => 0, 'repetido' => true];
            }
            $cuantos = $cuantos === null ? $faltan : $cuantos;
            if ($cuantos < 1) throw new RuntimeException('Elige cuántas personas entran.');
            if ($cuantos > $faltan) {
                if ($propia) $this->db->commit();
                return ['ok' => false, 'mensaje' => "{$i['nombre']} solo tiene $faltan " . ($faltan === 1 ? 'pase' : 'pases') . " por usar (de $pases).", 'invitado' => $i, 'entraron' => 0, 'repetido' => false];
            }
            $this->db->prepare("UPDATE invitados SET ingresaron = ingresaron + ?, ingreso_en = COALESCE(ingreso_en, now()),
                                       estado = CASE WHEN estado = 'pendiente' OR estado = 'no_asiste' THEN 'confirmado' ELSE estado END,
                                       confirmados = GREATEST(COALESCE(confirmados, 0), ingresaron + ?) WHERE id = ?")
                     ->execute([$cuantos, $cuantos, $invitadoId]);
            if ($propia) $this->db->commit();
            $i['ingresaron'] = $ya + $cuantos;
            return ['ok' => true, 'mensaje' => "Bienvenido(a), {$i['nombre']}.", 'invitado' => $i, 'entraron' => $cuantos, 'repetido' => false];
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Corrige un ingreso marcado por error (resta personas). */
    public function deshacerIngreso(int $eventoId, int $invitadoId, int $cuantos = 1): void
    {
        $st = $this->db->prepare('UPDATE invitados SET ingresaron = GREATEST(0, ingresaron - ?), ingreso_en = CASE WHEN ingresaron - ? <= 0 THEN NULL ELSE ingreso_en END
                                   WHERE id = ? AND evento_id = ?');
        $st->execute([max(1, $cuantos), max(1, $cuantos), $invitadoId, $eventoId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró al invitado.');
    }

    /** Los últimos que entraron (para la pantalla de la puerta). */
    public function ultimosIngresos(int $eventoId, int $limite = 8): array
    {
        $st = $this->db->prepare('SELECT i.id, i.nombre, i.grupo, i.pases, i.confirmados, i.estado, i.ingresaron, i.ingreso_en, m.nombre AS mesa
                                    FROM invitados i LEFT JOIN mesas m ON m.id = i.mesa_id
                                   WHERE i.evento_id = ? AND i.ingresaron > 0 ORDER BY i.ingreso_en DESC, i.id DESC LIMIT ' . (int) $limite);
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    /** Búsqueda rápida para la puerta (por nombre, sin tildes). */
    public function buscarPuerta(int $eventoId, string $q, int $limite = 30): array
    {
        $q = trim($q);
        $sql = 'SELECT i.id, i.nombre, i.grupo, i.pases, i.confirmados, i.estado, i.ingresaron, m.nombre AS mesa
                  FROM invitados i LEFT JOIN mesas m ON m.id = i.mesa_id WHERE i.evento_id = ?';
        $p = [$eventoId];
        if ($q !== '') {
            $digitos = preg_replace('/\D+/', '', $q);
            if (strlen($digitos) >= 4) {
                $sql .= " AND regexp_replace(COALESCE(i.telefono, ''), '\\D', '', 'g') LIKE ?";
                $p[] = '%' . $digitos . '%';
            } else {
                // Cada palabra debe aparecer (en cualquier orden): "rosa tia" encuentra "Tía Rosa"
                foreach (preg_split('/\s+/u', self::clave($q)) as $palabra) {
                    $sql .= " AND translate(lower(i.nombre), 'áéíóúüñ', 'aeiouun') LIKE ?";
                    $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $palabra) . '%';
                }
            }
        }
        $st = $this->db->prepare($sql . ' ORDER BY (i.ingresaron >= i.pases), i.nombre LIMIT ' . (int) $limite);
        $st->execute($p);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Mensajes y exportar
    // ------------------------------------------------------------------

    /** Mensaje bonito para mandar la invitación por WhatsApp. */
    public static function mensajeInvitacion(array $inv, array $evento, array $negocio, string $enlace): string
    {
        $nombre = trim((string) $inv['nombre']);
        $pases = (int) $inv['pases'];
        return "✨ ¡Hola, $nombre! ✨\n\n"
             . "Con mucha alegría te invitamos a *{$evento['nombre']}*.\n\n"
             . '📅 ' . DiaEvento::fechaLarga($evento['fecha']) . "\n"
             . '🕖 ' . substr((string) $evento['hora_inicio'], 0, 5) . "\n"
             . '📍 ' . $negocio['nombre'] . (!empty($negocio['ciudad']) ? ', ' . $negocio['ciudad'] : '') . "\n"
             . ($pases > 1 ? "🎟️ Tu invitación es para $pases personas.\n" : '')
             . "\nConfirma tu asistencia aquí (ahí también está tu código QR para la entrada):\n$enlace\n\n¡Te esperamos!";
    }

    /** CSV para Excel: separador ; y BOM UTF-8. */
    public function csv(int $negocioId, int $eventoId): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['Nombre', 'Celular', 'Grupo', 'Pases', 'Estado', 'Confirmados', 'Mesa', 'Restricciones', 'Ingresaron', 'Mensaje'], ';', '"', '');
        foreach ($this->lista($negocioId, $eventoId, ['orden' => 'grupo']) as $i) {
            $fila = [$i['nombre'], $i['telefono'], $i['grupo'], $i['pases'], self::ESTADOS[$i['estado']], $i['confirmados'], $i['mesa'], $i['restricciones'], $i['ingresaron'], $i['mensaje']];
            // Que Excel no ejecute fórmulas escritas por alguien en un nombre
            $fila = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $fila);
            fputcsv($f, $fila, ';', '"', '');
        }
        rewind($f);
        $s = (string) stream_get_contents($f);
        fclose($f);
        return $s;
    }

    public function marcarInvitacionEnviada(int $negocioId, int $invitadoId): void
    {
        if (!$this->invitado($negocioId, $invitadoId)) throw new RuntimeException('No se encontró al invitado.');
        $this->db->prepare('UPDATE invitados SET invitacion_enviada = CURRENT_DATE WHERE id = ?')->execute([$invitadoId]);
    }

    /**
     * Acciones de la lista que comparten la pantalla del negocio (invitados) y el portal de los novios (portal_invitados).
     * Devuelve los avisos para mostrar: [[texto, tipo], ...]. Lanza RuntimeException con el error para el usuario.
     */
    public function accion(int $negocioId, int $eventoId, string $accion, array $p, bool $delNegocio = true): array
    {
        $id = (int) ($p['invitado_id'] ?? 0);
        switch ($accion) {
            case 'agregar':
                $this->guardar($negocioId, $eventoId, null, $p);
                return [['Invitado agregado: ' . trim((string) ($p['nombre'] ?? '')) . '.', 'ok']];
            case 'editar':
                if (!$delNegocio) unset($p['estado'], $p['confirmados']);   // los novios no cambian lo que respondió el invitado
                $this->guardar($negocioId, $eventoId, $id, $p);
                return [['Cambios guardados.', 'ok']];
            case 'borrar':
                $i = $this->invitado($negocioId, $id);
                if (!$i || (int) $i['evento_id'] !== $eventoId) throw new RuntimeException('No se encontró al invitado.');
                $this->borrar($negocioId, $id);
                return [["Se quitó a {$i['nombre']} de la lista.", 'ok']];
            case 'importar':
                $r = $this->importar($negocioId, $eventoId, (string) ($p['lista'] ?? ''), (string) ($p['grupo'] ?? ''));
                $avisos = [[$r['agregados'] === 1 ? 'Se agregó 1 invitado.' : "Se agregaron {$r['agregados']} invitados."
                            . ($r['repetidos'] ? " {$r['repetidos']} ya estaban en la lista y no se repitieron." : ''), $r['agregados'] ? 'ok' : 'error']];
                if ($r['errores']) $avisos[] = ['Revisa: ' . implode(' ', array_slice($r['errores'], 0, 8)) . (count($r['errores']) > 8 ? ' (y ' . (count($r['errores']) - 8) . ' más)' : ''), 'error'];
                return $avisos;
            case 'mesas_crear':
                $n = $this->crearMesas($negocioId, $eventoId, (int) ($p['cantidad'] ?? 0), (int) ($p['puestos'] ?? 0), (string) ($p['prefijo'] ?? 'Mesa'));
                return [[$n === 1 ? 'Se creó 1 mesa.' : "Se crearon $n mesas.", 'ok']];
            case 'mesa_editar':
                $this->mesaDeEsteEvento($negocioId, $eventoId, (int) ($p['mesa_id'] ?? 0));
                $this->editarMesa($negocioId, (int) $p['mesa_id'], (string) ($p['nombre'] ?? ''), (int) ($p['capacidad'] ?? 0));
                return [['Mesa guardada.', 'ok']];
            case 'mesa_borrar':
                $this->mesaDeEsteEvento($negocioId, $eventoId, (int) ($p['mesa_id'] ?? 0));
                $this->borrarMesa($negocioId, (int) $p['mesa_id']);
                return [['Mesa borrada. Sus invitados quedaron sin mesa.', 'ok']];
            case 'mesa':
                $i = $this->invitado($negocioId, $id);
                if (!$i || (int) $i['evento_id'] !== $eventoId) throw new RuntimeException('No se encontró al invitado.');
                $m = $this->asignarMesa($negocioId, $id, (int) ($p['mesa_id'] ?? 0) ?: null);
                if (!$m) return [["{$i['nombre']} quedó sin mesa.", 'ok']];
                return $m['se_pasa']
                    ? [["{$i['nombre']} quedó en {$m['nombre']}, pero la mesa se pasa: {$m['ocupados']} personas para {$m['capacidad']} puestos.", 'error']]
                    : [["{$i['nombre']} quedó en {$m['nombre']} ({$m['ocupados']} de {$m['capacidad']}).", 'ok']];
            case 'enviada':
                $i = $this->invitado($negocioId, $id);
                if (!$i || (int) $i['evento_id'] !== $eventoId) throw new RuntimeException('No se encontró al invitado.');
                $this->marcarInvitacionEnviada($negocioId, $id);
                return [['Invitación marcada como enviada.', 'ok']];
            case 'invitacion':
                $this->guardarInvitacion($negocioId, $eventoId, (string) ($p['invitacion_texto'] ?? ''), (string) ($p['confirmar_hasta'] ?? ''));
                return [['Invitación guardada.', 'ok']];
            case 'ingreso_deshacer':
                if (!$delNegocio) throw new RuntimeException('Acción no válida.');
                $this->deshacerIngreso($eventoId, $id, (int) ($p['cuantos'] ?? 1));
                return [['Ingreso corregido.', 'ok']];
            default:
                throw new RuntimeException('Acción no válida.');
        }
    }

    private function mesaDeEsteEvento(int $negocioId, int $eventoId, int $mesaId): void
    {
        $m = $this->mesa($negocioId, $mesaId);
        if ((int) $m['evento_id'] !== $eventoId) throw new RuntimeException('No se encontró la mesa.');
    }

    /** Texto y fecha límite propios de la invitación del evento. */
    public function guardarInvitacion(int $negocioId, int $eventoId, string $texto, ?string $confirmarHasta): void
    {
        $ev = $this->evento($negocioId, $eventoId);
        $hasta = trim((string) $confirmarHasta);
        if ($hasta !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
            if (!$d || $d->format('Y-m-d') !== $hasta) throw new RuntimeException('Revisa la fecha límite para confirmar.');
            if ($hasta > $ev['fecha']) throw new RuntimeException('La fecha límite para confirmar debe ser antes del evento.');
        }
        $this->db->prepare('UPDATE eventos SET invitacion_texto = ?, confirmar_hasta = ? WHERE id = ?')
                 ->execute([mb_substr(trim($texto), 0, 600) ?: null, $hasta ?: null, $eventoId]);
    }
}
