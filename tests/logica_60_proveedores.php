<?php
/**
 * Proveedores y compras (060): ficha, documentos con vencimiento, compras y estados, pagos (sin pagar de más, anular,
 * cheques posfechados), saldo con retención y descuentos por incidencias, factura manual y desde el XML del SRI,
 * cuentas por pagar y antigüedad de saldos, ganancia real con compras, comparar precios, cumplimiento y puntualidad,
 * puntaje y ranking, incidencias, portal del proveedor, tareas automáticas, PDFs y aislamiento entre negocios.
 */
use TuRenta\{Adjuntos, AvisosProveedores, Automaticas, Cobranza, Compras, DiaEvento, Eventos, FacturaXml, Incidencias, Pdf, PdfCompras, PuntajeProveedores,
              ProveedoresFicha, Reportes};

$db = base_limpia();
$hoy = new DateTimeImmutable('today');
$H = $hoy->format('Y-m-d');
$dia = fn(int $n) => $hoy->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
[$nid, $dueno] = negocio_prueba($db);
$ev = new Eventos($db);
$de = new DiaEvento($db);
$fi = new ProveedoresFicha($db);
$co = new Compras($db);
$inc = new Incidencias($db);
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/factura_sri.xml');
// Copia de la factura con fecha de hace 2 días (para que los vencimientos no dependan del día en que se corre la prueba)
$xmlHoy = str_replace('<fechaEmision>10/10/2026</fechaEmision>', '<fechaEmision>' . $hoy->modify('-2 days')->format('d/m/Y') . '</fechaEmision>', $fixture);

/** Archivo simulado para subir (como $_FILES): pdf, xml o foto. */
$subida = function (string $tipo, ?string $contenido = null): array {
    $ruta = tempnam(sys_get_temp_dir(), 'adj');
    if ($tipo === 'foto') return foto_subida();
    file_put_contents($ruta, $contenido ?? ($tipo === 'pdf' ? "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n" : '<factura/>'));
    return ['name' => "archivo.$tipo", 'type' => $tipo === 'pdf' ? 'application/pdf' : 'text/xml', 'tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ruta)];
};

$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'Ana Torres', 'telefono' => '0991112233'], 'tipo' => 'boda', 'fecha' => $dia(20),
    'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 150, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$ev->cambiarEstado($nid, $e1, 'separado', [], $dueno, $hoy);
$db->prepare('UPDATE eventos SET total = 11500 WHERE id = ?')->execute([$e1]);   // 10.000 + IVA

titulo('1. Ficha del proveedor: RUC, banco y crédito');
$pFlor = $de->guardarProveedor($nid, null, ['nombre' => 'Florería El Valle', 'servicio' => 'decoracion', 'contacto' => 'Rosa Pérez', 'telefono' => '0998765432']);
$pDj = $de->guardarProveedor($nid, null, ['nombre' => 'DJ Fiesta Total', 'servicio' => 'musica', 'telefono' => '0993334444']);
$pCat = $de->guardarProveedor($nid, null, ['nombre' => 'Catering Delicias', 'servicio' => 'menu', 'telefono' => '0981112222']);
check('El proveedor tiene enlace secreto para su portal', 48, strlen((string) $fi->proveedor($nid, $pFlor)['token']));
check('Cédula de 10 dígitos válida', '1712345678', ProveedoresFicha::identificacion('171234567-8'));
check('RUC de 13 dígitos válido', '1791234567001', ProveedoresFicha::identificacion('1791234567001'));
check('Sin RUC queda vacío', null, ProveedoresFicha::identificacion(''));
check_error('RUC con dígitos de menos', 'RUC tiene 13 dígitos', fn() => ProveedoresFicha::identificacion('12345'));
check_error('Cuenta de banco muy corta', 'número de cuenta', fn() => $fi->guardarDatos($nid, $pDj, ['numero_cuenta' => '12']));
check_error('Días de crédito fuera de rango', 'Los días de crédito', fn() => $fi->guardarDatos($nid, $pDj, ['credito_dias' => '400']));
check_error('Anticipo de más de 100 %', 'anticipo va de 0 a 100', fn() => $fi->guardarDatos($nid, $pDj, ['anticipo_pct' => '150']));
check_error('Tipo de cuenta no válido', 'ahorros o corriente', fn() => $fi->guardarDatos($nid, $pDj, ['tipo_cuenta' => 'plazo']));
$fi->guardarDatos($nid, $pDj, ['ruc' => '1712345678001', 'razon_social' => 'Juan Pérez Música', 'banco' => 'Banco Pichincha', 'tipo_cuenta' => 'ahorros',
    'numero_cuenta' => '2201234567', 'titular_cuenta' => 'Juan Pérez', 'credito_dias' => '15', 'cupo_credito' => '1.000,00', 'anticipo_pct' => '50', 'preferido' => '1'], $dueno);
$P = $fi->proveedor($nid, $pDj);
check('RUC guardado', '1712345678001', $P['ruc']);
check('Crédito de 15 días', 15, (int) $P['credito_dias']);
check('Cupo con número ecuatoriano 1.000,00', 1000.0, (float) $P['cupo_credito']);
check('Anticipo habitual 50 %', 50.0, (float) $P['anticipo_pct']);
check('Marcado como preferido', true, (bool) $P['preferido']);
check('Datos del banco', 'Banco Pichincha|ahorros|2201234567', $P['banco'] . '|' . $P['tipo_cuenta'] . '|' . $P['numero_cuenta']);
check_error('Mismo RUC en dos proveedores', 'ya es de otro proveedor', fn() => $fi->guardarDatos($nid, $pCat, ['ruc' => '1712345678001']));
$bit = $fi->bitacora($nid, $pDj);
check('Bitácora: cambio de condiciones', true, str_contains($bit[0]['accion'], 'crédito de contado a 15 días') && str_contains($bit[0]['accion'], 'cupo $1.000,00'));
check('Bitácora: quién lo hizo', $dueno['nombre'], $bit[0]['quien']);
check('Texto del crédito', 'contado', ProveedoresFicha::textoCredito(0));
check('Proveedor por RUC (persona natural: cédula + 001)', $pDj, (int) $fi->porRuc($nid, '1712345678001')['id']);
check('La base no deja guardar un RUC mal escrito', true, (function () use ($db, $pCat) {
    try { $db->prepare("UPDATE proveedores SET ruc = '123' WHERE id = ?")->execute([$pCat]); return false; } catch (PDOException) { return true; }
})());
$fi->guardarDatos($nid, $pCat, ['credito_dias' => '30', 'anticipo_pct' => '40'], $dueno);

titulo('2. Documentos con vencimiento');
$d1 = $fi->agregarDocumento($nid, $pCat, ['tipo' => 'permiso_sanitario', 'vence' => $dia(-2)], $subida('pdf'), $dueno);
$d2 = $fi->agregarDocumento($nid, $pCat, ['tipo' => 'poliza', 'nombre' => 'Seguros Equinoccial', 'vence' => $dia(20)], $subida('foto'), $dueno);
$d3 = $fi->agregarDocumento($nid, $pCat, ['tipo' => 'ruc', 'vence' => $dia(200)], null, $dueno);
$docs = $fi->documentos($nid, $pCat);
check('Tres documentos', 3, count($docs));
$porId = array_column($docs, null, 'id');
check('Permiso vencido', 'vencido', $porId[$d1]['estado']);
check('Póliza vence en 30 días o menos', 'por_vencer', $porId[$d2]['estado']);
check('RUC vigente', 'vigente', $porId[$d3]['estado']);
check('PDF guardado fuera de la carpeta pública', true, Adjuntos::ruta($porId[$d1]['archivo']) !== null && str_ends_with($porId[$d1]['archivo'], '.pdf'));
check('La foto se guarda como JPG limpio', true, str_ends_with((string) $porId[$d2]['archivo'], '.jpg'));
check('Sin fecha', 'sin_fecha', ProveedoresFicha::estadoDocumento(null));
check('Alertas: 1 vencido y 1 por vencer', ['vencidos' => 1, 'por_vencer' => 1], $fi->alertasDocumentos($nid)[$pCat]);
check_error('Documento vacío', 'Sube la foto o el PDF', fn() => $fi->agregarDocumento($nid, $pCat, ['tipo' => 'otro']));
check_error('Tipo de archivo no permitido (XML como documento)', 'El archivo debe ser', fn() => $fi->agregarDocumento($nid, $pCat, ['tipo' => 'otro'], $subida('xml', $fixture)));
check_error('Archivo que dice ser PDF pero no lo es', 'El archivo debe ser', fn() => $fi->agregarDocumento($nid, $pCat, ['tipo' => 'otro'], $subida('pdf', 'hola, no soy un PDF')));
$fi->borrarDocumento($nid, $d3, $dueno);
check('Documento quitado', 2, count($fi->documentos($nid, $pCat)));
check('Bitácora del documento', true, str_contains($fi->bitacora($nid, $pCat)[0]['accion'], 'Quitó el documento RUC'));

