<?php
/**
 * Módulo documentos: catálogo (precios en bloque), paquetes (duplicar, simulador),
 * PDF de la cotización y del contrato (firmado y borrador).
 */
use TuRenta\{Catalogo, Cotizaciones, Contratos, Eventos, Pagos, Pdf, PdfDocumentos};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$fecha = $hoy->modify('+90 days')->format('Y-m-d');
[$nid, $dueno] = negocio_prueba($db);
$cat = new Catalogo($db);
$cot = new Cotizaciones($db);
$con = new Contratos($db);
$ev = new Eventos($db);
$neg = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();

titulo('1. Productos del catálogo');
$p1 = $cat->guardarProducto($nid, null, ['nombre' => 'Menú criollo', 'categoria' => 'menu', 'unidad' => 'persona', 'precio' => '20,00', 'costo' => '9', 'con_iva' => true]);
$P1 = $cat->producto($nid, $p1);
check('Producto creado con precio 20', 20.0, (float) $P1['precio']);
check('Margen 55 %', 55.0, Catalogo::margen(20, 9));
check('Margen sin precio = null', null, Catalogo::margen(0, 5));
check_error('Nombre obligatorio', 'nombre', fn() => $cat->guardarProducto($nid, null, ['nombre' => '', 'categoria' => 'menu', 'unidad' => 'persona', 'precio' => 1]));
check_error('Categoría válida', 'categoría', fn() => $cat->guardarProducto($nid, null, ['nombre' => 'X1', 'categoria' => 'nada', 'unidad' => 'persona', 'precio' => 1]));
check_error('Precio negativo', 'negativo', fn() => $cat->guardarProducto($nid, null, ['nombre' => 'X1', 'categoria' => 'menu', 'unidad' => 'persona', 'precio' => -1]));
check_error('Precio con letras', 'no es un valor', fn() => $cat->guardarProducto($nid, null, ['nombre' => 'X1', 'categoria' => 'menu', 'unidad' => 'persona', 'precio' => 'veinte']));
$cat->guardarProducto($nid, $p1, ['nombre' => 'Menú criollo', 'categoria' => 'menu', 'unidad' => 'persona', 'precio' => '21', 'con_iva' => true]);
check('Editar sin costo (vendedor) mantiene el costo', 9.0, (float) $cat->producto($nid, $p1)['costo']);
check('Búsqueda por nombre', 1, count($cat->productos($nid, ['buscar' => 'criollo'])));
check('Filtro por categoría (menú: 4 de ejemplo + 1)', 5, count($cat->productos($nid, ['categoria' => 'menu'])));
$cat->activarProducto($nid, $p1, false);
check('Desactivado no sale en la lista', 0, count($cat->productos($nid, ['buscar' => 'criollo'])));
check('Desactivado sí sale con "ver inactivos"', 1, count($cat->productos($nid, ['buscar' => 'criollo', 'inactivos' => true])));
$cat->activarProducto($nid, $p1, true);
check('Buscador sin costos para el vendedor', false, array_key_exists('costo', $cat->paraBuscador($nid, false)[0]));
check('Buscador con costos para el dueño', true, array_key_exists('costo', $cat->paraBuscador($nid, true)[0]));

