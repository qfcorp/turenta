<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Programa modular (PROTOCOLO_V2.md del Central, Anexo B): cada cliente tiene la BASE (siempre) y los MÓDULOS que paga.
 *  - Catálogo: nombre, ícono, dependencias y grupo están aquí (código); precio, descripción y orden se guardan en la
 *    tabla `modulos` (los cambia el súper usuario). Si falta una fila, vale lo de aquí.
 *  - Plan: precio base + lista de módulos (`planes.modulos`, "*" = todos).
 *  - Por negocio: `modulos_extra` (encendidos además del plan, se cobran) y `modulos_quitados` (del plan, apagados, se
 *    descuentan), `precio_acordado` (precio fijo al mes, o NULL) y `modulos_prueba` (en demo: NULL = todos encendidos).
 *  - Activos = (módulos del plan − quitados) + extra, respetando dependencias. En demo: todos (o los elegidos).
 *  - Planes de $20 y $45 (migración 112, decisión de Dimitry del 28 oct 2026): los primeros `planes.modulos_elegir` extra
 *    (en el orden del catálogo) van incluidos en el precio del plan y los demás cuestan `planes.precio_modulo_extra`
 *    (NULL = su precio del catálogo). Los de FUERA_DEL_CUPO (Asistente IA) no cuentan y van a su precio del catálogo.
 *  - Apagar un módulo NUNCA borra datos: solo se esconden sus pantallas, botones, portales, tareas y avisos.
 */
final class Modulos
{
    public const GRUPOS = [
        'ventas' => 'Ventas y contratos',
        'cliente' => 'Portal del cliente',
        'dia' => 'Día del evento',
        'gestion' => 'Proveedores y gestión',
        'conexiones' => 'Avisos y conexiones',
        'extras' => 'Extras',
    ];

    /** Lo que todo cliente tiene siempre (no se puede apagar). */
    public const BASE = [
        'Calendario sin doble reserva (lo impide el sistema) y próximos sábados',
        'Eventos con estados, seguimiento, tareas y lista de espera',
        'Clientes con su historial',
        'Espacios, fechas cerradas y catálogo de productos con precios',
        'Cotizaciones con IVA, descuento y versiones, en PDF con QR y con enlace para que el cliente la acepte',
        'Plan de pagos, cuotas, recibos en PDF y comprobantes',
        'Mensajes de WhatsApp del día listos para enviar',
        'Página pública del local con "¿está libre tu fecha?" y formulario de consulta',
        'Reportes de ventas, cobros, por cobrar y ocupación',
        'Usuarios con roles (dueño, vendedor, coordinador, consulta) y avisos en la campana',
        'App instalable en el celular',
    ];