titulo('3. Compras: número, totales con IVA, anticipo y fecha límite');
$c1 = $co->crear($nid, ['proveedor_id' => $pDj, 'evento_id' => $e1, 'estado' => 'pedida'], [
    ['descripcion' => 'DJ 6 horas con sonido', 'cantidad' => '1', 'precio_unitario' => '600'],
    ['descripcion' => 'Luces robóticas', 'cantidad' => '4', 'precio_unitario' => '25,50'],
    ['descripcion' => '', 'cantidad' => '', 'precio_unitario' => ''],                               // fila vacía: se ignora
    ['descripcion' => 'Propina técnica', 'cantidad' => '1', 'precio_unitario' => '20', 'con_iva' => false],
], $dueno, $hoy);
$C1 = $co->compra($nid, $c1);
check('Primera orden: OC-0001', 'OC-0001', $C1['codigo']);
check('Tres líneas (la vacía no cuenta)', 3, count($co->items($c1)));
check('Subtotal', 722.0, $C1['subtotal']);
check('IVA 15 % solo de lo que lleva IVA (702)', 105.3, $C1['iva']);
check('Total', 827.3, $C1['total']);
check('Anticipo sugerido = 50 % del proveedor', 413.65, $C1['anticipo']);
check('Fecha límite = hoy + 15 días de crédito', $dia(15), $C1['vence']);
check('Estado de pago: pendiente', 'pendiente', $C1['estado_pago']);
check('Saldo = total', 827.3, $C1['saldo']);
check('La categoría sale del servicio del proveedor', 'musica', $C1['categoria']);
$EP = $db->query("SELECT * FROM evento_proveedores WHERE evento_id = $e1 AND proveedor_id = $pDj")->fetch();
check('Al pedirla, el proveedor aparece en el día del evento', $c1, (int) $EP['compra_id']);
check('Todavía no confirmado en el día del evento', false, (bool) $EP['confirmado']);
check('El historial del evento lo registra', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $e1 AND accion LIKE '%OC-0001%'")->fetchColumn());
$c2 = $co->crear($nid, ['proveedor_id' => $pFlor, 'estado' => 'pedida', 'vence' => $dia(-40), 'fecha' => $dia(-45)],
    [['descripcion' => 'Flores para la recepción del local', 'cantidad' => 1, 'precio_unitario' => 100]], $dueno, $hoy);
check('Segunda: OC-0002 (compra general, sin evento)', 'OC-0002', $co->compra($nid, $c2)['codigo']);
check('Fecha límite escrita a mano', $dia(-40), $co->compra($nid, $c2)['vence']);
check('Pasó la fecha límite: vencida', 'vencida', $co->compra($nid, $c2)['estado_pago']);
check('Antigüedad: 31 a 60 días', '31_60', $co->compra($nid, $c2)['tramo']);
check_error('Sin ítems', 'al menos una línea', fn() => $co->crear($nid, ['proveedor_id' => $pDj], [['descripcion' => '']]));
check_error('Sin proveedor', 'Elige el proveedor', fn() => $co->crear($nid, ['proveedor_id' => 0], [['descripcion' => 'X', 'precio_unitario' => 1]]));
check_error('Cantidad cero', 'Revisa la cantidad', fn() => $co->crear($nid, ['proveedor_id' => $pDj], [['descripcion' => 'Sillas', 'cantidad' => '0', 'precio_unitario' => 1]]));
check_error('Precio negativo', 'Revisa el precio', fn() => $co->crear($nid, ['proveedor_id' => $pDj], [['descripcion' => 'Sillas', 'cantidad' => '1', 'precio_unitario' => '-5']]));
check_error('Anticipo mayor que el total', 'anticipo no puede ser mayor', fn() => $co->crear($nid, ['proveedor_id' => $pDj, 'anticipo' => '9999'], [['descripcion' => 'Sillas', 'precio_unitario' => 10]]));
check('Números ecuatorianos: 1.234,50', 1234.5, Compras::totales([['cantidad' => 1, 'precio_unitario' => 1234.5, 'con_iva' => false]], 15)['total']);

titulo('4. Estados de la compra');
check_error('No salta de pedida a entregada', 'no puede pasar a entregada', fn() => $co->cambiarEstado($nid, $c1, 'entregada', $dueno));
$co->cambiarEstado($nid, $c1, 'confirmada', $dueno);
check('Confirmada', 'confirmada', $co->compra($nid, $c1)['estado']);
check('Al confirmar, queda confirmado en el día del evento', true, (bool) $db->query("SELECT confirmado FROM evento_proveedores WHERE compra_id = $c1")->fetchColumn());
check('Sin duplicar el proveedor en el evento', 1, (int) $db->query("SELECT count(*) FROM evento_proveedores WHERE evento_id = $e1 AND proveedor_id = $pDj")->fetchColumn());
check_error('Cancelar exige el motivo', 'por qué se cancela', fn() => $co->cambiarEstado($nid, $c2, 'cancelada', $dueno, ''));
check('Bitácora con quién confirmó', true, str_contains($fi->bitacora($nid, $pDj)[0]['accion'], 'confirmada la orden OC-0001'));
check('Sin aviso de cupo mientras no se pase (827,30 de 1.000)', null, $co->avisoCupo($nid, $pDj));

titulo('5. Pagos: abonos, sin pagar de más, cheque posfechado y anular');
check_error('Pago en cero', 'valor del pago', fn() => $co->registrarPago($nid, $c1, ['monto' => '0'], null, $dueno, $hoy));
check_error('No se paga más que el saldo', 'No puedes pagar más que el saldo', fn() => $co->registrarPago($nid, $c1, ['monto' => '900', 'metodo' => 'efectivo'], null, $dueno, $hoy));
check_error('Fecha futura (no es cheque)', 'no puede ser futura', fn() => $co->registrarPago($nid, $c1, ['monto' => '10', 'fecha' => $dia(3)], null, $dueno, $hoy));
check_error('Fecha de cobro solo con cheque', 'solo va con cheques', fn() => $co->registrarPago($nid, $c1, ['monto' => '10', 'metodo' => 'efectivo', 'cheque_fecha' => $dia(10)], null, $dueno, $hoy));
check_error('Cheque sin número', 'número del cheque', fn() => $co->registrarPago($nid, $c1, ['monto' => '10', 'metodo' => 'cheque'], null, $dueno, $hoy));
check_error('Forma de pago inventada', 'forma de pago', fn() => $co->registrarPago($nid, $c1, ['monto' => '10', 'metodo' => 'bitcoin'], null, $dueno, $hoy));
$pg1 = $co->registrarPago($nid, $c1, ['monto' => '413,65', 'metodo' => 'transferencia', 'referencia' => 'TRF-889'], $subida('foto'), $dueno, $hoy);
$C1 = $co->compra($nid, $c1, $hoy);
check('Anticipo pagado: estado abonada', 'parcial', $C1['estado_pago']);
check('Saldo después del anticipo', 413.65, $C1['saldo']);
check('Anticipo pendiente en cero', 0.0, $C1['anticipo_pendiente']);
check('Comprobante del pago guardado', true, $co->rutaComprobante($nid, $pg1) !== null);
$pg2 = $co->registrarPago($nid, $c1, ['monto' => '200', 'metodo' => 'cheque', 'referencia' => 'Cheque 000145', 'cheque_fecha' => $dia(10)], null, $dueno, $hoy);
check('Cheque posfechado: fecha de cobro', $dia(10), $co->pago($nid, $pg2)['cheque_fecha']);
check_error('Pagar más del saldo que queda', 'se debe $213,65', fn() => $co->registrarPago($nid, $c1, ['monto' => '213,66'], null, $dueno, $hoy));
$co->anularPago($nid, $pg2, 'El cheque salió con la fecha mal', $dueno);
check('Anular no borra: queda anulado con el motivo', 'anulado|El cheque salió con la fecha mal', implode('|', array_intersect_key($co->pago($nid, $pg2), array_flip(['estado', 'anulado_motivo']))));
check('El pago anulado ya no cuenta', 413.65, $co->compra($nid, $c1)['saldo']);
check_error('Anular sin motivo', 'por qué se anula', fn() => $co->anularPago($nid, $pg1, ''));
check_error('Anular dos veces', 'ya estaba anulado', fn() => $co->anularPago($nid, $pg2, 'otra vez'));
check('Bitácora del pago anulado', true, str_contains($fi->bitacora($nid, $pDj)[0]['accion'], 'Anuló el pago de $200,00'));
check_error('Cancelar una compra con pagos', 'tiene pagos', fn() => $co->cambiarEstado($nid, $c1, 'cancelada', $dueno, 'ya no'));
check('La base no deja un cheque posfechado en efectivo', true, (function () use ($db, $nid, $c1, $pDj) {
    try { $db->prepare("INSERT INTO pagos_proveedor (negocio_id, compra_id, proveedor_id, monto, metodo, cheque_fecha) VALUES (?,?,?,1,'efectivo',CURRENT_DATE)")->execute([$nid, $c1, $pDj]); return false; }
    catch (PDOException) { return true; }
})());
check('La base no deja un monto negativo', true, (function () use ($db, $nid, $c1, $pDj) {
    try { $db->prepare("INSERT INTO pagos_proveedor (negocio_id, compra_id, proveedor_id, monto) VALUES (?,?,?,-5)")->execute([$nid, $c1, $pDj]); return false; }
    catch (PDOException) { return true; }
})());
$co->guardarItems($nid, $c1, [['descripcion' => 'DJ 6 horas con sonido', 'cantidad' => 1, 'precio_unitario' => 600], ['descripcion' => 'Luces robóticas', 'cantidad' => 4, 'precio_unitario' => 25.5],
                              ['descripcion' => 'Propina técnica', 'cantidad' => 1, 'precio_unitario' => 20, 'con_iva' => false], ['descripcion' => 'Máquina de humo', 'cantidad' => 1, 'precio_unitario' => 40]], $dueno);
check('Cambiar ítems recalcula el total', 873.3, $co->compra($nid, $c1)['total']);
check_error('El total no puede quedar por debajo de lo pagado', 'lo ya pagado', fn() => $co->guardarItems($nid, $c1, [['descripcion' => 'Solo luces', 'cantidad' => 1, 'precio_unitario' => 100]], $dueno));

titulo('6. Retención y descuento por incidencia bajan el saldo');
$co->editar($nid, $c1, ['retencion' => '17,46'], $dueno);
check('Retención guardada', 17.46, $co->compra($nid, $c1)['retencion']);
check('Saldo = total − retención − pagos', round(873.3 - 17.46 - 413.65, 2), $co->compra($nid, $c1)['saldo']);
check_error('Retención mayor que el valor', 'retención no puede ser mayor', fn() => $co->editar($nid, $c1, ['retencion' => '5000'], $dueno));
$i1 = $inc->crear($nid, ['evento_id' => $e1, 'proveedor_id' => $pDj, 'compra_id' => $c1, 'gravedad' => 'media',
    'descripcion' => 'Llegó sin el micrófono inalámbrico', 'descuento' => '50', 'costo' => '30'], foto_subida(), $dueno);
check('Incidencia del proveedor: área proveedor por defecto', 'proveedor', $inc->incidencia($nid, $i1)['area']);
check('Saldo baja con el descuento', round(873.3 - 17.46 - 50 - 413.65, 2), $co->compra($nid, $c1)['saldo']);
check('Foto de la incidencia guardada', true, $inc->rutaFoto($nid, $i1) !== null);
check_error('Descuento mayor que el saldo', 'no puede ser mayor que el saldo', fn() => $inc->crear($nid, ['proveedor_id' => $pDj, 'compra_id' => $c1, 'descripcion' => 'Otro problema serio', 'descuento' => '9999']));
check_error('Descuento sin compra', 'de qué compra', fn() => $inc->crear($nid, ['proveedor_id' => $pDj, 'descripcion' => 'Otro problema serio', 'descuento' => '10']));
check_error('Compra de otro proveedor', 'Esa compra es de otro proveedor', fn() => $inc->crear($nid, ['proveedor_id' => $pFlor, 'compra_id' => $c1, 'descripcion' => 'Problema con flores']));
$inc->actualizar($nid, $i1, ['descuento' => '60'], $dueno);
check('Cambiar el descuento (cuenta el que ya tenía)', 60.0, (float) $inc->incidencia($nid, $i1)['descuento']);
$inc->actualizar($nid, $i1, ['descuento' => '999', 'estado' => 'en_proceso'], $dueno, false);
check('Sin permiso de dinero (coordinador): el descuento no cambia', 60.0, (float) $inc->incidencia($nid, $i1)['descuento']);
check('…pero sí el estado', 'en_proceso', $inc->incidencia($nid, $i1)['estado']);
$saldoC1 = $co->compra($nid, $c1)['saldo'];
$co->registrarPago($nid, $c1, ['monto' => (string) $saldoC1, 'metodo' => 'efectivo'], null, $dueno, $hoy);
check('Pagada por completo', 'pagada', $co->compra($nid, $c1)['estado_pago']);
check('Saldo en cero', 0.0, $co->compra($nid, $c1)['saldo']);
check_error('Ya pagada: no acepta más pagos', 'ya está pagada', fn() => $co->registrarPago($nid, $c1, ['monto' => '1'], null, $dueno, $hoy));

titulo('7. Factura del proveedor (a mano)');
check('Número con guiones', '001-001-000000123', Compras::numeroFactura('1-1-123'));
check('Número de 15 dígitos', '001-002-000004567', Compras::numeroFactura('001002000004567'));
check_error('Número mal escrito', '001-001-000000123', fn() => Compras::numeroFactura('FAC 123'));
$co->registrarFactura($nid, $c2, ['numero' => '002-001-000000777', 'fecha' => $dia(-44), 'total' => '115'], $subida('pdf'), $dueno);
$C2 = $co->compra($nid, $c2, $hoy);
check('Factura registrada', '002-001-000000777', $C2['factura_numero']);
check('El saldo usa el total facturado', 115.0, $C2['saldo']);
check('La fecha límite escrita a mano no cambia', $dia(-40), $C2['vence']);
check('PDF de la factura guardado', true, Adjuntos::ruta($C2['factura_archivo']) !== null);
$c3 = $co->crear($nid, ['proveedor_id' => $pFlor, 'evento_id' => $e1, 'estado' => 'confirmada'], [['descripcion' => 'Arreglos florales', 'cantidad' => 10, 'precio_unitario' => 25]], $dueno, $hoy);
check_error('Factura repetida del mismo proveedor', 'ya está registrada en OC-0002', fn() => $co->registrarFactura($nid, $c3, ['numero' => '002-001-000000777'], null, $dueno));
check_error('Factura sin datos', 'número de la factura', fn() => $co->registrarFactura($nid, $c3, [], null, $dueno));

titulo('8. Factura electrónica (XML del SRI)');
$F = FacturaXml::leer($fixture);
check('XML: número', '001-002-000004567', $F['numero']);
check('XML: fecha', '2026-10-10', $F['fecha']);
check('XML: RUC del proveedor', '1791234567001', $F['ruc_emisor']);
check('XML: razón social', 'FLORES Y EVENTOS DEL VALLE CIA. LTDA.', $F['razon_emisor']);
check('XML: subtotal', 400.0, $F['subtotal']);
check('XML: IVA', 45.0, $F['iva']);
check('XML: total', 445.0, $F['total']);
check('XML: base con IVA y base 0 %', [300.0, 100.0], [$F['base_iva'], $F['base_cero']]);
check('XML: clave de acceso de 49 dígitos', 49, strlen((string) $F['clave']));
check('XML: tres ítems', 3, count($F['items']));
check('XML: precio con el descuento del ítem (bouquet 50)', 50.0, $F['items'][1]['precio_unitario']);
check('XML: los pétalos no llevan IVA', false, $F['items'][2]['con_iva']);
check('XML sin el sobre de autorización también se lee', '001-002-000004567',
      FacturaXml::leer(preg_replace('/^.*<!\[CDATA\[(.*)\]\]>.*$/s', '$1', $fixture))['numero']);
check_error('XML dañado', 'no es un XML válido', fn() => FacturaXml::leer('<factura><infoTributaria>'));
check_error('XML vacío', 'vacío', fn() => FacturaXml::leer('  '));
check_error('Comprobante de retención', 'comprobante de retención', fn() => FacturaXml::leer('<comprobanteRetencion><infoTributaria/></comprobanteRetencion>'));
check_error('XML con entidades (ataque XXE)', 'no parece una factura', fn() => FacturaXml::leer('<?xml version="1.0"?><!DOCTYPE f [<!ENTITY x SYSTEM "file:///etc/passwd">]><factura>&x;</factura>'));
check('Fecha del SRI dd/mm/aaaa', '2026-01-05', FacturaXml::fecha('5/1/2026'));
// La compra c3 (florería, sin RUC, con 30 días de crédito) recibe el XML: toma el RUC y avisa que el total no cuadra
$fi->guardarDatos($nid, $pFlor, ['credito_dias' => '30'], $dueno);
$avisos = $co->importarXml($nid, $c3, $xmlHoy, $dueno);
$C3 = $co->compra($nid, $c3, $hoy);
check('Importar XML: número de factura', '001-002-000004567', $C3['factura_numero']);
check('Importar XML: total facturado', 445.0, (float) $C3['factura_total']);
check('Importar XML: clave de acceso', $F['clave'], $C3['factura_clave']);
check('Importar XML: el proveedor toma el RUC', '1791234567001', $fi->proveedor($nid, $pFlor)['ruc']);
check('Importar XML: fecha límite = fecha de la factura + 30 días de crédito', $dia(28), $C3['vence']);
check('Importar XML: aviso de que el total no cuadra con la orden', true, (bool) array_filter($avisos, fn($a) => str_contains($a, 'no es igual al de la orden')));
check('Importar XML: la factura es a nombre del negocio (sin aviso de RUC)', false, (bool) array_filter($avisos, fn($a) => str_contains($a, 'a nombre de')));
check('XML guardado', true, Adjuntos::ruta($C3['factura_xml']) !== null);
check('Bitácora: factura del XML', true, str_contains($fi->bitacora($nid, $pFlor)[0]['accion'], '(XML del SRI)'));
check_error('XML de otro RUC no entra', 'Revisa que sea la factura correcta', fn() => $co->importarXml($nid, $c1, $xmlHoy, $dueno));
// Factura que llega sin orden: el RUC no es de ningún proveedor → se crea el proveedor y la compra
$otroRuc = str_replace(['1791234567001', 'FLORES Y EVENTOS DEL VALLE CIA. LTDA.', 'FLORERIA EL VALLE', '000004567'], ['0991112223001', 'MANTELES ANDINOS S.A.', 'MANTELES ANDINOS', '000000088'], $xmlHoy);
$F2 = FacturaXml::leer($otroRuc);
check('RUC que no es de ningún proveedor', null, $fi->porRuc($nid, $F2['ruc_emisor']));
$pMan = $fi->crearDesdeFactura($nid, $F2, 'mobiliario', null, $dueno);
check('Proveedor creado con los datos de la factura', 'Manteles Andinos|0991112223001|MANTELES ANDINOS S.A.', implode('|', array_intersect_key($fi->proveedor($nid, $pMan), array_flip(['nombre', 'ruc', 'razon_social']))));
check_error('No se crea dos veces el mismo RUC', 'Ya tienes un proveedor con el RUC', fn() => $fi->crearDesdeFactura($nid, $F2));
$c4 = $co->crearDesdeFactura($nid, $pMan, $F2, $otroRuc, $e1, $dueno, $hoy);
$C4 = $co->compra($nid, $c4, $hoy);
check('Compra desde la factura: entregada', 'entregada', $C4['estado']);
check('Compra desde la factura: ítems del XML', 3, count($co->items($c4)));
check('Compra desde la factura: total 445', 445.0, $C4['base']);
check('Compra desde la factura: unida al evento', $c4, (int) $db->query("SELECT compra_id FROM evento_proveedores WHERE evento_id = $e1 AND proveedor_id = $pMan")->fetchColumn());
$facturaAjena = str_replace('1790000000001', '1799999999001', $xmlHoy);
$c5 = $co->crear($nid, ['proveedor_id' => $pFlor, 'estado' => 'pedida'], [['descripcion' => 'Flores sueltas', 'cantidad' => 1, 'precio_unitario' => 0]], $dueno, $hoy);
$av5 = $co->importarXml($nid, $c5, str_replace('000004567', '000004999', $facturaAjena), $dueno);
check('Aviso: la factura no está a nombre del negocio', true, (bool) array_filter($av5, fn($a) => str_contains($a, 'no del RUC de tu negocio')));
check('Si la orden no tenía precios, toma los ítems de la factura', 3, count($co->items($c5)));
$co->cambiarEstado($nid, $c5, 'confirmada', $dueno);

titulo('9. Por pagar: total, vencido, semana, antigüedad, cheques y cupo');
$c6 = $co->crear($nid, ['proveedor_id' => $pCat, 'evento_id' => $e1, 'estado' => 'confirmada', 'vence' => $dia(5)],
    [['descripcion' => 'Menú 3 tiempos', 'cantidad' => 150, 'precio_unitario' => 18]], $dueno, $hoy);
$c7 = $co->crear($nid, ['proveedor_id' => $pCat, 'estado' => 'pedida', 'fecha' => $dia(-120), 'vence' => $dia(-100)],
    [['descripcion' => 'Bocaditos para feria', 'cantidad' => 1, 'precio_unitario' => 200, 'con_iva' => false]], $dueno, $hoy);
$co->registrarPago($nid, $c6, ['monto' => '1000', 'metodo' => 'cheque', 'referencia' => 'Cheque 000201', 'cheque_fecha' => $dia(12)], null, $dueno, $hoy);
$fi->guardarDatos($nid, $pCat, ['credito_dias' => '30', 'cupo_credito' => '1500', 'anticipo_pct' => '40'], $dueno);
$pp = $co->porPagar($nid, null, $hoy);
$saldos = array_column(array_map(fn($c) => ['id' => $c['id'], 's' => $c['saldo']], $pp['compras']), 's', 'id');
check('Compras con saldo, de la que vence antes a la última', [$c7, $c2, $c4, $c6, $c3, $c5], array_map(fn($c) => (int) $c['id'], $pp['compras']));
check('Total adeudado', round(200 + 115 + 445 + 445 + 3105 - 1000 + 445, 2), $pp['total']);
check('Vencido (OC-0002, la de feria y la de manteles de contado)', 760.0, $pp['vencido']);
check('Tres compras vencidas', 3, $pp['vencidas']);
check('Vence esta semana (el catering, en 5 días)', 2105.0, $pp['semana']);
check('Antigüedad: más de 90 días', 200.0, $pp['tramos']['mas_90']);
check('Antigüedad: 31 a 60 días', 115.0, $pp['tramos']['31_60']);
check('Antigüedad: 1 a 30 días (manteles, venció hace 2)', 445.0, $pp['tramos']['1_30']);
check('Antigüedad: al día', round($pp['total'] - 760, 2), $pp['tramos']['al_dia']);
check('Cheques posfechados por cobrar', 1000.0, $pp['cheques_total']);
check('Catering pasó su cupo de 1.500', true, in_array($pCat, array_column($pp['sobre_cupo'], 'proveedor_id'), true));
check('Aviso de cupo al proveedor', true, str_contains((string) $co->avisoCupo($nid, $pCat), 'más que su cupo de crédito de $1.500,00'));
check('Primero en la lista: el que vence antes', $c7, (int) $pp['compras'][0]['id']);
check('Por proveedor: el de mayor saldo primero', $pCat, $pp['por_proveedor'][0]['proveedor_id']);
$ppEv = $co->porPagar($nid, $e1, $hoy);
check('Filtro por evento: sin la compra general', false, in_array($c2, array_map(fn($c) => (int) $c['id'], $ppEv['compras']), true));
check('Filtro por evento: total', round(445 + 445 + 2105, 2), $ppEv['total']);
$ri = $co->resumenInicio($nid, $hoy);
check('Inicio: 3 vencidas y 1 esta semana', [3, 1], [$ri['vencidas'], $ri['semana']]);
[$cab, $filasCsv] = $co->exportarPorPagar($nid, null, $hoy);
check('CSV: encabezado', 'Orden', $cab[0]);
check('CSV: una fila por compra con saldo', count($pp['compras']), count($filasCsv));
check('CSV: números con coma', '200,00', $filasCsv[0][11]);
$cta = $co->cuentaProveedor($nid, $pCat, $hoy);
check('Estado de cuenta del catering: saldo', round(2105 + 200, 2), $cta['saldo']);
check('Estado de cuenta: sobre el cupo', true, $cta['sobre_cupo']);
// Antigüedad cambia con el tiempo
check('Dentro de 70 días, el catering está en 61-90', '61_90', $co->compra($nid, $c6, $hoy->modify('+70 days'))['tramo']);

titulo('10. Ganancia real con compras (sin contar dos veces los gastos)');
$cob = new Cobranza($db);
$g0 = $cob->ganancia($nid, $e1);
$costoCompras = round(873.3 - 60 + 445 + 445 + 3105, 2);   // c1 (menos descuento) + c3 + c4 + c6 (c5 no es del evento)
check('Compras del evento', $costoCompras, $g0['compras']);
check('Compras del evento = costoEvento()', $costoCompras, $co->costoEvento($nid, $e1));
$cob->registrarGasto($nid, $e1, ['concepto' => 'Hielo y velas', 'monto' => '80'], $dueno);
$g1 = $cob->ganancia($nid, $e1);
check('Los gastos siguen contando una vez', 80.0, $g1['gastos']);
check('Costo real = gastos + compras + personal', round(80 + $costoCompras, 2), $g1['costo_real']);
check('Ganancia real = ingreso − costo real', round($g1['ingreso'] - 80 - $costoCompras, 2), $g1['ganancia_real']);
$c8 = $co->crear($nid, ['proveedor_id' => $pCat, 'evento_id' => $e1, 'estado' => 'cotizacion'], [['descripcion' => 'Menú alternativo', 'cantidad' => 150, 'precio_unitario' => 30]], $dueno, $hoy);
check('Un pedido de precio no suma al costo', $costoCompras, $cob->ganancia($nid, $e1)['compras']);
check('Un pedido de precio no va al día del evento', 1, (int) $db->query("SELECT count(*) FROM evento_proveedores WHERE evento_id = $e1 AND proveedor_id = $pCat")->fetchColumn());
$co->cambiarEstado($nid, $c8, 'cancelada', $dueno, 'Era otra opción');
check('Una cancelada tampoco', $costoCompras, $cob->ganancia($nid, $e1)['compras']);
$rent = (new Reportes($db))->rentabilidad($nid, $dia(0), $dia(30));
$fila = array_values(array_filter($rent['lista'], fn($r) => (int) $r['id'] === $e1))[0];
check('Reporte de ganancia: compras del evento', $costoCompras, $fila['compras']);
check('Reporte de ganancia: costo = gastos + compras', round(80 + $costoCompras, 2), $fila['costo']);
check('Reporte: ya no usa el costo estimado', false, $fila['usa_estimado']);
$rentG = (new Reportes($db))->rentabilidad($nid, $dia(-60), $dia(30));
check('Compras generales del negocio = gastos generales', round(115 + 445, 2), $rentG['gastos_generales']);

titulo('11. Comparar precios y elegir');
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Empresa XYZ', 'telefono' => '0977777777'], 'tipo' => 'corporativo', 'fecha' => $dia(40),
    'hora_inicio' => '12:00', 'hora_fin' => '16:00', 'invitados' => 80, 'espacios' => [$jardin]], null, $hoy);
