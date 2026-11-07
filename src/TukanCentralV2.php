<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conector con el Panel Tukán Central, protocolo v2 (PROTOCOLO_V2.md del Central; mapeo de TuRenta en §10.2).
 * Todas las acciones v2 son POST a /tukan_central.php?accion=<accion> con el token en la cabecera X-Tukan-Token
 * (mismo secreto y misma firma que v1, con "v":2 y "bh" = hash del cuerpo) y un cuerpo JSON.
 *
 * Lectura: capacidades, clientes, cliente, cotizar, planes, modulos, solicitudes, solicitud, finanzas.
 * Escritura: ejecutar (crear_demo, extender_prueba, registrar_pago, anular_pago, suspender, reactivar, cancelar,
 *            cambiar_plan, nueva_clave_dueno, nota_interna, atender_solicitud, guardar_plan,
 *            del Anexo A: pausar, borrar_cliente, restaurar_cliente — ver Papelera;
 *            del Anexo B: cambiar_modulos, guardar_modulos — ver Modulos).
 * Las reglas del negocio son las de TuRenta (Planes, PanelTukan, Altas); aquí solo se validan los datos,
 * se arma la respuesta y se garantiza que una operación repetida no se haga dos veces (tukan_central_operaciones).
 * Solicitudes: pedidos de demo (no hay registro abierto con periodo de prueba), pedidos de módulos desde "Mi plan" y los recordatorios
 * que se arman al momento (demos por terminar, renovaciones, dominios incluidos). TuRenta no tiene comprobantes ni soporte.
 */