    /**
     * Catálogo: id => [nombre, ícono, descripción para vender, precio al mes (USD), depende_de, grupo].
     * El orden de aquí es el orden por defecto.
     */
    public const CATALOGO = [
        'paquetes' => ['Paquetes y simulador', '🎁', 'Paquetes armados (Boda Oro, Quince años…) que cargan toda la cotización en un toque, con simulador de precio por número de invitados.', 3.0, [], 'ventas'],
        'contratos' => ['Contratos con firma', '✍️', 'Contrato con tu plantilla y variables que el cliente firma con el dedo en su celular, con huella y QR de verificación. Anexos por extras.', 6.0, [], 'ventas'],
        'portal_cliente' => ['Portal del cliente', '📱', 'Tu cliente tiene su propio enlace, sin claves: ve sus pagos, su contrato y sube comprobantes de transferencia.', 6.0, [], 'cliente'],
        'cobros_linea' => ['Cobros en línea (Stripe o Payphone)', '💳', 'Tu cliente paga sus cuotas con tarjeta desde el portal y el pago se registra solo.', 4.0, ['portal_cliente'], 'cliente'],
        'cuestionarios' => ['Formulario, extras y encuesta', '📝', 'Formulario del evento que el cliente llena en su portal, extras que pide en línea (hora extra, fotocabina…) y encuesta de satisfacción con NPS y reseña en Google.', 5.0, ['portal_cliente'], 'cliente'],
        'dia_evento' => ['Día del evento', '⏱️', 'Cronograma minuto a minuto con modo en vivo, personal del evento con su portal y confirmación, hoja del evento en PDF para todo el equipo.', 8.0, [], 'dia'],
        'invitados' => ['Invitados con QR', '🎟️', 'Invitación digital con confirmación, mesas, pegar la lista, portal de invitados para los novios y control de ingreso con QR en la puerta.', 8.0, [], 'dia'],
        'plano' => ['Plano del salón', '🗺️', 'Plano visual de cada espacio con el dedo o el mouse, plano de cada evento unido a las mesas de invitados y PDF a escala.', 6.0, [], 'dia'],
        'mobiliario' => ['Mobiliario y menaje', '🪑', 'Sillas, mesas, mantelería y vajilla: cuánto se reserva para cada evento, si alcanza cuando hay dos eventos el mismo día y cuánto cobrar por lo que se daña.', 5.0, [], 'dia'],
        'boda_vivo' => ['Boda en vivo', '🎥', 'Transmisión del evento (con QF Producciones o tu enlace de YouTube) con el botón "Ver en vivo" en el portal y en la invitación.', 5.0, ['dia_evento'], 'dia'],
        'proveedores' => ['Proveedores y compras', '🚚', 'Ficha del proveedor con documentos que vencen, órdenes de compra con PDF y QR, facturas (también del XML del SRI), pagos y cuentas por pagar con antigüedad.', 7.0, [], 'gestion'],
        'portal_proveedor' => ['Portal del proveedor', '🔗', 'Cada proveedor ve sus eventos y el cronograma, confirma órdenes, sube su factura y ve sus pagos y saldo.', 4.0, ['proveedores'], 'gestion'],
        'incidencias' => ['Incidencias y puntaje de proveedores', '⭐', 'Problemas de cada evento con foto y cómo se resolvieron, puntualidad y puntaje de cada proveedor que tú configuras.', 3.0, ['proveedores'], 'gestion'],
        'metas' => ['Metas y reportes avanzados', '🎯', 'Metas de ventas del local y por vendedor con su avance en el Inicio, embudo y conversión, rentabilidad por evento y comisiones de vendedores.', 4.0, [], 'gestion'],
        'telegram' => ['Avisos por Telegram', '📲', 'Pagos, firmas, consultas nuevas, cuotas por cobrar y seguimientos del día llegan solos al celular del equipo.', 3.0, [], 'conexiones'],
        'exportar' => ['Exportar a Excel y Google Calendar', '📤', 'Descarga eventos, cobros y reportes para Excel y ve tus eventos en Google Calendar.', 3.0, [], 'conexiones'],
        // Incluido en Completo. En Básico es un extra de $5 al mes que no cuenta entre los 3 módulos a elegir (migración 112).
        'asistente_ia' => ['Asistente IA', '✨', 'Lee fotos de documentos y llena los datos por usted, y responde las dudas al instante. Arma la cotización a partir del mensaje de WhatsApp del cliente.', 5.0, [], 'extras'],
    ];

    /**
     * Módulos que se dan solo a pedido: no entran en "*" (todos los módulos) ni en la demo con todos encendidos.
     * Un plan los trae si se nombran después del asterisco: "*,asistente_ia" (Completo, migraciones 111 y 112). En Básico
     * se encienden uno por uno como extra (súper usuario, Panel Tukán Central o pedido aprobado).
     */
    public const SOLO_A_PEDIDO = ['asistente_ia'];

    /**
     * Planes de $20 y $45 (migración 112): el plan Básico trae `planes.modulos_elegir` módulos a elección del cliente y cada
     * uno más cuesta `planes.precio_modulo_extra`. Estos módulos NO cuentan entre los que elige: siempre se cobran a su
     * precio del catálogo (Asistente IA $5 al mes).
     */
    public const FUERA_DEL_CUPO = ['asistente_ia'];

