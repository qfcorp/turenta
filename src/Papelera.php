<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Pausar y borrar clientes (negocios) desde el Panel Tukán Central (PROTOCOLO_V2.md, Anexo A).
 *
 *  - Pausar: la cuenta queda "suspendida" con un tipo y un motivo. Sus usuarios ven "Tu cuenta está pausada" (con el motivo,
 *    salvo abuso/revisión) y el WhatsApp de Tukán; sus páginas públicas y portales dicen "temporalmente no disponible".
 *  - Borrar (papelera): primero se guarda una copia completa del negocio (todas sus filas de todas las tablas que tienen
 *    negocio_id o que llegan a él por una tabla madre, sacadas del esquema de la base, y la lista de archivos subidos) en un
 *    JSON comprimido fuera de la carpeta pública (respaldos/borrados/, solo lo lee el dueño del servidor). Después el negocio
 *    se marca "eliminado": no entra, no sale en listas ni cifras y sus páginas dan 404. Se puede restaurar 30 días.
 *  - Purga (bin/tareas.php, cada hora): a los 30 días se borra de verdad (DELETE en cascada) y los archivos subidos pasan a
 *    la carpeta de la copia. Las copias se borran al año.
 */
final class Papelera
{
    public const TIPOS_PAUSA = ['falta_pago' => 'Falta de pago', 'pedido_cliente' => 'A pedido del cliente', 'revision' => 'En revisión',
                                'abuso' => 'Uso indebido', 'otro' => 'Otro motivo'];
    /** Tipos de pausa cuyo motivo NO se le muestra al cliente (ve un texto genérico). */
    public const TIPOS_RESERVADOS = ['revision', 'abuso'];
    public const DIAS_PAPELERA = 30;
    public const DIAS_COPIA = 365;
    /** Páginas públicas que no son de ningún negocio (no se revisan). */
    public const PUBLICAS_SIN_NEGOCIO = ['login', 'registro', 'privacidad', 'admin_entrar', 'telegram'];
    /** Nombre de archivo subido (Fotos y Adjuntos): 32 hex + extensión. */
    private const ARCHIVO = '/^[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|xml)$/';

    private ?array $tablas = null;

    public function __construct(private PDO $db) {}

    /** Carpeta de las copias (TURENTA_BORRADOS o <app>/respaldos/borrados), fuera de public/. */
    public static function carpeta(): string
    {
        return rtrim((string) (getenv('TURENTA_BORRADOS') ?: dirname(__DIR__) . '/respaldos/borrados'), '/');
    }

    /** Texto que ve el cliente de una cuenta pausada. */
    public static function textoPausa(?string $tipo, ?string $motivo): string
    {
        $motivo = trim((string) $motivo);
        if ($tipo === null || $tipo === '' || in_array($tipo, self::TIPOS_RESERVADOS, true) || $motivo === '') {
            return 'Tukán pausó tu cuenta temporalmente. Escríbenos por WhatsApp y te ayudamos a reactivarla.';
        }
        return $motivo;
    }

    // ------------------------------------------------------------------
    // Tablas del negocio, sacadas del esquema (no de una lista escrita a mano)
    // ------------------------------------------------------------------

