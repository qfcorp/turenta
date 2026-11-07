<?php
use TuRenta\{Anexos, Contratos, Cotizaciones, Eventos, Pagos, WhatsApp};

/**
 * Contrato del evento: /?r=contrato&id=<evento>[&k=<contrato>]
 *   Contrato vigente y su estado, generar (con una plantilla), editar el texto en borrador, enviar para firmar,
 *   enlace de firma (copiar + WhatsApp), PDF, anular con motivo, historial; si está firmado: firma, datos y verificación.
 * Sin id: plantillas de contrato del negocio (solo el dueño): lista, crear/editar, predeterminada, variables,
 *   restaurar la de fábrica y vista previa con datos de ejemplo.   (&plantilla=<id> | &plantilla=nueva | &previa=<id>)
 * Solo el dueño y los vendedores cambian algo; el usuario de consulta solo mira.
 */
$nid = (int) $u['negocio_id'];
$con = new Contratos(db());
$puede = es_comercial($u);
$dueno = es_admin_total($u);
$id = (int) ($_GET['id'] ?? 0);

/** Datos inventados para ver cómo queda una plantilla. */
$valoresEjemplo = function () use ($u): array {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$u['negocio_id']]);
    $n = $st->fetch();
    $fecha = (new DateTimeImmutable('today'))->modify('+120 days');
    $total = 8625.00;
    return [
        'negocio' => $n['nombre'], 'razon_social' => $n['razon_social'] ?: $n['nombre'], 'ruc' => $n['ruc'] ?: '1790000000001',
        'direccion_negocio' => $n['direccion'] ?: 'Av. Principal y Calle 2', 'ciudad' => $n['ciudad'] ?: 'Quito',
        'cliente' => 'Ana Lucía Torres Vega', 'identificacion' => '1712345678', 'telefono_cliente' => '0991234567',
        'direccion_cliente' => 'Calle Los Pinos N34-12', 'evento' => 'Boda de Ana y Luis', 'tipo_evento' => 'Boda',
        'fecha' => fecha_larga($fecha->format('Y-m-d')), 'hora_inicio' => '18:00', 'hora_fin' => '02:00',
        'espacios' => 'Salón principal', 'invitados' => '120', 'total' => '$' . number_format($total, 2, ',', '.'),
        'total_letras' => \TuRenta\Letras::dolares($total),
        'detalle' => "• Menú tres tiempos — 120 personas · $3.360,00\n• Barra libre nacional (5 horas) — 120 personas · $2.160,00\n• DJ con sonido e iluminación — 1 x · $650,00\n• IVA 15 %: $1.125,00",
        'plan_pagos' => "• Reserva: $2.587,50 hasta el " . date('d/m/Y') . "\n• Cuota 2: $3.018,75 hasta el " . $fecha->modify('-60 days')->format('d/m/Y') . "\n• Saldo: $3.018,75 hasta el " . $fecha->modify('-15 days')->format('d/m/Y'),
        'garantia' => '$' . number_format((float) $n['garantia_defecto'] ?: 300, 2, ',', '.'), 'hora_limite' => substr((string) $n['hora_limite'], 0, 5),
        'fecha_contrato' => fecha_larga(date('Y-m-d')), 'codigo' => 'EV-' . date('Y') . '-0001',
    ];
};