$pFoto1 = $de->guardarProveedor($nid, null, ['nombre' => 'Foto Uno', 'servicio' => 'foto_video', 'telefono' => '0971111111']);
$pFoto2 = $de->guardarProveedor($nid, null, ['nombre' => 'Foto Dos', 'servicio' => 'foto_video']);
$pFoto3 = $de->guardarProveedor($nid, null, ['nombre' => 'Foto Tres', 'servicio' => 'foto_video']);
$q1 = $co->crear($nid, ['proveedor_id' => $pFoto1, 'evento_id' => $e2, 'estado' => 'cotizacion'], [['descripcion' => 'Fotografía 4 horas', 'cantidad' => 1, 'precio_unitario' => 450]], $dueno, $hoy);
$q2 = $co->crear($nid, ['proveedor_id' => $pFoto2, 'evento_id' => $e2, 'estado' => 'cotizacion'], [['descripcion' => 'Fotografía y video', 'cantidad' => 1, 'precio_unitario' => 380]], $dueno, $hoy);
$q3 = $co->crear($nid, ['proveedor_id' => $pFoto3, 'evento_id' => $e2, 'estado' => 'cotizacion'], [['descripcion' => 'Foto todo el evento', 'cantidad' => 1, 'precio_unitario' => 520]], $dueno, $hoy);
$cmp = $co->comparables($nid, $e2, 'foto_video');
check('Tres precios para comparar, del más barato al más caro', [$q2, $q1, $q3], array_map(fn($c) => (int) $c['id'], $cmp));
check('Cada uno con sus ítems', 'Fotografía y video', $cmp[0]['items'][0]['descripcion']);
check('Grupo para comparar en la lista', 3, (int) $co->gruposComparables($nid)[0]['cuantos']);
check('Ningún pedido de precio está todavía en el día del evento', 0, (int) $db->query("SELECT count(*) FROM evento_proveedores WHERE evento_id = $e2")->fetchColumn());
check('Se cancelaron los otros 2', 2, $co->elegirCotizacion($nid, $q2, $dueno));
check('El elegido pasa a pedida', 'pedida|1', $co->compra($nid, $q2)['estado'] . '|' . (int) $co->compra($nid, $q2)['elegida']);
check('Los otros quedan cancelados con el motivo', 'cancelada', $co->compra($nid, $q1)['estado']);
check('Motivo de cancelación', true, str_contains((string) $co->compra($nid, $q3)['motivo_cancelacion'], 'Se eligió OC-'));
check('El elegido ya está en el día del evento', $q2, (int) $db->query("SELECT compra_id FROM evento_proveedores WHERE evento_id = $e2")->fetchColumn());
check_error('No se elige algo que no es pedido de precio', 'Solo se puede elegir', fn() => $co->elegirCotizacion($nid, $q2, $dueno));
$co->cambiarEstado($nid, $q1, 'cotizacion', $dueno);
check('Reabrir una cancelada como pedido de precio', 'cotizacion', $co->compra($nid, $q1)['estado']);

