<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * PDF del día del evento (TCPDF a través de Pdf::nuevo):
 *   hoja()        Hoja del evento / orden de servicio para el equipo (sin precios del cliente)
 *   invitados()   Lista de invitados por grupo, con casilla para marcar el ingreso
 *   mesas()       Plano de mesas (cada mesa con sus invitados)
 *   cronograma()  Cronograma minuto a minuto
 * Cada método devuelve el objeto TCPDF; la página lo envía con Pdf::enviar().
 */
final class PdfDia
{
    private static function h(mixed $v): string
    {
        return Pdf::h($v);
    }

    private static function hex(array $negocio): string
    {
        [$r, $g, $b] = Pdf::rgb((string) ($negocio['color'] ?? '#6B2D3C'));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    private static function hora(?string $h): string
    {
        return $h ? substr($h, 0, 5) : '—';
    }

    private static function seccion(string $titulo, string $color): string
    {
        return '<table cellpadding="3" cellspacing="0"><tr><td style="border-bottom:1px solid ' . $color . ';color:' . $color . ';font-size:11pt;font-weight:bold;">'
             . self::h($titulo) . '</td></tr></table>';
    }

    private static function vacio(string $texto): string
    {
        return '<p style="color:#8C8286;font-size:9pt;">' . self::h($texto) . '</p>';
    }

    /** Tabla con encabezado. $cols = [[titulo, ancho%, alineación], ...]; $filas = lista de listas de HTML ya escapado. */
    private static function tabla(array $cols, array $filas, string $color, string $tam = '8.5pt'): string
    {
        $html = '<table cellpadding="4" cellspacing="0" style="font-size:' . $tam . ';"><thead><tr style="background-color:' . $color . ';color:#FFFFFF;font-weight:bold;">';
        foreach ($cols as [$t, $w, $al]) $html .= '<th width="' . $w . '%" align="' . ($al ?? 'left') . '">' . self::h($t) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($filas as $i => $f) {
            $html .= '<tr nobr="true" style="background-color:' . ($i % 2 ? '#F7F3F4' : '#FFFFFF') . ';">';
            foreach ($cols as $k => [, $w, $al]) $html .= '<td width="' . $w . '%" align="' . ($al ?? 'left') . '" style="border-bottom:0.3px solid #E6DCDD;">' . ($f[$k] ?? '') . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Datos generales en dos columnas de "etiqueta: valor". */
    private static function datos(array $pares, int $ancho = 100): string
    {
        $html = '<table cellpadding="3" cellspacing="0" width="' . $ancho . '%" style="font-size:9pt;">';
        $pares = array_values(array_filter($pares, fn($p) => $p[1] !== null && $p[1] !== ''));
        for ($i = 0; $i < count($pares); $i += 2) {
            $html .= '<tr>';
            foreach ([$pares[$i] ?? null, $pares[$i + 1] ?? null] as $p) {
                $html .= $p ? '<td width="17%" style="color:#665A5E;">' . self::h($p[0]) . '</td><td width="33%"><b>' . self::h($p[1]) . '</b></td>'
                            : '<td width="50%"></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    private static function texto(?string $t): string
    {
        return nl2br(self::h(trim((string) $t)));
    }

    // ------------------------------------------------------------------

    /** Hoja del evento. $h = DiaEvento::hoja() (+ 'enlace_vivo' opcional para el QR). */
    public static function hoja(array $h): object
    {
        $ev = $h['evento'];
        $n = $h['negocio'];
        $c = self::hex($n);
        $pdf = Pdf::nuevo($n, 'Hoja del evento · ' . $ev['codigo']);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColorArray(Pdf::rgb($c));
        $pdf->MultiCell(150, 7, (string) $ev['nombre'], 0, 'L', false, 1);
        $pdf->SetTextColor(30, 26, 28);
        $pdf->SetFont('dejavusans', '', 9.5);
        if (!empty($h['enlace_vivo'])) {
            $y = $pdf->GetY();
            $pdf->write2DBarcode($h['enlace_vivo'], 'QRCODE,M', 173, 34, 22, 22, ['fgcolor' => [31, 26, 28], 'bgcolor' => [255, 255, 255]], 'N');
            $pdf->SetFont('dejavusans', '', 6);
            $pdf->SetXY(165, 56.5);
            $pdf->Cell(38, 3, 'Cronograma en vivo', 0, 0, 'C');
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->SetY($y);
        }
        $inv = $h['invitados'];
        $html = self::datos([
            ['Fecha', DiaEvento::fechaLarga($ev['fecha'])],
            ['Horario', self::hora($ev['hora_inicio']) . ' a ' . self::hora($ev['hora_fin'])],
            ['Tipo', Eventos::TIPOS[$ev['tipo']] ?? $ev['tipo']],
            ['Estado', Eventos::ESTADOS[$ev['estado']] ?? $ev['estado']],
            ['Espacios', $ev['espacios_texto'] ?: '—'],
            ['Invitados', $ev['invitados'] . ($inv['invitados'] ? ' (lista: ' . $inv['confirmados'] . ' confirmados de ' . $inv['pases'] . ' pases)' : '')],
            ['Cliente', $ev['cliente']],
            ['Teléfono', $ev['cliente_telefono'] ?? ''],
            ['Montaje', $ev['montaje'] ? (Eventos::MONTAJES[$ev['montaje']] ?? $ev['montaje']) : 'Por definir'],
            ['Coordina', $ev['coordinador'] ?? ''],
            ['Transmisión', $ev['transmision_estado'] !== 'no' ? DiaEvento::TRANSMISION[$ev['transmision_estado']] : ''],
            ['Código', $ev['codigo']],
        ], !empty($h['enlace_vivo']) ? 84 : 100);
        $pdf->writeHTML($html, true, false, true, false, '');

        // Menú, detalles del cliente y lo contratado
        $html = self::seccion('Menú y pedidos del cliente', $c);
        $html .= '<table cellpadding="4" cellspacing="0" style="font-size:9pt;"><tr>'
               . '<td width="50%"><b style="color:' . $c . ';">Menú elegido</b><br>' . ($ev['menu_elegido'] ? self::texto($ev['menu_elegido']) : '<span style="color:#8C8286;">Todavía no se eligió el menú.</span>') . '</td>'
               . '<td width="50%"><b style="color:' . $c . ';">Detalles que pidió el cliente</b><br>' . ($ev['detalles_cliente'] ? self::texto($ev['detalles_cliente']) : '<span style="color:#8C8286;">Sin detalles anotados.</span>') . '</td>'
               . '</tr></table>';
        if ($h['restricciones']) {
            $html .= '<p style="font-size:9pt;"><b style="color:' . $c . ';">Restricciones de comida de los invitados:</b> '
                   . implode(' · ', array_map(fn($r) => self::h(mb_strtoupper(mb_substr($r['texto'], 0, 1)) . mb_substr($r['texto'], 1)) . ' <b>×' . $r['veces'] . '</b>', $h['restricciones'])) . '</p>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // Respuestas del formulario del evento (migración 070), si el cliente ya respondió algo
        if (!empty($h['formulario'])) {
            $pdf->writeHTML(self::seccion('Formulario del cliente', $c) . PdfFormulario::tabla($h['formulario'], $c), true, false, true, false, '');
        }

        if ($h['lineas']) {
            $filas = array_map(fn($l) => [
                self::h(Cotizaciones::CATEGORIAS[$l['categoria']] ?? $l['categoria']),
                '<b>' . self::h($l['descripcion']) . '</b>',
                self::h(rtrim(rtrim(number_format((float) $l['cantidad'], 2, ',', '.'), '0'), ',')) . ' <span style="color:#8C8286;">' . self::h(Cotizaciones::UNIDADES[$l['unidad']] ?? '') . '</span>',
            ], $h['lineas']);
            $pdf->writeHTML(self::seccion('Lo contratado (cotización N.º ' . $h['cotizacion']['numero'] . ' aceptada)', $c)
                . self::tabla([['Rubro', 20, 'left'], ['Detalle', 58, 'left'], ['Cantidad', 22, 'right']], $filas, $c), true, false, true, false, '');
        }

        // Cronograma
        $filas = array_map(fn($b) => ['<b>' . self::hora($b['hora']) . '</b>', (int) $b['duracion_min'] . ' min', '<b>' . self::h($b['actividad']) . '</b>' . ($b['notas'] ? '<br><span style="color:#665A5E;">' . self::h($b['notas']) . '</span>' : ''), self::h($b['responsable'] ?? '')], $h['cronograma']);
        $pdf->writeHTML(self::seccion('Cronograma', $c) . ($filas ? self::tabla([['Hora', 10, 'left'], ['Dura', 10, 'left'], ['Actividad', 52, 'left'], ['Responsable', 28, 'left']], $filas, $c)
            : self::vacio('Todavía no hay cronograma.')), true, false, true, false, '');

        // Personal
        $rp = $h['resumen_personal'];
        $filas = array_map(fn($p) => ['<b>' . self::h($p['nombre']) . '</b>', self::h(DiaEvento::ROLES[$p['rol']] ?? $p['rol']), self::hora($p['hora_entrada']) . ' a ' . self::hora($p['hora_salida']),
                                      self::h($p['telefono'] ?? ''), $p['confirmado'] ? 'Sí' : '<span style="color:#B53D33;">No</span>', ''], $h['personal']);
        $nota = '<p style="font-size:8.5pt;color:#665A5E;">Meseros: ' . $rp['meseros'] . ' de ' . $rp['meseros_sugeridos'] . ' sugeridos'
              . ($rp['faltan_meseros'] ? ' — <b style="color:#B53D33;">faltan ' . $rp['faltan_meseros'] . '</b>' : ' — completo') . '.</p>';
        $pdf->writeHTML(self::seccion('Personal (' . count($h['personal']) . ')', $c) . ($filas
            ? self::tabla([['Nombre', 28, 'left'], ['Rol', 17, 'left'], ['Horario', 16, 'left'], ['Celular', 16, 'left'], ['Va', 8, 'center'], ['Firma llegada', 15, 'left']], $filas, $c) . $nota
            : self::vacio('Todavía no hay personal asignado.') . $nota), true, false, true, false, '');

        // Proveedores
        $filas = array_map(fn($p) => ['<b>' . self::hora($p['hora_llegada']) . '</b>', '<b>' . self::h($p['nombre']) . '</b>' . ($p['lo_trae_cliente'] ? '<br><span style="color:#7A5300;">Lo trae el cliente</span>' : ''),
                                      self::h(Cotizaciones::CATEGORIAS[$p['servicio']] ?? $p['servicio']), self::h($p['telefono'] ?? ''), $p['confirmado'] ? 'Sí' : '<span style="color:#B53D33;">No</span>',
                                      self::h($p['notas'] ?? '')], $h['proveedores']);
        $pdf->writeHTML(self::seccion('Proveedores', $c) . ($filas
            ? self::tabla([['Llega', 9, 'left'], ['Proveedor', 27, 'left'], ['Servicio', 17, 'left'], ['Celular', 15, 'left'], ['Va', 8, 'center'], ['Notas', 24, 'left']], $filas, $c)
            : self::vacio('Sin proveedores anotados.')), true, false, true, false, '');

        // Mobiliario
        $filas = array_map(fn($m) => ['<b>' . self::h($m['nombre']) . '</b>', self::h(DiaEvento::CATEGORIAS_MOBILIARIO[$m['categoria']] ?? $m['categoria']), '<b>' . $m['reservado'] . '</b>',
                                      $m['faltan'] ? '<b style="color:#B53D33;">Faltan ' . $m['faltan'] . '</b>' : 'OK', '', ''], $h['mobiliario']);
        $pdf->writeHTML(self::seccion('Mobiliario y menaje', $c) . ($filas
            ? self::tabla([['Ítem', 34, 'left'], ['Categoría', 18, 'left'], ['Cantidad', 12, 'right'], ['Stock', 12, 'center'], ['Salió ✓', 12, 'center'], ['Volvió ✓', 12, 'center']], $filas, $c)
            : self::vacio('Sin mobiliario reservado.')), true, false, true, false, '');

        // Incidencias del evento (060)
        if (!empty($h['incidencias'])) {
            $filas = array_map(fn($i) => [
                '<b' . ($i['gravedad'] === 'grave' ? ' style="color:#B53D33;"' : '') . '>' . self::h(Incidencias::GRAVEDADES[$i['gravedad']] ?? $i['gravedad']) . '</b>',
                self::h(Incidencias::AREAS[$i['area']] ?? $i['area']) . ($i['proveedor'] ? '<br>' . self::h($i['proveedor']) : ''),
                self::h($i['descripcion']) . ($i['resolucion'] ? '<br><span style="color:#1C5A31;">' . self::h($i['resolucion']) . '</span>' : ''),
                self::h(Incidencias::ESTADOS[$i['estado']] ?? $i['estado']),
            ], $h['incidencias']);
            $pdf->writeHTML(self::seccion('Incidencias (' . count($h['incidencias']) . ')', $c)
                . self::tabla([['Gravedad', 12, 'left'], ['Área', 20, 'left'], ['Qué pasó', 54, 'left'], ['Estado', 14, 'left']], $filas, $c), true, false, true, false, '');
        }

        if (!empty($ev['notas'])) {
            $pdf->writeHTML(self::seccion('Notas internas', $c) . '<p style="font-size:9pt;">' . self::texto($ev['notas']) . '</p>', true, false, true, false, '');
        }
        return $pdf;
    }

    /** Lista de invitados por grupo, con casilla para marcar el ingreso. */
    public static function invitados(array $negocio, array $ev, array $lista, array $cont): object
    {
        $c = self::hex($negocio);
        $pdf = Pdf::nuevo($negocio, 'Lista de invitados');
        $pdf->writeHTML('<h2 style="color:' . $c . ';">' . self::h($ev['nombre']) . '</h2>'
            . '<p style="font-size:9pt;color:#665A5E;">' . self::h(DiaEvento::fechaLarga($ev['fecha'])) . ' · ' . self::hora($ev['hora_inicio'])
            . ' &nbsp;·&nbsp; <b>' . $cont['invitados'] . '</b> invitados · <b>' . $cont['pases'] . '</b> pases · <b>' . $cont['confirmados'] . '</b> confirmados · '
            . $cont['no_asisten'] . ' no asisten · ' . $cont['pendientes'] . ' pendientes</p>', true, false, true, false, '');
        if (!$lista) {
            $pdf->writeHTML(self::vacio('Todavía no hay invitados en la lista.'), true, false, true, false, '');
            return $pdf;
        }
        $grupos = [];
        foreach ($lista as $i) $grupos[$i['grupo'] ?: 'Sin grupo'][] = $i;
        uksort($grupos, fn($a, $b) => ($a === 'Sin grupo') <=> ($b === 'Sin grupo') ?: strcasecmp($a, $b));
        foreach ($grupos as $g => $filasG) {
            $pases = array_sum(array_map(fn($i) => (int) $i['pases'], $filasG));
            $filas = array_map(fn($i) => [
                '<span style="font-size:12pt;">☐</span>',
                '<b>' . self::h($i['nombre']) . '</b>' . ($i['restricciones'] ? '<br><span style="color:#7A5300;font-size:7.5pt;">' . self::h($i['restricciones']) . '</span>' : ''),
                (string) (int) $i['pases'],
                $i['estado'] === 'confirmado' ? '<span style="color:#1C5A31;">Confirmó ' . (int) $i['confirmados'] . '</span>'
                    : ($i['estado'] === 'no_asiste' ? '<span style="color:#B53D33;">No asiste</span>' : '<span style="color:#7A5300;">Pendiente</span>'),
                self::h($i['mesa'] ?? '—'),
                $i['ingresaron'] ? (int) $i['ingresaron'] . ' de ' . (int) $i['pases'] : '',
            ], $filasG);
            $pdf->writeHTML(self::seccion($g . ' (' . count($filasG) . ' · ' . $pases . ($pases === 1 ? ' pase)' : ' pases)'), $c)
                . self::tabla([['Entró', 7, 'center'], ['Nombre', 41, 'left'], ['Pases', 8, 'center'], ['Respuesta', 17, 'left'], ['Mesa', 15, 'left'], ['Ingresó', 12, 'center']], $filas, $c, '9pt'),
                true, false, true, false, '');
        }
        return $pdf;
    }

    /** Plano de mesas: cada mesa con sus invitados (dos mesas por fila). */
    public static function mesas(array $negocio, array $ev, array $plano): object
    {
        $c = self::hex($negocio);
        $pdf = Pdf::nuevo($negocio, 'Plano de mesas');
        $mesas = $plano['mesas'];
        $puestos = array_sum(array_map(fn($m) => (int) $m['capacidad'], $mesas));
        $ocupados = array_sum(array_map(fn($m) => (int) $m['ocupados'], $mesas));
        $pdf->writeHTML('<h2 style="color:' . $c . ';">' . self::h($ev['nombre']) . '</h2>'
            . '<p style="font-size:9pt;color:#665A5E;">' . self::h(DiaEvento::fechaLarga($ev['fecha'])) . ' · ' . count($mesas) . ' mesas · '
            . $ocupados . ' de ' . $puestos . ' puestos ocupados' . ($plano['sin_mesa'] ? ' · <b style="color:#B53D33;">' . count($plano['sin_mesa']) . ' invitados sin mesa</b>' : '') . '</p>',
            true, false, true, false, '');
        if (!$mesas) {
            $pdf->writeHTML(self::vacio('Todavía no hay mesas. Créalas en la pestaña Invitados.'), true, false, true, false, '');
        }
        $celda = function (array $m) use ($c): string {
            $color = $m['se_pasa'] ? '#B53D33' : $c;
            $lis = '';
            foreach ($m['lista'] as $i) {
                $n = $i['estado'] === 'confirmado' ? (int) $i['confirmados'] : (int) $i['pases'];
                $lis .= '• ' . self::h($i['nombre']) . ($n > 1 ? ' <b>(' . $n . ')</b>' : '')
                      . ($i['estado'] === 'pendiente' ? ' <span style="color:#7A5300;">?</span>' : '')
                      . ($i['restricciones'] ? ' <span style="color:#7A5300;font-size:7pt;">[' . self::h($i['restricciones']) . ']</span>' : '') . '<br>';
            }
            return '<td width="49%" style="border:0.6px solid ' . $color . ';">'
                 . '<table cellpadding="3"><tr><td style="background-color:' . $color . ';color:#FFFFFF;font-weight:bold;font-size:10pt;">' . self::h($m['nombre'])
                 . ' <span style="font-weight:normal;font-size:8pt;">' . $m['ocupados'] . ' / ' . (int) $m['capacidad'] . ($m['se_pasa'] ? ' · SE PASA' : '') . '</span></td></tr>'
                 . '<tr><td style="font-size:8.5pt;">' . ($lis ?: '<span style="color:#8C8286;">Mesa vacía</span>') . '</td></tr></table></td>';
        };
        for ($k = 0; $k < count($mesas); $k += 2) {
            $html = '<table cellpadding="0" cellspacing="0" nobr="true"><tr>' . $celda($mesas[$k]) . '<td width="2%"></td>'
                  . (isset($mesas[$k + 1]) ? $celda($mesas[$k + 1]) : '<td width="49%"></td>') . '</tr></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }
        if ($plano['sin_mesa']) {
            $pdf->writeHTML(self::seccion('Sin mesa asignada', '#B53D33') . '<p style="font-size:9pt;">'
                . implode(' · ', array_map(fn($i) => self::h($i['nombre']) . ((int) $i['pases'] > 1 ? ' (' . (int) $i['pases'] . ')' : ''), $plano['sin_mesa'])) . '</p>',
                true, false, true, false, '');
        }
        return $pdf;
    }

    /** Cronograma minuto a minuto (con hora real si ya se usó el modo en vivo). */
    public static function cronograma(array $negocio, array $ev, array $bloques, ?string $enlaceVivo = null): object
    {
        $c = self::hex($negocio);
        $pdf = Pdf::nuevo($negocio, 'Cronograma');
        $pdf->writeHTML('<h2 style="color:' . $c . ';">' . self::h($ev['nombre']) . '</h2>'
            . '<p style="font-size:9pt;color:#665A5E;">' . self::h(DiaEvento::fechaLarga($ev['fecha'])) . ' · de ' . self::hora($ev['hora_inicio']) . ' a ' . self::hora($ev['hora_fin'])
            . ($enlaceVivo ? ' · Modo en vivo: escanea el código' : '') . '</p>', true, false, true, false, '');
        if ($enlaceVivo) {
            $pdf->write2DBarcode($enlaceVivo, 'QRCODE,M', 173, 34, 22, 22, ['fgcolor' => [31, 26, 28], 'bgcolor' => [255, 255, 255]], 'N');
            $pdf->SetY(max($pdf->GetY(), 58));
        }
        $conReal = (bool) array_filter($bloques, fn($b) => $b['inicio_real'] !== null);
        $filas = array_map(function ($b) use ($conReal) {
            $f = ['<b style="font-size:11pt;">' . self::hora($b['hora']) . '</b>', (int) $b['duracion_min'] . ' min',
                  '<b>' . self::h($b['actividad']) . '</b>' . ($b['notas'] ? '<br><span style="color:#665A5E;">' . self::h($b['notas']) . '</span>' : ''),
                  self::h($b['responsable'] ?? '')];
            if ($conReal) $f[] = $b['inicio_real'] ? substr((string) $b['inicio_real'], 11, 5) : '';
            return $f;
        }, $bloques);
        $cols = $conReal
            ? [['Hora', 11, 'left'], ['Dura', 10, 'left'], ['Actividad', 45, 'left'], ['Responsable', 22, 'left'], ['Real', 12, 'center']]
            : [['Hora', 12, 'left'], ['Dura', 11, 'left'], ['Actividad', 50, 'left'], ['Responsable', 27, 'left']];
        $pdf->writeHTML($filas ? self::tabla($cols, $filas, $c, '9.5pt') : self::vacio('Todavía no hay cronograma.'), true, false, true, false, '');
        return $pdf;
    }
}