/** Datos inventados para ver cómo queda la plantilla del anexo. */
$valoresEjemploAnexo = fn(array $ej): array => [
    'negocio' => $ej['negocio'], 'razon_social' => $ej['razon_social'], 'ruc' => $ej['ruc'], 'ciudad' => $ej['ciudad'], 'cliente' => $ej['cliente'],
    'identificacion' => $ej['identificacion'], 'evento' => $ej['evento'], 'fecha' => $ej['fecha'], 'codigo' => $ej['codigo'],
    'numero_anexo' => '1', 'numero_contrato' => '12', 'fecha_contrato' => fecha_larga(date('Y-m-d', strtotime('-30 days'))), 'fecha_anexo' => fecha_larga(date('Y-m-d')),
    'extras' => "• Hora adicional — 2 × \$150,00 + IVA = \$300,00\n• Hora loca — 1 × \$280,00 + IVA = \$280,00",
    'subtotal_extras' => '$580,00', 'iva_extras' => '$87,00', 'total_extras' => '$667,00', 'total_extras_letras' => \TuRenta\Letras::dolares(667),
    'total_anterior' => $ej['total'], 'nuevo_total' => '$9.292,00', 'nuevo_total_letras' => \TuRenta\Letras::dolares(9292),
    'cuota_extras' => 'Extras: Hora adicional, Hora loca', 'cuota_monto' => '$667,00', 'cuota_fecha' => date('d/m/Y', strtotime('+90 days')),
    'plan_pagos' => $ej['plan_pagos'] . "\n• Extras: Hora adicional, Hora loca: \$667,00 hasta el " . date('d/m/Y', strtotime('+90 days')),
];