titulo('2. Subir precios en bloque');
$antesMenu = (float) $db->query("SELECT precio FROM productos WHERE negocio_id = $nid AND nombre = 'Menú buffet'")->fetchColumn();
$antesDj = (float) $db->query("SELECT precio FROM productos WHERE negocio_id = $nid AND nombre LIKE 'DJ%'")->fetchColumn();
$antesPaq = (float) $db->query("SELECT pi.precio FROM paquete_items pi JOIN productos p ON p.id = pi.producto_id JOIN paquetes pq ON pq.id = pi.paquete_id
                                 WHERE pq.negocio_id = $nid AND pq.nombre = 'Paquete Boda Plata' AND p.nombre = 'Menú buffet'")->fetchColumn();
$n = $cat->subirPrecios($nid, 5, 'menu');
check('Cambiaron los 5 productos de menú', 5, $n);
check('Menú buffet sube 5 % (24 → 25,20)', round($antesMenu * 1.05, 2), (float) $db->query("SELECT precio FROM productos WHERE negocio_id = $nid AND nombre = 'Menú buffet'")->fetchColumn());
check('El DJ (otra categoría) no cambia', $antesDj, (float) $db->query("SELECT precio FROM productos WHERE negocio_id = $nid AND nombre LIKE 'DJ%'")->fetchColumn());
check('El precio especial en el paquete también sube', round($antesPaq * 1.05, 2), (float) $db->query("SELECT pi.precio FROM paquete_items pi JOIN productos p ON p.id = pi.producto_id JOIN paquetes pq ON pq.id = pi.paquete_id
                                 WHERE pq.negocio_id = $nid AND pq.nombre = 'Paquete Boda Plata' AND p.nombre = 'Menú buffet'")->fetchColumn());
$total = (int) $db->query("SELECT count(*) FROM productos WHERE negocio_id = $nid AND activo AND precio > 0")->fetchColumn();
check('Todo el catálogo: cambian todos los activos con precio', $total, $cat->subirPrecios($nid, -10, null, false));
check_error('Porcentaje 0', 'porcentaje', fn() => $cat->subirPrecios($nid, 0));
check_error('Porcentaje exagerado', 'entre', fn() => $cat->subirPrecios($nid, 300));
[$nidOtro] = negocio_prueba($db, 'salon', 'Otro local');
$otroAntes = (float) $db->query("SELECT sum(precio) FROM productos WHERE negocio_id = $nidOtro")->fetchColumn();
$cat->subirPrecios($nid, 10);
check('Subir precios no toca otro negocio', $otroAntes, (float) $db->query("SELECT sum(precio) FROM productos WHERE negocio_id = $nidOtro")->fetchColumn());

titulo('3. Paquetes: crear, duplicar y simular');
$menu = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Menú tres tiempos'")->fetchColumn();
$torta = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Torta de bodas'")->fetchColumn();
$gas = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre LIKE 'Gaseosas%'")->fetchColumn();
$db->exec("UPDATE productos SET con_iva = false WHERE id = $gas");
$pq = $cat->guardarPaquete($nid, null, ['nombre' => 'Paquete Prueba', 'tipo_evento' => 'boda', 'minimo_invitados' => 50],
    [['producto_id' => $menu, 'cantidad' => 1, 'precio' => '30'], ['producto_id' => $torta, 'cantidad' => 1, 'precio' => ''], ['producto_id' => $gas, 'cantidad' => 1, 'precio' => '2']]);
$PQ = $cat->paquete($nid, $pq);
check('Paquete con 3 productos', 3, count($PQ['items']));
check('Precio vacío = precio del producto (NULL)', null, $PQ['items'][1]['precio_paquete'] ?? $PQ['items'][array_search($torta, array_column($PQ['items'], 'producto_id'))]['precio_paquete']);
check_error('Paquete sin productos', 'al menos un producto', fn() => $cat->guardarPaquete($nid, null, ['nombre' => 'Vacío'], []));
check_error('Producto repetido', 'dos veces', fn() => $cat->guardarPaquete($nid, null, ['nombre' => 'Repetido'], [['producto_id' => $menu], ['producto_id' => $menu]]));
check_error('Producto de otro negocio', 'ya no existe', fn() => $cat->guardarPaquete($nidOtro, null, ['nombre' => 'Ajeno'], [['producto_id' => $menu]]));
$dup = $cat->duplicarPaquete($nid, $pq);
$DUP = $cat->paquete($nid, $dup);
check('Copia con otro id', true, $dup !== $pq);
check('Copia: nombre "Copia de …"', 'Copia de Paquete Prueba', $DUP['nombre']);
check('Copia: mismos productos', 3, count($DUP['items']));
check('Copia: mismo mínimo de invitados', 50, (int) $DUP['minimo_invitados']);
check('Copia: mismos precios especiales', array_column($PQ['items'], 'precio_paquete'), array_column($DUP['items'], 'precio_paquete'));
$cat->activarPaquete($nid, $dup, false);
check('Paquete desactivado no sale en la lista de activos', false, in_array($dup, array_map('intval', array_column($cat->paquetes($nid, false), 'id')), true));
$precioTorta = (float) $cat->producto($nid, $torta)['precio'];
$s = $cat->simular($nid, $pq, 100);
// menú 30 x 100 = 3000 (IVA) ; torta x1 (IVA) ; gaseosas 2 x 100 = 200 (0 %)
check('Simulador: subtotal', round(3000 + $precioTorta + 200, 2), $s['subtotal']);
check('Simulador: base 0 %', 200.0, $s['base_cero']);
check('Simulador: IVA 15 % solo sobre lo que paga IVA', round((3000 + $precioTorta) * 0.15, 2), $s['iva']);
check('Simulador: por persona', round($s['total'] / 100, 2), $s['por_persona']);
$s2 = $cat->simular($nid, $pq, 20);
check('Simulador bajo el mínimo: calcula con 50', 50, $s2['invitados_calculo']);
check('Simulador avisa que está bajo el mínimo', true, $s2['bajo_minimo']);

titulo('4. Cotización con el paquete y PDF');
$salon = espacio_id($db, $nid, 'Salón principal');
$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'María José Andrade', 'telefono' => '0991231234', 'identificacion' => '1711111111', 'email' => 'mj@correo.ec'],
    'tipo' => 'boda', 'fecha' => $fecha, 'hora_inicio' => '17:00', 'hora_fin' => '01:00', 'invitados' => 100, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$c1 = $cot->crear($nid, $e1, $pq, (int) $dueno['id'], $hoy);
$C1 = $cot->obtener($nid, $c1);
check('La cotización da el mismo total que el simulador', $s['total'], (float) $C1['total']);
$pdf = PdfDocumentos::cotizacion($neg, $C1, 'http://127.0.0.1/?r=cot&t=' . $C1['token'], 'Salón principal');
$bin = Pdf::comoTexto($pdf);
check('PDF de cotización empieza con %PDF', '%PDF', substr($bin, 0, 4));
check('PDF de cotización no está vacío (> 5 KB)', true, strlen($bin) > 5000);
$txt = pdf_a_texto($bin);
check('PDF de cotización: nombre del cliente', true, str_contains($txt, 'María José Andrade'));
check('PDF de cotización: total', true, str_contains($txt, Pdf::dinero($C1['total'])));
check('PDF de cotización: por persona', true, str_contains($txt, 'Precio por persona'));
check('PDF de cotización: categoría Menú', true, str_contains($txt, 'Menú y comida'));
check('PDF de cotización: base 0 %', true, str_contains($txt, 'Base 0 %'));
check('PDF de cotización: validez', true, str_contains($txt, 'válida hasta'));

titulo('5. Contrato en PDF: borrador y firmado');
$cot->aceptar($nid, $c1, 'María José Andrade', '1.2.3.4', null, $hoy);
$k = $con->generar($nid, $e1, null, $dueno, $hoy);
$K = $con->obtener($nid, $k);
$bin = Pdf::comoTexto(PdfDocumentos::contrato($neg, $K, 'http://127.0.0.1/?r=verificar&c=' . $K['codigo_verificacion']));
check('PDF de contrato empieza con %PDF', '%PDF', substr($bin, 0, 4));
$txt = pdf_a_texto($bin);
check('Contrato borrador: nombre del cliente', true, str_contains($txt, 'María José Andrade'));
check('Contrato borrador: marca de agua (texto transparente)', true, str_contains($bin, '/ca 0.13'));
check('Contrato borrador: código de verificación', true, str_contains($txt, $K['codigo_verificacion']));
$con->enviar($nid, $k, $dueno);
$K = $con->firmar($K['token'], 'María José Andrade Ruiz', '1711111111', firma_png(), true, '9.8.7.6', 'Prueba');
$bin = Pdf::comoTexto(PdfDocumentos::contrato($neg, $K, 'http://127.0.0.1/?r=verificar&c=' . $K['codigo_verificacion']));
$txt = pdf_a_texto($bin);
check('Contrato firmado: sin marca de agua', false, str_contains($bin, '/ca 0.13'));
check('Contrato firmado: nombre del firmante', true, str_contains($txt, 'María José Andrade Ruiz'));
check('Contrato firmado: IP de la firma', true, str_contains($txt, '9.8.7.6'));
check('Contrato firmado: huella SHA-256', true, str_contains(str_replace(["\n", ' '], '', $txt), $K['hash_sha256']));
check('Contrato firmado: lleva la imagen de la firma', true, str_contains($bin, '/Subtype /Image'));
$html = PdfDocumentos::htmlContrato("TÍTULO\n\nPRIMERA — OBJETO. Texto.\n• uno\n• dos");
check('Formato: título centrado', true, str_contains($html, '<h3'));
check('Formato: cláusula en negrita', true, str_contains($html, '<b style="color:#6B2D3C">PRIMERA — OBJETO.</b>'));
check('Formato: viñetas', true, substr_count($html, '•') === 2);

/** Texto del PDF con pdftotext (si existe) o con el contenido sin comprimir de TCPDF. */
function pdf_a_texto(string $bin): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($tmp, $bin);
    $txt = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    @unlink($tmp);
    return (string) $txt;
}