    /**
     * Módulo de cada ruta de public/index.php ('' = base: la tienen todos). Toda ruta debe estar aquí (lo revisa una prueba).
     * Las verificaciones de documentos ya emitidos (verificar, verificar_oc) son base: siempre deben funcionar.
     */
    public const RUTAS = [
        // Acceso y panel de Tukán
        'login' => '', 'registro' => '', 'privacidad' => '', 'admin_entrar' => '', 'telegram' => '', 'salir' => '',
        'admin' => '', 'admin_negocios' => '', 'admin_negocio' => '', 'admin_nuevo' => '', 'admin_planes' => '', 'admin_ver' => '',
        'admin_volver' => '', 'admin_salir' => '',
        // Base
        'inicio' => '', 'calendario' => '', 'eventos' => '', 'evento' => '', 'evento_nuevo' => '', 'evento_editar' => '',
        'evento_pagos' => '', 'clientes' => '', 'cliente' => '', 'seguimiento' => '', 'cobros' => '', 'mensajes' => '',
        'catalogo' => '', 'espacios' => '', 'reportes' => '', 'configuracion' => '', 'usuarios' => '', 'mi_cuenta' => '',
        'planes' => '', 'prueba_terminada' => '', 'notificaciones' => '', 'archivo' => '', 'pdf' => '', 'buscar' => '', 'mas' => '',
        'sitio' => '', 'consulta' => '', 'disponible' => '', 'imagen_publica' => '', 'verificar' => '', 'verificar_oc' => '',
        // Módulos
        'cotizacion' => '', 'cot' => '', 'paquetes' => 'paquetes',
        'contrato' => 'contratos', 'firmar' => 'contratos', 'firmar_anexo' => 'contratos',
        'portal' => 'portal_cliente', 'portal_pagar' => 'portal_cliente',
        'payphone' => 'cobros_linea', 'stripe' => 'cobros_linea', 'stripe_webhook' => 'cobros_linea',
        'reservar' => 'portal_cliente',
        'portal_cuestionario' => 'cuestionarios', 'portal_extras' => 'cuestionarios', 'encuesta' => 'cuestionarios',
        'evento_formulario' => 'cuestionarios', 'cuestionarios' => 'cuestionarios', 'extras' => 'cuestionarios',
        'evento_dia' => 'dia_evento', 'vivo' => 'dia_evento', 'staff' => 'dia_evento', 'personal' => 'dia_evento',
        'invitados' => 'invitados', 'invitacion' => 'invitados', 'puerta' => 'invitados', 'ingreso' => 'invitados', 'portal_invitados' => 'invitados',
        'plano' => 'plano',
        'mobiliario' => 'mobiliario',
        'proveedores' => 'proveedores', 'proveedor' => 'proveedores', 'proveedor_archivo' => 'proveedores', 'compras' => 'proveedores',
        'compra' => 'proveedores', 'por_pagar' => 'proveedores',
        'portal_proveedor' => 'portal_proveedor',
        'incidencias' => 'incidencias', 'puntaje_proveedores' => 'incidencias',
        'metas' => 'metas',
        'exportar' => 'exportar', 'ical' => 'exportar',
        // Ayuda e IA (migración 110): la Ayuda es de la base (todos los planes); el súper ve las preguntas frecuentes
        'ayuda' => '', 'admin_faq' => '', 'admin_demo_correo' => '',
        'ia_cotizacion' => 'asistente_ia',
    ];

    /** Rutas de un módulo que el router NO corta: Payphone vuelve con pagos ya iniciados (no se pueden perder). */
    public const SIN_CORTE = ['payphone', 'stripe', 'stripe_webhook'];

    /** Módulo de cada PDF (/?r=pdf&tipo=…). */
    public const PDFS = [
        'cotizacion' => '', 'contrato' => 'contratos', 'anexo' => 'contratos', 'recibo' => '', 'estado_cuenta' => '',
        'hoja' => 'dia_evento', 'cronograma' => 'dia_evento', 'invitados' => 'invitados', 'mesas' => 'invitados', 'plano' => 'plano',
        'orden_compra' => 'proveedores', 'estado_proveedor' => 'proveedores', 'formulario' => 'cuestionarios',
    ];

    /** Módulo de cada grupo de "Mensajes de hoy" (Cobranza::MENSAJES). */
    public const MENSAJES = [
        'cuota' => '', 'contrato' => 'contratos', 'anexo' => 'contratos', 'cotizacion' => '', 'staff' => 'dia_evento',
        'invitados' => '', 'cuestionario' => 'cuestionarios', 'resena' => '',
    ];

    /** Plan de cada cuenta nueva por defecto y los módulos de los planes que trae la instalación (migración 098). */
    public const PLAN_DEFECTO = 'quinta';

    private ?array $catalogoCache = null;
    private ?array $planesCache = null;

    public function __construct(private PDO $db) {}

    /** Vuelve a leer catálogo y planes de la base (después de guardarlos). */
    public function olvidar(): void
    {
        $this->catalogoCache = $this->planesCache = null;
    }

    // ------------------------------------------------------------------
    // Catálogo y planes
    // ------------------------------------------------------------------

    public static function existe(string $id): bool
    {
        return isset(self::CATALOGO[$id]);
    }

    public static function nombre(string $id): string
    {
        return self::CATALOGO[$id][0] ?? $id;
    }

    /** Lista "a,b,c" → ids válidos, sin repetir, en el orden del catálogo. "*" = todos. */
    public static function parse(?string $csv): array
    {
        $csv = trim((string) $csv);
        if ($csv === '*') return self::todos();
        if (str_starts_with($csv, '*')) {   // "*,asistente_ia" = todos + los nombrados
            return array_values(array_intersect(array_keys(self::CATALOGO), array_unique(array_merge(self::todos(), self::parse(ltrim(substr($csv, 1), ' ,'))))));
        }
        $ids = array_filter(array_map('trim', explode(',', $csv)), fn($x) => $x !== '');
        return array_values(array_intersect(array_keys(self::CATALOGO), $ids));
    }

    /** Los módulos que trae "*" (todos menos los que son solo a pedido). */
    public static function todos(): array
    {
        return array_values(array_diff(array_keys(self::CATALOGO), self::SOLO_A_PEDIDO));
    }