titulo('12. Cumplimiento: llegada, puntualidad y calificación');
$epDj = (int) $db->query("SELECT id FROM evento_proveedores WHERE evento_id = $e1 AND proveedor_id = $pDj")->fetchColumn();
$de->editarProveedorEvento($nid, $epDj, ['hora_llegada' => '15:00']);
$fechaE1 = $dia(20);
$p1 = $fi->marcarLlegada($nid, $epDj, '15:08', $dueno);
check('Llegó 8 minutos tarde: dentro de la tolerancia (10)', 'a_tiempo', $p1['estado']);
$p2 = $fi->marcarLlegada($nid, $epDj, '15:25', $dueno);
check('Llegó 25 minutos tarde', ['tarde', 25], [$p2['estado'], $p2['min']]);
check('Texto de la puntualidad', 'Tarde 25 min', $p2['texto']);
check('Hora real guardada con la fecha del evento', "$fechaE1 15:25:00", substr((string) $db->query("SELECT llegada_real FROM evento_proveedores WHERE id = $epDj")->fetchColumn(), 0, 19));
check('Llegada de madrugada en evento de noche es del día siguiente', (new DateTimeImmutable($fechaE1))->modify('+1 day')->format('Y-m-d') . ' 00:30',
      ProveedoresFicha::momentoLlegada($fechaE1, '18:00', '00:30')->format('Y-m-d H:i'));