// =====================================================================
// Plantillas (sin evento)
// =====================================================================
if (!$id) {
    exigir($dueno, 'Las plantillas de contrato las cambia el dueño o un administrador.');
    if (es_post()) {
        verificar_csrf();
        $accion = (string) ($_GET['accion'] ?? '');
        $pid = (int) ($_POST['plantilla_id'] ?? 0) ?: null;
        try {
            switch ($accion) {
                case 'guardar_plantilla':
                    $nuevo = $con->guardarPlantilla($nid, $pid, campo('nombre'), (string) ($_POST['texto'] ?? ''), !empty($_POST['predeterminada']));
                    aviso($pid ? 'Plantilla guardada. Los contratos nuevos usarán este texto.' : 'Plantilla creada.');
                    redirigir('contrato', ['plantilla' => $nuevo]);
                case 'predeterminada':
                    $st = db()->prepare("SELECT * FROM plantillas_contrato WHERE id = ? AND negocio_id = ? AND tipo = 'contrato'");
                    $st->execute([$pid, $nid]);
                    $p = $st->fetch();
                    if (!$p) throw new RuntimeException('No se encontró la plantilla.');
                    $con->guardarPlantilla($nid, (int) $p['id'], $p['nombre'], $p['texto'], true);
                    aviso('«' . $p['nombre'] . '» es ahora la plantilla predeterminada.');
                    break;
                case 'restaurar':
                    $st = db()->prepare("SELECT * FROM plantillas_contrato WHERE id = ? AND negocio_id = ? AND tipo = 'contrato'");
                    $st->execute([$pid, $nid]);
                    $p = $st->fetch();
                    if (!$p) throw new RuntimeException('No se encontró la plantilla.');
                    $con->guardarPlantilla($nid, (int) $p['id'], $p['nombre'], Contratos::plantillaDefecto(), (bool) $p['predeterminada']);
                    aviso('Se restauró el texto de fábrica en «' . $p['nombre'] . '».');
                    redirigir('contrato', ['plantilla' => $p['id']]);
                case 'guardar_anexo':   // plantilla del anexo por extras (migración 090)
                    (new Anexos(db()))->guardarPlantilla($nid, (string) ($_POST['texto'] ?? ''));
                    aviso('Plantilla del anexo guardada. Los anexos nuevos usarán este texto.');
                    redirigir('contrato', ['previa' => 'anexo']);
                case 'restaurar_anexo':
                    (new Anexos(db()))->restaurarPlantilla($nid);
                    aviso('Se restauró el texto de fábrica del anexo.');
                    redirigir('contrato', ['previa' => 'anexo']);
                case 'borrar_plantilla':
                    $st = db()->prepare("DELETE FROM plantillas_contrato WHERE id = ? AND negocio_id = ? AND NOT predeterminada AND tipo = 'contrato'");
                    $st->execute([$pid, $nid]);
                    if ($st->rowCount() !== 1) throw new RuntimeException('No se puede borrar la plantilla predeterminada. Elige otra como predeterminada primero.');
                    aviso('Plantilla borrada. Los contratos ya hechos no cambian.');
                    break;
                default:
                    throw new RuntimeException('Acción no válida.');
            }
        } catch (RuntimeException $e) {
            aviso($e->getMessage(), 'error');
            if ($accion === 'guardar_anexo') {
                $_SESSION['doc_anexo_borrador'] = (string) ($_POST['texto'] ?? '');
                redirigir('contrato', ['plantilla' => 'anexo']);
            }
            if ($accion === 'guardar_plantilla') {
                $_SESSION['doc_plantilla_borrador'] = ['nombre' => campo('nombre'), 'texto' => (string) ($_POST['texto'] ?? ''), 'predeterminada' => !empty($_POST['predeterminada'])];
                redirigir('contrato', ['plantilla' => $pid ?: 'nueva']);
            }
        }
        redirigir('contrato');
    }

    $plantillas = $con->plantillas($nid);
    $editar = null;
    $sel = (string) ($_GET['plantilla'] ?? '');
    if ($sel === 'nueva') {
        $editar = ['id' => null, 'nombre' => '', 'texto' => Contratos::plantillaDefecto(), 'predeterminada' => !$plantillas];
    } elseif ($sel !== '' && $sel !== 'anexo') {
        foreach ($plantillas as $p) if ((int) $p['id'] === (int) $sel) $editar = $p;
        if (!$editar) aviso('No se encontró esa plantilla.', 'error');
    }
    $borrador = $_SESSION['doc_plantilla_borrador'] ?? null;
    unset($_SESSION['doc_plantilla_borrador']);
    if ($borrador && $editar !== null) $editar = array_merge($editar, $borrador);

    // Vista previa con datos de ejemplo
    $previa = null;
    if (!empty($_GET['previa'])) {
        foreach ($plantillas as $p) if ((int) $p['id'] === (int) $_GET['previa']) $previa = $p;
    }
    $ejemplo = $valoresEjemplo();
    $textoPrevia = $previa ? Contratos::reemplazar($previa['texto'], $ejemplo) : null;

    // Anexo por extras aprobados (migración 090): una plantilla aparte, con sus propias variables
    $anexo = (new Anexos(db()))->plantilla($nid);
    $editarAnexo = $sel === 'anexo' ? ($_SESSION['doc_anexo_borrador'] ?? $anexo['texto']) : null;
    unset($_SESSION['doc_anexo_borrador']);
    $ejemploAnexo = $valoresEjemploAnexo($ejemplo);
    $textoPreviaAnexo = ($_GET['previa'] ?? '') === 'anexo' ? Contratos::reemplazar($anexo['texto'], $ejemploAnexo) : null;
    vista('contrato_plantillas', compact('plantillas', 'editar', 'previa', 'textoPrevia', 'ejemplo', 'anexo', 'editarAnexo', 'ejemploAnexo', 'textoPreviaAnexo'),
          'Plantillas de contrato · TuRenta');
    return;
}