    /** Cómo se guarda la lista de un plan: "*" si trae todos, "*,asistente_ia" si además trae uno a pedido, o "a,b,c". */
    public static function csvPlan(array $ids): string
    {
        if (array_diff(self::todos(), $ids)) return self::csv($ids);
        $extra = array_values(array_intersect(self::SOLO_A_PEDIDO, $ids));
        return '*' . ($extra ? ',' . self::csv($extra) : '');
    }

    /** ¿Se vende por cotización (precio 0 en el catálogo)? */
    public static function porCotizacion(array $m): bool
    {
        return (float) ($m['precio_mensual'] ?? 0) <= 0;
    }

    public static function csv(array $ids): string
    {
        return implode(',', array_values(array_intersect(array_keys(self::CATALOGO), $ids)));
    }

    /** Catálogo con lo guardado en la base: id => [id, nombre, icono, descripcion, precio_mensual, depende_de, grupo, grupo_nombre, orden]. */
    public function catalogo(): array
    {
        if ($this->catalogoCache !== null) return $this->catalogoCache;
        $db = [];
        foreach ($this->db->query('SELECT id, precio_mensual, descripcion, orden FROM modulos')->fetchAll() as $f) $db[$f['id']] = $f;
        $r = [];
        $i = 0;
        foreach (self::CATALOGO as $id => [$nombre, $icono, $desc, $precio, $dep, $grupo]) {
            $f = $db[$id] ?? null;
            $r[$id] = ['id' => $id, 'nombre' => $nombre, 'icono' => $icono,
                       'descripcion' => ($f && trim((string) $f['descripcion']) !== '') ? (string) $f['descripcion'] : $desc,
                       'precio_mensual' => $f ? round((float) $f['precio_mensual'], 2) : $precio,
                       'depende_de' => $dep, 'grupo' => $grupo, 'grupo_nombre' => self::GRUPOS[$grupo],
                       'orden' => $f ? (int) $f['orden'] : ($i + 1) * 10];
            $i++;
        }
        $grupos = array_flip(array_keys(self::GRUPOS));
        uasort($r, fn($a, $b) => [$grupos[$a['grupo']], $a['orden'], $a['id']] <=> [$grupos[$b['grupo']], $b['orden'], $b['id']]);
        return $this->catalogoCache = $r;
    }

    /**
     * Planes con sus módulos: codigo => [codigo, nombre, precio_mensual, modulos (ids), todos (bool), modulos_elegir (int),
     * precio_modulo_extra (float|null = precio del catálogo)].
     */
    public function planes(): array
    {
        if ($this->planesCache !== null) return $this->planesCache;
        $r = [];
        foreach ($this->db->query('SELECT codigo, nombre, precio_mensual, modulos, modulos_elegir, precio_modulo_extra FROM planes ORDER BY precio_mensual, codigo')->fetchAll() as $p) {
            $r[$p['codigo']] = ['codigo' => (string) $p['codigo'], 'nombre' => (string) $p['nombre'], 'precio_mensual' => round((float) $p['precio_mensual'], 2),
                                'modulos' => self::parse($p['modulos']), 'todos' => str_starts_with(trim((string) $p['modulos']), '*'),
                                'modulos_elegir' => max(0, (int) $p['modulos_elegir']),
                                'precio_modulo_extra' => $p['precio_modulo_extra'] === null ? null : round((float) $p['precio_modulo_extra'], 2)];
        }
        return $this->planesCache = $r;
    }

    /** Agrega a la lista las dependencias (y las de ellas). */
    public static function conDependencias(array $ids): array
    {
        $r = [];
        $pend = array_values($ids);
        while ($pend) {
            $m = array_shift($pend);
            if (!isset(self::CATALOGO[$m]) || isset($r[$m])) continue;
            $r[$m] = true;
            foreach (self::CATALOGO[$m][4] as $d) $pend[] = $d;
        }
        return array_values(array_intersect(array_keys(self::CATALOGO), array_keys($r)));
    }

    /** Quita los que no tienen encendidas sus dependencias (hasta que no cambie nada). */
    public static function respetarDependencias(array $ids): array
    {
        $ids = array_values(array_intersect(array_keys(self::CATALOGO), $ids));
        do {
            $antes = count($ids);
            $ids = array_values(array_filter($ids, fn($m) => !array_diff(self::CATALOGO[$m][4], $ids)));
        } while (count($ids) !== $antes);
        return $ids;
    }

    /** Módulos encendidos que dependen de $id. */
    public static function dependientes(string $id, array $activos): array
    {
        return array_values(array_filter($activos, fn($m) => in_array($id, self::CATALOGO[$m][4] ?? [], true)));
    }

    // ------------------------------------------------------------------
    // Módulos de un negocio y su precio
    // ------------------------------------------------------------------