check('Llegada de la mañana es el mismo día', "$fechaE1 09:00", ProveedoresFicha::momentoLlegada($fechaE1, '18:00', '09:00')->format('Y-m-d H:i'));
check('Llegó antes: a tiempo', 'a_tiempo', ProveedoresFicha::puntualidad(['fecha' => $fechaE1, 'hora_inicio' => '18:00', 'hora_llegada' => '15:00', 'llegada_real' => "$fechaE1 14:40"])['estado']);
check('Texto al llegar antes', 'A tiempo (1 h 5 min antes)', ProveedoresFicha::puntualidad(['fecha' => $fechaE1, 'hora_inicio' => '18:00', 'hora_llegada' => '15:00', 'llegada_real' => "$fechaE1 13:55"])['texto']);
check('Duración en horas', ['25 min', '2 h', '2 h 5 min'], [ProveedoresFicha::duracion(25), ProveedoresFicha::duracion(120), ProveedoresFicha::duracion(125)]);
check('Sin hora pactada no se mide', null, ProveedoresFicha::puntualidad(['fecha' => $fechaE1, 'hora_inicio' => '18:00', 'hora_llegada' => null, 'llegada_real' => "$fechaE1 14:40"]));
check('Bitácora de la llegada', true, str_contains($fi->bitacora($nid, $pDj)[0]['accion'], 'Llegó a las 15:25 · tarde 25 min'));
check_error('No llegó, pero tiene hora', 'Bórrala primero', fn() => $fi->evaluar($nid, $epDj, ['cumplimiento' => 'no_llego'], $dueno));
check_error('Calificación de 6 estrellas', 'de 1 a 5', fn() => $fi->evaluar($nid, $epDj, ['calificacion' => '6'], $dueno));
check_error('Evaluación vacía', 'Elige si cumplió', fn() => $fi->evaluar($nid, $epDj, [], $dueno));
$fi->evaluar($nid, $epDj, ['cumplimiento' => 'observaciones', 'calificacion' => '4', 'comentario' => 'Buena música, llegó tarde'], $dueno);
check('Evaluación guardada', 'observaciones|4', (string) $db->query("SELECT cumplimiento || '|' || calificacion FROM evento_proveedores WHERE id = $epDj")->fetchColumn());
// Cronograma con el proveedor responsable y modo en vivo
$de->cargarEjemplo($nid, $e1);
$vals = (int) $db->query("SELECT id FROM cronograma WHERE evento_id = $e1 AND actividad = 'Vals'")->fetchColumn();
$hl = (int) $db->query("SELECT id FROM cronograma WHERE evento_id = $e1 AND actividad = 'Hora loca'")->fetchColumn();
$de->guardarBloque($nid, $e1, $vals, ['hora' => '20:25', 'duracion_min' => 10, 'actividad' => 'Vals', 'evento_proveedor_id' => $epDj]);
$de->guardarBloque($nid, $e1, $hl, ['hora' => '22:00', 'duracion_min' => 45, 'actividad' => 'Hora loca', 'responsable' => '', 'evento_proveedor_id' => $epDj]);
check('El bloque queda a cargo del DJ', $epDj, (int) $db->query("SELECT evento_proveedor_id FROM cronograma WHERE id = $vals")->fetchColumn());
check('Si no hay responsable escrito, se pone el nombre del proveedor', 'DJ Fiesta Total', $db->query("SELECT responsable FROM cronograma WHERE id = $hl")->fetchColumn());
$epFlor = (int) $db->query("SELECT id FROM evento_proveedores WHERE evento_id = $e2 LIMIT 1")->fetchColumn();
check_error('No se asigna un proveedor de otro evento', 'no está en este evento', fn() => $de->guardarBloque($nid, $e1, $vals, ['hora' => '20:25', 'actividad' => 'Vals', 'evento_proveedor_id' => $epFlor]));
$de->guardarBloque($nid, $e1, $vals, ['hora' => '20:25', 'duracion_min' => 10, 'actividad' => 'Vals']);
check('Editar sin el campo no borra el proveedor', $epDj, (int) $db->query("SELECT evento_proveedor_id FROM cronograma WHERE id = $vals")->fetchColumn());
$db->exec("UPDATE cronograma SET inicio_real = '$fechaE1 20:30' WHERE id = $vals");       // 5 min tarde: a tiempo
$db->exec("UPDATE cronograma SET inicio_real = '$fechaE1 22:40' WHERE id = $hl");         // 40 min tarde
$bl = $fi->bloquesDe($epDj);
check('Bloques del DJ: vals a tiempo, hora loca tarde', [true, false], array_column($bl, 'a_tiempo'));
check('Atraso de la hora loca', 40, $bl[1]['atraso']);

titulo('13. Puntaje y ranking');
// El DJ además trabajó en un evento pasado y cumplió perfecto
$e0 = $ev->crear($nid, ['cliente' => ['nombre' => 'Sofía Quince', 'telefono' => '0966666666'], 'tipo' => 'quince', 'fecha' => $dia(-30),
    'hora_inicio' => '19:00', 'hora_fin' => '01:00', 'invitados' => 100, 'espacios' => [$salon]], null, $hoy->modify('-60 days'));
$db->exec("UPDATE eventos SET estado = 'realizado' WHERE id = $e0");
$ep0 = $de->agregarProveedorEvento($nid, $e0, ['proveedor_id' => $pDj, 'hora_llegada' => '16:00']);
$fi->marcarLlegada($nid, $ep0, '15:50', $dueno);
$fi->evaluar($nid, $ep0, ['cumplimiento' => 'cumplio', 'calificacion' => '5'], $dueno);
$epCat0 = $de->agregarProveedorEvento($nid, $e0, ['proveedor_id' => $pCat, 'hora_llegada' => '14:00']);
$fi->evaluar($nid, $epCat0, ['cumplimiento' => 'no_llego', 'calificacion' => '1', 'comentario' => 'No vino'], $dueno);
$inc->crear($nid, ['evento_id' => $e0, 'proveedor_id' => $pCat, 'gravedad' => 'grave', 'descripcion' => 'No llegó la comida, se pidió a otro lado'], null, $dueno);
$pj = $fi->puntajes($nid, $hoy);
$sDj = $pj[$pDj];
check('DJ: 1 evento trabajado (pasado) y 1 próximo', [1, 1], [$sDj['eventos'], $sDj['proximos']]);
check('DJ: puntualidad 2 de 4 (llegó a tiempo 1 de 2 y 1 de 2 bloques a tiempo)', 50, $sDj['puntualidad']);
check('DJ: calificación promedio', 4.5, $sDj['calificacion']);
check('DJ: cumplimiento (1 completo + 1 con observaciones)', 75, $sDj['cumplimiento']);
check('DJ: 1 incidencia', 1, $sDj['incidencias']);
check('DJ: comprado en el año (sin las canceladas)', 873.3, $sDj['comprado_anio']);
check('DJ: saldo en cero', 0.0, $sDj['saldo']);
check('DJ: puntaje = 4,5★(40) + 50(30) + 75(20) + incidencia media (10)', (int) round((90 * 40 + 50 * 30 + 75 * 20 + 75 * 10) / 100), $sDj['puntaje']);
check('DJ: nivel', 'bueno', $sDj['nivel']);
$sCat = $pj[$pCat];
check('Catering: no llegó cuenta como impuntual', 0, $sCat['puntualidad']);
check('Catering: puntaje bajo', 'malo', $sCat['nivel']);
check('Catering: saldo pendiente', 2305.0, $sCat['saldo']);
check('Proveedor sin datos: sin puntaje', null, $pj[$pFoto3]['puntaje']);
$rk = $fi->ranking($nid, $hoy);
check('Ranking: el DJ primero', $pDj, $rk[0]['proveedor_id']);
check('Ranking: los sin datos al final', null, end($rk)['puntaje']);
check('Texto del puntaje junto al nombre', '★ 4,5 · ' . $sDj['puntaje'] . ' pts', ProveedoresFicha::textoPuntaje($sDj));
$evs = $fi->eventosDe($nid, $pDj, $hoy);
check('Eventos del DJ: 1 próximo y 1 pasado', [1, 1], [count($evs['proximos']), count($evs['pasados'])]);
check('Con su puntualidad', 'a_tiempo', $evs['pasados'][0]['puntualidad']['estado']);
$hp = $fi->historialPrecios($nid, $pDj);
check('Historial de precios del DJ', true, in_array('Máquina de humo', array_column($hp, 'descripcion'), true));
$c9 = $co->crear($nid, ['proveedor_id' => $pDj, 'estado' => 'pedida'], [['descripcion' => 'Luces robóticas', 'cantidad' => 2, 'precio_unitario' => 30]], $dueno, $hoy);
check('Cambio de precio contra la compra anterior (25,50 → 30)', 17.6, $fi->historialPrecios($nid, $pDj)[0]['cambio_pct']);
$co->cambiarEstado($nid, $c9, 'cancelada', $dueno, 'Prueba');