final class TukanCentralV2
{
    public const PROTOCOLO = 2;
    public const REVISION = 3;   // 2: Anexo A (pausar, papelera) · 3: Anexo B (módulos) y pedidos de demo
    public const MAX_CUERPO = 65536;
    public const VIDA_LECTURA = 60;
    public const VIDA_EJECUTAR = 30;
    /** Acciones que existen en el protocolo v2 (el resto de nombres es "Acción no válida", 400). */
    public const ACCIONES_PROTOCOLO = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'modulos', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'ia_uso'];
    /** Las que TuRenta contesta (sin "adjunto": no tiene comprobantes ni tickets con archivos). */
    public const ACCIONES = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'modulos', 'solicitudes', 'solicitud', 'finanzas', 'ejecutar', 'ia_uso'];
    public const OPERACIONES = ['crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'suspender', 'reactivar', 'cancelar',
                                'cambiar_plan', 'nueva_clave_dueno', 'nota_interna', 'atender_solicitud', 'guardar_plan',
                                'pausar', 'borrar_cliente', 'restaurar_cliente', 'cambiar_modulos', 'guardar_modulos', 'ia_tope'];
    /** Operaciones que solo puede hacer el dueño del Panel Tukán Central (ia_tope: Anexo C.2, el tope de gasto de IA del negocio). */
    public const SOLO_DUENO = ['borrar_cliente', 'cambiar_modulos', 'guardar_modulos', 'ia_tope'];
    /** Operaciones que se pueden pedir sobre un negocio que está en la papelera. */
    public const OPERACIONES_PAPELERA = ['restaurar_cliente', 'borrar_cliente'];
    public const TIPOS_SOLICITUD = ['demo_pedida', 'cambio_modulos', 'seguimiento_prueba', 'renovacion', 'dominio_gratis'];
    public const ESTADOS = ['prueba', 'activo', 'por_vencer', 'vencido', 'suspendido'];
    public const ESTADOS_DETALLE = ['prueba' => 'En demo', 'activo' => 'Al día', 'sin_limite' => 'Sin vencimiento', 'gracia' => 'Venció (en gracia)',
                                    'vencido' => 'Vencido', 'prueba_vencida' => 'Demo terminada', 'suspendido' => 'Suspendido', 'cancelado' => 'Cancelado'];
    public const ORDENES = ['vence', 'alta', 'nombre', 'actividad'];
    public const RESULTADOS = ['en_proceso', 'atendida', 'descartada'];
    public const DIAS_POR_VENCER = 7;      // §4.2: pagado y vence en 0 a 7 días
    public const DIAS_PRUEBA_TERMINADA = 60;
    public const DIAS_RENOVACION = 5;
    public const DIAS_VENCIDA = 30;
    public const ID = '/^[A-Za-z0-9_.:-]{1,80}$/';

    private Planes $planes;
    private PanelTukan $panel;
    private TukanCentral $v1;
    private DateTimeImmutable $hoy;
    private ?array $pagosCache = null;
    private ?array $solicitudesCache = null;
    private Modulos $modulos;
    private ?array $modulosCache = null;

    public function __construct(private PDO $db, ?DateTimeImmutable $hoy = null)
    {
        $this->hoy = ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $this->planes = new Planes($db);
        $this->panel = new PanelTukan($db);
        $this->v1 = new TukanCentral($db);
        $this->modulos = new Modulos($db);
    }

    // ------------------------------------------------------------------
    // Transporte: validación del pedido (§3.1) y respuesta
    // ------------------------------------------------------------------

    /**
     * Atiende un pedido v2 completo (lo usa public/tukan_central.php y las pruebas).
     * Devuelve [http, json (array), cabeceras extra].
     */
    public function procesar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, string $ip, ?int $ahora = null): array
    {
        try {
            if (!TukanCentral::config()) {
                throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
            }
            if (!$this->v1->dentroDelLimite($ip, $ahora)) {
                throw new TukanCentralError('limite', 'Demasiados pedidos. Espera un minuto y vuelve a intentar.', 429);
            }
            if (!in_array($accion, self::ACCIONES_PROTOCOLO, true)) throw new TukanCentralError('invalido', 'Acción no válida.', 400, 'accion');
            [$p, $datos] = $this->validar($accion, $metodo, $tipoContenido, $cuerpo, $token, $ahora);
            [$http, $json] = $this->atender($accion, $p, $datos, self::hashCuerpo($cuerpo));
            return [$http, $json, []];
        } catch (TukanCentralError $e) {
            if ($e->http === 401) {
                try { $this->v1->anotarRechazo($ip, $ahora); } catch (Throwable) { /* el límite no debe tapar el error */ }
            }
            $cab = match (true) {
                $e->codigo === 'limite' => ['Retry-After' => '60'],
                $e->http === 405 => ['Allow' => 'POST'],
                default => [],
            };
            return [$e->http, $e->json(), $cab];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('TuRenta central v2: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return [500, (new TukanCentralError('interno', 'TuRenta tuvo un error interno. Intenta en un momento.', 500))->json(), []];
        }
    }

    public static function hashCuerpo(string $cuerpo): string
    {
        return TukanCentral::b64(hash('sha256', $cuerpo, true));
    }

    /** Revisa el pedido en el orden del protocolo (§3.1, pasos 3 a 15). Devuelve [payload del token, datos del cuerpo]. */
    public function validar(string $accion, string $metodo, string $tipoContenido, string $cuerpo, string $token, ?int $ahora = null): array
    {
        $conf = TukanCentral::config();
        if (!$conf) throw new TukanCentralError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404);
        $ahora ??= time();
        // 3. Método, tipo de contenido y tamaño
        if (strtoupper($metodo) !== 'POST') throw new TukanCentralError('invalido', 'Esta acción se pide con POST.', 405);
        if (!preg_match('#^application/json\s*(;|$)#i', trim($tipoContenido))) {
            throw new TukanCentralError('invalido', 'El contenido debe ser JSON (Content-Type: application/json).', 415);
        }
        if (strlen($cuerpo) > self::MAX_CUERPO) throw new TukanCentralError('invalido', 'El pedido es demasiado grande (máximo 64 KiB).', 413);
        // 4. Firma
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === ''
            || !hash_equals(TukanCentral::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true)), $partes[1])) {
            throw new TukanCentralError('firma', 'La firma del pedido no es correcta.', 401);
        }
        $json = TukanCentral::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new TukanCentralError('firma', 'El pedido no es válido.', 401);
        // 5 a 7. Versión, tipo e instancia
        if (($p['v'] ?? null) !== 2) throw new TukanCentralError('version', 'Versión de protocolo no soportada.', 401);
        if (($p['tipo'] ?? null) !== $accion) throw new TukanCentralError('tipo', 'El pedido no es para esta acción.', 401);
        if (!is_string($p['inst'] ?? null) || !hash_equals($conf['id'], $p['inst'])) throw new TukanCentralError('inst', 'El pedido es de otra instalación.', 401);
        // 8 a 10. Tiempo
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        $max = $accion === 'ejecutar' ? self::VIDA_EJECUTAR : self::VIDA_LECTURA;
        if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) {
            throw new TukanCentralError('invalido', 'El pedido dura más de lo permitido.', 401);
        }
        if ($ahora > $exp + TukanCentral::TOLERANCIA) throw new TukanCentralError('vencido', 'El pedido venció. Intente otra vez desde el Panel Tukán.', 401);
        if ($iat > $ahora + TukanCentral::TOLERANCIA) throw new TukanCentralError('futuro', 'El pedido tiene fecha futura (revise la hora del servidor).', 401);
        // 11. Hash del cuerpo
        if (!is_string($p['bh'] ?? null) || !hash_equals(self::hashCuerpo($cuerpo), $p['bh'])) {
            throw new TukanCentralError('firma', 'El contenido del pedido cambió en el camino.', 401, null, ['parte' => 'cuerpo']);
        }
        // 12. Nonce de un solo uso (después de firma, tiempo y cuerpo: un token roto no gasta su nonce)
        if (!$this->v1->usarNonce($p['nonce'] ?? null, $exp, $ahora)) {
            throw new TukanCentralError('nonce', 'El pedido se repitió (nonce ya usado o no válido).', 401);
        }
        // 13. Correo
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralError('invalido', 'Correo inválido en el pedido.', 401);
        }
        $p['email'] = strtolower(trim($email));
        $p['nombre'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_string($p['nombre'] ?? null) ? $p['nombre'] : '')), 0, 100);
        // 14. Cuerpo: objeto JSON
        $datos = json_decode($cuerpo, true, 8);
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw new TukanCentralError('invalido', 'El contenido no es un objeto JSON.', 400);
        // 15. Acción disponible en TuRenta
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new TukanCentralError('no_permitido', 'TuRenta no tiene esta acción.', 403, null, ['motivo' => 'no_disponible']);
        }
        return [$p, $datos];
    }

    private function atender(string $accion, array $p, array $d, string $bh): array
    {
        if ($accion === 'ejecutar') return $this->ejecutar($p, $d, $bh);
        $r = match ($accion) {
            'capacidades' => $this->capacidades(),
            'clientes' => $this->clientes($d),
            'cliente' => ['cliente' => $this->clienteFicha($this->idCliente($d['id'] ?? null, 'id'))],
            'cotizar' => $this->cotizar($d),
            'planes' => $this->planesV2(),
            'modulos' => $this->modulosV2(),
            'solicitudes' => $this->solicitudes($d),
            'solicitud' => ['solicitud' => $this->solicitud($d)],
            'finanzas' => $this->finanzas($d),
            'ia_uso' => $this->iaUso($d),
        };
        return [200, $this->sobre($accion) + $r];
    }

    private function sobre(string $accion): array
    {
        return ['ok' => true, 'protocolo' => self::PROTOCOLO, 'accion' => $accion, 'producto' => TukanCentral::PRODUCTO,
                'generado' => (new DateTimeImmutable('now'))->format('c')];
    }

    // ------------------------------------------------------------------
    // capacidades (§5.1)
    // ------------------------------------------------------------------

    public function capacidades(): array
    {
        $periodos = [];
        foreach ($this->planes->periodos() as $per => $x) $periodos[] = ['periodo' => $per, 'meses' => $x['recibe']];
        $metodos = [];
        foreach (PanelTukan::METODOS as $m => $nombre) $metodos[] = ['metodo' => $m, 'nombre' => $nombre];
        return [
            'revision' => self::REVISION,
            'nombre' => TukanCentral::NOMBRE,
            'version' => TukanCentral::version(dirname(__DIR__)),
            'unico' => false,
            'moneda' => 'USD',
            'zona_horaria' => 'America/Guayaquil',
            'url_publica' => self::urlBase(),
            'acciones' => array_merge(['capacidades', 'estado', 'sso'], array_values(array_diff(self::ACCIONES, ['capacidades']))),
            'sso_ir' => true,
            'rutas_sso' => TukanCentral::RUTAS_SSO,
            'ejecutar' => self::OPERACIONES,
            'tipos_solicitud' => self::TIPOS_SOLICITUD,
            'estados_detalle' => self::ESTADOS_DETALLE,
            'periodos' => $periodos,
            'meses_libres' => false,
            'metodos_pago' => $metodos,
            'planes_editar' => true,
            'filtros_clientes' => ['buscar', 'estados', 'plan', 'vence_desde', 'vence_hasta', 'alta_desde', 'alta_hasta', 'sin_uso_dias', 'origen', 'ids', 'papelera'],
            'tipos_pausa' => Papelera::TIPOS_PAUSA,
            'papelera' => ['dias' => Papelera::DIAS_PAPELERA, 'copia_dias' => Papelera::DIAS_COPIA],
            'formularios' => [
                'pausar' => self::camposPausa(),
                'borrar_cliente' => self::camposBorrar(null),
                'crear_demo' => [
                    ['campo' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'requerido' => false, 'valor' => 'Quito', 'max' => 80],
                    ['campo' => 'modulos', 'etiqueta' => 'Módulos de la demo', 'tipo' => 'texto', 'requerido' => false, 'max' => 1000,
                     'ayuda' => 'Vacío = todos. Si no, los ids separados por coma (' . implode(', ', array_keys(Modulos::CATALOGO)) . ').'],
                ],
                'guardar_plan' => $this->camposPlan(null),
                'cambiar_modulos' => self::camposModulos(null),
                'ia_tope' => self::camposIaTope(null, null),
            ],
            'limites' => ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => 90],
            'modulos' => true,
            'registro_abierto' => false,   // no hay registro abierto: quien quiere probar pide una demo (demo_pedida)
        ];
    }

    // ------------------------------------------------------------------
    // Clientes (§4.2, §4.3, §5.4, §5.5)
    // ------------------------------------------------------------------

    /** Todos los negocios con su estado de cuenta (Planes::estadoCuenta), por id. */
    private function filas(): array
    {
        $r = [];
        foreach ($this->panel->negocios('', '', $this->hoy) as $n) $r[(int) $n['id']] = $n;
        return $r;
    }

    private function fila(int $id): ?array
    {
        return $this->panel->negocio($id, $this->hoy);
    }

    /** Estado v2 con la regla única de §4.2 (a partir del estado propio de TuRenta). */
    public function clasificar(array $n): array
    {
        $nativo = $n['estado_cuenta'] ?? $this->planes->estadoCuenta($n, $this->hoy);
        $en7 = $this->hoy->modify('+' . self::DIAS_POR_VENCER . ' days')->format('Y-m-d');
        [$estado, $detalle, $gracia] = match ($nativo) {
            'prueba' => ['prueba', 'prueba', false],
            'prueba_vencida' => ['vencido', 'prueba_vencida', false],
            'activo' => empty($n['activo_hasta']) ? ['activo', 'sin_limite', false]
                        : [$n['activo_hasta'] <= $en7 ? 'por_vencer' : 'activo', 'activo', false],
            'por_vencer' => ['vencido', 'gracia', true],   // en TuRenta "por_vencer" es "ya venció, en días de gracia"
            'vencido' => ['vencido', 'vencido', false],
            'suspendido' => ['suspendido', 'suspendido', false],
            default => ['suspendido', 'cancelado', false],
        };
        return ['estado' => $estado, 'estado_detalle' => $detalle, 'en_gracia' => $gracia,
                'bloqueado' => !in_array($nativo, ['prueba', 'activo', 'por_vencer'], true), 'nativo' => $nativo];
    }

    /** Cuántos clientes hay en cada estado v2 (lo usan "clientes" y el "estado" v1, para que den lo mismo). */
    public function contadores(?array $todos = null): array
    {
        $c = ['total' => 0, 'prueba' => 0, 'activo' => 0, 'por_vencer' => 0, 'vencido' => 0, 'suspendido' => 0];
        foreach ($todos ?? $this->filas() as $n) {
            $c['total']++;
            $c[$this->clasificar($n)['estado']]++;
        }
        return $c;
    }

    /** Pagos de suscripción no anulados de cada negocio (total, cantidad, primero, último y lo que vale al mes lo vigente). */
    private function pagosResumen(): array
    {
        if ($this->pagosCache !== null) return $this->pagosCache;
        $hoy = $this->hoy->format('Y-m-d');
        $st = $this->db->prepare('SELECT negocio_id, count(*) AS cantidad, COALESCE(sum(monto), 0) AS total, min(pagado_en) AS primer, max(pagado_en) AS ultimo,
                                         (array_agg(monto ORDER BY pagado_en DESC, id DESC))[1] AS ultimo_monto,
                                         COALESCE(sum(CASE WHEN inicio <= ?::date AND fin >= ?::date THEN monto / meses_recibidos ELSE 0 END), 0) AS mensual
                                    FROM suscripciones WHERE NOT anulada GROUP BY negocio_id');
        $st->execute([$hoy, $hoy]);
        $r = [];
        foreach ($st->fetchAll() as $f) $r[(int) $f['negocio_id']] = $f;
        return $this->pagosCache = $r;
    }

    private function olvidar(): void
    {
        $this->pagosCache = null;
        $this->solicitudesCache = null;
        $this->modulosCache = null;
        $this->modulos = new Modulos($this->db);
    }

    /** Módulos de un negocio con la forma de Anexo B.2 (cliente.modulos). */
    private function modulosDe(int $id): array
    {
        if (isset($this->modulosCache[$id])) return $this->modulosCache[$id];
        $e = $this->modulos->estado($id);
        return $this->modulosCache[$id] = [
            'del_plan' => $e['del_plan'], 'extra' => $e['extra'], 'quitados' => $e['quitados'], 'activos' => $e['activos'], 'pagados' => $e['pagados'],
            'precio_plan' => $e['precio_plan'], 'precio_extra' => $e['precio_extra'], 'descuento' => $e['descuento'],
            'precio_acordado' => $e['precio_acordado'], 'mensual' => $e['mensual'], 'demo' => $e['demo'], 'demo_todos' => $e['demo_todos'],
            // Planes de $20 y $45 (migración 112): módulos a elegir incluidos en el plan y lo que cuesta cada extra
            'modulos_elegir' => $e['modulos_elegir'], 'elegidos' => $e['elegidos'], 'por_elegir' => $e['por_elegir'],
            'precio_por_extra' => $e['precio_por_extra'],
        ];
    }

    /** Cliente con la forma de §4.3. */
    private function cliente(array $n): array
    {
        $c = $this->clasificar($n);
        $pg = $this->pagosResumen()[(int) $n['id']] ?? null;
        $vence = ($n['estado'] === 'prueba' ? $n['prueba_hasta'] : $n['activo_hasta']) ?: null;
        $dias = $vence ? $this->diasHasta($vence) : null;
        $tel = $n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']);
        return [
            'id' => (string) $n['id'],
            'nombre' => (string) $n['nombre'],
            'tipo' => null,
            'tipo_nombre' => null,
            'ciudad' => $n['ciudad'] ?: null,
            'plan' => ['codigo' => (string) $n['plan_codigo'], 'nombre' => (string) $n['plan_nombre']],
            'estado' => $c['estado'],
            'estado_detalle' => $c['estado_detalle'],
            'estado_texto' => $this->textoEstado($c, $n, $vence, $dias),
            'en_gracia' => $c['en_gracia'],
            'bloqueado' => $c['bloqueado'],
            'fechas' => [
                'alta' => substr((string) $n['creado_en'], 0, 10),
                'prueba_hasta' => $n['prueba_hasta'] ?: null,
                'activo_hasta' => $n['activo_hasta'] ?: null,
                'vence' => $vence,
                'dias_para_vencer' => $dias,
            ],
            'dueno' => ['nombre' => $n['dueno'] ?: null, 'telefono' => $tel ?: null, 'whatsapp' => WhatsApp::normalizarTelefono($tel),
                        'email' => $n['dueno_email'] ?: null],
            'ultima_actividad' => self::momento($n['ultima_actividad'] ?? null),
            'pagos' => [
                'total' => round((float) ($pg['total'] ?? 0), 2),
                'cantidad' => (int) ($pg['cantidad'] ?? 0),
                'primer_pago' => $pg['primer'] ?? null,
                'ultimo_pago' => $pg['ultimo'] ?? null,
                'ultimo_monto' => $pg ? round((float) $pg['ultimo_monto'], 2) : null,
            ],
            // Anexo B: lo que paga al mes con sus módulos (plan + extra − quitados, o el precio acordado); 0 si no está pagando
            'mensual_estimado' => (float) ($pg['mensual'] ?? 0) > 0 ? $this->modulosDe((int) $n['id'])['mensual'] : 0.0,
            'modulos' => $this->modulosDe((int) $n['id']),
            'origen' => in_array($n['origen'] ?? '', Altas::ORIGENES, true) ? $n['origen'] : 'desconocido',
            'vendedor' => null,
            'uso' => ['Espacios' => (int) $n['espacios'], 'Eventos (30 días)' => (int) $n['eventos_30'],
                      'Eventos vendidos' => (int) $n['vendidos'], 'Usuarios' => (int) $n['usuarios']],
            'ir' => '/?r=admin_negocio&id=' . (int) $n['id'],
            'ir_trabajar' => null,
            'actualizado' => (new DateTimeImmutable('now'))->format('c'),
            // Anexo A: pausa y papelera
            'pausa' => $n['estado'] === 'suspendido' ? [
                'tipo' => $n['pausa_tipo'] ?? null, 'tipo_nombre' => Papelera::TIPOS_PAUSA[$n['pausa_tipo'] ?? ''] ?? 'Suspendido',
                'motivo' => $n['pausa_motivo'] ?? null, 'desde' => self::momento($n['pausa_en'] ?? null),
                'texto_cliente' => Papelera::textoPausa($n['pausa_tipo'] ?? null, $n['pausa_motivo'] ?? null)] : null,
            'eliminado' => !empty($n['eliminado_en']),
            'papelera' => !empty($n['eliminado_en']) ? [
                'desde' => self::momento($n['eliminado_en']), 'hasta' => Papelera::fechaPurga($n['eliminado_en']),
                'motivo' => $n['eliminado_motivo'] ?? null, 'por' => $n['eliminado_por'] ?? null,
                'se_puede_restaurar' => Papelera::fechaPurga($n['eliminado_en']) > $this->hoy->format('Y-m-d')] : null,
        ];
    }

    private function textoEstado(array $c, array $n, ?string $vence, ?int $dias): string
    {
        return match ($c['estado_detalle']) {
            'prueba' => 'Demo hasta el ' . self::fechaTexto($vence) . ($dias === 0 ? ' (termina hoy)' : ' (faltan ' . self::dias((int) $dias) . ')'),
            'sin_limite' => 'Al día, sin fecha de vencimiento',
            'activo' => 'Al día hasta el ' . self::fechaTexto($vence)
                        . ($c['estado'] === 'por_vencer' ? ($dias === 0 ? ' (vence hoy)' : ' (vence en ' . self::dias((int) $dias) . ')') : ''),
            'gracia' => 'Venció el ' . self::fechaTexto($n['activo_hasta']) . ' (en días de gracia, todavía puede trabajar)',
            'vencido' => 'Venció el ' . self::fechaTexto($n['activo_hasta']),
            'prueba_vencida' => 'La demo terminó el ' . self::fechaTexto($n['prueba_hasta']),
            'suspendido' => !empty($n['pausa_tipo']) ? 'Pausado por Tukán: ' . (Papelera::TIPOS_PAUSA[$n['pausa_tipo']] ?? $n['pausa_tipo']) : 'Suspendido por Tukán',
            default => 'Cancelado',
        } . (!empty($n['eliminado_en']) ? ' · En la papelera (se borra el ' . self::fechaTexto(Papelera::fechaPurga($n['eliminado_en'])) . ')' : '');
    }

    public function clientes(array $d): array
    {
        $pagina = $this->entero($d, 'pagina', 1, 1, 1000000);
        $porPagina = $this->entero($d, 'por_pagina', 50, 1, 200);
        $buscar = $this->texto($d, 'buscar', 80);
        // Papelera (Anexo A): filtros.estado = "papelera" (también "estado" o "papelera" en "estados")
        $filtros = $d['filtros'] ?? [];
        if (!is_array($filtros)) throw TukanCentralError::campo('filtros', 'Los filtros no son válidos.');
        $papelera = ($filtros['estado'] ?? null) === 'papelera' || ($d['estado'] ?? null) === 'papelera'
                    || (is_array($d['estados'] ?? null) && in_array('papelera', $d['estados'], true));
        if (is_array($d['estados'] ?? null)) $d['estados'] = array_values(array_filter($d['estados'], fn($x) => $x !== 'papelera'));
        $estados = $this->lista($d, 'estados', self::ESTADOS);
        $plan = $this->texto($d, 'plan', 40);
        $tipo = $this->texto($d, 'tipo', 40);
        $origen = $this->texto($d, 'origen', 20);
        $venceDesde = $this->fecha($d, 'vence_desde');
        $venceHasta = $this->fecha($d, 'vence_hasta');
        $altaDesde = $this->fecha($d, 'alta_desde');
        $altaHasta = $this->fecha($d, 'alta_hasta');
        $sinUso = ($d['sin_uso_dias'] ?? null) === null ? null : $this->entero($d, 'sin_uso_dias', 0, 0, 3650);
        $ids = $d['ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.');
        $ids = array_map(fn($x) => is_int($x) || (is_string($x) && preg_match(self::ID, $x)) ? (string) $x
            : throw TukanCentralError::campo('ids', 'La lista de clientes no es válida.'), $ids);
        $orden = $this->opcion($d, 'orden', self::ORDENES, 'vence');

        $todos = $this->filas();
        $contadores = $this->contadores($todos);
        $enPapelera = $this->panel->negocios('', '', $this->hoy, '', true);
        $base = $papelera ? $enPapelera : $todos;
        if ($buscar !== '') {
            $base = [];
            foreach ($this->panel->negocios('', $buscar, $this->hoy, '', $papelera) as $n) $base[(int) $n['id']] = $n;
        }
        $limiteUso = $sinUso === null ? null : (new DateTimeImmutable('now'))->modify("-$sinUso days")->format('Y-m-d H:i:s');
        $lista = [];
        foreach ($base as $n) {
            $c = $this->cliente($n);
            if ($estados && !in_array($c['estado'], $estados, true)) continue;
            if ($plan !== '' && $c['plan']['codigo'] !== $plan) continue;
            if ($tipo !== '' && $c['tipo'] !== $tipo) continue;
            if ($origen !== '' && $c['origen'] !== $origen) continue;
            if ($ids && !in_array($c['id'], $ids, true)) continue;
            $v = $c['fechas']['vence'];
            if ($venceDesde !== null && ($v === null || $v < $venceDesde)) continue;
            if ($venceHasta !== null && ($v === null || $v > $venceHasta)) continue;
            if ($altaDesde !== null && $c['fechas']['alta'] < $altaDesde) continue;
            if ($altaHasta !== null && $c['fechas']['alta'] > $altaHasta) continue;
            if ($limiteUso !== null && $n['ultima_actividad'] !== null && (string) $n['ultima_actividad'] >= $limiteUso) continue;
            $c['_act'] = (string) ($n['ultima_actividad'] ?? '');
            $lista[] = $c;
        }
        usort($lista, match ($orden) {
            'alta' => fn($a, $b) => [$b['fechas']['alta'], (int) $b['id']] <=> [$a['fechas']['alta'], (int) $a['id']],
            'nombre' => fn($a, $b) => [mb_strtolower($a['nombre']), (int) $a['id']] <=> [mb_strtolower($b['nombre']), (int) $b['id']],
            'actividad' => fn($a, $b) => [$a['_act'] !== '', $a['_act'], (int) $a['id']] <=> [$b['_act'] !== '', $b['_act'], (int) $b['id']],
            default => fn($a, $b) => [$a['fechas']['vence'] === null, $a['fechas']['vence'], mb_strtolower($a['nombre'])]
                                     <=> [$b['fechas']['vence'] === null, $b['fechas']['vence'], mb_strtolower($b['nombre'])],
        });
        $total = count($lista);
        $pagina_ = array_map(function ($c) { unset($c['_act']); return $c; }, array_slice($lista, ($pagina - 1) * $porPagina, $porPagina));
        return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contadores' => $contadores, 'papelera' => $papelera, 'papelera_total' => count($enPapelera), 'clientes' => $pagina_];
    }

    public function clienteFicha(int $id): array
    {
        $n = $this->fila($id);
        if (!$n) throw new TukanCentralError('no_encontrado', 'Ese negocio ya no existe en TuRenta.', 404);
        $c = $this->cliente($n);
        $st = $this->db->prepare('SELECT dominio FROM suscripciones WHERE negocio_id = ? AND dominio IS NOT NULL AND NOT anulada ORDER BY fin DESC, id DESC LIMIT 1');
        $st->execute([$id]);
        $dominio = (string) ($st->fetchColumn() ?: '');
        $pagos = [];
        foreach ($this->panel->pagos($id, 200) as $s) {
            $anulado = (bool) $s['anulada'];
            $pagos[] = [
                'id' => (string) $s['id'], 'fecha' => (string) $s['pagado_en'], 'monto' => round((float) $s['monto'], 2),
                'plan' => ['codigo' => (string) $s['plan_codigo'], 'nombre' => (string) $s['plan_nombre']],
                'periodo' => (string) $s['periodo'], 'meses' => (int) $s['meses_recibidos'],
                'desde' => (string) $s['inicio'], 'hasta' => (string) $s['fin'],
                'metodo' => $s['metodo_pago'], 'metodo_nombre' => PanelTukan::METODOS[(string) $s['metodo_pago']] ?? $s['metodo_pago'],
                'nota' => null, 'registrado_por' => null, 'origen' => 'panel', 'anulado' => $anulado, 'puede_anular' => !$anulado,
            ];
        }
        $st = $this->db->prepare('SELECT r.creado_en, r.accion, a.nombre, a.email FROM registro_admin r LEFT JOIN superadmins a ON a.id = r.admin_id
                                   WHERE r.negocio_id = ? ORDER BY r.creado_en DESC, r.id DESC LIMIT 20');
        $st->execute([$id]);
        $registro = array_map(fn($r) => [
            'momento' => self::momento($r['creado_en']),
            'quien' => str_starts_with((string) $r['accion'], 'Panel Tukán Central')
                ? 'Panel Tukán Central · ' . ($r['nombre'] ?? '') . ' <' . ($r['email'] ?? '') . '>' : ($r['nombre'] ?? 'Sistema'),
            'texto' => (string) $r['accion'],
        ], $st->fetchAll());
        $abiertas = array_values(array_filter($this->todasLasSolicitudes(),
            fn($s) => $s['cliente_id'] === (string) $id && in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
        return $c + [
            'notas_admin' => (string) ($n['notas_admin'] ?? ''),
            'usuarios' => (int) $n['usuarios'],
            'recomendado_por' => null,
            'combo_con' => null,
            'dominio' => $dominio,
            'enlace_publico' => self::urlSitio((string) $n['slug']),
            'pagos_detalle' => $pagos,
            'solicitudes' => $abiertas,
            'registro' => $registro,
            'mensajes_sugeridos' => $this->mensajes($n),
            'acciones_posibles' => $this->accionesCliente($n, $c),
        ];
    }

    /** Textos de WhatsApp de TuRenta (los mismos del panel de Tukán), o null si no aplica. */
    private function mensajes(array $n): array
    {
        $c = $this->clasificar($n);
        $primer = trim(explode(' ', trim((string) ($n['dueno'] ?: '')))[0]) ?: 'hola';
        $hola = $primer === 'hola' ? 'Hola' : "Hola $primer";
        $nombre = (string) $n['nombre'];
        $precio = self::dinero($this->modulosDe((int) $n['id'])['mensual']);
        $nat = $c['nativo'];
        return [
            'bienvenida' => "$hola, te escribo de TuRenta. La cuenta de $nombre ya está lista: entra en " . self::urlLogin()
                . ' con tu correo' . ($n['dueno_email'] ? ' ' . $n['dueno_email'] : '') . '. ¿Te ayudo a empezar?',
            'recordar_prueba' => $nat === 'prueba' && $n['prueba_hasta']
                ? "$hola, soy de TuRenta. Tu demo de $nombre " . ($this->diasHasta($n['prueba_hasta']) === 0 ? 'termina hoy' : 'termina el ' . self::fechaCorta($n['prueba_hasta']))
                  . '. ¿Te ayudo a elegir tu plan y tus módulos para que sigas usándolo?' : null,
            'recordar_renovacion' => $nat === 'activo' && $n['activo_hasta']
                ? "$hola, soy de TuRenta. El plan de $nombre vence el " . self::fechaCorta($n['activo_hasta'])
                  . " (plan {$n['plan_nombre']} con tus módulos: $precio al mes). ¿Te paso los datos para renovarlo?" : null,
            'vencido' => in_array($nat, ['prueba_vencida', 'por_vencer', 'vencido'], true)
                ? "$hola, soy de TuRenta. Vi que $nombre ya no tiene el plan activo. Tus datos siguen guardados. ¿Te ayudo a renovarlo?" : null,
        ];
    }

    private function puedeExtender(array $c): bool
    {
        return $c['estado'] === 'prueba' || ($c['estado'] === 'vencido' && $c['pagos']['cantidad'] === 0);
    }

    // --- Formularios genéricos (§4.4) ---

    private function accionesCliente(array $n, array $c): array
    {
        $datos = ['cliente_id' => $c['id']];
        $m = $this->mensajes($n);
        $a = [];
        if ($c['eliminado']) {   // en la papelera: solo restaurar (mientras se pueda) y ver
            if ($c['papelera']['se_puede_restaurar']) {
                $a[] = ['accion' => 'restaurar_cliente', 'etiqueta' => 'Restaurar', 'estilo' => 'principal',
                        'confirmar' => '¿Sacar a ' . $c['nombre'] . ' de la papelera? Vuelve tal como estaba.', 'datos' => $datos, 'campos' => []];
            }
            $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuRenta', 'ir' => $c['ir']];
            return $a;
        }
        if ($c['dueno']['whatsapp']) {
            $texto = $m['vencido'] ?? $m['recordar_prueba'] ?? $m['recordar_renovacion'] ?? $m['bienvenida'];
            $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $c['dueno']['whatsapp'], 'texto' => $texto];
        }
        $a[] = $this->formPago($n, $datos);
        if ($this->puedeExtender($c)) $a[] = $this->formExtender($c, $datos);
        $a[] = ['accion' => 'cambiar_plan', 'etiqueta' => 'Cambiar plan', 'estilo' => 'normal', 'confirmar' => '¿Cambiar el plan de ' . $c['nombre'] . ' sin cobrar?',
                'datos' => $datos, 'campos' => [['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $c['plan']['codigo'],
                                                 'opciones' => $this->opcionesPlan()]]];
        $a[] = ['accion' => 'cambiar_modulos', 'etiqueta' => 'Módulos', 'estilo' => 'normal',
                'confirmar' => '¿Cambiar los módulos de ' . $c['nombre'] . '? Cambia lo que puede usar y lo que paga al mes.', 'datos' => $datos,
                'campos' => self::camposModulos($c['modulos'])];
        if (in_array($c['estado_detalle'], ['suspendido', 'cancelado'], true)) {
            $a[] = ['accion' => 'reactivar', 'etiqueta' => 'Reactivar', 'estilo' => 'principal', 'confirmar' => '¿Reactivar ' . $c['nombre'] . '?', 'datos' => $datos, 'campos' => []];
        } else {
            $a[] = ['accion' => 'pausar', 'etiqueta' => 'Pausar', 'estilo' => 'peligro',
                    'confirmar' => '¿Pausar ' . $c['nombre'] . '? No podrá trabajar y sus páginas dirán "temporalmente no disponible" hasta que lo reactives.',
                    'datos' => $datos, 'campos' => self::camposPausa()];
        }
        if ($c['estado_detalle'] !== 'cancelado') {
            $a[] = ['accion' => 'cancelar', 'etiqueta' => 'Cancelar cuenta', 'estilo' => 'peligro', 'confirmar' => '¿Cancelar la cuenta de ' . $c['nombre'] . '?',
                    'datos' => $datos, 'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 200]]];
        }
        if ($n['dueno_usuario_id']) {
            $a[] = ['accion' => 'nueva_clave_dueno', 'etiqueta' => 'Nueva clave del dueño', 'estilo' => 'normal',
                    'confirmar' => '¿Crear una contraseña nueva para el dueño? La anterior deja de servir.', 'datos' => $datos, 'campos' => []];
        }
        $a[] = ['accion' => 'nota_interna', 'etiqueta' => 'Nota interna', 'estilo' => 'normal', 'confirmar' => null, 'datos' => $datos,
                'campos' => [['campo' => 'texto', 'etiqueta' => 'Notas de Tukán (el cliente no las ve)', 'tipo' => 'texto_largo', 'requerido' => false,
                              'valor' => (string) ($n['notas_admin'] ?? ''), 'max' => 4000]]];
        $ia = new Ia($this->db, $this->modulos);
        $a[] = ['accion' => 'ia_tope', 'etiqueta' => 'Tope de IA', 'estilo' => 'normal', 'confirmar' => null, 'datos' => $datos,
                'campos' => self::camposIaTope($ia->topePropio((int) $n['id']), $ia->gastoMes((int) $n['id'])['costo'])];
        $a[] = ['accion' => 'borrar_cliente', 'etiqueta' => 'Borrar', 'estilo' => 'peligro',
                'confirmar' => '¿Borrar ' . $c['nombre'] . '? Queda ' . Papelera::DIAS_PAPELERA . ' días en la papelera (se puede restaurar) y después se borra para siempre.',
                'datos' => $datos, 'campos' => self::camposBorrar($c['nombre'])];
        $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuRenta', 'ir' => $c['ir']];
        return $a;
    }

    /** Campos de "cambiar_modulos" (Anexo B). $m = cliente.modulos (o null en capacidades). */
    public static function camposModulos(?array $m): array
    {
        $ids = implode(', ', array_keys(Modulos::CATALOGO));
        return [
            ['campo' => 'encender', 'etiqueta' => 'Encender (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 1000, 'ayuda' => "Módulos: $ids"],
            ['campo' => 'apagar', 'etiqueta' => 'Apagar (ids separados por coma)', 'tipo' => 'texto', 'requerido' => false, 'max' => 1000,
             'ayuda' => $m ? 'Encendidos ahora: ' . (implode(', ', $m['pagados']) ?: 'ninguno') : 'Apagar nunca borra datos.'],
            ['campo' => 'precio_acordado', 'etiqueta' => 'Precio acordado al mes ($, vacío = el calculado)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0,
             'valor' => $m['precio_acordado'] ?? null],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
        ];
    }

    /** Campos de "ia_tope" (Anexo C.2): tope del mes en USD (vacío = el de siempre). */
    public static function camposIaTope(?float $tope, ?float $gastado): array
    {
        return [['campo' => 'tope_usd', 'etiqueta' => 'Tope de IA al mes ($)', 'tipo' => 'dinero', 'requerido' => false, 'valor' => $tope, 'min' => 0, 'max' => 1000,
                 'ayuda' => 'Vacío = el de siempre: ' . self::dinero(Ia::TOPE_MODULO) . ' con Asistente IA y ' . self::dinero(Ia::TOPE_AYUDA) . ' solo para la Ayuda.'
                            . ($gastado !== null ? ' Gastado este mes: $' . number_format($gastado, 4, ',', '.') . '.' : '')]];
    }

    /** Campos de "pausar" (Anexo A): tipo y motivo obligatorios. */
    public static function camposPausa(): array
    {
        $tipos = [];
        foreach (Papelera::TIPOS_PAUSA as $k => $v) $tipos[] = ['valor' => $k, 'etiqueta' => $v];
        return [
            ['campo' => 'tipo', 'etiqueta' => 'Tipo', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'falta_pago', 'opciones' => $tipos],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo (el cliente lo ve, salvo en revisión y uso indebido)', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 300],
        ];
    }

    /** Campos de "borrar_cliente" (Anexo A): escribir el nombre exacto y el motivo. */
    public static function camposBorrar(?string $nombre): array
    {
        return [
            ['campo' => 'confirmar', 'etiqueta' => 'Escribe el nombre exacto del negocio', 'tipo' => 'texto', 'requerido' => true, 'max' => 120,
             'ayuda' => $nombre !== null ? 'Escribe: ' . $nombre : 'El nombre exacto (o el número) del negocio.'],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 300],
        ];
    }

    private function opcionesPlan(): array
    {
        return array_map(fn($p) => ['valor' => (string) $p['codigo'], 'etiqueta' => $p['nombre'] . ' (' . self::dinero((float) $p['precio_mensual']) . ' al mes)'],
                         $this->planes->lista());
    }

    private function formPago(array $n, array $datos): array
    {
        $periodos = [];
        foreach ($this->planes->periodos() as $per => $x) {
            $periodos[] = ['valor' => $per, 'etiqueta' => $per === 'mensual' ? '1 mes'
                : $x['recibe'] . ' meses (paga ' . $x['paga'] . ($x['dominio'] ? ', con dominio propio' : '') . ')'];
        }
        $metodos = [];
        foreach (PanelTukan::METODOS as $m => $nombre) $metodos[] = ['valor' => $m, 'etiqueta' => $nombre];
        $cot = $this->planes->cotizarNegocio((int) $n['id'], (string) $n['plan_codigo'], 'mensual');
        return ['accion' => 'registrar_pago', 'etiqueta' => 'Registrar pago', 'estilo' => 'principal',
                'confirmar' => '¿Registrar el pago de ' . $n['nombre'] . '?', 'datos' => $datos,
                'campos' => [
                    ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => (string) $n['plan_codigo'], 'opciones' => $this->opcionesPlan()],
                    ['campo' => 'periodo', 'etiqueta' => 'Tiempo', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'mensual', 'opciones' => $periodos],
                    ['campo' => 'monto', 'etiqueta' => 'Valor recibido ($)', 'tipo' => 'dinero', 'requerido' => true, 'valor' => $cot['monto'], 'min' => 0, 'cotizar' => true],
                    ['campo' => 'metodo', 'etiqueta' => 'Forma de pago', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'transferencia', 'opciones' => $metodos],
                    ['campo' => 'fecha', 'etiqueta' => 'Fecha del pago', 'tipo' => 'fecha', 'requerido' => true, 'valor' => $this->hoy->format('Y-m-d')],
                    ['campo' => 'inicio', 'etiqueta' => 'Desde', 'tipo' => 'fecha', 'requerido' => false,
                     'valor' => $this->planes->inicioSugerido((int) $n['id'], $this->hoy)->format('Y-m-d'), 'ayuda' => 'Se suma desde el vencimiento actual si aún no vence.'],
                    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 200, 'ayuda' => 'N° de transferencia, factura…'],
                    ['campo' => 'dominio', 'etiqueta' => 'Dominio propio', 'tipo' => 'texto', 'requerido' => false, 'max' => 120, 'ayuda' => 'Solo con el pago anual (ej. quintalosarrayanes.com).'],
                ]];
    }

    private function formExtender(array $c, array $datos): array
    {
        return ['accion' => 'extender_prueba', 'etiqueta' => 'Alargar demo', 'estilo' => 'normal',
                'confirmar' => '¿Alargar la demo de ' . $c['nombre'] . '?', 'datos' => $datos,
                'campos' => [['campo' => 'dias', 'etiqueta' => 'Días más', 'tipo' => 'dias', 'requerido' => true, 'valor' => 7, 'min' => 1, 'max' => 90]]];
    }

    private function formAtender(array $s, array $extra = []): array
    {
        return ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar atendida', 'estilo' => 'normal', 'confirmar' => null,
                'datos' => ['solicitud_id' => $s['id']],
                'campos' => array_merge([
                    ['campo' => 'resultado', 'etiqueta' => 'Resultado', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'atendida',
                     'opciones' => [['valor' => 'en_proceso', 'etiqueta' => 'En proceso'], ['valor' => 'atendida', 'etiqueta' => 'Atendida'],
                                    ['valor' => 'descartada', 'etiqueta' => 'Descartar']]],
                    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
                ], $extra)];
    }

    // ------------------------------------------------------------------
    // cotizar (§5.6) y planes (§5.7)
    // ------------------------------------------------------------------

    public function cotizar(array $d): array
    {
        $id = $this->idCliente($d['cliente_id'] ?? null, 'cliente_id');
        $n = $this->fila($id);
        [$plan, $periodo] = $this->planPeriodo($d, (string) $n['plan_codigo'], '');
        $c = $this->planes->cotizarNegocio($id, $plan, $periodo);   // Anexo B: con sus módulos extra y quitados (o su precio acordado)
        $desde = $this->planes->inicioSugerido($id, $this->hoy);
        $hasta = $desde->modify('+' . $c['meses_recibidos'] . ' months')->modify('-1 day');
        return ['monto' => $c['monto'], 'meses' => $c['meses_recibidos'], 'desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d'),
                'detalle' => $this->detallePlan($plan, $periodo),
                'incluye_dominio' => $c['incluye_dominio'], 'descuentos' => [], 'mensual' => $c['mensual']];
    }

    private function detallePlan(string $plan, string $periodo): string
    {
        $nombre = (string) ($this->db->query('SELECT nombre FROM planes WHERE codigo = ' . $this->db->quote($plan))->fetchColumn() ?: $plan);
        $x = $this->planes->periodos()[$periodo];
        return $periodo === 'mensual' ? "$nombre mensual"
            : "$nombre $periodo (paga {$x['paga']}, recibe {$x['recibe']}" . ($x['dominio'] ? ' + dominio propio' : '') . ')';
    }

    /** Plan y período de los datos (validados). $ruta: prefijo del campo para el error ("datos."). */
    private function planPeriodo(array $d, string $planDefecto, string $ruta): array
    {
        $plan = $d['plan'] ?? null;
        if ($plan === null || $plan === '') $plan = $planDefecto;
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo($ruta . 'plan', 'Ese plan no existe en TuRenta.');
        $periodo = $d['periodo'] ?? null;
        if (($periodo === null || $periodo === '') && array_key_exists('meses', $d)) {
            throw TukanCentralError::campo($ruta . 'meses', 'TuRenta no cobra por meses sueltos: elige mensual, semestral o anual.');
        }
        if (!is_string($periodo) || !isset($this->planes->periodos()[$periodo])) {
            throw TukanCentralError::campo($ruta . 'periodo', 'Elige el tiempo: mensual, semestral o anual.');
        }
        return [$plan, $periodo];
    }

    private function planExiste(string $plan): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        return (bool) $st->fetchColumn();
    }

    public function planesV2(): array
    {
        $a = $this->planes->ajustes();
        $cuantos = $this->db->query('SELECT plan_codigo, count(*) FROM negocios WHERE eliminado_en IS NULL GROUP BY plan_codigo')->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['editable' => true, 'planes' => array_map(fn($p) => $this->plan($p, (int) ($cuantos[$p['codigo']] ?? 0)), $this->planes->lista()),
                'ajustes' => ['dias_prueba' => (int) $a['dias_prueba'], 'dias_demo' => (int) $a['dias_prueba'], 'dias_gracia' => (int) $a['dias_gracia'],
                              'dias_gracia_demo' => 0, 'registro_abierto' => false]];
    }

    private function plan(array $p, int $clientes): array
    {
        $precios = [];
        foreach ($this->planes->periodos() as $per => $x) {
            $c = $this->planes->cotizar((string) $p['codigo'], $per);
            $precios[] = ['periodo' => $per, 'meses' => $x['recibe'], 'precio' => $c['monto'], 'meses_gratis' => $x['recibe'] - $x['paga'],
                          'incluye_dominio' => $x['dominio']];
        }
        return ['codigo' => (string) $p['codigo'], 'nombre' => (string) $p['nombre'], 'activo' => true, 'para' => null,
                'modulos' => $this->modulos->planes()[$p['codigo']]['modulos'] ?? [],
                'descripcion' => $p['descripcion'] ?? null, 'precios' => $precios,
                // Planes de $20 y $45 (migración 112): módulos a elección incluidos y precio de cada módulo más (null = el del catálogo)
                'modulos_elegir' => (int) ($p['modulos_elegir'] ?? 0),
                'precio_modulo_extra' => isset($p['precio_modulo_extra']) ? round((float) $p['precio_modulo_extra'], 2) : null,
                'limites' => ['Espacios' => $p['max_espacios'] === null ? 'Ilimitados' : (string) $p['max_espacios'],
                              'Usuarios' => $p['max_usuarios'] === null ? 'Ilimitados' : (string) $p['max_usuarios']]
                    + ((int) ($p['modulos_elegir'] ?? 0) > 0 ? ['Módulos a elegir' => (string) (int) $p['modulos_elegir']] : []),
                'clientes' => $clientes];
    }

    private function camposPlan(?array $p): array
    {
        return [
            ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $p['codigo'] ?? null, 'opciones' => $this->opcionesPlan()],
            ['campo' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'texto', 'requerido' => false, 'max' => 50],
            ['campo' => 'precio_mensual', 'etiqueta' => 'Precio al mes ($)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0],
            ['campo' => 'descripcion', 'etiqueta' => 'Descripción', 'tipo' => 'texto', 'requerido' => false, 'max' => 300],
            ['campo' => 'max_espacios', 'etiqueta' => 'Máximo de espacios (vacío = ilimitados)', 'tipo' => 'numero', 'requerido' => false, 'min' => 1],
            ['campo' => 'max_usuarios', 'etiqueta' => 'Máximo de usuarios (vacío = ilimitados)', 'tipo' => 'numero', 'requerido' => false, 'min' => 1],
            ['campo' => 'modulos_elegir', 'etiqueta' => 'Módulos que elige el cliente (incluidos en el precio)', 'tipo' => 'numero', 'requerido' => false, 'min' => 0],
            ['campo' => 'precio_modulo_extra', 'etiqueta' => 'Cada módulo más ($, vacío = precio del catálogo)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0],
        ];
    }

    // ------------------------------------------------------------------
    // Solicitudes (§5.8, §5.9): registros nuevos y recordatorios que se arman al momento
    // ------------------------------------------------------------------

    /** Todas las solicitudes que corresponden hoy, con su estado según tukan_central_atenciones. */
    private function todasLasSolicitudes(): array
    {
        if ($this->solicitudesCache !== null) return $this->solicitudesCache;
        $hoy = $this->hoy->format('Y-m-d');
        $hace = fn(int $d) => $this->hoy->modify("-$d days")->format('Y-m-d');
        $pagos = $this->pagosResumen();
        $items = [];
        foreach ($this->filas() as $id => $n) {
            $c = $this->clasificar($n);
            $nat = $c['nativo'];
            if (in_array($nat, ['suspendido', 'cancelado'], true)) continue;
            $plan = ['codigo' => (string) $n['plan_codigo'], 'nombre' => (string) $n['plan_nombre']];
            $alta = substr((string) $n['creado_en'], 0, 10);
            if ($nat === 'prueba' && $n['prueba_hasta']) {
                $dias = $this->diasHasta($n['prueba_hasta']);
                $etapa = $dias === 0 ? 'vence_hoy' : ($dias <= 2 ? 'faltan_2' : null);
                if ($etapa) {
                    $desde = (new DateTimeImmutable($n['prueba_hasta']))->modify($etapa === 'vence_hoy' ? '+0 days' : '-2 days')->format('Y-m-d');
                    $items[] = $this->sol($n, $c, 'seguimiento_prueba', "seguimiento_prueba:$id:$etapa:{$n['prueba_hasta']}", 'recordatorio',
                        self::momento(max($desde, $alta)), $etapa === 'vence_hoy' ? 'alta' : 'normal',
                        $etapa === 'vence_hoy' ? 'La demo termina hoy' : 'Demo por terminar (faltan ' . self::dias($dias) . ')',
                        'Plan ' . $plan['nombre'] . ' · demo hasta el ' . self::fechaTexto($n['prueba_hasta']),
                        ['etapa' => $etapa, 'plan' => $plan, 'prueba_hasta' => $n['prueba_hasta'], 'dias' => $dias]);
                }
            }
            if ($nat === 'prueba_vencida' && $n['prueba_hasta'] >= $hace(self::DIAS_PRUEBA_TERMINADA)) {
                $items[] = $this->sol($n, $c, 'seguimiento_prueba', "seguimiento_prueba:$id:terminada:{$n['prueba_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['prueba_hasta']))->modify('+1 day')->format('Y-m-d')), 'normal',
                    'La demo terminó sin pagar', 'Plan ' . $plan['nombre'] . ' · terminó el ' . self::fechaTexto($n['prueba_hasta']),
                    ['etapa' => 'terminada', 'plan' => $plan, 'prueba_hasta' => $n['prueba_hasta'], 'dias' => $this->diasHasta($n['prueba_hasta'])]);
            }
            $pago = (int) ($pagos[$id]['cantidad'] ?? 0) > 0;
            if ($nat === 'activo' && $n['activo_hasta'] && $pago && $this->diasHasta($n['activo_hasta']) <= self::DIAS_RENOVACION) {
                $dias = $this->diasHasta($n['activo_hasta']);
                $items[] = $this->sol($n, $c, 'renovacion', "renovacion:$id:vence_pronto:{$n['activo_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['activo_hasta']))->modify('-' . self::DIAS_RENOVACION . ' days')->format('Y-m-d')), 'normal',
                    $dias === 0 ? 'El plan vence hoy' : 'Plan por vencer (faltan ' . self::dias($dias) . ')',
                    'Plan ' . $plan['nombre'] . ' · vence el ' . self::fechaTexto($n['activo_hasta']),
                    ['etapa' => 'vence_pronto', 'plan' => $plan, 'activo_hasta' => $n['activo_hasta'], 'dias' => $dias]);
            }
            if (in_array($nat, ['por_vencer', 'vencido'], true) && $n['activo_hasta'] && $n['activo_hasta'] >= $hace(self::DIAS_VENCIDA)) {
                $items[] = $this->sol($n, $c, 'renovacion', "renovacion:$id:vencida:{$n['activo_hasta']}", 'recordatorio',
                    self::momento((new DateTimeImmutable($n['activo_hasta']))->modify('+1 day')->format('Y-m-d')), 'alta',
                    'Plan vencido sin renovar', 'Plan ' . $plan['nombre'] . ' · venció el ' . self::fechaTexto($n['activo_hasta'])
                        . ($nat === 'por_vencer' ? ' (en días de gracia)' : ''),
                    ['etapa' => 'vencida', 'plan' => $plan, 'activo_hasta' => $n['activo_hasta'], 'dias' => $this->diasHasta($n['activo_hasta']),
                     'en_gracia' => $nat === 'por_vencer']);
            }
        }
        // Dominio incluido por registrar (pago anual sin dominio)
        $filas = null;
        foreach ($this->db->query("SELECT su.id, su.negocio_id, su.pagado_en, su.monto, su.inicio, su.fin, pl.nombre AS plan_nombre, su.plan_codigo
                                     FROM suscripciones su JOIN planes pl ON pl.codigo = su.plan_codigo
                                    WHERE su.incluye_dominio AND su.dominio IS NULL AND NOT su.anulada ORDER BY su.id")->fetchAll() as $s) {
            $filas ??= $this->filas();
            $n = $filas[(int) $s['negocio_id']] ?? null;
            if (!$n) continue;
            $items[] = $this->sol($n, $this->clasificar($n), 'dominio_gratis', 'dominio_gratis:' . $s['id'], 'recordatorio', self::momento($s['pagado_en']), 'normal',
                'Dominio incluido por registrar', 'Pago anual del ' . self::fechaTexto($s['pagado_en']) . ' · ' . self::dinero((float) $s['monto']),
                ['pago_id' => (string) $s['id'], 'plan' => ['codigo' => $s['plan_codigo'], 'nombre' => $s['plan_nombre']], 'desde' => $s['inicio'], 'hasta' => $s['fin']],
                round((float) $s['monto'], 2));
        }
        // Anexo B: pedidos de módulos desde "Mi plan" (tabla propia: modulos_solicitudes)
        $filas = null;
        foreach ($this->modulos->solicitudes($hace(400)) as $ms) {
            $filas ??= $this->filas();
            $n = $filas[(int) $ms['negocio_id']] ?? null;
            if (!$n) continue;
            $items[] = $this->solModulos($n, $ms);
        }
        // Pedidos de demo (no hay registro abierto con periodo de prueba): tabla propia pedidos_demo
        foreach ((new PedidosDemo($this->db))->lista($hace(400)) as $pd) $items[] = $this->solDemo($pd);
        // Estado según lo que el Central ya atendió (los de tabla propia ya traen su estado)
        if ($items) {
            $ids = array_column($items, 'id');
            $st = $this->db->prepare('SELECT * FROM tukan_central_atenciones WHERE solicitud_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $at = [];
            foreach ($st->fetchAll() as $f) $at[$f['solicitud_id']] = $f;
            foreach ($items as &$s) {
                if (!isset($at[$s['id']]) || in_array($s['tipo'], ['cambio_modulos', 'demo_pedida'], true)) continue;
                $f = $at[$s['id']];
                $s['estado'] = $s['estado_producto'] = (string) $f['estado'];
                $s['actualizado'] = self::momento($f['momento']);
                $s['detalle']['atencion'] = ['estado' => $f['estado'], 'nota' => $f['nota'], 'por' => trim(($f['nombre'] ?? '') . ' <' . $f['email'] . '>'),
                                             'momento' => self::momento($f['momento'])];
            }
            unset($s);
        }
        return $this->solicitudesCache = $items;
    }

    private function sol(array $n, array $c, string $tipo, string $id, string $categoria, ?string $fecha, string $prioridad,
                         string $titulo, string $resumen, array $detalle, ?float $monto = null): array
    {
        $cl = ['id' => (string) $n['id'], 'nombre' => (string) $n['nombre'], 'plan' => ['codigo' => (string) $n['plan_codigo']],
               'pagos' => ['cantidad' => (int) ($this->pagosResumen()[(int) $n['id']]['cantidad'] ?? 0)]] + $c;
        $tel = $n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']);
        $wa = WhatsApp::normalizarTelefono($tel);
        $m = $this->mensajes($n);
        $s = [
            'id' => $id, 'tipo' => $tipo, 'categoria' => $categoria, 'titulo' => $titulo, 'fecha' => $fecha, 'actualizado' => $fecha,
            'cliente_id' => (string) $n['id'],
            'quien' => ['nombre' => $n['dueno'] ?: null, 'organizacion' => (string) $n['nombre'], 'telefono' => $tel ?: null, 'whatsapp' => $wa,
                        'email' => $n['dueno_email'] ?: null],
            'resumen' => $resumen, 'monto' => $monto, 'estado' => 'pendiente', 'estado_producto' => 'pendiente', 'prioridad' => $prioridad,
            'vence_el' => null, 'detalle' => $detalle, 'adjuntos' => [], 'acciones_posibles' => [], 'ir' => '/?r=admin_negocio&id=' . (int) $n['id'],
        ];
        $datos = ['cliente_id' => (string) $n['id']];
        $a = [];
        $texto = match ($tipo) {
            'seguimiento_prueba' => $m['recordar_prueba'] ?? $m['vencido'],
            'renovacion' => $m['recordar_renovacion'] ?? $m['vencido'],
            default => null,
        };
        if ($wa && $texto) $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa, 'texto' => $texto];
        if ($tipo === 'seguimiento_prueba' && $this->puedeExtender($cl)) $a[] = $this->formExtender($cl, $datos);
        if (in_array($tipo, ['seguimiento_prueba', 'renovacion'], true)) $a[] = $this->formPago($n, $datos);
        if ($tipo === 'renovacion' && ($detalle['etapa'] ?? '') === 'vencida') {
            $a[] = ['accion' => 'suspender', 'etiqueta' => 'Suspender', 'estilo' => 'peligro', 'confirmar' => '¿Suspender ' . $n['nombre'] . '?', 'datos' => $datos,
                    'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'valor' => 'No renovó el plan', 'min' => 5, 'max' => 200]]];
        }
        $a[] = $this->formAtender($s, $tipo === 'dominio_gratis'
            ? [['campo' => 'dominio', 'etiqueta' => 'Dominio registrado', 'tipo' => 'texto', 'requerido' => false, 'max' => 120, 'ayuda' => 'Se guarda en el pago anual (ej. quintalosarrayanes.com).']]
            : []);
        $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuRenta', 'ir' => $s['ir']];
        $s['acciones_posibles'] = $a;
        return $s;
    }

    public const ESTADO_PEDIDO_MODULOS = ['pendiente' => 'pendiente', 'aprobada' => 'atendida', 'rechazada' => 'descartada'];
    public const ESTADO_PEDIDO_DEMO = ['nueva' => 'pendiente', 'contactada' => 'en_proceso', 'demo_creada' => 'atendida', 'descartada' => 'descartada'];

    /** Solicitud cambio_modulos (Anexo B): un dueño pidió módulos desde "Mi plan". Aprobar = cambiar_modulos; rechazar = atender_solicitud descartada. */
    private function solModulos(array $n, array $ms): array
    {
        $enc = Modulos::parse($ms['encender']);
        $cat = $this->modulos->catalogo();
        // Cuánto suma al mes (planes de $20 y $45, migración 112: un módulo elegido del Básico suma 0). Si ya los tiene, lo que paga por ellos.
        $hoyMod = $this->modulos->estado((int) $n['id']);
        $suma = array_diff($enc, $hoyMod['pagados'])
            ? round($this->modulos->estado((int) $n['id'], null, $enc)['calculado'] - $hoyMod['calculado'], 2)
            : round(array_sum(array_map(fn($m) => $hoyMod['precio_por_extra'][$m] ?? 0.0, $enc)), 2);
        $tel = $n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']);
        $wa = WhatsApp::normalizarTelefono($tel);
        $sid = 'cambio_modulos:' . (int) $ms['id'];
        $estado = self::ESTADO_PEDIDO_MODULOS[$ms['estado']];
        $nombres = implode(', ', array_map([Modulos::class, 'nombre'], $enc));
        $mod = $this->modulosDe((int) $n['id']);
        $s = [
            'id' => $sid, 'tipo' => 'cambio_modulos', 'categoria' => 'pedido', 'titulo' => 'Pidió módulos: ' . $nombres,
            'fecha' => self::momento($ms['creado']), 'actualizado' => self::momento($ms['atendido_en'] ?? $ms['creado']),
            'cliente_id' => (string) $n['id'],
            'quien' => ['nombre' => $ms['usuario_nombre'] ?? ($n['dueno'] ?: null), 'organizacion' => (string) $n['nombre'], 'telefono' => $tel ?: null,
                        'whatsapp' => $wa, 'email' => $ms['usuario_email'] ?? ($n['dueno_email'] ?: null)],
            'resumen' => $nombres . ' · +' . self::dinero($suma) . ' al mes · hoy paga ' . self::dinero($mod['mensual']),
            'monto' => $suma, 'estado' => $estado, 'estado_producto' => (string) $ms['estado'], 'prioridad' => 'normal', 'vence_el' => null,
            'detalle' => ['encender' => $enc, 'apagar' => Modulos::parse($ms['apagar']), 'nota' => $ms['nota'], 'suma_mensual' => $suma,
                          'mensual_actual' => $mod['mensual'], 'mensual_nuevo' => round($mod['mensual'] + $suma, 2), 'respuesta' => $ms['respuesta'],
                          'atendido_por' => $ms['atendido_por']],
            'adjuntos' => [], 'acciones_posibles' => [], 'ir' => '/?r=admin_negocio&id=' . (int) $n['id'] . '#modulos',
        ];
        if ($estado === 'pendiente') {
            $s['acciones_posibles'][] = ['accion' => 'cambiar_modulos', 'etiqueta' => 'Aprobar y encender', 'estilo' => 'principal',
                'confirmar' => '¿Encender ' . $nombres . ' a ' . $n['nombre'] . '? Pagará ' . self::dinero(round($mod['mensual'] + $suma, 2)) . ' al mes.',
                'datos' => ['cliente_id' => (string) $n['id'], 'solicitud_id' => $sid, 'encender' => $enc],
                'campos' => [['campo' => 'precio_acordado', 'etiqueta' => 'Precio acordado al mes ($, vacío = el calculado)', 'tipo' => 'dinero', 'requerido' => false, 'min' => 0]]];
            $s['acciones_posibles'][] = ['accion' => 'atender_solicitud', 'etiqueta' => 'Rechazar', 'estilo' => 'peligro', 'confirmar' => null,
                'datos' => ['solicitud_id' => $sid, 'resultado' => 'descartada'],
                'campos' => [['campo' => 'nota', 'etiqueta' => 'Motivo (lo ve el cliente en Mi plan)', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]];
            if ($wa) $s['acciones_posibles'][] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa,
                'texto' => 'Hola, te escribo de TuRenta por los módulos que pediste para ' . $n['nombre'] . ' (' . $nombres . ').'];
        }
        $s['acciones_posibles'][] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuRenta', 'ir' => $s['ir']];
        return $s;
    }

    /** Solicitud demo_pedida: alguien llenó "Solicitar demo". Crear demo = crear_demo con solicitud_id. */
    private function solDemo(array $pd): array
    {
        $wa = WhatsApp::normalizarTelefono($pd['whatsapp']);
        $sid = 'demo_pedida:' . (int) $pd['id'];
        $estado = self::ESTADO_PEDIDO_DEMO[$pd['estado']];
        $mods = Modulos::parse($pd['modulos']);
        $s = [
            'id' => $sid, 'tipo' => 'demo_pedida', 'categoria' => 'pedido', 'titulo' => 'Pidió una demo: ' . $pd['negocio'],
            'fecha' => self::momento($pd['creado']), 'actualizado' => self::momento($pd['actualizado']),
            'cliente_id' => $pd['negocio_id'] !== null ? (string) $pd['negocio_id'] : null,
            'quien' => ['nombre' => $pd['nombre'], 'organizacion' => $pd['negocio'], 'telefono' => $pd['whatsapp'], 'whatsapp' => $wa, 'email' => $pd['email']],
            'resumen' => trim(($pd['ciudad'] ? $pd['ciudad'] . ' · ' : '') . ($mods ? 'Le interesa: ' . implode(', ', array_map([Modulos::class, 'nombre'], $mods)) : 'Sin módulos elegidos')),
            'monto' => null, 'estado' => $estado, 'estado_producto' => (string) $pd['estado'], 'prioridad' => 'alta', 'vence_el' => null,
            'detalle' => ['negocio' => $pd['negocio'], 'ciudad' => $pd['ciudad'], 'necesita' => $pd['necesita'], 'modulos' => $mods, 'nota' => $pd['nota']],
            'adjuntos' => [], 'acciones_posibles' => [], 'ir' => $pd['negocio_id'] !== null ? '/?r=admin_negocio&id=' . (int) $pd['negocio_id'] : '/?r=admin#pedidos-demo',
        ];
        if (in_array($estado, ['pendiente', 'en_proceso'], true)) {
            if ($wa) $s['acciones_posibles'][] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'estilo' => 'normal', 'whatsapp' => $wa,
                'texto' => 'Hola ' . explode(' ', $pd['nombre'])[0] . ', te escribo de TuRenta por la demo que pediste para ' . $pd['negocio'] . '. ¿Cuándo te la puedo mostrar?'];
            $s['acciones_posibles'][] = ['accion' => 'crear_demo', 'etiqueta' => 'Crear demo', 'estilo' => 'principal',
                'confirmar' => '¿Crear la demo de ' . $pd['negocio'] . '?', 'datos' => ['solicitud_id' => $sid],
                'campos' => [
                    ['campo' => 'nombre', 'etiqueta' => 'Negocio', 'tipo' => 'texto', 'requerido' => true, 'valor' => $pd['negocio'], 'min' => 3, 'max' => 120],
                    ['campo' => 'contacto_nombre', 'etiqueta' => 'Contacto', 'tipo' => 'texto', 'requerido' => true, 'valor' => $pd['nombre'], 'min' => 2, 'max' => 80],
                    ['campo' => 'email', 'etiqueta' => 'Correo (será el usuario)', 'tipo' => 'email', 'requerido' => true, 'valor' => $pd['email']],
                    ['campo' => 'telefono', 'etiqueta' => 'WhatsApp', 'tipo' => 'telefono', 'requerido' => true, 'valor' => $pd['whatsapp']],
                    ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => false, 'valor' => Modulos::PLAN_DEFECTO, 'opciones' => $this->opcionesPlan()],
                    ['campo' => 'dias', 'etiqueta' => 'Días de demo', 'tipo' => 'dias', 'requerido' => false, 'valor' => max(1, (int) $this->planes->ajuste('dias_prueba')), 'min' => 1, 'max' => 90],
                    ['campo' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'requerido' => false, 'valor' => $pd['ciudad'] ?: 'Quito', 'max' => 80],
                    ['campo' => 'modulos', 'etiqueta' => 'Módulos de la demo (vacío = todos)', 'tipo' => 'texto', 'requerido' => false, 'valor' => '', 'max' => 1000,
                     'ayuda' => $mods ? 'Le interesa: ' . implode(',', $mods) : 'Ids separados por coma.'],
                ]];
            $s['acciones_posibles'][] = ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid],
                'campos' => [['campo' => 'resultado', 'etiqueta' => 'Resultado', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'en_proceso',
                              'opciones' => [['valor' => 'en_proceso', 'etiqueta' => 'Contactado'], ['valor' => 'descartada', 'etiqueta' => 'Descartar']]],
                             ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]];
        }
        $s['acciones_posibles'][] = ['accion' => 'abrir', 'etiqueta' => 'Ver en TuRenta', 'ir' => $s['ir']];
        return $s;
    }

    /** Solicitudes pendientes o en proceso (para el estado v1: cuántas y su huella). */
    public function solicitudesAbiertas(): array
    {
        return array_values(array_filter($this->todasLasSolicitudes(), fn($s) => in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
    }

    /** Primeros 16 hex del sha256 de "id|estado" de las solicitudes abiertas, ordenados (§2.4). */
    public static function huella(array $solicitudes): string
    {
        $l = array_map(fn($s) => $s['id'] . '|' . $s['estado'], $solicitudes);
        sort($l, SORT_STRING);
        return substr(hash('sha256', implode("\n", $l)), 0, 16);
    }

    public function solicitudes(array $d): array
    {
        $estado = $this->opcion($d, 'estado', ['abiertas', 'cerradas', 'todas'], 'abiertas');
        $tipos = $d['tipos'] ?? [];
        if (!is_array($tipos) || !array_is_list($tipos) || array_filter($tipos, fn($t) => !is_string($t) || strlen($t) > 40)) {
            throw TukanCentralError::campo('tipos', 'La lista de tipos no es válida.');
        }
        $cliente = $d['cliente_id'] ?? null;
        if ($cliente !== null && !(is_int($cliente) || (is_string($cliente) && preg_match(self::ID, $cliente)))) {
            throw TukanCentralError::campo('cliente_id', 'El cliente no es válido.');
        }
        $desde = $this->fecha($d, 'desde') ?? $this->hoy->modify('-60 days')->format('Y-m-d');
        $pagina = $this->entero($d, 'pagina', 1, 1, 1000000);
        $porPagina = $this->entero($d, 'por_pagina', 100, 1, 200);
        $lista = array_values(array_filter($this->todasLasSolicitudes(), function ($s) use ($estado, $tipos, $cliente, $desde) {
            $abierta = in_array($s['estado'], ['pendiente', 'en_proceso'], true);
            if ($estado === 'abiertas' && !$abierta) return false;
            if ($estado === 'cerradas' && $abierta) return false;
            if ($estado !== 'abiertas' && substr((string) $s['actualizado'], 0, 10) < $desde) return false;
            if ($tipos && !in_array($s['tipo'], $tipos, true)) return false;
            return $cliente === null || $s['cliente_id'] === (string) $cliente;
        }));
        $orden = ['alta' => 0, 'normal' => 1, 'baja' => 2];
        usort($lista, fn($a, $b) => [$orden[$a['prioridad']], $b['fecha'], $a['id']] <=> [$orden[$b['prioridad']], $a['fecha'], $b['id']]);
        $contadores = [];
        foreach ($lista as $s) $contadores[$s['tipo']] = ($contadores[$s['tipo']] ?? 0) + 1;
        $total = count($lista);
        return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contadores' => (object) $contadores, 'solicitudes' => array_slice($lista, ($pagina - 1) * $porPagina, $porPagina)];
    }

    public function solicitud(array $d): array
    {
        $id = $d['id'] ?? null;
        if (!is_string($id) || !preg_match(self::ID, $id)) throw TukanCentralError::campo('id', 'Falta el id de la solicitud.');
        foreach ($this->todasLasSolicitudes() as $s) if ($s['id'] === $id) return $s;
        throw new TukanCentralError('no_encontrado', 'Esa solicitud ya no existe en TuRenta (quizás ya se resolvió).', 404);
    }

    // ------------------------------------------------------------------
    // modulos (Anexo B.2)
    // ------------------------------------------------------------------

    public function modulosV2(): array
    {
        $lista = [];
        foreach ($this->modulos->catalogo() as $m) {
            $lista[] = ['id' => $m['id'], 'nombre' => $m['nombre'], 'icono' => $m['icono'], 'descripcion' => $m['descripcion'],
                        'precio_mensual' => $m['precio_mensual'], 'depende_de' => $m['depende_de'], 'grupo' => $m['grupo'],
                        'grupo_nombre' => $m['grupo_nombre'], 'orden' => $m['orden']];
        }
        $planes = [];
        foreach ($this->modulos->planes() as $p) {
            $planes[] = ['codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'precio_mensual' => $p['precio_mensual'], 'modulos' => $p['modulos'], 'todos' => $p['todos'],
                         'modulos_elegir' => $p['modulos_elegir'], 'precio_modulo_extra' => $p['precio_modulo_extra']];
        }
        // fuera_del_cupo: módulos que no cuentan entre los que elige el cliente del Básico y se cobran a su precio del catálogo
        // (el Panel Tukán Central calcula con esto el total en vivo; planes de $20 y $45, migración 112)
        return ['base' => Modulos::BASE, 'grupos' => Modulos::GRUPOS, 'modulos' => $lista, 'planes' => $planes,
                'fuera_del_cupo' => Modulos::FUERA_DEL_CUPO];
    }

    // ------------------------------------------------------------------
    // finanzas (§5.11)
    // ------------------------------------------------------------------

    public function finanzas(array $d): array
    {
        $hasta = $this->mes($d, 'hasta') ?? $this->hoy->format('Y-m');
        $desde = $this->mes($d, 'desde') ?? (new DateTimeImmutable("$hasta-01"))->modify('-11 months')->format('Y-m');
        if ($desde > $hasta) throw TukanCentralError::campo('desde', 'El mes "desde" va antes que "hasta".');
        $ini = new DateTimeImmutable("$desde-01");
        $finRango = (new DateTimeImmutable("$hasta-01"))->modify('first day of next month');
        $meses = [];
        for ($m = $ini; $m < $finRango; $m = $m->modify('first day of next month')) $meses[] = $m->format('Y-m');
        if (count($meses) > 24) throw TukanCentralError::campo('desde', 'Pide como máximo 24 meses.');

        $vacio = fn(string $mes) => ['mes' => $mes, 'ingresos' => 0.0, 'pagos' => 0, 'clientes_que_pagaron' => 0, 'nuevos_pagadores' => 0, 'devengado' => 0.0,
                                     'por_plan' => [], 'por_periodo' => [], 'por_metodo' => [], 'otros_ingresos' => [], 'costos' => []];
        $r = [];
        foreach ($meses as $mes) $r[$mes] = $vacio($mes);
        $clientes = [];
        $st = $this->db->prepare("SELECT su.negocio_id, su.plan_codigo, pl.nombre AS plan_nombre, su.periodo, su.meses_recibidos, su.monto, su.metodo_pago,
                                         to_char(su.pagado_en, 'YYYY-MM') AS mes
                                    FROM suscripciones su JOIN planes pl ON pl.codigo = su.plan_codigo
                                   WHERE NOT su.anulada AND " . str_replace('suscripciones.', 'su.', PanelTukan::SQL_PAGO_CUENTA) . "
                                     AND su.pagado_en >= ? AND su.pagado_en < ? ORDER BY su.pagado_en, su.id");
        $st->execute([$ini->format('Y-m-d'), $finRango->format('Y-m-d')]);
        $sumar = function (array &$grupo, string $clave, array $base, float $monto): void {
            $grupo[$clave] ??= $base + ['monto' => 0.0, 'pagos' => 0];
            $grupo[$clave]['monto'] += $monto;
            $grupo[$clave]['pagos']++;
        };
        foreach ($st->fetchAll() as $p) {
            $mes = $p['mes'];
            $monto = (float) $p['monto'];
            $r[$mes]['ingresos'] += $monto;
            $r[$mes]['pagos']++;
            $clientes[$mes][(int) $p['negocio_id']] = true;
            $sumar($r[$mes]['por_plan'], (string) $p['plan_codigo'], ['plan' => (string) $p['plan_codigo'], 'nombre' => (string) $p['plan_nombre']], $monto);
            $sumar($r[$mes]['por_periodo'], $p['periodo'] . '|' . $p['meses_recibidos'], ['periodo' => (string) $p['periodo'], 'meses' => (int) $p['meses_recibidos']], $monto);
            $met = (string) ($p['metodo_pago'] ?? 'otro');
            $sumar($r[$mes]['por_metodo'], $met, ['metodo' => $met, 'nombre' => PanelTukan::METODOS[$met] ?? $met], $monto);
        }
        foreach ($this->db->query("SELECT negocio_id, to_char(min(pagado_en), 'YYYY-MM') AS mes FROM suscripciones WHERE NOT anulada AND " . PanelTukan::SQL_PAGO_CUENTA
                                  . ' GROUP BY negocio_id')->fetchAll() as $f) {
            if (isset($r[$f['mes']])) $r[$f['mes']]['nuevos_pagadores']++;
        }
        // Devengado: cada pago repartido por días entre su inicio y su fin (de un negocio en la papelera, solo hasta el mes en que se borró)
        $st = $this->db->prepare("SELECT su.monto, su.inicio, su.fin, to_char(n.eliminado_en, 'YYYY-MM') AS borrado
                                    FROM suscripciones su JOIN negocios n ON n.id = su.negocio_id
                                   WHERE NOT su.anulada AND " . str_replace('suscripciones.', 'su.', PanelTukan::SQL_PAGO_CUENTA) . ' AND su.inicio < ? AND su.fin >= ?');
        $st->execute([$finRango->format('Y-m-d'), $ini->format('Y-m-d')]);
        foreach ($st->fetchAll() as $s) {
            $i = new DateTimeImmutable($s['inicio']);
            $f = new DateTimeImmutable($s['fin']);
            $total = (int) $i->diff($f)->days + 1;
            if ($total <= 0) continue;
            foreach ($meses as $mes) {
                if ($s['borrado'] !== null && $mes > $s['borrado']) continue;
                $mi = new DateTimeImmutable("$mes-01");
                $mf = $mi->modify('last day of this month');
                $a = max($i, $mi);
                $b = min($f, $mf);
                if ($b < $a) continue;
                $r[$mes]['devengado'] += (float) $s['monto'] * ((int) $a->diff($b)->days + 1) / $total;
            }
        }
        $tot = ['ingresos' => 0.0, 'otros_ingresos' => 0.0, 'costos' => 0.0, 'pagos' => 0];
        $ordenar = function (array $g): array {
            $g = array_map(function ($x) { $x['monto'] = round($x['monto'], 2); return $x; }, array_values($g));
            usort($g, fn($a, $b) => $b['monto'] <=> $a['monto']);
            return $g;
        };
        foreach ($r as $mes => &$x) {
            $x['ingresos'] = round($x['ingresos'], 2);
            $x['devengado'] = round($x['devengado'], 2);
            $x['clientes_que_pagaron'] = count($clientes[$mes] ?? []);
            $x['por_plan'] = $ordenar($x['por_plan']);
            $x['por_periodo'] = $ordenar($x['por_periodo']);
            $x['por_metodo'] = $ordenar($x['por_metodo']);
            $tot['ingresos'] += $x['ingresos'];
            $tot['pagos'] += $x['pagos'];
        }
        unset($x);
        $tot['ingresos'] = round($tot['ingresos'], 2);
        return ['moneda' => 'USD',
                'criterio' => 'Pagos de suscripción no anulados, por fecha de pago. Devengado: cada pago repartido por días entre su inicio y su fin. '
                              . 'Un negocio en la papelera no cuenta desde el día en que se borró.',
                'mrr' => round($this->panel->ingresoMensual($this->hoy), 2),
                'meses' => array_values($r), 'totales' => $tot,
                // Anexo B: por módulo, cuántos clientes que pagan lo tienen (y como extra) y cuánto suman los extras al mes (estimado)
                'por_modulo' => $this->modulos->porModulo()];
    }

    // ------------------------------------------------------------------
    // ejecutar (§6) con idempotencia (§7.1) y auditoría (§7.3)
    // ------------------------------------------------------------------

    private function ejecutar(array $p, array $d, string $bh): array
    {
        $op = $d['operacion_id'] ?? null;
        if (!is_string($op) || !preg_match('/^[0-9a-f]{32}$/', $op)) throw TukanCentralError::campo('operacion_id', 'Falta la clave de la operación (32 letras hex).');
        $accion = $d['accion'] ?? null;
        if (!is_string($accion) || $accion === '') throw TukanCentralError::campo('accion', 'Falta la operación.');
        if (!in_array($accion, self::OPERACIONES, true)) {
            throw new TukanCentralError('no_permitido', 'TuRenta no tiene la operación "' . mb_substr($accion, 0, 40) . '".', 403, null, ['motivo' => 'no_disponible']);
        }
        $datos = $d['datos'] ?? [];
        if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw TukanCentralError::campo('datos', 'Los datos de la operación no son válidos.');
        $clienteId = $d['cliente_id'] ?? ($datos['cliente_id'] ?? null);
        if ($clienteId !== null && !(is_int($clienteId) || (is_string($clienteId) && preg_match(self::ID, $clienteId)))) {
            throw TukanCentralError::campo('cliente_id', 'El cliente no es válido.');
        }

        // 1. ¿Ya se hizo (o se está haciendo) esta operación?
        $this->db->exec("DELETE FROM tukan_central_operaciones WHERE creado < now() - interval '90 days'");
        $st = $this->db->prepare("INSERT INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, email)
                                  VALUES (?, ?, ?, ?, 'en_curso', ?) ON CONFLICT (operacion_id) DO NOTHING");
        $st->execute([$op, $accion, $clienteId === null ? null : (string) $clienteId, $bh, $p['email']]);
        if ($st->rowCount() !== 1) {
            $st = $this->db->prepare('SELECT * FROM tukan_central_operaciones WHERE operacion_id = ?');
            $st->execute([$op]);
            $g = $st->fetch();
            if (!$g) throw new TukanCentralError('conflicto', 'La operación está en curso. Intenta en unos segundos.', 409, null, ['motivo' => 'en_curso']);
            if (!hash_equals((string) $g['cuerpo_hash'], $bh)) {
                throw new TukanCentralError('conflicto', 'Esa operación ya se usó con otros datos.', 409, null, ['motivo' => 'otros_datos']);
            }
            if ($g['estado'] === 'en_curso') throw new TukanCentralError('conflicto', 'La operación está en curso. Intenta en unos segundos.', 409, null, ['motivo' => 'en_curso']);
            $json = json_decode((string) $g['respuesta'], true) ?: [];
            $json['repetida'] = true;
            return [(int) $g['http'], $json];
        }

        // 2. Hacerla en una transacción (con la fila del cliente bloqueada) y guardar la respuesta sin claves
        try {
            $this->db->beginTransaction();
            try {
                [$adminId] = $this->v1->buscarSuper($p);
            } catch (TukanCentralRechazo $e) {
                throw new TukanCentralError('no_permitido', $e->getMessage(), 403);
            }
            $id = null;
            if (!in_array($accion, ['crear_demo', 'atender_solicitud', 'guardar_plan', 'guardar_modulos'], true)) {
                if ($clienteId === null) throw TukanCentralError::campo('cliente_id', 'Falta el cliente.');
                $id = $this->bloquear($clienteId);
                if (!in_array($accion, self::OPERACIONES_PAPELERA, true) && $this->enPapelera($id)) {
                    throw new TukanCentralError('conflicto', 'Ese negocio está en la papelera. Primero restáuralo.', 409, null, ['motivo' => 'en_papelera']);
                }
            }
            if (in_array($accion, self::SOLO_DUENO, true) && ($p['rol'] ?? null) !== 'dueno') {   // Anexo A y B: borrar y módulos, solo el dueño de Tukán
                throw new TukanCentralError('no_permitido', $accion === 'borrar_cliente' ? 'Solo el dueño del Panel Tukán Central puede borrar clientes.'
                    : 'Solo el dueño del Panel Tukán Central puede cambiar módulos y precios.', 403, null, ['motivo' => 'rol']);
            }
            $r = $this->operar($accion, $id, $datos, $p);
            $id = $r['cliente_id'] ?? $id;
            $this->db->prepare('INSERT INTO registro_admin (admin_id, negocio_id, accion) VALUES (?, ?, ?)')
                     ->execute([$adminId, $id, mb_substr('Panel Tukán Central · ' . $r['auditoria'] . (!empty($r['sin_cambios']) ? ' (sin cambios)' : '')
                                                         . ' (op ' . substr($op, 0, 8) . ')', 0, 300)]);
            $this->olvidar();
            $fila = $id ? $this->fila($id) : null;
            $json = $this->sobre('ejecutar') + [
                'operacion' => $accion, 'operacion_id' => $op, 'repetida' => false, 'sin_cambios' => (bool) ($r['sin_cambios'] ?? false),
                'mensaje' => $r['mensaje'], 'resultado' => $r['resultado'], 'cliente' => $fila ? $this->cliente($fila) : null,
                'solicitud' => $r['solicitud'] ?? null,
            ] + ($r['arriba'] ?? []);   // borrar_cliente: "copia" y "borrar_definitivo_el" (Anexo A); cambiar_modulos: "modulos" (Anexo B)
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'ok', http = 200, respuesta = ?, cliente_id = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([json_encode(self::sinClaves($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                                $id === null ? ($clienteId === null ? null : (string) $clienteId) : (string) $id, $op]);
            $this->db->commit();
            return [200, $json];
        } catch (TukanCentralError $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->olvidar();
            if ($e->codigo === 'interno') {
                $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);
                throw $e;
            }
            // 3. Error de validación: queda guardado (el reintento recibe lo mismo)
            $this->db->prepare("UPDATE tukan_central_operaciones SET estado = 'error', http = ?, respuesta = ?, terminado = now() WHERE operacion_id = ?")
                     ->execute([$e->http, json_encode($e->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $op]);
            throw $e;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->olvidar();
            $this->db->prepare('DELETE FROM tukan_central_operaciones WHERE operacion_id = ?')->execute([$op]);   // se puede reintentar
            throw $e;
        }
    }

    /** La respuesta que se guarda: sin contraseñas ni mensajes que las lleven (§6.2 "Claves de un solo uso"). */
    public static function sinClaves(array $json): array
    {
        $r = $json['resultado'] ?? null;
        if (is_array($r)) {
            if (isset($r['acceso']) && is_array($r['acceso']) && array_key_exists('clave', $r['acceso'])) {
                $r['acceso']['clave'] = null;
                $r['acceso']['clave_oculta'] = true;
            }
            if (array_key_exists('clave', $r)) {
                $r['clave'] = null;
                $r['clave_oculta'] = true;
            }
            if (isset($r['acceso']) || array_key_exists('clave', $r)) {
                if (array_key_exists('mensaje_whatsapp', $r)) $r['mensaje_whatsapp'] = null;
                if (array_key_exists('whatsapp_url', $r)) $r['whatsapp_url'] = null;
            }
            $json['resultado'] = $r;
        }
        return $json;
    }

    private function enPapelera(int $id): bool
    {
        return (bool) $this->db->query("SELECT eliminado_en IS NOT NULL FROM negocios WHERE id = $id")->fetchColumn();
    }

    private function bloquear(int|string $clienteId): int
    {
        if (!preg_match('/^\d{1,10}$/', (string) $clienteId)) throw new TukanCentralError('no_encontrado', 'Ese negocio no existe en TuRenta.', 404);
        $st = $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE');
        $st->execute([(int) $clienteId]);
        $id = $st->fetchColumn();
        if ($id === false) throw new TukanCentralError('no_encontrado', 'Ese negocio ya no existe en TuRenta.', 404);
        return (int) $id;
    }

    private function operar(string $accion, ?int $id, array $d, array $p): array
    {
        try {
            return match ($accion) {
                'crear_demo' => $this->opCrearDemo($d),
                'extender_prueba' => $this->opExtenderPrueba($id, $d),
                'registrar_pago' => $this->opRegistrarPago($id, $d),
                'anular_pago' => $this->opAnularPago($id, $d),
                'suspender' => $this->opEstado($id, $d, 'suspendido'),
                'cancelar' => $this->opEstado($id, $d, 'cancelado'),
                'reactivar' => $this->opReactivar($id),
                'cambiar_plan' => $this->opCambiarPlan($id, $d),
                'nueva_clave_dueno' => $this->opNuevaClave($id),
                'nota_interna' => $this->opNota($id, $d),
                'atender_solicitud' => $this->opAtender($d, $p),
                'guardar_plan' => $this->opGuardarPlan($d),
                'pausar' => $this->opPausar($id, $d),
                'borrar_cliente' => $this->opBorrar($id, $d, $p),
                'restaurar_cliente' => $this->opRestaurar($id),
                'cambiar_modulos' => $this->opCambiarModulos($id, $d, $p),
                'guardar_modulos' => $this->opGuardarModulos($d),
                'ia_tope' => $this->opIaTope($id, $d),
            };
        } catch (TukanCentralError | PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {   // reglas de TuRenta (Planes, PanelTukan, Altas): mensaje en español para mostrar
            throw new TukanCentralError('invalido', $e->getMessage(), 422);
        }
    }

    private function opCrearDemo(array $d): array
    {
        $nombre = $this->textoReq($d, 'nombre', 3, 120);
        $contacto = $this->textoReq($d, 'contacto_nombre', 2, 80);
        $email = $d['email'] ?? null;
        if (!is_string($email) || mb_strlen(trim($email)) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw TukanCentralError::campo('datos.email', 'El correo no es válido.');
        }
        $email = strtolower(trim($email));
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw TukanCentralError::campo('datos.email', 'Ya existe una cuenta de TuRenta con ese correo.');
        $telefono = $this->textoReq($d, 'telefono', 9, 30);
        if (strlen((string) preg_replace('/\D+/', '', $telefono)) < 9) throw TukanCentralError::campo('datos.telefono', 'El teléfono debe tener al menos 9 dígitos.');
        $plan = $d['plan'] ?? null;
        if ($plan === null || $plan === '') $plan = 'quinta';
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuRenta.');
        $diasDefecto = max(1, (int) $this->planes->ajuste('dias_prueba'));
        $dias = $this->entero($d, 'dias', $diasDefecto, 1, 90, 'datos.');
        $sol = $d['solicitud_id'] ?? null;
        $pedido = null;
        $pedidos = new PedidosDemo($this->db);
        if ($sol !== null && $sol !== '') {
            if (!is_string($sol) || !preg_match('/^demo_pedida:(\d{1,10})$/', $sol, $mm)) throw TukanCentralError::campo('datos.solicitud_id', 'La solicitud no es un pedido de demo.');
            $pedido = $pedidos->obtener((int) $mm[1]);
            if (!$pedido) throw new TukanCentralError('no_encontrado', 'Ese pedido de demo ya no existe.', 404);
            if ($pedido['estado'] === 'demo_creada') throw new TukanCentralError('conflicto', 'Ese pedido ya tiene su demo.', 409, null, ['motivo' => 'ya_atendida', 'estado' => 'atendida']);
        }
        $extra = $d['extra'] ?? [];
        if (!is_array($extra) || ($extra !== [] && array_is_list($extra))) throw TukanCentralError::campo('datos.extra', 'Los datos propios no son válidos.');
        $ciudad = $this->texto($extra, 'ciudad', 80, 'datos.extra.') ?: ($this->texto($d, 'ciudad', 80, 'datos.') ?: ($pedido['ciudad'] ?? '') ?: 'Quito');
        // Anexo B: la demo tiene todos los módulos, salvo que se elijan (datos.modulos o datos.extra.modulos: lista o "a,b,c")
        $modsDemo = array_key_exists('modulos', $extra) ? $extra['modulos'] : ($d['modulos'] ?? null);
        $modsDemo = $this->idsModulos($modsDemo, array_key_exists('modulos', $extra) ? 'datos.extra.modulos' : 'datos.modulos', true);

        $clave = Usuarios::claveTemporal();
        $alta = (new Altas($this->db))->crearNegocioConDueno($nombre, $contacto, $telefono, $email, $clave, $plan, $ciudad, $pedido ? 'demo_pedida' : 'central');
        if ($modsDemo) $this->modulos->elegirDemo($alta['negocio_id'], $modsDemo);
        if ($pedido) $pedidos->demoCreada((int) $pedido['id'], $alta['negocio_id']);
        $this->db->prepare('UPDATE usuarios SET clave_temporal = true WHERE id = ?')->execute([$alta['usuario_id']]);
        $st = $this->db->prepare('UPDATE negocios SET prueba_hasta = ?::date + ?::int WHERE id = ? RETURNING prueba_hasta');
        $st->execute([$this->hoy->format('Y-m-d'), $dias, $alta['negocio_id']]);
        $hasta = (string) $st->fetchColumn();
        $primer = explode(' ', $contacto)[0];
        $enlace = self::urlSitio($alta['slug']);
        $msg = "¡Hola $primer! Tu demo de *$nombre* ya está lista en TuRenta, con datos de ejemplo para que veas todo.\n\n"
             . "🌐 Página pública: $enlace\n🔐 Panel: " . self::urlLogin() . "\n👤 Usuario: $email\n🔑 Contraseña temporal: $clave\n\n"
             . 'Al entrar te pedirá cambiar la contraseña. La demo dura hasta el ' . self::fechaTexto($hasta) . '.';
        $wa = WhatsApp::normalizarTelefono($telefono);
        return [
            'cliente_id' => $alta['negocio_id'],
            'mensaje' => "Demo creada: $nombre, hasta el $hasta.",
            'solicitud' => $pedido ? $this->solDemo((array) $pedidos->obtener((int) $pedido['id'])) : null,
            'resultado' => ['cliente_id' => (string) $alta['negocio_id'], 'prueba_hasta' => $hasta,
                            'modulos' => $modsDemo ? Modulos::conDependencias($modsDemo) : 'todos',
                            'acceso' => ['url_panel' => self::urlLogin(), 'usuario' => $email, 'clave' => $clave],
                            'enlace_publico' => $enlace, 'mensaje_whatsapp' => $msg,
                            'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg) : null,
                            'correo_enviado' => false],   // TuRenta todavía no envía correo de bienvenida
            'auditoria' => "Demo creada: $nombre (plan $plan, demo hasta $hasta" . ($modsDemo ? ', módulos ' . implode(',', Modulos::conDependencias($modsDemo)) : ', todos los módulos')
                           . ')' . ($pedido ? ' · pedido de demo N.º ' . $pedido['id'] : ''),
        ];
    }

    private function opExtenderPrueba(int $id, array $d): array
    {
        $dias = $this->entero($d, 'dias', null, 1, 90, 'datos.');
        $c = $this->cliente($this->fila($id));
        if (!$this->puedeExtender($c)) {
            throw new TukanCentralError('no_permitido', 'Solo se alarga la demo de quien está en demo o la terminó sin pagar nunca.', 403, null,
                                        ['motivo' => 'ya_paga', 'estado' => $c['estado']]);
        }
        $hasta = $this->panel->extenderPrueba($id, $dias, $this->hoy);
        // Reinicia los avisos de la prueba: los recordatorios viejos ya no aplican
        $this->db->prepare("DELETE FROM tukan_central_atenciones WHERE solicitud_id LIKE ?")->execute(["seguimiento_prueba:$id:%"]);
        return ['mensaje' => "Demo de {$c['nombre']} hasta el " . self::fechaTexto($hasta) . '.',
                'resultado' => ['prueba_hasta' => $hasta], 'auditoria' => "Demo extendida $dias días, hasta $hasta"];
    }

    private function opRegistrarPago(int $id, array $d): array
    {
        $n = $this->fila($id);
        [$plan, $periodo] = $this->planPeriodo($d, (string) $n['plan_codigo'], 'datos.');
        $metodo = $d['metodo'] ?? null;
        if (!is_string($metodo) || !isset(PanelTukan::METODOS[$metodo])) throw TukanCentralError::campo('datos.metodo', 'Elige la forma de pago.');
        $monto = $this->dineroOpc($d, 'monto', 'datos.');
        $fecha = $this->fecha($d, 'fecha', 'datos.') ?? $this->hoy->format('Y-m-d');
        if ($fecha > $this->hoy->format('Y-m-d') || $fecha < $this->hoy->modify('-366 days')->format('Y-m-d')) {
            throw TukanCentralError::campo('datos.fecha', 'La fecha del pago debe ser de hoy o del último año.');
        }
        $inicio = $this->fecha($d, 'inicio', 'datos.') ?? $this->planes->inicioSugerido($id, $this->hoy)->format('Y-m-d');
        $nota = $this->texto($d, 'nota', 200, 'datos.');
        $dominio = $this->texto($d, 'dominio', 120, 'datos.');
        if ($dominio !== '' && !$this->planes->periodos()[$periodo]['dominio']) {
            throw TukanCentralError::campo('datos.dominio', 'El dominio propio solo viene con el pago anual.');
        }
        try {
            $r = $this->panel->registrarPago($id, ['plan' => $plan, 'periodo' => $periodo, 'inicio' => $inicio, 'metodo' => $metodo,
                                                   'monto' => $monto === null ? '' : number_format($monto, 2, '.', ''), 'dominio' => $dominio, 'fecha' => $fecha]);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $campo = str_contains($e->getMessage(), 'dominio') ? 'datos.dominio' : (str_contains($e->getMessage(), 'monto') ? 'datos.monto' : null);
            throw new TukanCentralError('invalido', $e->getMessage(), 422, $campo);
        }
        $hasta = (string) $this->db->query('SELECT activo_hasta FROM negocios WHERE id = ' . $id)->fetchColumn();
        $montoFinal = round((float) $r['monto'], 2);
        return ['mensaje' => "Pago registrado. {$n['nombre']} queda activo hasta el " . self::fechaTexto($hasta) . '.',
                'resultado' => ['pago_id' => (string) $r['id'], 'activo_hasta' => $hasta, 'monto' => $montoFinal, 'comision' => null],
                'auditoria' => "Pago registrado: $plan $periodo " . self::dinero($montoFinal) . ' (' . PanelTukan::METODOS[$metodo] . ") · pagó el $fecha"
                               . ($nota !== '' ? " · $nota" : '')];
    }

    private function opAnularPago(int $id, array $d): array
    {
        $pagoId = $d['pago_id'] ?? null;
        if (!(is_int($pagoId) || (is_string($pagoId) && preg_match('/^\d{1,10}$/', $pagoId)))) throw TukanCentralError::campo('datos.pago_id', 'Falta el pago.');
        $motivo = $this->textoReq($d, 'motivo', 5, 200);
        $st = $this->db->prepare('SELECT * FROM suscripciones WHERE id = ? AND negocio_id = ?');
        $st->execute([(int) $pagoId, $id]);
        $s = $st->fetch();
        if (!$s) throw new TukanCentralError('no_encontrado', 'Ese pago no es de este negocio o ya no existe.', 404);
        if ($s['anulada']) throw new TukanCentralError('conflicto', 'Ese pago ya estaba anulado.', 409, null, ['motivo' => 'ya_anulado']);
        $this->planes->anularPago((int) $pagoId);
        $f = $this->db->query("SELECT estado, activo_hasta FROM negocios WHERE id = $id")->fetch();
        $aviso = '';
        if ($f['activo_hasta'] === null && $f['estado'] === 'activo') {   // no quedan pagos: vuelve a su prueba (ya terminada o no)
            $this->db->exec("UPDATE negocios SET estado = 'prueba' WHERE id = $id");
            $aviso = ' No le quedan pagos: vuelve al estado de demo.';
        }
        return ['mensaje' => 'Pago anulado.' . ($f['activo_hasta'] ? ' Queda pagado hasta el ' . self::fechaTexto($f['activo_hasta']) . '.' : '') . $aviso,
                'resultado' => ['activo_hasta' => $f['activo_hasta']],
                'auditoria' => "Pago anulado N.º {$s['id']} (" . self::dinero((float) $s['monto']) . "): $motivo"];
    }

    private function opEstado(int $id, array $d, string $estado): array
    {
        $motivo = $this->textoReq($d, 'motivo', 5, 200);
        $n = $this->fila($id);
        $ya = $estado === 'suspendido' ? in_array($n['estado'], ['suspendido', 'cancelado'], true) : $n['estado'] === 'cancelado';
        $palabra = $estado === 'suspendido' ? 'suspendido' : 'cancelado';
        if ($ya) {
            return ['mensaje' => "{$n['nombre']} ya estaba $palabra.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => ucfirst($palabra) . ": $motivo"];
        }
        $this->panel->cambiarEstado($id, $estado);
        if ($estado === 'suspendido') {   // sin tipo: el cliente ve el texto genérico de "cuenta pausada"
            $this->db->prepare('UPDATE negocios SET pausa_tipo = NULL, pausa_motivo = ?, pausa_en = now() WHERE id = ?')->execute([$motivo, $id]);
        }
        return ['mensaje' => "{$n['nombre']} quedó $palabra. Sus datos se guardan.", 'resultado' => ['estado' => 'suspendido'],
                'auditoria' => ($estado === 'suspendido' ? 'Suspendido' : 'Cancelado') . ": $motivo"];
    }

    private function opReactivar(int $id): array
    {
        $n = $this->fila($id);
        if (!in_array($n['estado'], ['suspendido', 'cancelado'], true)) {
            return ['mensaje' => "{$n['nombre']} no estaba suspendido.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => 'Reactivar'];
        }
        $this->panel->cambiarEstado($id, $n['activo_hasta'] ? 'activo' : 'prueba');
        $nuevo = $this->clasificar($this->fila($id))['estado'];
        return ['mensaje' => "{$n['nombre']} reactivado: queda " . self::ESTADOS_V2_TEXTO[$nuevo] . '.', 'resultado' => ['estado' => $nuevo],
                'auditoria' => 'Reactivado (' . ($n['activo_hasta'] ? 'activo' : 'prueba') . ')'];
    }

    // --- Anexo A: pausar, borrar (papelera) y restaurar ---

    private function opPausar(int $id, array $d): array
    {
        $tipo = $d['tipo'] ?? null;
        if (!is_string($tipo) || !isset(Papelera::TIPOS_PAUSA[$tipo])) {
            throw TukanCentralError::campo('datos.tipo', 'Elige el tipo de pausa: ' . implode(', ', array_keys(Papelera::TIPOS_PAUSA)) . '.');
        }
        $motivo = $this->textoReq($d, 'motivo', 3, 300);
        $n = $this->fila($id);
        if ($n['estado'] === 'cancelado') {
            throw new TukanCentralError('conflicto', "{$n['nombre']} está cancelado: reactívalo antes de pausarlo.", 409, null, ['motivo' => 'cancelado']);
        }
        $nombreTipo = Papelera::TIPOS_PAUSA[$tipo];
        $cambio = (new Papelera($this->db))->pausar($id, $tipo, $motivo);
        $res = ['estado' => 'suspendido', 'pausa' => ['tipo' => $tipo, 'motivo' => $motivo, 'texto_cliente' => Papelera::textoPausa($tipo, $motivo)]];
        if (!$cambio) {
            return ['mensaje' => "{$n['nombre']} ya estaba pausado así.", 'sin_cambios' => true, 'resultado' => $res, 'auditoria' => "Pausado ($nombreTipo): $motivo"];
        }
        return ['mensaje' => "{$n['nombre']} quedó pausado ($nombreTipo). No puede trabajar y sus páginas dicen \"temporalmente no disponible\". Sus datos se guardan.",
                'resultado' => $res, 'auditoria' => ($n['estado'] === 'suspendido' ? 'Pausa cambiada' : 'Pausado') . " ($nombreTipo): $motivo"];
    }

    private function opBorrar(int $id, array $d, array $p): array
    {
        $n = $this->fila($id);
        $confirmar = $this->texto($d, 'confirmar', 200, 'datos.');
        if ($confirmar === '' || ($confirmar !== trim((string) $n['nombre']) && $confirmar !== (string) $id)) {
            throw TukanCentralError::campo('datos.confirmar', 'Para borrar, escribe el nombre exacto del negocio: ' . $n['nombre'] . '.');
        }
        $motivo = $this->textoReq($d, 'motivo', 3, 300);
        if (!empty($n['eliminado_en'])) {
            $hasta = Papelera::fechaPurga($n['eliminado_en']);
            return ['mensaje' => "{$n['nombre']} ya estaba en la papelera (se borra para siempre el " . self::fechaTexto($hasta) . ').', 'sin_cambios' => true,
                    'resultado' => ['copia' => $n['eliminado_copia'], 'borrar_definitivo_el' => $hasta],
                    'arriba' => ['copia' => $n['eliminado_copia'], 'borrar_definitivo_el' => $hasta], 'auditoria' => "Borrar: $motivo"];
        }
        $por = trim(($p['nombre'] ?: 'Panel Tukán Central') . ' <' . $p['email'] . '>');
        $c = (new Papelera($this->db))->borrar($id, $motivo, $por);
        $res = ['copia' => $c['ruta'], 'borrar_definitivo_el' => $c['borrar_definitivo_el'], 'tablas' => count($c['tablas']),
                'filas' => array_sum($c['tablas']), 'archivos' => $c['archivos']];
        return ['mensaje' => "{$n['nombre']} está en la papelera: ya no puede entrar y sus páginas no existen. Se puede restaurar hasta el "
                             . self::fechaTexto((new DateTimeImmutable($c['borrar_definitivo_el']))->modify('-1 day')->format('Y-m-d'))
                             . '; el ' . self::fechaTexto($c['borrar_definitivo_el']) . ' se borra para siempre (la copia se guarda 1 año).',
                'resultado' => $res, 'arriba' => ['copia' => $c['ruta'], 'borrar_definitivo_el' => $c['borrar_definitivo_el']],
                'auditoria' => "Enviado a la papelera: $motivo · copia " . basename($c['ruta'])];
    }

    private function opRestaurar(int $id): array
    {
        $n = $this->fila($id);
        if (empty($n['eliminado_en'])) {
            return ['mensaje' => "{$n['nombre']} no está en la papelera.", 'sin_cambios' => true,
                    'resultado' => ['estado' => $this->clasificar($n)['estado']], 'auditoria' => 'Restaurar'];
        }
        if (Papelera::fechaPurga($n['eliminado_en']) <= $this->hoy->format('Y-m-d')) {
            throw new TukanCentralError('conflicto', 'Ya pasaron los ' . Papelera::DIAS_PAPELERA . ' días de la papelera: se está borrando para siempre.', 409, null,
                                        ['motivo' => 'papelera_vencida']);
        }
        (new Papelera($this->db))->restaurar($id);
        $estado = $this->clasificar($this->fila($id))['estado'];
        return ['mensaje' => "{$n['nombre']} salió de la papelera: queda " . self::ESTADOS_V2_TEXTO[$estado] . ', con todos sus datos.',
                'resultado' => ['estado' => $estado], 'auditoria' => 'Restaurado de la papelera'];
    }

    public const ESTADOS_V2_TEXTO = ['prueba' => 'en prueba', 'activo' => 'al día', 'por_vencer' => 'al día (vence pronto)', 'vencido' => 'vencido', 'suspendido' => 'suspendido'];

    private function opCambiarPlan(int $id, array $d): array
    {
        $plan = $d['plan'] ?? null;
        if (!is_string($plan) || !$this->planExiste($plan)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuRenta.');
        $n = $this->fila($id);
        if ($n['plan_codigo'] === $plan) {
            return ['mensaje' => "{$n['nombre']} ya tenía ese plan.", 'sin_cambios' => true, 'resultado' => ['plan' => $plan], 'auditoria' => "Plan $plan"];
        }
        $this->panel->cambiarPlan($id, $plan);
        return ['mensaje' => "Plan cambiado a $plan (sin cobrar).", 'resultado' => ['plan' => $plan], 'auditoria' => "Plan cambiado de {$n['plan_codigo']} a $plan"];
    }

    private function opNuevaClave(int $id): array
    {
        try {
            $r = $this->panel->nuevaClaveDueno($id);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new TukanCentralError('no_permitido', $e->getMessage(), 403);
        }
        $primer = explode(' ', (string) $r['nombre'])[0];
        $msg = "Hola $primer, tu acceso a TuRenta: " . self::urlLogin() . " · Correo: {$r['email']} · Contraseña temporal: {$r['clave']} (cámbiala en Mi cuenta)";
        return ['mensaje' => 'Contraseña nueva creada. La anterior ya no funciona.',
                'resultado' => ['usuario' => $r['email'], 'clave' => $r['clave'], 'mensaje_whatsapp' => $msg],
                'auditoria' => 'Nueva contraseña para el dueño'];
    }

    private function opNota(int $id, array $d): array
    {
        $texto = $d['texto'] ?? '';
        if (!is_string($texto) || mb_strlen($texto) > 4000) throw TukanCentralError::campo('datos.texto', 'La nota puede tener hasta 4000 letras.');
        $antes = (string) ($this->fila($id)['notas_admin'] ?? '');
        if (trim($texto) === trim($antes)) return ['mensaje' => 'La nota no cambió.', 'sin_cambios' => true, 'resultado' => ['notas_admin' => $antes], 'auditoria' => 'Notas internas'];
        $this->panel->guardarNotas($id, $texto);
        return ['mensaje' => trim($texto) === '' ? 'Nota borrada.' : 'Nota guardada.', 'resultado' => ['notas_admin' => mb_substr(trim($texto), 0, 4000)],
                'auditoria' => 'Notas internas actualizadas'];
    }

    private function opAtender(array $d, array $p): array
    {
        $sid = $d['solicitud_id'] ?? null;
        if (!is_string($sid) || !preg_match(self::ID, $sid)) throw TukanCentralError::campo('datos.solicitud_id', 'Falta la solicitud.');
        $resultado = $d['resultado'] ?? null;
        if (!is_string($resultado) || !in_array($resultado, self::RESULTADOS, true)) {
            throw TukanCentralError::campo('datos.resultado', 'Elige el resultado: en proceso, atendida o descartada.');
        }
        $nota = $this->texto($d, 'nota', 300, 'datos.');
        $dominio = strtolower($this->texto($d, 'dominio', 120, 'datos.'));
        $s = $this->solicitud(['id' => $sid]);
        if ($s['tipo'] === 'demo_pedida') return $this->atenderPedidoDemo($s, $resultado, $nota);
        if ($s['tipo'] === 'cambio_modulos') return $this->atenderPedidoModulos($s, $resultado, $nota, $p);
        $id = (int) $s['cliente_id'];
        $this->bloquear($id);
        $actual = $s['estado'];
        $guardarDominio = $s['tipo'] === 'dominio_gratis' && $dominio !== '';
        if ($actual === $resultado && !$guardarDominio) {
            return ['cliente_id' => $id, 'mensaje' => 'La solicitud ya estaba así.', 'sin_cambios' => true, 'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                    'auditoria' => "Solicitud {$s['id']}: $resultado"];
        }
        if (in_array($actual, ['atendida', 'descartada'], true) && $actual !== $resultado) {
            throw new TukanCentralError('conflicto', 'Esta solicitud ya fue ' . ($actual === 'atendida' ? 'atendida' : 'descartada') . '.', 409, null,
                                        ['motivo' => 'ya_atendida', 'estado' => $actual]);
        }
        if ($dominio !== '' && $s['tipo'] !== 'dominio_gratis') throw TukanCentralError::campo('datos.dominio', 'El dominio solo va en un dominio incluido.');
        if ($guardarDominio) {
            if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $dominio)) throw TukanCentralError::campo('datos.dominio', 'El dominio no es válido (ej. quintalosarrayanes.com).');
            $this->db->prepare('UPDATE suscripciones SET dominio = ? WHERE id = ? AND dominio IS NULL')->execute([$dominio, (int) $s['detalle']['pago_id']]);
            if ($nota === '') $nota = "Dominio: $dominio";
        }
        $this->db->prepare('INSERT INTO tukan_central_atenciones (solicitud_id, tipo, estado, nota, email, nombre, momento) VALUES (?,?,?,?,?,?, now())
                            ON CONFLICT (solicitud_id) DO UPDATE SET estado = EXCLUDED.estado, nota = EXCLUDED.nota, email = EXCLUDED.email,
                                                                     nombre = EXCLUDED.nombre, momento = now()')
                 ->execute([$sid, $s['tipo'], $resultado, $nota !== '' ? $nota : null, $p['email'], $p['nombre'] ?: null]);
        $s['estado'] = $s['estado_producto'] = $resultado;
        $s['actualizado'] = (new DateTimeImmutable('now'))->format('c');
        $s['detalle']['atencion'] = ['estado' => $resultado, 'nota' => $nota !== '' ? $nota : null, 'por' => trim($p['nombre'] . ' <' . $p['email'] . '>'),
                                     'momento' => $s['actualizado']];
        $texto = ['en_proceso' => 'en proceso', 'atendida' => 'atendida', 'descartada' => 'descartada'][$resultado];
        return ['cliente_id' => $id, 'mensaje' => "Solicitud marcada como $texto." . ($guardarDominio ? " Dominio $dominio guardado." : ''),
                'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                'auditoria' => "Solicitud «{$s['titulo']}» marcada como $texto" . ($nota !== '' ? " · $nota" : '')];
    }

    /** demo_pedida: en_proceso = contactado; descartada; atendida solo con crear_demo. */
    private function atenderPedidoDemo(array $s, string $resultado, string $nota): array
    {
        $pid = (int) substr($s['id'], strlen('demo_pedida:'));
        if ($s['estado'] === $resultado) {
            return ['mensaje' => 'La solicitud ya estaba así.', 'sin_cambios' => true, 'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                    'auditoria' => "Solicitud {$s['id']}: $resultado"];
        }
        if (in_array($s['estado'], ['atendida', 'descartada'], true)) {
            throw new TukanCentralError('conflicto', 'Este pedido de demo ya fue ' . ($s['estado'] === 'atendida' ? 'atendido (tiene su demo)' : 'descartado') . '.', 409, null,
                                        ['motivo' => 'ya_atendida', 'estado' => $s['estado']]);
        }
        if ($resultado === 'atendida') throw TukanCentralError::campo('datos.resultado', 'Un pedido de demo se atiende creando la demo (crear_demo), o se descarta.');
        (new PedidosDemo($this->db))->marcar($pid, $resultado === 'en_proceso' ? 'contactada' : 'descartada', $nota);
        $this->olvidar();
        $s = $this->solicitud(['id' => $s['id']]);
        return ['mensaje' => $resultado === 'en_proceso' ? 'Pedido de demo marcado como contactado.' : 'Pedido de demo descartado.',
                'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                'auditoria' => "Pedido de demo «{$s['quien']['organizacion']}» " . ($resultado === 'en_proceso' ? 'contactado' : 'descartado') . ($nota !== '' ? " · $nota" : '')];
    }

    /** cambio_modulos: atendida = aprobar (encender), descartada = rechazar. */
    private function atenderPedidoModulos(array $s, string $resultado, string $nota, array $p): array
    {
        $id = (int) $s['cliente_id'];
        $this->bloquear($id);
        if ($s['estado'] === $resultado || ($resultado === 'en_proceso' && $s['estado'] === 'pendiente')) {
            return ['cliente_id' => $id, 'mensaje' => 'La solicitud ya estaba así.', 'sin_cambios' => true, 'resultado' => ['solicitud' => $s], 'solicitud' => $s,
                    'auditoria' => "Solicitud {$s['id']}: $resultado"];
        }
        if ($s['estado'] !== 'pendiente') {
            throw new TukanCentralError('conflicto', 'Este pedido de módulos ya fue ' . ($s['estado'] === 'atendida' ? 'aprobado' : 'rechazado') . '.', 409, null,
                                        ['motivo' => 'ya_atendida', 'estado' => $s['estado']]);
        }
        if ($resultado === 'atendida' && ($p['rol'] ?? null) !== 'dueno') {
            throw new TukanCentralError('no_permitido', 'Solo el dueño del Panel Tukán Central puede aprobar módulos.', 403, null, ['motivo' => 'rol']);
        }
        $por = trim(($p['nombre'] ?: 'Panel Tukán Central') . ' <' . $p['email'] . '>');
        $r = $this->modulos->atender((int) substr($s['id'], strlen('cambio_modulos:')), $resultado === 'atendida' ? 'aprobada' : 'rechazada', $nota, $por);
        $this->olvidar();
        $s = $this->solicitud(['id' => $s['id']]);
        return ['cliente_id' => $id, 'mensaje' => $resultado === 'atendida' ? 'Pedido aprobado. ' . $r['texto'] . '.' : 'Pedido de módulos rechazado.',
                'resultado' => ['solicitud' => $s, 'modulos' => $this->modulosDe($id)], 'solicitud' => $s,
                'auditoria' => 'Pedido de módulos ' . ($resultado === 'atendida' ? 'aprobado: ' . $r['texto'] : 'rechazado') . ($nota !== '' ? " · $nota" : '')];
    }

    /** Lista de ids de módulos (lista JSON o texto "a,b,c"). $vacioNull: vacío → null (todos). */
    private function idsModulos(mixed $v, string $campo, bool $vacioNull = false): ?array
    {
        if ($v === null) return $vacioNull ? null : [];
        if (is_string($v)) $v = $v === '' ? [] : array_map('trim', explode(',', $v));
        if (!is_array($v) || !array_is_list($v) || count($v) > 50) throw TukanCentralError::campo($campo, 'La lista de módulos no es válida.');
        $ids = [];
        foreach ($v as $m) {
            if (!is_string($m) || !Modulos::existe($m)) throw TukanCentralError::campo($campo, 'Ese módulo no existe en TuRenta: ' . (is_string($m) ? mb_substr($m, 0, 30) : '?') . '.');
            $ids[] = $m;
        }
        $ids = array_values(array_unique($ids));
        return $ids === [] && $vacioNull ? null : $ids;
    }

    /** Anexo B: encender y apagar módulos de un cliente, precio acordado. Si viene solicitud_id (cambio_modulos), queda aprobada. */
    private function opCambiarModulos(int $id, array $d, array $p): array
    {
        $encender = $this->idsModulos($d['encender'] ?? null, 'datos.encender');
        $apagar = $this->idsModulos($d['apagar'] ?? null, 'datos.apagar');
        $acordado = false;
        if (array_key_exists('precio_acordado', $d)) {
            $acordado = $d['precio_acordado'] === null || $d['precio_acordado'] === '' ? null : $this->dineroOpc($d, 'precio_acordado', 'datos.');
        }
        $motivo = $this->texto($d, 'motivo', 300, 'datos.');
        $sol = $d['solicitud_id'] ?? null;
        $pedido = null;
        if ($sol !== null && $sol !== '') {
            if (!is_string($sol) || !preg_match('/^cambio_modulos:(\d{1,10})$/', $sol, $mm)) throw TukanCentralError::campo('datos.solicitud_id', 'La solicitud no es un pedido de módulos.');
            $pedido = $this->modulos->solicitud((int) $mm[1]);
            if (!$pedido || (int) $pedido['negocio_id'] !== $id) throw new TukanCentralError('no_encontrado', 'Ese pedido de módulos no es de este cliente.', 404);
        }
        if (!$encender && !$apagar && $acordado === false && !$pedido) throw TukanCentralError::campo('datos.encender', 'Di qué módulos encender o apagar.');
        $n = $this->fila($id);
        try {
            $r = $this->modulos->cambiar($id, $encender, $apagar, $acordado);
        } catch (RuntimeException $e) {
            throw new TukanCentralError('invalido', $e->getMessage(), 422, str_contains($e->getMessage(), 'apagar') ? 'datos.apagar' : 'datos.encender');
        }
        $solicitud = null;
        if ($pedido && $pedido['estado'] === 'pendiente') {
            $por = trim(($p['nombre'] ?: 'Panel Tukán Central') . ' <' . $p['email'] . '>');
            $this->db->prepare("UPDATE modulos_solicitudes SET estado = 'aprobada', respuesta = ?, atendido_en = now(), atendido_por = ? WHERE id = ? AND estado = 'pendiente'")
                     ->execute([$motivo !== '' ? $motivo : null, mb_substr($por, 0, 200), (int) $pedido['id']]);
        }
        $this->olvidar();
        if ($pedido) $solicitud = $this->solicitud(['id' => 'cambio_modulos:' . (int) $pedido['id']]);
        $m = $this->modulosDe($id);
        $sinCambios = $r['sin_cambios'] && !($pedido && $pedido['estado'] === 'pendiente');
        $texto = $r['texto'] . ($r['automaticos'] ? '' : '');
        return ['mensaje' => ($sinCambios ? "{$n['nombre']} ya tenía esos módulos." : "Módulos de {$n['nombre']} guardados. $texto.")
                             . ' Paga ' . self::dinero($m['mensual']) . ' al mes.',
                'sin_cambios' => $sinCambios, 'solicitud' => $solicitud,
                'resultado' => ['modulos' => $m, 'encendidos' => $r['encendidos'], 'apagados' => $r['apagados'], 'automaticos' => $r['automaticos']],
                'arriba' => ['modulos' => $m],
                'auditoria' => 'Módulos: ' . $texto . ' · ' . self::dinero($m['mensual']) . ' al mes' . ($motivo !== '' ? " · $motivo" : '')
                               . ($pedido ? ' · pedido N.º ' . $pedido['id'] : '')];
    }

    /** Anexo B: editar el catálogo de módulos (precio, descripción, orden) y los módulos de cada plan. */
    private function opGuardarModulos(array $d): array
    {
        $mods = $d['modulos'] ?? [];
        $planes = $d['planes'] ?? [];
        if (!is_array($mods) || !is_array($planes)) throw TukanCentralError::campo('datos.modulos', 'Los datos del catálogo no son válidos.');
        $porId = [];
        foreach ($mods as $k => $x) {   // lista [{id,…}] u objeto {id: {…}}
            if (!is_array($x)) throw TukanCentralError::campo('datos.modulos', 'Los datos del catálogo no son válidos.');
            $mid = array_is_list($mods) ? ($x['id'] ?? null) : $k;
            if (!is_string($mid) || !Modulos::existe($mid)) throw TukanCentralError::campo('datos.modulos', 'Ese módulo no existe en TuRenta: ' . (is_string($mid) ? mb_substr($mid, 0, 30) : '?') . '.');
            $porId[$mid] = array_intersect_key($x, array_flip(['precio_mensual', 'descripcion', 'orden']));
        }
        $porPlan = [];
        foreach ($planes as $k => $x) {   // lista [{codigo, modulos}] u objeto {codigo: [ids] | "*"}
            $codigo = array_is_list($planes) ? (is_array($x) ? ($x['codigo'] ?? null) : null) : $k;
            $lista = array_is_list($planes) ? (is_array($x) ? ($x['modulos'] ?? null) : null) : $x;
            if (!is_string($codigo) || !$this->planExiste($codigo)) throw TukanCentralError::campo('datos.planes', 'Ese plan no existe en TuRenta.');
            $porPlan[$codigo] = $lista === '*' ? '*' : $this->idsModulos($lista, 'datos.planes');
        }
        if (!$porId && !$porPlan) throw TukanCentralError::campo('datos.modulos', 'No hay nada que guardar.');
        try {
            $cambios = $this->modulos->guardarCatalogo($porId, $porPlan);
        } catch (RuntimeException $e) {
            throw new TukanCentralError('invalido', $e->getMessage(), 422, 'datos.modulos');
        }
        $this->olvidar();
        return ['mensaje' => $cambios ? "Catálogo de módulos guardado ($cambios cambios)." : 'El catálogo no cambió.', 'sin_cambios' => $cambios === 0,
                'resultado' => $this->modulosV2(), 'auditoria' => "Catálogo de módulos guardado ($cambios cambios)"];
    }

    /** Anexo C.2: tope de gasto de IA del negocio en USD al mes (null o vacío = el de siempre según tenga o no «Asistente IA»). */
    private function opIaTope(int $id, array $d): array
    {
        foreach (array_keys($d) as $k) {
            if (!in_array($k, ['tope_usd', 'cliente_id'], true)) throw TukanCentralError::campo('datos.' . mb_substr((string) $k, 0, 40), 'Campo desconocido.');
        }
        if (!array_key_exists('tope_usd', $d)) throw TukanCentralError::campo('datos.tope_usd', 'Falta el tope (número en dólares, o null para el de siempre).');
        $v = $d['tope_usd'];
        if ($v === '' || $v === null) {
            $tope = null;
        } elseif (is_int($v) || is_float($v)) {
            if ($v < 0 || $v > 1000) throw TukanCentralError::campo('datos.tope_usd', 'El tope debe estar entre 0 y 1000 dólares al mes.');
            $tope = round((float) $v, 2);
        } else {
            throw TukanCentralError::campo('datos.tope_usd', 'Escribe el tope con números, por ejemplo 3.00 (o vacío para el de siempre).');
        }
        $ia = new Ia($this->db, $this->modulos);
        [$antes, $despues] = $ia->cambiarTope($id, $tope);
        $efectivo = $ia->tope($id);
        $txt = fn(?float $t) => $t === null ? 'el de siempre' : self::dinero($t);
        $resultado = ['tope_usd' => $despues, 'tope_efectivo' => $efectivo, 'modulo' => $ia->tieneModulo($id), 'gastado_mes' => $ia->gastoMes($id)['costo']];
        if ($antes === $despues) {
            return ['mensaje' => 'El tope de IA no cambió (' . $txt($despues) . ').', 'sin_cambios' => true, 'resultado' => $resultado, 'auditoria' => 'Tope de IA'];
        }
        return ['mensaje' => 'Tope de IA: ' . $txt($despues) . ' (' . self::dinero($efectivo) . ' al mes).', 'resultado' => $resultado,
                'auditoria' => 'Tope de IA: ' . $txt($antes) . ' → ' . $txt($despues)];
    }

    // ------------------------------------------------------------------
    // ia_uso (Anexo C.1): gasto de IA por negocio del mes (solo lectura)
    // ------------------------------------------------------------------

    public function iaUso(array $d): array
    {
        foreach (array_keys($d) as $k) {
            if ($k !== 'mes') throw TukanCentralError::campo(mb_substr((string) $k, 0, 40), 'Campo desconocido.');
        }
        $mes = $d['mes'] ?? date('Y-m');
        if (!is_string($mes) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) throw TukanCentralError::campo('mes', 'Mes no válido (AAAA-MM).');
        return (new Ia($this->db, $this->modulos))->uso($mes);
    }

    private function opGuardarPlan(array $d): array
    {
        $codigo = $d['plan'] ?? null;
        if (!is_string($codigo) || !$this->planExiste($codigo)) throw TukanCentralError::campo('datos.plan', 'Ese plan no existe en TuRenta.');
        $st = $this->db->prepare('SELECT * FROM planes WHERE codigo = ?');
        $st->execute([$codigo]);
        $p = $st->fetch();
        $nombre = array_key_exists('nombre', $d) ? $this->textoReq($d, 'nombre', 2, 50) : (string) $p['nombre'];
        $precio = $this->dineroOpc($d, 'precio_mensual', 'datos.');
        if ($precio === null && isset($d['precios'])) {
            if (!is_array($d['precios']) || !array_is_list($d['precios'])) throw TukanCentralError::campo('datos.precios', 'Los precios no son válidos.');
            foreach ($d['precios'] as $i => $x) {
                if (is_array($x) && ($x['periodo'] ?? null) === 'mensual') $precio = $this->dineroOpc($x, 'precio', "datos.precios.$i.");
            }
        }
        $precio ??= (float) $p['precio_mensual'];
        $descripcion = array_key_exists('descripcion', $d) ? $this->texto($d, 'descripcion', 300, 'datos.') : (string) ($p['descripcion'] ?? '');
        $max = function (string $k) use ($d, $p): ?int {
            if (!array_key_exists($k, $d)) return $p[$k] === null ? null : (int) $p[$k];
            return $d[$k] === null || $d[$k] === '' ? null : $this->entero($d, $k, null, 1, 100000, 'datos.');
        };
        // Planes de $20 y $45 (migración 112): módulos a elegir (vacío = 0) y precio de cada módulo más (vacío = el del catálogo)
        $elegir = array_key_exists('modulos_elegir', $d)
            ? ($d['modulos_elegir'] === null || $d['modulos_elegir'] === '' ? 0 : $this->entero($d, 'modulos_elegir', null, 0, 50, 'datos.')) : null;
        $precioExtra = array_key_exists('precio_modulo_extra', $d) ? $this->dineroOpc($d, 'precio_modulo_extra', 'datos.') : false;
        $this->planes->guardarPlan($codigo, $nombre, $precio, $max('max_espacios'), $max('max_usuarios'), $descripcion, $elegir, $precioExtra);
        if (isset($d['ajustes'])) {
            if (!is_array($d['ajustes']) || array_is_list($d['ajustes'])) throw TukanCentralError::campo('datos.ajustes', 'Los ajustes no son válidos.');
            $this->planes->guardarAjustes(array_map('strval', array_merge($this->planes->ajustes(), $d['ajustes'])));
        }
        $st->execute([$codigo]);
        $cuantos = (int) $this->db->query('SELECT count(*) FROM negocios WHERE eliminado_en IS NULL AND plan_codigo = ' . $this->db->quote($codigo))->fetchColumn();
        return ['mensaje' => "Plan $nombre guardado (" . self::dinero($precio) . ' al mes).', 'resultado' => ['plan' => $this->plan($st->fetch(), $cuantos)],
                'auditoria' => "Plan $codigo guardado: $nombre " . self::dinero($precio) . ' al mes'];
    }

    // ------------------------------------------------------------------
    // Validación de campos (errores 422 con "campo")
    // ------------------------------------------------------------------

    private function idCliente(mixed $v, string $campo): int
    {
        if (!(is_int($v) || (is_string($v) && preg_match(self::ID, $v)))) throw TukanCentralError::campo($campo, 'Falta el cliente.');
        if (!preg_match('/^\d{1,10}$/', (string) $v) || !$this->fila((int) $v)) {
            throw new TukanCentralError('no_encontrado', 'Ese negocio ya no existe en TuRenta.', 404);
        }
        return (int) $v;
    }

    private function entero(array $d, string $k, ?int $defecto, int $min, int $max, string $ruta = ''): int
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            if ($defecto === null) throw TukanCentralError::campo($ruta . $k, "Falta el valor de \"$k\".");
            return $defecto;
        }
        if (is_float($v) && floor($v) === $v) $v = (int) $v;
        if (is_string($v) && preg_match('/^-?\d{1,9}$/', $v)) $v = (int) $v;
        if (!is_int($v) || $v < $min || $v > $max) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser un número de $min a $max.");
        return $v;
    }

    private function texto(array $d, string $k, int $max, string $ruta = ''): string
    {
        $v = $d[$k] ?? null;
        if ($v === null) return '';
        if (!is_string($v)) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser texto.");
        $v = trim($v);
        if (mb_strlen($v) > $max) throw TukanCentralError::campo($ruta . $k, "\"$k\" puede tener hasta $max letras.");
        return $v;
    }

    private function textoReq(array $d, string $k, int $min, int $max): string
    {
        $v = $this->texto($d, $k, $max, 'datos.');
        if (mb_strlen($v) < $min) throw TukanCentralError::campo('datos.' . $k, "Escribe \"$k\" (de $min a $max letras).");
        return $v;
    }

    private function lista(array $d, string $k, array $permitidos): array
    {
        $v = $d[$k] ?? [];
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn($x) => !is_string($x) || !in_array($x, $permitidos, true))) {
            throw TukanCentralError::campo($k, "\"$k\" debe ser una lista con: " . implode(', ', $permitidos) . '.');
        }
        return $v;
    }

    private function opcion(array $d, string $k, array $permitidos, string $defecto): string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return $defecto;
        if (!is_string($v) || !in_array($v, $permitidos, true)) throw TukanCentralError::campo($k, "\"$k\" debe ser uno de: " . implode(', ', $permitidos) . '.');
        return $v;
    }

    private function fecha(array $d, string $k, string $ruta = ''): ?string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        $f = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$f || $f->format('Y-m-d') !== $v) throw TukanCentralError::campo($ruta . $k, "\"$k\" debe ser una fecha como 2026-10-13.");
        return $v;
    }

    private function mes(array $d, string $k): ?string
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        $f = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m', $v) : false;
        if (!$f || $f->format('Y-m') !== $v) throw TukanCentralError::campo($k, "\"$k\" debe ser un mes como 2026-10.");
        return $v;
    }

    private function dineroOpc(array $d, string $k, string $ruta = ''): ?float
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') return null;
        if (!(is_int($v) || is_float($v))) throw TukanCentralError::campo($ruta . $k, 'Escribe el monto con números, por ejemplo 39.00.');
        if ($v < 0 || $v > 100000) throw TukanCentralError::campo($ruta . $k, 'El monto debe estar entre 0 y 100.000.');
        return round((float) $v, 2);
    }

    // ------------------------------------------------------------------
    // Ayudas: fechas, dinero y direcciones
    // ------------------------------------------------------------------

    private function diasHasta(string $fecha): int
    {
        return (int) $this->hoy->diff(new DateTimeImmutable(substr($fecha, 0, 10)))->format('%r%a');
    }

    public static function momento(?string $ts): ?string
    {
        return $ts ? (new DateTimeImmutable($ts))->format('c') : null;
    }

    private static function fechaTexto(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m/Y') : '';
    }

    private static function fechaCorta(?string $f): string
    {
        return $f ? (new DateTimeImmutable($f))->format('d/m') : '';
    }

    private static function dias(int $n): string
    {
        return $n === 1 ? '1 día' : "$n días";
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    /** Dirección pública de TuRenta (TURENTA_URL o el dominio del pedido, como url_completa()). */
    public static function urlBase(): string
    {
        $base = rtrim((string) (getenv('TURENTA_URL') ?: ''), '/');
        if ($base !== '') return $base;
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]+$/', $host)) return 'https://turenta.tukanec.com';
        $local = str_starts_with($host, '127.') || str_starts_with($host, 'localhost');
        return ($local ? 'http://' : 'https://') . $host;
    }

    public static function urlLogin(): string
    {
        return self::urlBase() . '/?r=login';
    }

    public static function urlSitio(string $slug): string
    {
        return self::urlBase() . '/?' . http_build_query(['r' => 'sitio', 's' => $slug]);
    }
}