    /**
     * Todas las tablas que son de un negocio: [tabla => ['condicion' => SQL con %1$d = id del negocio, 'propia' => bool]],
     * en orden de madre a hija. "propia": se borra con el negocio (las que solo apuntan con ON DELETE SET NULL, como
     * registro_admin, se copian pero quedan, sin el negocio).
     */
    public function tablas(): array
    {
        if ($this->tablas !== null) return $this->tablas;
        $base = $this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")
                         ->fetchAll(PDO::FETCH_COLUMN);
        $conNegocio = $this->db->query("SELECT c.table_name FROM information_schema.columns c
                                          JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
                                         WHERE c.table_schema = current_schema() AND c.column_name = 'negocio_id'
                                           AND c.data_type IN ('integer', 'bigint', 'smallint')")->fetchAll(PDO::FETCH_COLUMN);
        $fks = [];
        $sql = "SELECT cl.relname AS hija, ref.relname AS madre, c.confdeltype AS al_borrar,
                       array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num, n)
                                           JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.num ORDER BY k.n)) AS cols,
                       array_to_json(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(num, n)
                                           JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.num ORDER BY k.n)) AS refs
                  FROM pg_constraint c
                  JOIN pg_class cl ON cl.oid = c.conrelid JOIN pg_namespace ns ON ns.oid = cl.relnamespace
                  JOIN pg_class ref ON ref.oid = c.confrelid
                 WHERE c.contype = 'f' AND ns.nspname = current_schema()
                 ORDER BY 1, 2";
        foreach ($this->db->query($sql)->fetchAll() as $f) {
            $f['cols'] = json_decode((string) $f['cols'], true);
            $f['refs'] = json_decode((string) $f['refs'], true);
            $fks[] = $f;
        }
        // 1. Cuáles son del negocio: negocios, las que tienen negocio_id y las hijas de esas (hasta que no aparezcan más)
        $del = ['negocios' => true];
        foreach ($conNegocio as $t) if (in_array($t, $base, true)) $del[$t] = true;
        do {
            $mas = false;
            foreach ($fks as $f) {
                if (isset($del[$f['madre']]) && !isset($del[$f['hija']])) { $del[$f['hija']] = true; $mas = true; }
            }
        } while ($mas);
        // 2. Condición de cada una
        $cond = ['negocios' => 'id = %1$d'];
        foreach (array_keys($del) as $t) if ($t !== 'negocios' && in_array($t, $conNegocio, true)) $cond[$t] = 'negocio_id = %1$d';
        $armar = function (string $t, array $camino) use (&$armar, &$cond, $fks, $del): ?string {
            if (isset($cond[$t])) return $cond[$t];
            $partes = [];
            foreach ($fks as $f) {
                if ($f['hija'] !== $t || $f['madre'] === $t || !isset($del[$f['madre']]) || in_array($f['madre'], $camino, true)) continue;
                $madre = $armar($f['madre'], array_merge($camino, [$t]));
                if ($madre === null) continue;
                $cols = implode(', ', array_map(fn($c) => self::id($c), $f['cols']));
                $refs = implode(', ', array_map(fn($c) => self::id($c), $f['refs']));
                $partes[] = "($cols) IN (SELECT $refs FROM " . self::id($f['madre']) . " WHERE $madre)";
            }
            return $partes ? ($cond[$t] = '(' . implode(' OR ', $partes) . ')') : null;
        };
        foreach (array_keys($del) as $t) $armar($t, []);
        // 3. Propias (se borran con el negocio): negocio_id sin "SET NULL", o una madre propia sin "SET NULL"
        $borra = fn(string $d) => in_array($d, ['a', 'r', 'c'], true);   // NO ACTION, RESTRICT, CASCADE
        $propia = ['negocios' => true];
        foreach (array_keys($cond) as $t) {
            if ($t === 'negocios' || !in_array($t, $conNegocio, true)) continue;
            $fkNeg = array_values(array_filter($fks, fn($f) => $f['hija'] === $t && $f['madre'] === 'negocios' && $f['cols'] === ['negocio_id']));
            if (!$fkNeg || $borra($fkNeg[0]['al_borrar'])) $propia[$t] = true;
        }
        do {
            $mas = false;
            foreach ($fks as $f) {
                if (isset($propia[$f['madre']]) && isset($cond[$f['hija']]) && !isset($propia[$f['hija']]) && $f['hija'] !== $f['madre'] && $borra($f['al_borrar'])) {
                    $propia[$f['hija']] = true; $mas = true;
                }
            }
        } while ($mas);
        // 4. Orden: de madre a hija (para copiar); la purga lo recorre al revés
        $orden = [];
        $visto = [];
        $visitar = function (string $t) use (&$visitar, &$orden, &$visto, $fks, $cond): void {
            if (isset($visto[$t])) return;
            $visto[$t] = true;
            foreach ($fks as $f) {
                if ($f['madre'] === $t && $f['hija'] !== $t && isset($cond[$f['hija']])) $visitar($f['hija']);
            }
            $orden[] = $t;   // las hijas quedan antes que la madre
        };
        $visitar('negocios');
        foreach (array_keys($cond) as $t) $visitar($t);
        $r = [];
        foreach (array_reverse($orden) as $t) {
            if (isset($cond[$t])) $r[$t] = ['condicion' => $cond[$t], 'propia' => isset($propia[$t])];
        }
        return $this->tablas = $r;
    }

    private static function id(string $nombre): string
    {
        return '"' . str_replace('"', '""', $nombre) . '"';
    }

    /** Todas las filas del negocio, tabla por tabla (también las vacías). */
    public function datos(int $id): array
    {
        $r = [];
        foreach ($this->tablas() as $t => $x) {
            $st = $this->db->query('SELECT * FROM ' . self::id($t) . ' WHERE ' . sprintf($x['condicion'], $id) . ' ORDER BY 1');
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($filas as &$f) {
                foreach ($f as $k => $v) if (is_resource($v)) $f[$k] = ['base64' => base64_encode((string) stream_get_contents($v))];
            }
            unset($f);
            $r[$t] = $filas;
        }
        return $r;
    }

    /** Archivos subidos que nombran las filas del negocio: [['carpeta', 'nombre', 'ruta', 'bytes'], …] (solo los que existen). */
    public function archivos(array $datos): array
    {
        $base = Fotos::base();
        $carpetas = is_dir($base) ? array_values(array_filter(scandir($base) ?: [], fn($c) => $c[0] !== '.' && is_dir("$base/$c"))) : [];
        $r = [];
        foreach ($datos as $filas) {
            foreach ($filas as $f) {
                foreach ($f as $v) {
                    if (!is_string($v) || !preg_match(self::ARCHIVO, $v)) continue;
                    foreach ($carpetas as $c) {
                        $ruta = "$base/$c/$v";
                        if (is_file($ruta) && !isset($r[$ruta])) $r[$ruta] = ['carpeta' => $c, 'nombre' => $v, 'ruta' => $ruta, 'bytes' => (int) filesize($ruta)];
                    }
                }
            }
        }
        return array_values($r);
    }

    /** Guarda la copia completa del negocio (JSON comprimido, permisos 600 en una carpeta 700). Devuelve la ruta y lo que guardó. */
    public function exportar(int $id, array $info = [], ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable('now');
        $datos = $this->datos($id);
        if (!$datos['negocios']) throw new RuntimeException('Ese negocio no existe.');
        $archivos = $this->archivos($datos);
        $copia = [
            'producto' => TukanCentral::PRODUCTO, 'formato' => 1, 'version' => TukanCentral::version(dirname(__DIR__)),
            'negocio_id' => $id, 'nombre' => $datos['negocios'][0]['nombre'] ?? null,
            'exportado_en' => $ahora->format('c'), 'borrar_definitivo_el' => $ahora->modify('+' . self::DIAS_PAPELERA . ' days')->format('Y-m-d'),
            'info' => $info,
            'tablas' => array_map(fn($filas) => $filas, $datos),
            'cuantas' => array_map('count', $datos),
            'archivos' => $archivos,
        ];
        $json = json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new RuntimeException('No se pudo armar la copia del negocio.');
        $dir = self::carpeta();
        $viejo = umask(077);
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de copias (' . $dir . ').');
            @chmod($dir, 0700);
            $ruta = $dir . '/' . $id . '_' . $ahora->format('Ymd-His') . '_' . bin2hex(random_bytes(3)) . '.json.gz';
            $gz = gzencode($json, 9);
            if ($gz === false || @file_put_contents($ruta . '.tmp', $gz) !== strlen($gz)) {
                @unlink($ruta . '.tmp');
                throw new RuntimeException('No se pudo guardar la copia del negocio en ' . $dir . '.');
            }
            @chmod($ruta . '.tmp', 0600);
            if (!@rename($ruta . '.tmp', $ruta)) throw new RuntimeException('No se pudo guardar la copia del negocio.');
        } finally {
            umask($viejo);
        }
        return ['ruta' => $ruta, 'tablas' => $copia['cuantas'], 'archivos' => count($archivos), 'bytes' => strlen($gz)];
    }

    /** Lee una copia (para revisarla o para las pruebas). */
    public static function leerCopia(string $ruta): array
    {
        $gz = @file_get_contents($ruta);
        $json = $gz === false ? false : gzdecode($gz);
        $r = $json === false ? null : json_decode($json, true);
        if (!is_array($r)) throw new RuntimeException('La copia no se puede leer.');
        return $r;
    }

    // ------------------------------------------------------------------
    // Pausar, borrar, restaurar
    // ------------------------------------------------------------------

    /** Pausa la cuenta (estado suspendido + tipo y motivo). Devuelve false si ya estaba pausada igual. */
    public function pausar(int $id, string $tipo, string $motivo): bool
    {
        if (!isset(self::TIPOS_PAUSA[$tipo])) throw new RuntimeException('Elige el tipo de pausa.');
        $st = $this->db->prepare("UPDATE negocios SET estado = 'suspendido', pausa_tipo = ?, pausa_motivo = ?, pausa_en = now()
                                   WHERE id = ? AND eliminado_en IS NULL
                                     AND (estado <> 'suspendido' OR pausa_tipo IS DISTINCT FROM ? OR pausa_motivo IS DISTINCT FROM ?)");
        $st->execute([$tipo, $motivo, $id, $tipo, $motivo]);
        return $st->rowCount() === 1;
    }

    /** Lo manda a la papelera: copia completa y después la marca. Devuelve la copia y la fecha del borrado definitivo. */
    public function borrar(int $id, string $motivo, string $por, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable('now');
        $copia = $this->exportar($id, ['motivo' => $motivo, 'por' => $por], $ahora);
        $st = $this->db->prepare('UPDATE negocios SET eliminado_en = ?, eliminado_motivo = ?, eliminado_por = ?, eliminado_copia = ?
                                   WHERE id = ? AND eliminado_en IS NULL');
        $st->execute([$ahora->format('Y-m-d H:i:s'), mb_substr($motivo, 0, 300), mb_substr($por, 0, 200), $copia['ruta'], $id]);
        if ($st->rowCount() !== 1) {
            @unlink($copia['ruta']);
            throw new RuntimeException('Ese negocio ya está en la papelera.');
        }
        return $copia + ['borrar_definitivo_el' => self::fechaPurga($ahora->format('Y-m-d H:i:s'))];
    }

    public function restaurar(int $id): bool
    {
        $st = $this->db->prepare('UPDATE negocios SET eliminado_en = NULL, eliminado_motivo = NULL, eliminado_por = NULL, eliminado_copia = NULL
                                   WHERE id = ? AND eliminado_en IS NOT NULL');
        $st->execute([$id]);
        return $st->rowCount() === 1;
    }

    public static function fechaPurga(?string $eliminadoEn): ?string
    {
        return $eliminadoEn ? (new DateTimeImmutable(substr($eliminadoEn, 0, 10)))->modify('+' . self::DIAS_PAPELERA . ' days')->format('Y-m-d') : null;
    }

    /** Negocios en la papelera (más recientes primero). */
    public function lista(): array
    {
        return $this->db->query('SELECT id, nombre, slug, eliminado_en, eliminado_motivo, eliminado_por, eliminado_copia FROM negocios
                                  WHERE eliminado_en IS NOT NULL ORDER BY eliminado_en DESC, id DESC')->fetchAll();
    }

    /**
     * Tarea automática: borra de verdad los negocios con más de 30 días en la papelera (sus archivos subidos pasan a la
     * carpeta de su copia) y las copias de más de 1 año. Devuelve cuántos negocios y copias borró.
     */
    public function purgar(DateTimeImmutable $ahora): array
    {
        $limite = $ahora->modify('-' . self::DIAS_PAPELERA . ' days')->format('Y-m-d H:i:s');
        $st = $this->db->prepare('SELECT id, nombre, eliminado_copia FROM negocios WHERE eliminado_en IS NOT NULL AND eliminado_en <= ? ORDER BY id');
        $st->execute([$limite]);
        $r = ['negocios' => 0, 'copias' => 0, 'archivos' => 0];
        foreach ($st->fetchAll() as $n) {
            $id = (int) $n['id'];
            $archivos = $this->archivos($this->datos($id));
            $this->db->beginTransaction();
            try {
                $pagos = $this->db->query("SELECT id FROM suscripciones WHERE negocio_id = $id")->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_reverse($this->tablas(), true) as $t => $x) {   // de hija a madre (respeta los RESTRICT)
                    if ($x['propia']) $this->db->exec('DELETE FROM ' . self::id($t) . ' WHERE ' . sprintf($x['condicion'], $id));
                }
                // Lo que el conector guardó con el id del cliente (texto, sin clave foránea)
                $this->db->prepare("DELETE FROM tukan_central_atenciones WHERE solicitud_id ~ ? OR solicitud_id = ANY(?::text[])")
                         ->execute(['^(registro_nuevo|seguimiento_prueba|renovacion):' . $id . '(:|$)',
                                    '{' . implode(',', array_map(fn($p) => '"dominio_gratis:' . (int) $p . '"', $pagos)) . '}']);
                $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE cliente_id = ?')->execute([(string) $id]);
                $this->db->prepare('INSERT INTO registro_admin (admin_id, negocio_id, accion) VALUES (NULL, NULL, ?)')
                         ->execute([mb_substr("Borrado definitivo (30 días en la papelera): {$n['nombre']} (N.º $id). Copia: " . ($n['eliminado_copia'] ?: '—'), 0, 300)]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $r['negocios']++;
            // Los archivos subidos se guardan junto a la copia (se borran con ella al año)
            if ($archivos) {
                $destino = preg_replace('/\.json\.gz$/', '', (string) ($n['eliminado_copia'] ?: self::carpeta() . "/{$id}_" . $ahora->format('Ymd-His'))) . '.archivos';
                $viejo = umask(077);
                if (!is_dir($destino)) @mkdir($destino, 0700, true);
                umask($viejo);
                foreach ($archivos as $a) {
                    if (!is_dir("$destino/{$a['carpeta']}")) @mkdir("$destino/{$a['carpeta']}", 0700, true);
                    if (@rename($a['ruta'], "$destino/{$a['carpeta']}/{$a['nombre']}") || @unlink($a['ruta'])) $r['archivos']++;
                }
            }
        }
        // Copias de más de 1 año
        $dir = self::carpeta();
        $viejas = $ahora->modify('-' . self::DIAS_COPIA . ' days')->getTimestamp();
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
            if (!preg_match('/^\d+_\d{8}-\d{6}_[a-f0-9]{6}\.json\.gz$/', $f)) continue;
            $ruta = "$dir/$f";
            if ((int) filemtime($ruta) >= $viejas) continue;
            @unlink($ruta);
            self::borrarCarpeta(preg_replace('/\.json\.gz$/', '', $ruta) . '.archivos');
            $r['copias']++;
        }
        return $r;
    }

    private static function borrarCarpeta(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            is_dir("$dir/$f") && !is_link("$dir/$f") ? self::borrarCarpeta("$dir/$f") : @unlink("$dir/$f");
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Páginas públicas y portales: ¿de qué negocio son y cómo está?
    // ------------------------------------------------------------------

    /** Cómo se ve el negocio por fuera: "normal", "pausado" (suspendido) o "eliminado" (en la papelera). */
    public function estadoPublico(int $id): string
    {
        $st = $this->db->prepare('SELECT estado, eliminado_en FROM negocios WHERE id = ?');
        $st->execute([$id]);
        $n = $st->fetch();
        if (!$n || $n['eliminado_en'] !== null) return 'eliminado';
        return $n['estado'] === 'suspendido' ? 'pausado' : 'normal';
    }

    /**
     * Negocio al que pertenece una página pública (por su enlace: slug, token o código), o null si la página no es de un
     * negocio o el enlace no existe (la página misma contesta). Cada página pública debe estar aquí o en PUBLICAS_SIN_NEGOCIO.
     */
    public const RUTAS_PUBLICAS = [
        'sitio' => ['s', 'SELECT id FROM negocios WHERE slug = ?'],
        'consulta' => ['s', 'SELECT id FROM negocios WHERE slug = ?'],
        'disponible' => ['s', 'SELECT id FROM negocios WHERE slug = ?'],
        'imagen_publica' => ['s|id', "SELECT id FROM negocios WHERE slug = ?|SELECT negocio_id FROM espacios WHERE id = ?::bigint"],
        'cot' => ['t', 'SELECT negocio_id FROM cotizaciones WHERE token = ?'],
        'firmar' => ['t', 'SELECT negocio_id FROM contratos WHERE token = ?'],
        'firmar_anexo' => ['t', 'SELECT negocio_id FROM contrato_anexos WHERE token = ?'],
        'verificar' => ['c', 'SELECT negocio_id FROM contratos WHERE codigo_verificacion = ? UNION ALL SELECT negocio_id FROM contrato_anexos WHERE codigo_verificacion = ?'],
        'verificar_oc' => ['c', 'SELECT negocio_id FROM compras WHERE codigo_verificacion = ?'],
        'portal' => ['t', 'SELECT negocio_id FROM eventos WHERE token_cliente = ?'],
        'portal_pagar' => ['t', 'SELECT negocio_id FROM eventos WHERE token_cliente = ?'],
        'portal_invitados' => ['t', 'SELECT negocio_id FROM eventos WHERE token_cliente = ?'],
        'portal_cuestionario' => ['t', 'SELECT negocio_id FROM eventos WHERE token_cliente = ?'],
        'portal_extras' => ['t', 'SELECT negocio_id FROM eventos WHERE token_cliente = ?'],
        'encuesta' => ['t', 'SELECT negocio_id FROM eventos WHERE token_encuesta = ?'],
        'puerta' => ['t', 'SELECT negocio_id FROM eventos WHERE token_puerta = ?'],
        'vivo' => ['t', 'SELECT negocio_id FROM eventos WHERE token_vivo = ?'],
        'invitacion' => ['t', 'SELECT e.negocio_id FROM invitados i JOIN eventos e ON e.id = i.evento_id WHERE i.token = ?'],
        'ingreso' => ['t', 'SELECT e.negocio_id FROM invitados i JOIN eventos e ON e.id = i.evento_id WHERE i.token = ?'],
        'staff' => ['t', 'SELECT negocio_id FROM personal WHERE token = ?'],
        'ical' => ['t', 'SELECT id FROM negocios WHERE token_ical = ?'],
        'portal_proveedor' => ['t', 'SELECT negocio_id FROM proveedores WHERE token = ?'],
        'payphone' => ['clientTransactionId', 'SELECT negocio_id FROM pagos WHERE id = ?::bigint'],
        'reservar' => ['s', 'SELECT id FROM negocios WHERE slug = ?'],
        'stripe' => ['session_id', "SELECT negocio_id FROM pagos WHERE metodo = 'stripe' AND referencia = ?"],
        'stripe_webhook' => ['s', 'SELECT id FROM negocios WHERE slug = ?'],
    ];

    public function negocioDeRuta(string $ruta, array $get): ?int
    {
        if (!isset(self::RUTAS_PUBLICAS[$ruta])) return null;
        [$params, $sqls] = self::RUTAS_PUBLICAS[$ruta];
        $params = explode('|', $params);
        $sqls = explode('|', $sqls);
        foreach ($params as $i => $param) {
            $v = $get[$param] ?? null;
            if (!is_string($v) || $v === '' || strlen($v) > 200) continue;
            if ($param === 'c') $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
            if ($param === 'id' && !preg_match('/^\d{1,15}$/', $v)) continue;
            if ($param === 'clientTransactionId') {
                if (!preg_match('/^TE(\d{1,15})-/', $v, $m)) continue;
                $v = $m[1];
            }
            if ($ruta === 'imagen_publica' && (($get['tipo'] ?? '') === 'logo') !== ($param === 's')) continue;
            $sql = $sqls[$i];
            $st = $this->db->prepare($sql);
            $st->execute(array_fill(0, substr_count($sql, '?'), $v));
            $id = $st->fetchColumn();
            if ($id !== false && $id !== null) return (int) $id;
        }
        return null;
    }
}