titulo('14. Incidencias');
check('Incidencia grave: aviso al dueño', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid AND texto LIKE '🚨 Incidencia grave%'")->fetchColumn());
$i3 = $inc->crear($nid, ['evento_id' => $e1, 'area' => 'instalaciones', 'gravedad' => 'leve', 'descripcion' => 'Se fue la luz 5 minutos en el jardín', 'responsable' => 'Mantenimiento'], null, $dueno);
check('Incidencia sin proveedor va al historial del evento', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $e1 AND accion LIKE 'Incidencia leve (Instalaciones)%'")->fetchColumn());
check_error('Descripción muy corta', 'Cuenta qué pasó', fn() => $inc->crear($nid, ['descripcion' => 'mal']));
check_error('Área inventada', 'Elige el área', fn() => $inc->crear($nid, ['area' => 'cocina', 'descripcion' => 'Se quemó el arroz']));
check_error('Resolver sin escribir cómo', 'cómo se resolvió', fn() => $inc->actualizar($nid, $i3, ['estado' => 'resuelta'], $dueno));
$inc->actualizar($nid, $i3, ['estado' => 'resuelta', 'resolucion' => 'Se prendió el generador'], $dueno);
check('Resuelta con fecha', true, $inc->incidencia($nid, $i3)['resuelta_en'] !== null);
check('Abiertas: la del DJ (en proceso) y la del catering', 2, $inc->abiertas($nid));
check('Lista de abiertas: primero la grave', 'grave', $inc->lista($nid, ['estado' => 'abiertas'])[0]['gravedad']);
check('Incidencias del evento', 2, count($inc->deEvento($nid, $e1)));
check('La hoja del evento trae las incidencias', 2, count($de->hoja($nid, $e1)['incidencias']));

titulo('15. Portal del proveedor');
$tokDj = $fi->proveedor($nid, $pDj)['token'];
$P = $fi->porToken($tokDj);
check('Portal: entra con el enlace', $pDj, (int) $P['id']);
check('Portal: enlace falso', null, $fi->porToken('abc'));
$evP = $fi->eventosPortal($P, $hoy);
check('Portal: ve su próximo evento', [$e1], array_map(fn($x) => (int) $x['evento_id'], $evP));
check('Portal: ve sus bloques del cronograma', ['Vals', 'Hora loca'], array_column($evP[0]['bloques'], 'actividad'));
$c10 = $co->crear($nid, ['proveedor_id' => $pDj, 'evento_id' => $e2, 'estado' => 'pedida'], [['descripcion' => 'DJ evento corporativo', 'cantidad' => 1, 'precio_unitario' => 350]], $dueno, $hoy);
$c11 = $co->crear($nid, ['proveedor_id' => $pDj, 'estado' => 'pedida'], [['descripcion' => 'Parlantes para la oficina', 'cantidad' => 1, 'precio_unitario' => 90]], $dueno, $hoy);
$ordenes = $co->delPortal($P, $hoy);
check('Portal: ve sus órdenes (no los pedidos de precio)', true, in_array($c10, array_map(fn($c) => (int) $c['id'], $ordenes), true));
$co->responderDesdePortal($P, $c10, true, 'Listo, confirmado');
check('Portal: confirmó la orden', 'confirmada|proveedor', $co->compra($nid, $c10)['estado'] . '|' . $co->compra($nid, $c10)['confirmada_por']);
check('Portal: comentario del proveedor', 'Listo, confirmado', $co->compra($nid, $c10)['respuesta_proveedor']);
check('Portal: aviso al negocio', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid AND texto LIKE '✅ DJ Fiesta Total confirmó%'")->fetchColumn());
$mov = $fi->bitacora($nid, $pDj)[0];
check('Bitácora: origen proveedor', 'proveedor|Proveedor', $mov['origen'] . '|' . $mov['quien']);
check_error('Rechazar sin decir por qué', 'Cuéntanos por qué', fn() => $co->responderDesdePortal($P, $c11, false, ''));
$co->responderDesdePortal($P, $c11, false, 'No tengo parlantes ese día');
check('Portal: rechazó (queda cancelada)', 'cancelada', $co->compra($nid, $c11)['estado']);
check('Motivo del rechazo', true, str_contains((string) $co->compra($nid, $c11)['motivo_cancelacion'], 'Rechazada por el proveedor'));
check_error('No responde dos veces', 'ya está', fn() => $co->responderDesdePortal($P, $c10, true));
check_error('No toca órdenes de otro proveedor', 'No se encontró esa orden', fn() => $co->responderDesdePortal($P, $c6, true));
$xmlDj = str_replace(['1791234567001', '000004567'], ['1712345678001', '000000901'], $xmlHoy);
$co->facturaDesdePortal($P, $c10, $subida('xml', $xmlDj));
check('Portal: subió su factura XML', '001-002-000000901|proveedor', $co->compra($nid, $c10)['factura_numero'] . '|' . $co->compra($nid, $c10)['factura_origen']);
$co->facturaDesdePortal($P, $c1, $subida('pdf'), '001-001-55');
check('Portal: subió su factura PDF con número', '001-001-000000055', $co->compra($nid, $c1)['factura_numero']);
check_error('Portal: factura de otra persona (otro RUC)', 'Revisa que sea la factura correcta', fn() => $co->facturaDesdePortal($P, $c10, $subida('xml', $xmlHoy)));
$fi->portalConfirmarEvento($P, $evP[0]['id'], $hoy);
check('Portal: confirmó que va al evento', true, (bool) $db->query('SELECT confirmado FROM evento_proveedores WHERE id = ' . (int) $evP[0]['id'])->fetchColumn());
$fi->quitarLlegada($nid, $epDj, $dueno);
check_error('"Ya llegué" antes del día', 'mismo día del evento', fn() => $fi->portalLlegue($P, $epDj, new DateTimeImmutable("$fechaE1 00:00 -1 minute")));
$pu = $fi->portalLlegue($P, $epDj, new DateTimeImmutable("$fechaE1 14:55"));
check('"Ya llegué" el día del evento: a tiempo', 'a_tiempo', $pu['estado']);
check('Llegada marcada por el proveedor', 'proveedor', $db->query("SELECT llegada_origen FROM evento_proveedores WHERE id = $epDj")->fetchColumn());
check_error('No marca dos veces', 'Ya registraste tu llegada', fn() => $fi->portalLlegue($P, $epDj, new DateTimeImmutable("$fechaE1 15:30")));
check('Aviso de llegada al equipo', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid AND texto LIKE '🚚 DJ Fiesta Total llegó%'")->fetchColumn());
check('Puede marcar hasta las 6:00 del día siguiente', true, ProveedoresFicha::puedeMarcarLlegada(['fecha' => $fechaE1, 'evento_estado' => 'confirmado'], new DateTimeImmutable("$fechaE1 05:00 +1 day")));
check('…pero no después', false, ProveedoresFicha::puedeMarcarLlegada(['fecha' => $fechaE1, 'evento_estado' => 'confirmado'], new DateTimeImmutable("$fechaE1 06:01 +1 day")));
$tokNuevo = $fi->nuevoToken($nid, $pDj, $dueno);
check('Cambiar el enlace: el viejo ya no sirve', [null, $pDj], [$fi->porToken($tokDj), (int) $fi->porToken($tokNuevo)['id']]);
$msg = Compras::mensajeOrden($co->compra($nid, $c6), $co->items($c6), $de->negocio($nid), 'https://x.ec/?r=portal_proveedor&t=abc');
check('Mensaje de WhatsApp de la orden: código y total', true, str_contains($msg, 'OC-0006') && str_contains($msg, '$3.105,00'));
check('Mensaje con el enlace del portal', true, str_contains($msg, 'r=portal_proveedor&t=abc'));
check('Verificación del QR de la orden', 'OC-0006', $co->verificar($co->compra($nid, $c6)['codigo_verificacion'])['codigo']);
check('Código de verificación falso', null, $co->verificar('XXXX'));

titulo('16. Tareas automáticas');
$auto = new AvisosProveedores($db);
$a7 = new DateTimeImmutable("$H 07:00");
$a9 = new DateTimeImmutable("$H 09:00");
check('Antes de las 8:00 no avisa pagos', 0, $auto->avisarPagos($nid, $a7));
$n = $auto->avisarPagos($nid, $a9);
check('Avisa las compras que vencen en 3 días o vencieron', true, $n >= 2);
check('Una sola vez al día por compra', 0, $auto->avisarPagos($nid, $a9->modify('+2 hours')));
check('Al día siguiente vuelve a avisar las vencidas', true, $auto->avisarPagos($nid, $a9->modify('+1 day')) >= 2);
check('Aviso en la campana (dueño)', true, (int) $db->query("SELECT count(*) FROM notificaciones WHERE usuario_id = {$dueno['id']} AND texto LIKE '🧾 Pagos a proveedores:%'")->fetchColumn() >= 1);
check('Documentos por vencer (permiso vencido y póliza)', 2, $auto->avisarDocumentos($nid, $a9));
check('Documentos: una sola vez', 0, $auto->avisarDocumentos($nid, $a9->modify('+1 day')));
$db->exec("UPDATE proveedor_documentos SET vence = vence + 365 WHERE id = $d2");
check('Si renuevan el documento con otra fecha, se avisará de nuevo cuando llegue', 0, $auto->avisarDocumentos($nid, $a9));
$e3 = $ev->crear($nid, ['cliente' => ['nombre' => 'Luis Mena', 'telefono' => '0955555555'], 'tipo' => 'cumpleanos', 'fecha' => $dia(2),
    'hora_inicio' => '15:00', 'hora_fin' => '20:00', 'invitados' => 40, 'espacios' => [$jardin]], null, $hoy);
$ev->cambiarEstado($nid, $e3, 'separado', [], $dueno, $hoy);
$de->agregarProveedorEvento($nid, $e3, ['proveedor_id' => $pFoto1, 'hora_llegada' => '13:00']);
$de->agregarProveedorEvento($nid, $e3, ['nombre' => 'Payaso Pepito', 'servicio' => 'otros', 'lo_trae_cliente' => 1]);
check('Proveedores sin confirmar a 2 días del evento', 2, $auto->avisarSinConfirmar($nid, $a9));
check('Sin confirmar: una sola vez por fecha', 0, $auto->avisarSinConfirmar($nid, $a9->modify('+1 day')));
check('Aviso con los nombres', true, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid AND evento_id = $e3 AND texto LIKE '⏰%Foto Uno, Payaso Pepito%'")->fetchColumn() >= 1);
$r = (new Automaticas($db))->correr(new DateTimeImmutable("$H 10:00"));
check('correr() incluye los avisos de proveedores', true, array_key_exists('prov_pagos', $r) && array_key_exists('prov_documentos', $r) && array_key_exists('prov_confirmar', $r));
check('resumen() los muestra', true, str_contains(Automaticas::resumen(new DateTimeImmutable("$H 10:00"), $r), 'pagos a proveedores'));

titulo('17. PDF de la orden de compra y estado de cuenta');
$pdfOc = Pdf::comoTexto((new PdfCompras($db))->orden($nid, $c6, 'https://turenta.tukanec.com/?r=verificar_oc&c=' . $co->compra($nid, $c6)['codigo_verificacion']));
check('Orden de compra en PDF', '%PDF', substr($pdfOc, 0, 4));
$pdfPrecio = Pdf::comoTexto((new PdfCompras($db))->orden($nid, $q1, 'https://x/?r=verificar_oc&c=' . $co->compra($nid, $q1)['codigo_verificacion']));
check('Pedido de precio en PDF', '%PDF', substr($pdfPrecio, 0, 4));
check('Estado de cuenta del proveedor en PDF', '%PDF', substr(Pdf::comoTexto((new PdfCompras($db))->estadoCuenta($nid, $pCat, $hoy)), 0, 4));
check('Hoja del evento con incidencias en PDF', '%PDF', substr(Pdf::comoTexto(\TuRenta\PdfDia::hoja($de->hoja($nid, $e1))), 0, 4));

titulo('18. Un negocio nunca ve lo de otro');
[$nidB, $duenoB] = negocio_prueba($db, 'quinta', 'Hacienda Ajena');
$pB = $de->guardarProveedor($nidB, null, ['nombre' => 'Proveedor de B', 'servicio' => 'otros']);
check('B no ve la compra de A', null, $co->compra($nidB, $c1));
check('B no ve la ficha del proveedor de A', null, $fi->proveedor($nidB, $pDj));
check('B no ve las compras de A en su lista', 0, count($co->lista($nidB)));
check('Por pagar de B vacío', 0.0, $co->porPagar($nidB)['total']);
check('B no ve los documentos de A', 0, count($fi->documentos($nidB, $pCat)));
check('B no ve la incidencia de A', null, $inc->incidencia($nidB, $i1));
check('B no ve las incidencias de A', 0, count($inc->lista($nidB)));
check('B no ve la bitácora del proveedor de A', 0, count($fi->bitacora($nidB, $pDj)));
check('B no ve el comprobante de un pago de A', null, $co->rutaComprobante($nidB, $pg1));
check('B no ve el pago de A', null, $co->pago($nidB, $pg1));
check('Puntajes de B: solo los suyos', [$pB], array_keys($fi->puntajes($nidB)));
check_error('B no compra al proveedor de A', 'Elige el proveedor', fn() => $co->crear($nidB, ['proveedor_id' => $pDj], [['descripcion' => 'X', 'precio_unitario' => 1]]));
check_error('B no hace una compra para el evento de A', 'No se encontró el evento', fn() => $co->crear($nidB, ['proveedor_id' => $pB, 'evento_id' => $e1], [['descripcion' => 'X', 'precio_unitario' => 1]]));
check_error('B no paga una compra de A', 'No se encontró esa compra', fn() => $co->registrarPago($nidB, $c6, ['monto' => '1'], null, $duenoB));
check_error('B no anula un pago de A', 'No se encontró ese pago', fn() => $co->anularPago($nidB, $pg1, 'intento'));
check_error('B no cambia el estado de una compra de A', 'No se encontró esa compra', fn() => $co->cambiarEstado($nidB, $c6, 'entregada'));
check_error('B no sube documentos al proveedor de A', 'No se encontró el proveedor', fn() => $fi->agregarDocumento($nidB, $pDj, ['tipo' => 'otro', 'vence' => $H]));
check_error('B no borra documentos de A', 'ya no existe', fn() => $fi->borrarDocumento($nidB, $d1));
check_error('B no marca llegadas en eventos de A', 'No se encontró ese proveedor del evento', fn() => $fi->marcarLlegada($nidB, $epDj));
check_error('B no evalúa proveedores de A', 'No se encontró ese proveedor del evento', fn() => $fi->evaluar($nidB, $epDj, ['calificacion' => '1']));
check_error('B no reporta incidencias en eventos de A', 'No se encontró el evento', fn() => $inc->crear($nidB, ['evento_id' => $e1, 'descripcion' => 'Algo pasó aquí']));
check_error('B no descuenta compras de A', 'No se encontró la compra', fn() => $inc->crear($nidB, ['compra_id' => $c6, 'descripcion' => 'Algo pasó aquí', 'descuento' => '1']));
check_error('B no cambia incidencias de A', 'No se encontró esa incidencia', fn() => $inc->actualizar($nidB, $i1, ['estado' => 'resuelta', 'resolucion' => 'x']));
check_error('B no cambia los datos del proveedor de A', 'No se encontró el proveedor', fn() => $fi->guardarDatos($nidB, $pDj, ['credito_dias' => '0']));
check_error('B no importa XML en compras de A', 'No se encontró esa compra', fn() => $co->importarXml($nidB, $c3, $fixture));
check('El mismo RUC sí puede estar en otro negocio', true, (function () use ($fi, $de, $nidB) {
    $x = $de->guardarProveedor($nidB, null, ['nombre' => 'DJ en B']);
    $fi->guardarDatos($nidB, $x, ['ruc' => '1712345678001']);
    return true;
})());
$PB = $fi->porToken($fi->proveedor($nidB, $pB)['token']);
check('El portal del proveedor de B no ve órdenes de A', 0, count($co->delPortal($PB, $hoy)));
check_error('El proveedor de B no responde órdenes de A', 'No se encontró esa orden', fn() => $co->responderDesdePortal($PB, $c6, true));
check_error('El proveedor de B no marca llegada en eventos de A', 'No se encontró ese evento', fn() => $fi->portalLlegue($PB, $epDj));
check('Costo de compras de un evento de A pedido por B: cero', 0.0, $co->costoEvento($nidB, $e1));

titulo('19. Puntaje configurable por negocio (091)');
$pj = new PuntajeProveedores($db);
$C = fn(array $x) => array_merge(PuntajeProveedores::RECOMENDADO, $x);
check('Sin configuración: los valores recomendados', PuntajeProveedores::RECOMENDADO, $pj->config($nid));
check('Sin configuración: es la recomendada', true, $pj->esRecomendada($nid));
// Los valores recomendados dan EXACTAMENTE la fórmula de antes (40/30/20/10, incidencias 10/25/50, tope en Bueno)
$base = $fi->puntajes($nid, $hoy);
$antes = function (array $r): ?int {
    $partes = [];
    if ($r['calificacion'] !== null) $partes[] = [$r['calificacion'] * 20, 40];
    if ($r['puntualidad'] !== null) $partes[] = [$r['puntualidad'], 30];
    if ($r['cumplimiento'] !== null) $partes[] = [$r['cumplimiento'], 20];
    if (!$partes) return null;
    $partes[] = [100 - min(100, $r['graves'] * 50 + $r['medias'] * 25 + max(0, $r['incidencias'] - $r['graves'] - $r['medias']) * 10), 10];
    return (int) round(array_sum(array_map(fn($x) => $x[0] * $x[1], $partes)) / array_sum(array_column($partes, 1)));
};
check('Recomendados = fórmula de antes (todos los proveedores)', array_map($antes, $base), array_map(fn($r) => $r['puntaje'], $base));
check('Pasar la configuración recomendada a mano da lo mismo', $base, $fi->puntajes($nid, $hoy, PuntajeProveedores::RECOMENDADO));
// Dos fotógrafos: uno puntual con 2 estrellas, otro tarde con 5 estrellas
$e4 = $ev->crear($nid, ['cliente' => ['nombre' => 'Rocío Andrade', 'telefono' => '0944444444'], 'tipo' => 'boda', 'fecha' => $dia(-10),
    'hora_inicio' => '18:00', 'hora_fin' => '23:00', 'invitados' => 90, 'espacios' => [$jardin]], null, $hoy->modify('-40 days'));
$db->exec("UPDATE eventos SET estado = 'realizado' WHERE id = $e4");
$db->exec("UPDATE proveedores SET activo = true WHERE id IN ($pFoto2, $pFoto3)");
$epF2 = $de->agregarProveedorEvento($nid, $e4, ['proveedor_id' => $pFoto2, 'hora_llegada' => '14:00']);
$epF3 = $de->agregarProveedorEvento($nid, $e4, ['proveedor_id' => $pFoto3, 'hora_llegada' => '14:00']);
$fi->marcarLlegada($nid, $epF2, '13:55', $dueno);
$fi->marcarLlegada($nid, $epF3, '15:00', $dueno);
$fi->evaluar($nid, $epF2, ['cumplimiento' => 'cumplio', 'calificacion' => '2'], $dueno);
$fi->evaluar($nid, $epF3, ['cumplimiento' => 'cumplio', 'calificacion' => '5'], $dueno);
$orden = fn(array $cfg) => array_values(array_filter(array_map(fn($r) => $r['proveedor_id'], $fi->ranking($nid, $hoy, true, $cfg)), fn($id) => in_array($id, [$pFoto2, $pFoto3], true)));
$soloCal = $C(['peso_calificacion' => 100, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0]);
$soloPun = $C(['peso_calificacion' => 0, 'peso_puntualidad' => 100, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0]);
check('Solo estrellas: 5★ = 100 y 2★ = 40', [100, 40], [$fi->puntaje($nid, $pFoto3, $hoy, $soloCal)['puntaje'], $fi->puntaje($nid, $pFoto2, $hoy, $soloCal)['puntaje']]);
check('Solo estrellas: gana el de 5★ en el ranking', [$pFoto3, $pFoto2], $orden($soloCal));
check('Solo puntualidad: gana el puntual', [$pFoto2, $pFoto3], $orden($soloPun));
check('Solo puntualidad: el que llegó 1 hora tarde tiene 0', 0, $fi->puntaje($nid, $pFoto3, $hoy, $soloPun)['puntaje']);
$sinPun = $C(['peso_calificacion' => 50, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 50, 'peso_incidencias' => 0]);
$r3 = $fi->puntaje($nid, $pFoto3, $hoy, $sinPun);
check('Peso 0: la puntualidad no se toma en cuenta', 100, $r3['puntaje']);
check('Peso 0: no aparece en el detalle', ['peso_calificacion', 'peso_cumplimiento'], array_keys($r3['detalle']));
check('Detalle con el peso real de cada parte', 50.0, $r3['detalle']['peso_calificacion']['peso']);
$repartido = $fi->puntaje($nid, $pFoto3, $hoy, $C(['peso_calificacion' => 40, 'peso_puntualidad' => 30, 'peso_cumplimiento' => 0, 'peso_incidencias' => 30]));
check('Lo que no tiene datos o pesa 0 reparte su peso (40/30/30)', (int) round((100 * 40 + 0 * 30 + 100 * 30) / 100), $repartido['puntaje']);
// Tolerancia
check('Con 10 min de tolerancia, llegar 60 min tarde es tarde', 0, $fi->puntaje($nid, $pFoto3, $hoy, $soloPun)['puntualidad']);
check('Con 60 min de tolerancia, cuenta a tiempo', 100, $fi->puntaje($nid, $pFoto3, $hoy, $C(['tolerancia_min' => 60]))['puntualidad']);
check('Tolerancia en la puntualidad de un evento', 'a_tiempo', ProveedoresFicha::puntualidad(['fecha' => $dia(-10), 'hora_inicio' => '18:00', 'hora_llegada' => '14:00', 'llegada_real' => $dia(-10) . ' 14:15'], 20)['estado']);
check('…y sin ella (10 min recomendados)', 'tarde', ProveedoresFicha::puntualidad(['fecha' => $dia(-10), 'hora_inicio' => '18:00', 'hora_llegada' => '14:00', 'llegada_real' => $dia(-10) . ' 14:15'])['estado']);
// Tope con incidencia grave
$inc->crear($nid, ['evento_id' => $e4, 'proveedor_id' => $pFoto3, 'gravedad' => 'grave', 'descripcion' => 'Perdió las fotos de la ceremonia'], null, $dueno);
$r = $fi->puntaje($nid, $pFoto3, $hoy, $soloCal);
check('Grave con tope (recomendado): 100 puntos pero nivel Bueno', [100, 'bueno', true], [$r['puntaje'], $r['nivel'], $r['tope_aplicado']]);
check('Tope en Regular', 'regular', $fi->puntaje($nid, $pFoto3, $hoy, $C(['peso_calificacion' => 100, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0, 'tope_nivel' => 'regular']))['nivel']);
check('Sin tope: Excelente', 'excelente', $fi->puntaje($nid, $pFoto3, $hoy, $C(['peso_calificacion' => 100, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0, 'tope_grave' => false]))['nivel']);
$conInc = $C(['peso_calificacion' => 50, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 0, 'peso_incidencias' => 50, 'tope_grave' => false]);
check('Una grave resta 50 (recomendado): (100 + 50) / 2', 75, $fi->puntaje($nid, $pFoto3, $hoy, $conInc)['puntaje']);
check('Si la grave resta 100: (100 + 0) / 2', 50, $fi->puntaje($nid, $pFoto3, $hoy, array_merge($conInc, ['resta_grave' => 100]))['puntaje']);
// Umbrales
check('Umbrales: 92 es Bueno si Excelente empieza en 95', 'bueno', PuntajeProveedores::nivel(92, $C(['umbral_excelente' => 95, 'umbral_bueno' => 90, 'umbral_regular' => 80])));
check('Umbrales: 85 es Regular con esos niveles', 'regular', PuntajeProveedores::nivel(85, $C(['umbral_excelente' => 95, 'umbral_bueno' => 90, 'umbral_regular' => 80])));
check('Umbrales: 79 es Malo con esos niveles', 'malo', PuntajeProveedores::nivel(79, $C(['umbral_excelente' => 95, 'umbral_bueno' => 90, 'umbral_regular' => 80])));
check('Umbrales recomendados: 85 Excelente, 84 Bueno', ['excelente', 'bueno'], [PuntajeProveedores::nivel(85, PuntajeProveedores::RECOMENDADO), PuntajeProveedores::nivel(84, PuntajeProveedores::RECOMENDADO)]);
// Periodo: un evento de hace 8 meses
$e5 = $ev->crear($nid, ['cliente' => ['nombre' => 'Mario Vega', 'telefono' => '0933333333'], 'tipo' => 'cumpleanos', 'fecha' => $dia(-240),
    'hora_inicio' => '15:00', 'hora_fin' => '20:00', 'invitados' => 50, 'espacios' => [$jardin]], null, $hoy->modify('-300 days'));
$db->exec("UPDATE eventos SET estado = 'realizado' WHERE id = $e5");
$epF2b = $de->agregarProveedorEvento($nid, $e5, ['proveedor_id' => $pFoto2]);
$fi->evaluar($nid, $epF2b, ['calificacion' => '1'], $dueno);
check('Todo el historial: 2 eventos y 1,5★', [2, 1.5], [$fi->puntaje($nid, $pFoto2, $hoy)['eventos'], $fi->puntaje($nid, $pFoto2, $hoy)['calificacion']]);
check('Últimos 12 meses: también cuenta', 1.5, $fi->puntaje($nid, $pFoto2, $hoy, $C(['periodo_meses' => 12]))['calificacion']);
check('Últimos 6 meses: solo el evento reciente (2★)', [1, 2.0], [$fi->puntaje($nid, $pFoto2, $hoy, $C(['periodo_meses' => 6]))['eventos'], $fi->puntaje($nid, $pFoto2, $hoy, $C(['periodo_meses' => 6]))['calificacion']]);
$incViejo = $inc->crear($nid, ['evento_id' => $e5, 'proveedor_id' => $pFoto2, 'gravedad' => 'leve', 'descripcion' => 'Llegó sin batería extra'], null, $dueno);
check('Periodo: la incidencia vieja no cuenta en 6 meses', [1, 0], [$fi->puntaje($nid, $pFoto2, $hoy)['incidencias'], $fi->puntaje($nid, $pFoto2, $hoy, $C(['periodo_meses' => 6]))['incidencias']]);
// Mínimo de eventos
$min3 = $fi->puntaje($nid, $pFoto2, $hoy, $C(['minimo_eventos' => 3]));
check('Mínimo 3 eventos: sin puntaje', [null, null, true], [$min3['puntaje'], $min3['nivel'], $min3['pocos_datos']]);
check('Mínimo 3 eventos: "Sin datos suficientes"', 'Sin datos suficientes', ProveedoresFicha::textoSinPuntaje($min3));
check('Mínimo 2 eventos: ya tiene puntaje', true, $fi->puntaje($nid, $pFoto2, $hoy, $C(['minimo_eventos' => 2]))['puntaje'] !== null);
check('Sin datos de verdad: "Sin datos"', 'Sin datos', ProveedoresFicha::textoSinPuntaje($fi->puntaje($nid, $pFoto1, $hoy)));
check('Mínimo 2: el de 1 evento queda sin puntaje y va después en el ranking', [$pFoto2, $pFoto3], $orden($C(['minimo_eventos' => 2, 'peso_calificacion' => 0, 'peso_puntualidad' => 100, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0])));
check('…aunque con mínimo 0 iría primero', [$pFoto3, $pFoto2], $orden($C(['minimo_eventos' => 0, 'peso_calificacion' => 100, 'peso_puntualidad' => 0, 'peso_cumplimiento' => 0, 'peso_incidencias' => 0, 'tope_grave' => false])));
// Validación
$ok = PuntajeProveedores::RECOMENDADO;
check_error('Los pesos deben sumar 100', 'Ahora suman 95 %', fn() => PuntajeProveedores::validar(array_merge($ok, ['peso_incidencias' => 5])));
check_error('Peso con letras', 'número entero', fn() => PuntajeProveedores::validar(array_merge($ok, ['peso_calificacion' => 'mucho'])));
check_error('Peso negativo', 'número entero', fn() => PuntajeProveedores::validar(array_merge($ok, ['peso_calificacion' => '-10', 'peso_puntualidad' => '80'])));
check_error('Tolerancia demasiado grande', 'va de 0 a 120', fn() => PuntajeProveedores::validar(array_merge($ok, ['tolerancia_min' => '200'])));
check_error('Leve no puede restar más que grave', 'leve ≤ media ≤ grave', fn() => PuntajeProveedores::validar(array_merge($ok, ['resta_leve' => '60'])));
check_error('Niveles desordenados', 'de mayor a menor', fn() => PuntajeProveedores::validar(array_merge($ok, ['umbral_bueno' => '90'])));
check_error('Excelente más de 100', 'va de 1 a 100', fn() => PuntajeProveedores::validar(array_merge($ok, ['umbral_excelente' => '120'])));
check_error('Periodo inventado', 'periodo', fn() => PuntajeProveedores::validar(array_merge($ok, ['periodo_meses' => '3'])));
check_error('Tope en Excelente no tiene sentido', 'nivel máximo', fn() => PuntajeProveedores::validar(array_merge($ok, ['tope_nivel' => 'excelente'])));
check_error('Mínimo de eventos fuera de rango', 'va de 0 a 50', fn() => PuntajeProveedores::validar(array_merge($ok, ['minimo_eventos' => '80'])));
check('Valores del formulario (texto) se aceptan', $C(['peso_calificacion' => 50, 'peso_incidencias' => 0, 'tope_grave' => false]),
      PuntajeProveedores::validar(['peso_calificacion' => '50', 'peso_puntualidad' => '30', 'peso_cumplimiento' => '20', 'peso_incidencias' => '0', 'tolerancia_min' => '10',
          'resta_leve' => '10', 'resta_media' => '25', 'resta_grave' => '50', 'tope_nivel' => 'bueno', 'umbral_excelente' => '85', 'umbral_bueno' => '70',
          'umbral_regular' => '50', 'periodo_meses' => '0', 'minimo_eventos' => '0']));
check('La base no deja guardar pesos que no suman 100', true, (function () use ($db, $nid) {
    try { $db->prepare('INSERT INTO puntaje_proveedores_config (negocio_id, peso_calificacion) VALUES (?, 90)')->execute([$nid]); return false; } catch (PDOException) { return true; }
})());
// Guardar y registro de cambios
$cambios = $pj->guardar($nid, array_merge($ok, ['peso_calificacion' => '50', 'peso_puntualidad' => '20', 'tolerancia_min' => '15', 'tope_grave' => '']), $dueno);
check('Guardar: lista de cambios con antes y después', true, in_array('peso de la calificación: 40 % → 50 %', $cambios, true) && in_array('tolerancia: 10 min → 15 min', $cambios, true));
check('Guardar: 4 cambios', 4, count($cambios));
check('Ya no es la recomendada', false, $pj->esRecomendada($nid));
check('Se aplica a los puntajes del negocio', $fi->puntajes($nid, $hoy, $pj->config($nid)), $fi->puntajes($nid, $hoy));
check('Se aplica a la tolerancia del negocio', 15, $fi->tolerancia($nid));
$rc = $db->query("SELECT quien, accion, evento_id FROM registro_cambios WHERE negocio_id = $nid AND accion LIKE 'Puntaje de proveedores:%' ORDER BY id DESC LIMIT 1")->fetch();
check('Registro de cambios: quién', $dueno['nombre'], $rc['quien']);
check('Registro de cambios: antes y después', true, str_contains($rc['accion'], 'peso de la calificación: 40 % → 50 %') && str_contains($rc['accion'], 'tope con incidencia grave: sí → no'));
check('Registro de cambios: del negocio (sin evento)', null, $rc['evento_id']);
check('Historial de la configuración', 1, count($pj->historial($nid)));
check('Guardar lo mismo no anota nada', [[], 1], [$pj->guardar($nid, $pj->config($nid), $dueno), count($pj->historial($nid))]);
$pLlega = $fi->marcarLlegada($nid, $epF3, '14:12', $dueno);
check('"Llegó" usa la tolerancia guardada (12 min tarde con 15 de tolerancia)', 'a_tiempo', $pLlega['estado']);
check('Los bloques del cronograma usan la tolerancia del negocio', true, $fi->bloquesDe($epDj)[1]['atraso'] === 40 && $fi->bloquesDe($epDj)[1]['a_tiempo'] === false);
check('Explicación en palabras con lo guardado', true, str_contains(implode(' ', PuntajeProveedores::explicar($pj->config($nid))), 'calificación (estrellas) 50 %'));
check('Explicación: el peso 0 se dice', true, str_contains(implode(' ', PuntajeProveedores::explicar($C(['peso_incidencias' => 0, 'peso_calificacion' => 50]))), 'No se toma en cuenta: sin incidencias'));
// Aislamiento: la configuración de A no toca a B
check('B sigue con los valores recomendados', PuntajeProveedores::RECOMENDADO, $pj->config($nidB));
check('B sigue con 10 min de tolerancia', 10, $fi->tolerancia($nidB));
check('El historial de A no se ve en B', 0, count($pj->historial($nidB)));
$pj->guardar($nidB, array_merge($ok, ['minimo_eventos' => '5']), $duenoB);
check('Guardar en B no cambia A', [0, 15], [$pj->config($nid)['minimo_eventos'], $pj->config($nid)['tolerancia_min']]);
check('Volver a los recomendados', true, (function () use ($pj, $nid, $dueno) { $pj->recomendados($nid, $dueno); return $pj->esRecomendada($nid); })());
check('Volver a los recomendados queda anotado', true, str_contains($pj->historial($nid)[0]['accion'], 'peso de la calificación: 50 % → 40 %'));
check('Con los recomendados vuelve el resultado de siempre', array_map($antes, $fi->puntajes($nid, $hoy)), array_map(fn($r) => $r['puntaje'], $fi->puntajes($nid, $hoy)));