// =====================================================================
// Contrato de un evento
// =====================================================================
$eventos = new Eventos(db());
$ev = $eventos->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró el evento. Puede que lo hayan borrado o que sea de otro negocio.'], 'Contrato · TuRenta');
    return;
}
$deEste = function (int $kid) use ($con, $nid, $id): ?array {
    $k = $con->obtener($nid, $kid);
    return ($k && (int) $k['evento_id'] === $id) ? $k : null;
};
$mensajeFirma = function (array $k) use ($ev, $u): string {
    $nombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
    return "Hola $nombre, te envío el contrato de " . $ev['nombre'] . ' (' . fecha_corta($ev['fecha']) . ") para que lo leas y lo firmes desde tu celular.\n\n"
         . 'Solo necesitas tu nombre, tu cédula y firmar con el dedo: ' . url_completa('firmar', ['t' => $k['token']]) . "\n\n"
         . 'Cualquier duda, me escribes. ' . $u['nombre'] . ' · ' . $u['negocio'];
};

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden cambiar contratos.');
    $accion = (string) ($_GET['accion'] ?? '');
    $kid = (int) ($_POST['contrato_id'] ?? 0);
    try {
        switch ($accion) {
            case 'generar':
                $plantilla = (int) ($_POST['plantilla_id'] ?? 0) ?: null;
                $nuevo = $con->generar($nid, $id, $plantilla, $u);
                aviso('Contrato preparado con los datos del evento. Léelo, corrige lo que haga falta y envíalo para firmar.');
                redirigir('contrato', ['id' => $id, 'k' => $nuevo]);
            case 'editar':
                if (!$deEste($kid)) throw new RuntimeException('No se encontró el contrato.');
                $con->editarTexto($nid, $kid, (string) ($_POST['texto'] ?? ''));
                registrar_cambio($nid, $id, 'Corrigió el texto del contrato');
                aviso('Texto del contrato guardado.');
                break;
            case 'enviar':
            case 'enviar_wa':
                $k = $deEste($kid);
                if (!$k) throw new RuntimeException('No se encontró el contrato.');
                if (isset($_POST['texto']) && $k['estado'] === 'borrador' && trim((string) $_POST['texto']) !== trim($k['texto'])) {
                    $con->editarTexto($nid, $kid, (string) $_POST['texto']);
                }
                $con->enviar($nid, $kid, $u);
                if ($accion === 'enviar_wa') {
                    $wa = WhatsApp::enlace($ev['cliente_telefono'], $mensajeFirma($k));
                    if ($wa) {
                        header('Location: ' . $wa);
                        exit;
                    }
                    aviso('Contrato listo para firmar. El cliente no tiene celular guardado: copia el enlace y mándaselo.', 'error');
                    break;
                }
                aviso('Contrato listo para firmar. El texto ya no se puede cambiar. Mándale el enlace al cliente.');
                break;
            case 'anular':
                if (!$deEste($kid)) throw new RuntimeException('No se encontró el contrato.');
                $con->anular($nid, $kid, campo('motivo'), $u);
                aviso('Contrato anulado. Si hace falta, prepara uno nuevo.');
                redirigir('contrato', ['id' => $id]);
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('contrato', ['id' => $id] + ($kid ? ['k' => $kid] : []));
}

$historial = $con->deEvento($nid, $id);
$actual = null;
if (!empty($_GET['k'])) {
    $actual = $deEste((int) $_GET['k']);
    if (!$actual) aviso('Ese contrato no es de este evento.', 'error');
}
$actual ??= $con->vigente($nid, $id);
// Si todos están anulados, se muestra el último (para preparar uno nuevo)
if (!$actual && $historial) $actual = $con->obtener($nid, (int) $historial[0]['id']);
$aceptada = (new Cotizaciones(db()))->aceptadaDe($id);
$plantillas = $con->plantillas($nid);
$resumen = (float) $ev['total'] > 0 ? (new Pagos(db()))->resumen($id) : null;
$reservaPagada = $resumen && $resumen['cuotas'] && (float) $resumen['cuotas'][0]['saldo'] <= 0;
$enlaceFirma = $actual ? url_completa('firmar', ['t' => $actual['token']]) : null;
$wa = $actual && $actual['estado'] === 'enviado' ? WhatsApp::enlace($ev['cliente_telefono'], $mensajeFirma($actual)) : null;
$enlaceVerificar = $actual ? url_completa('verificar', ['c' => $actual['codigo_verificacion']]) : null;
$tab = 'contrato';

vista('contrato', compact('ev', 'tab', 'historial', 'actual', 'aceptada', 'plantillas', 'resumen', 'reservaPagada', 'enlaceFirma', 'wa', 'enlaceVerificar', 'puede', 'dueno'),
      'Contrato · ' . $ev['nombre'] . ' · TuRenta');