    private function filaNegocio(int $negocioId): ?array
    {
        $st = $this->db->prepare('SELECT id, nombre, plan_codigo, estado, prueba_hasta, modulos_extra, modulos_quitados, modulos_prueba, precio_acordado
                                    FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        return $st->fetch() ?: null;
    }

    /** ¿Está en demo (prueba)? En demo tiene todos los módulos, salvo que el súper usuario haya elegido otros. */
    public static function enDemo(array $n): bool
    {
        return ($n['estado'] ?? '') === 'prueba';
    }

    /**
     * Estado de los módulos de un negocio (forma de Anexo B.2 "cliente.modulos"):
     * del_plan, extra, quitados, activos, precio_plan, precio_extra, descuento, precio_acordado, mensual, demo, demo_todos,
     * y de los planes de $20 y $45 (migración 112): modulos_elegir, elegidos (extra incluidos en el plan), por_elegir y
     * precio_por_extra (id => lo que cuesta al mes; 0 = elegido).
     * $plan: calcularlo con otro plan (para cotizar un cambio de plan). $sumar: módulos que se suman como extra (para
     * saber cuánto pagaría con un pedido).
     */
    public function estado(int $negocioId, ?string $plan = null, array $sumar = []): array
    {
        $n = $this->filaNegocio($negocioId);
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        $planes = $this->planes();
        $codigo = $plan ?? (string) $n['plan_codigo'];
        $pl = $planes[$codigo] ?? null;
        if (!$pl) throw new RuntimeException('Plan no válido.');
        $cat = $this->catalogo();
        $delPlan = $pl['modulos'];
        $extra = array_values(array_diff(self::parse(self::csv(array_merge(self::parse($n['modulos_extra']), self::conDependencias($sumar)))), $delPlan));
        $quitados = array_values(array_intersect(self::parse($n['modulos_quitados']), $delPlan));
        $pagados = self::respetarDependencias(array_merge(array_diff($delPlan, $quitados), $extra));
        $demo = self::enDemo($n);
        $demoTodos = $demo && $n['modulos_prueba'] === null;
        $activos = $demo ? ($demoTodos ? array_values(array_unique(array_merge(self::todos(), array_intersect($pagados, self::SOLO_A_PEDIDO))))
                              : self::respetarDependencias(self::parse($n['modulos_prueba']))) : $pagados;
        $activos = array_values(array_intersect(array_keys($cat), $activos));   // en el orden del catálogo
        // Planes de $20 y $45 (migración 112): los primeros `modulos_elegir` extra (en el orden del catálogo) van en el precio
        // del plan; los demás cuestan `precio_modulo_extra` (o su precio del catálogo). Los de FUERA_DEL_CUPO, a su precio del catálogo.
        $elegir = $pl['modulos_elegir'] ?? 0;
        $precioFijo = $pl['precio_modulo_extra'] ?? null;
        $elegidos = array_slice(array_values(array_diff($extra, self::FUERA_DEL_CUPO)), 0, $elegir);
        $precioPorExtra = [];
        foreach ($extra as $m) {
            $precioPorExtra[$m] = in_array($m, $elegidos, true) ? 0.0
                : (in_array($m, self::FUERA_DEL_CUPO, true) || $precioFijo === null ? (float) $cat[$m]['precio_mensual'] : (float) $precioFijo);
        }
        $precioExtra = round(array_sum($precioPorExtra), 2);
        $descuento = round(min($pl['precio_mensual'], array_sum(array_map(fn($m) => $cat[$m]['precio_mensual'], $quitados))), 2);
        $acordado = $n['precio_acordado'] === null ? null : round((float) $n['precio_acordado'], 2);
        $calculado = round(max(0, $pl['precio_mensual'] + $precioExtra - $descuento), 2);
        return [
            'plan' => $codigo, 'del_plan' => $delPlan, 'extra' => $extra, 'quitados' => $quitados, 'activos' => $activos,
            'pagados' => array_values(array_intersect(array_keys($cat), $pagados)),   // lo que tendrá al pagar (en demo puede ser distinto de activos)
            'precio_plan' => $pl['precio_mensual'], 'precio_extra' => $precioExtra, 'descuento' => $descuento,
            'precio_acordado' => $acordado, 'calculado' => $calculado, 'mensual' => $acordado ?? $calculado,
            'demo' => $demo, 'demo_todos' => $demoTodos,
            'modulos_elegir' => $elegir, 'elegidos' => $elegidos, 'por_elegir' => max(0, $elegir - count($elegidos)),
            'precio_por_extra' => $precioPorExtra,
        ];
    }

    public function activos(int $negocioId): array
    {
        return $this->estado($negocioId)['activos'];
    }

    public function activo(int $negocioId, string $id): bool
    {
        if ($id === '') return true;
        return in_array($id, $this->activos($negocioId), true);
    }

    /** Precio al mes de un negocio (con otro plan, si se pide). */
    public function mensual(int $negocioId, ?string $plan = null): float
    {
        return $this->estado($negocioId, $plan)['mensual'];
    }

    /**
     * Enciende y apaga módulos de un negocio (súper usuario / Panel Tukán Central).
     * Encender uno enciende también sus dependencias; no se puede apagar uno del que depende otro que queda encendido.
     * $precioAcordado: false = no cambiar; null = quitar el precio acordado; número = precio fijo al mes.
     * Devuelve [estado, encendidos, apagados, automaticos (dependencias que se encendieron solas), sin_cambios, texto].
     */
    public function cambiar(int $negocioId, array $encender, array $apagar, float|null|false $precioAcordado = false): array
    {
        foreach (array_merge($encender, $apagar) as $m) {
            if (!is_string($m) || !self::existe($m)) throw new RuntimeException('Ese módulo no existe: ' . (is_string($m) ? mb_substr($m, 0, 30) : '?') . '.');
        }
        if ($inter = array_intersect($encender, $apagar)) throw new RuntimeException('No se puede encender y apagar a la vez: ' . self::nombre(reset($inter)) . '.');
        if ($precioAcordado !== false && $precioAcordado !== null && ($precioAcordado < 0 || $precioAcordado > 10000)) {
            throw new RuntimeException('El precio acordado no es válido.');
        }
        $antes = $this->estado($negocioId);
        $n = $this->filaNegocio($negocioId);
        // Configuración "pagada" de hoy (en demo se trabaja sobre ella, y si la demo tiene módulos elegidos, también sobre ellos)
        $base = $antes['pagados'];
        $conDep = self::conDependencias($encender);
        $automaticos = array_values(array_diff($conDep, $encender, $base));
        if ($chocan = array_intersect($automaticos, $apagar)) {
            $m = reset($chocan);
            throw new RuntimeException('No se puede apagar ' . self::nombre($m) . ': lo necesita ' . self::nombre(self::dependientes($m, $conDep)[0] ?? $encender[0]) . '.');
        }
        $nuevo = array_values(array_diff(array_unique(array_merge($base, $conDep)), $apagar));
        foreach ($apagar as $m) {
            if ($dep = self::dependientes($m, $nuevo)) {
                throw new RuntimeException('No se puede apagar ' . self::nombre($m) . ': lo necesita ' . self::nombre($dep[0]) . ' (apágalo también).');
            }
        }
        $nuevo = array_values(array_intersect(array_keys(self::CATALOGO), $nuevo));
        $extra = array_values(array_diff($nuevo, $antes['del_plan']));
        $quitados = array_values(array_diff($antes['del_plan'], $nuevo));
        $prueba = $n['modulos_prueba'];
        if ($antes['demo'] && $prueba !== null) {
            $prueba = self::csv(array_values(array_diff(array_unique(array_merge(self::parse($prueba), $conDep)), $apagar)));
        }
        $acordado = $precioAcordado === false ? $n['precio_acordado'] : $precioAcordado;
        $sinCambios = self::csv($extra) === self::csv($antes['extra']) && self::csv($quitados) === self::csv($antes['quitados'])
            && $prueba === $n['modulos_prueba']
            && ($acordado === null ? null : round((float) $acordado, 2)) === $antes['precio_acordado'];
        if (!$sinCambios) {
            $this->db->prepare('UPDATE negocios SET modulos_extra = ?, modulos_quitados = ?, modulos_prueba = ?, precio_acordado = ? WHERE id = ?')
                     ->execute([self::csv($extra), self::csv($quitados), $prueba, $acordado === null ? null : round((float) $acordado, 2), $negocioId]);
        }
        $despues = $this->estado($negocioId);
        $encendidos = array_values(array_diff($nuevo, $base));
        $apagados = array_values(array_diff($base, $nuevo));
        $partes = [];
        if ($encendidos) $partes[] = 'Encendidos: ' . implode(', ', array_map([self::class, 'nombre'], $encendidos));
        if ($apagados) $partes[] = 'Apagados: ' . implode(', ', array_map([self::class, 'nombre'], $apagados));
        if ($automaticos) $partes[] = 'Se encendieron también (los necesita): ' . implode(', ', array_map([self::class, 'nombre'], $automaticos));
        if ($precioAcordado !== false && $acordado !== $n['precio_acordado']) {
            $partes[] = $acordado === null ? 'Sin precio acordado' : 'Precio acordado $' . number_format((float) $acordado, 2, ',', '.') . ' al mes';
        }
        return ['estado' => $despues, 'encendidos' => $encendidos, 'apagados' => $apagados, 'automaticos' => $automaticos,
                'sin_cambios' => $sinCambios, 'texto' => $sinCambios ? 'Sin cambios en los módulos' : implode('. ', $partes)];
    }

    /** Elige qué módulos tiene la demo (al crearla desde el Panel Central). null = todos. Agrega dependencias. */
    public function elegirDemo(int $negocioId, ?array $ids): void
    {
        if ($ids !== null) {
            foreach ($ids as $m) if (!is_string($m) || !self::existe($m)) throw new RuntimeException('Ese módulo no existe: ' . (is_string($m) ? mb_substr($m, 0, 30) : '?') . '.');
            $ids = self::conDependencias($ids);
        }
        $this->db->prepare('UPDATE negocios SET modulos_prueba = ? WHERE id = ?')->execute([$ids === null ? null : self::csv($ids), $negocioId]);
    }

    /**
     * Guarda el catálogo (súper usuario): $modulos = id => [precio_mensual?, descripcion?, orden?]; $planes = codigo => [ids] o "*".
     * Devuelve la cantidad de cambios.
     */
    public function guardarCatalogo(array $modulos, array $planes = []): int
    {
        $this->olvidar();
        $cat = $this->catalogo();
        $cambios = 0;
        $st = $this->db->prepare('INSERT INTO modulos (id, precio_mensual, descripcion, orden, actualizado) VALUES (?,?,?,?, now())
                                  ON CONFLICT (id) DO UPDATE SET precio_mensual = EXCLUDED.precio_mensual, descripcion = EXCLUDED.descripcion,
                                                                 orden = EXCLUDED.orden, actualizado = now()');
        foreach ($modulos as $id => $x) {
            if (!is_string($id) || !isset($cat[$id])) throw new RuntimeException('Ese módulo no existe: ' . mb_substr((string) $id, 0, 30) . '.');
            if (!is_array($x)) throw new RuntimeException('Datos no válidos para ' . self::nombre($id) . '.');
            $precio = array_key_exists('precio_mensual', $x) ? $x['precio_mensual'] : $cat[$id]['precio_mensual'];
            if (is_string($precio)) $precio = str_replace(',', '.', trim($precio));
            if (!is_numeric($precio) || (float) $precio < 0 || (float) $precio > 10000) throw new RuntimeException('Revisa el precio de ' . self::nombre($id) . '.');
            $desc = array_key_exists('descripcion', $x) ? trim((string) $x['descripcion']) : $cat[$id]['descripcion'];
            if (mb_strlen($desc) > 400) throw new RuntimeException('La descripción de ' . self::nombre($id) . ' es muy larga (máximo 400).');
            if ($desc === self::CATALOGO[$id][2]) $desc = '';
            $orden = array_key_exists('orden', $x) ? $x['orden'] : $cat[$id]['orden'];
            if (!is_numeric($orden) || (int) $orden < 0 || (int) $orden > 10000) throw new RuntimeException('Revisa el orden de ' . self::nombre($id) . '.');
            $nuevo = [round((float) $precio, 2), $desc === '' ? self::CATALOGO[$id][2] : $desc, (int) $orden];
            if ($nuevo !== [$cat[$id]['precio_mensual'], $cat[$id]['descripcion'], $cat[$id]['orden']]) $cambios++;
            $st->execute([$id, $nuevo[0], $desc === '' ? null : $desc, $nuevo[2]]);
        }
        $actuales = $this->planes();
        $up = $this->db->prepare('UPDATE planes SET modulos = ? WHERE codigo = ?');
        foreach ($planes as $codigo => $ids) {
            if (!is_string($codigo) || !isset($actuales[$codigo])) throw new RuntimeException('Ese plan no existe: ' . mb_substr((string) $codigo, 0, 30) . '.');
            if ($ids === '*') {
                $valor = '*';
            } else {
                if (!is_array($ids)) throw new RuntimeException('Los módulos del plan ' . $actuales[$codigo]['nombre'] . ' no son válidos.');
                foreach ($ids as $m) if (!is_string($m) || !self::existe($m)) throw new RuntimeException('Ese módulo no existe: ' . (is_string($m) ? mb_substr($m, 0, 30) : '?') . '.');
                $ids = self::conDependencias($ids);
                $valor = self::csvPlan($ids);
            }
            $antes = self::csvPlan($actuales[$codigo]['modulos']);
            if ($valor !== $antes) $cambios++;
            $up->execute([$valor, $codigo]);
        }
        $this->olvidar();
        return $cambios;
    }

    // ------------------------------------------------------------------
    // Pedidos de módulos desde "Mi plan" (solicitud cambio_modulos del Panel Central)
    // ------------------------------------------------------------------

    /** El dueño pide encender un módulo. Si ya hay un pedido pendiente con ese módulo, devuelve ese (no repite). */
    public function pedir(int $negocioId, ?int $usuarioId, array $encender, string $nota = ''): int
    {
        $encender = array_values(array_unique($encender));
        if (!$encender) throw new RuntimeException('Elige el módulo que quieres.');
        foreach ($encender as $m) if (!is_string($m) || !self::existe($m)) throw new RuntimeException('Ese módulo no existe.');
        $pagados = $this->estado($negocioId);
        $ya = array_intersect($encender, $pagados['pagados']);
        if (count($ya) === count($encender)) throw new RuntimeException('Ese módulo ya está en tu plan.');
        $st = $this->db->prepare("SELECT id, encender FROM modulos_solicitudes WHERE negocio_id = ? AND estado = 'pendiente' ORDER BY id");
        $st->execute([$negocioId]);
        foreach ($st->fetchAll() as $s) {
            if (!array_diff($encender, self::parse($s['encender']))) return (int) $s['id'];
        }
        $st = $this->db->prepare("INSERT INTO modulos_solicitudes (negocio_id, usuario_id, encender, apagar, nota) VALUES (?,?,?, '', ?) RETURNING id");
        $st->execute([$negocioId, $usuarioId, self::csv(self::conDependencias($encender)), mb_substr(trim($nota), 0, 300) ?: null]);
        return (int) $st->fetchColumn();
    }

    public function solicitudesDe(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM modulos_solicitudes WHERE negocio_id = ? ORDER BY creado DESC, id DESC LIMIT 30');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Todos los pedidos (para el Panel Central): pendientes y los atendidos desde $desde. */
    public function solicitudes(?string $desde = null): array
    {
        $st = $this->db->prepare("SELECT s.*, u.nombre AS usuario_nombre, u.email AS usuario_email FROM modulos_solicitudes s
                                    JOIN negocios n ON n.id = s.negocio_id LEFT JOIN usuarios u ON u.id = s.usuario_id
                                   WHERE n.eliminado_en IS NULL AND (s.estado = 'pendiente' OR s.atendido_en >= ?::date)
                                   ORDER BY s.id");
        $st->execute([$desde ?? '1970-01-01']);
        return $st->fetchAll();
    }

    public function solicitud(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM modulos_solicitudes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Aprueba (enciende los módulos) o rechaza un pedido. Devuelve el resultado de cambiar() al aprobar, o null. */
    public function atender(int $id, string $resultado, string $respuesta, string $por, float|null|false $precioAcordado = false): ?array
    {
        if (!in_array($resultado, ['aprobada', 'rechazada'], true)) throw new RuntimeException('Resultado no válido.');
        $s = $this->solicitud($id);
        if (!$s) throw new RuntimeException('Ese pedido no existe.');
        if ($s['estado'] !== 'pendiente') throw new RuntimeException('Ese pedido ya fue ' . ($s['estado'] === 'aprobada' ? 'aprobado' : 'rechazado') . '.');
        $r = null;
        if ($resultado === 'aprobada') $r = $this->cambiar((int) $s['negocio_id'], self::parse($s['encender']), self::parse($s['apagar']), $precioAcordado);
        $this->db->prepare("UPDATE modulos_solicitudes SET estado = ?, respuesta = ?, atendido_en = now(), atendido_por = ? WHERE id = ? AND estado = 'pendiente'")
                 ->execute([$resultado, mb_substr(trim($respuesta), 0, 300) ?: null, mb_substr($por, 0, 200), $id]);
        return $r;
    }

    // ------------------------------------------------------------------
    // Para el Panel Central: qué módulos se venden
    // ------------------------------------------------------------------

    /** Por módulo: cuántos clientes que pagan lo tienen como extra y cuánto suma al mes (estimado). */
    public function porModulo(): array
    {
        $cat = $this->catalogo();
        $r = [];
        foreach ($cat as $id => $m) $r[$id] = ['modulo' => $id, 'nombre' => $m['nombre'], 'precio_mensual' => $m['precio_mensual'],
                                              'clientes_extra' => 0, 'clientes_activos' => 0, 'mensual_extra' => 0.0];
        $ids = $this->db->query("SELECT id FROM negocios WHERE eliminado_en IS NULL AND estado = 'activo'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $nid) {
            $e = $this->estado((int) $nid);
            foreach ($e['activos'] as $m) $r[$m]['clientes_activos']++;
            foreach ($e['extra'] as $m) {
                $r[$m]['clientes_extra']++;
                $r[$m]['mensual_extra'] += $e['precio_por_extra'][$m] ?? $cat[$m]['precio_mensual'];   // los elegidos del Básico valen 0 (migración 112)
            }
        }
        return array_values(array_map(function ($x) { $x['mensual_extra'] = round($x['mensual_extra'], 2); return $x; }, $r));
    }
}
