<?php
/**
 * Campos del formulario del evento (migración 070). Se usa en el portal del cliente y en la pestaña del evento.
 *   pt_campo($pregunta, $editable)  -> HTML de una pregunta con su campo (o su respuesta, si no se puede editar)
 * Los campos se llaman r[<id>] (y r[<id>][] en "varias"); cada pregunta manda también en[]=<id> para saber
 * que estaba en el formulario (así una pregunta vaciada se guarda vacía).
 */
use TuRenta\Cuestionarios;

if (!function_exists('pt_campo')) {
    function pt_campo(array $p, bool $editable = true): string
    {
        $id = (int) $p['id'];
        $dom = 'pt-p' . $id;
        $nombre = 'r[' . $id . ']';
        $r = $p['respuesta'];
        $resp = $r !== null && $r !== '';
        $h = '<div class="pt-pregunta' . ($resp ? ' pt-lista' : '') . '" data-pt-pregunta>';
        $etiqueta = e($p['texto']) . ($p['obligatoria'] ? ' <span class="pt-obl" title="Obligatoria">*</span>' : '');
        $ayuda = $p['ayuda'] ? '<small class="pt-ayuda">' . e($p['ayuda']) . '</small>' : '';
        if (!$editable) {
            return $h . '<p class="pt-etiqueta">' . $etiqueta . '</p>'
                 . ($resp ? '<p class="pt-respuesta">' . nl2br(e(Cuestionarios::texto($p))) . '</p>' : '<p class="pt-respuesta pt-sin">Sin responder</p>') . '</div>';
        }
        $h .= '<input type="hidden" name="en[]" value="' . $id . '">';
        $grupo = in_array($p['tipo'], ['opcion', 'varias', 'sino'], true);
        $h .= $grupo ? '<fieldset class="pt-grupo"><legend class="pt-etiqueta">' . $etiqueta . '</legend>' . $ayuda
                     : '<label class="pt-etiqueta" for="' . $dom . '">' . $etiqueta . '</label>' . $ayuda;
        switch ($p['tipo']) {
            case 'largo':
                $h .= '<textarea id="' . $dom . '" name="' . $nombre . '" rows="3" maxlength="' . Cuestionarios::MAX_LARGO . '">' . e($r) . '</textarea>';
                break;
            case 'numero':
                $h .= '<input type="text" inputmode="decimal" id="' . $dom . '" name="' . $nombre . '" value="' . e($resp ? Cuestionarios::texto($p) : '') . '" class="pt-corto" maxlength="12">';
                break;
            case 'fecha':
                $h .= '<input type="date" id="' . $dom . '" name="' . $nombre . '" value="' . e($r) . '" class="pt-corto">';
                break;
            case 'hora':
                $h .= '<input type="time" id="' . $dom . '" name="' . $nombre . '" value="' . e($r) . '" class="pt-corto">';
                break;
            case 'sino':
            case 'opcion':
                $ops = $p['tipo'] === 'sino' ? ['si' => 'Sí', 'no' => 'No'] : array_combine(Cuestionarios::opciones($p['opciones']), Cuestionarios::opciones($p['opciones']));
                $h .= '<div class="pt-opciones">';
                foreach ($ops as $valor => $txt) {
                    $h .= '<label class="pt-chip"><input type="radio" name="' . $nombre . '" value="' . e($valor) . '"' . ((string) $r === (string) $valor ? ' checked' : '') . '><span>' . e($txt) . '</span></label>';
                }
                $h .= '<label class="pt-chip pt-chip-borrar"><input type="radio" name="' . $nombre . '" value=""' . (!$resp ? ' checked' : '') . '><span>Sin definir</span></label>';
                $h .= '</div>';
                break;
            case 'varias':
                $marcadas = $resp ? explode("\n", (string) $r) : [];
                $h .= '<div class="pt-opciones">';
                foreach (Cuestionarios::opciones($p['opciones']) as $o) {
                    $h .= '<label class="pt-chip pt-chip-check"><input type="checkbox" name="' . $nombre . '[]" value="' . e($o) . '"' . (in_array($o, $marcadas, true) ? ' checked' : '') . '><span>' . e($o) . '</span></label>';
                }
                $h .= '</div>';
                break;
            default:
                $h .= '<input type="text" id="' . $dom . '" name="' . $nombre . '" value="' . e($r) . '" maxlength="' . Cuestionarios::MAX_CORTO . '">';
        }
        if ($grupo) $h .= '</fieldset>';
        return $h . '</div>';
    }
}
