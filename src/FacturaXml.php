<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Lee el XML de una factura electrónica del SRI (Ecuador), tal como la descarga el proveedor
 * o como llega por correo: la factura sola (<factura>) o envuelta en la autorización
 * (<autorizacion>…<comprobante><![CDATA[<factura>…]]></comprobante>).
 * La lógica viene del ERP de QF (sri_xml_helper.php), reescrita y con los detalles (ítems).
 *
 *   $f = FacturaXml::leer($xml);
 *   $f['numero'] '001-002-000004567', $f['fecha'] 'AAAA-MM-DD', $f['ruc_emisor'], $f['razon_emisor'],
 *   $f['subtotal'], $f['iva'], $f['total'], $f['clave'], $f['items'] = [[descripcion, cantidad, precio_unitario, con_iva], ...]
 */
final class FacturaXml
{
    /** Saca el XML de adentro del sobre de autorización, si viene así. */
    private static function desenvolver(string $xml): string
    {
        $xml = trim(preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml);
        if (preg_match('/<comprobante>\s*<!\[CDATA\[(.*?)\]\]>\s*<\/comprobante>/s', $xml, $m)) return trim($m[1]);
        if (preg_match('/<comprobante>(.*?)<\/comprobante>/s', $xml, $m)) return trim(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        return $xml;
    }

    /** 'dd/mm/aaaa' -> 'aaaa-mm-dd' */
    public static function fecha(string $f): ?string
    {
        $f = trim($f);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $f, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return $f;
        return null;
    }

    private static function num(mixed $v): float
    {
        return round((float) str_replace(',', '.', trim((string) $v)), 6);
    }

    /** @return array<string, mixed> */
    public static function leer(string $contenido): array
    {
        if (trim($contenido) === '') throw new RuntimeException('El archivo XML está vacío.');
        $xmlTexto = self::desenvolver($contenido);
        if (stripos($xmlTexto, '<!DOCTYPE') !== false || stripos($xmlTexto, '<!ENTITY') !== false) {
            throw new RuntimeException('Este XML no parece una factura del SRI.');
        }
        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlTexto, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if ($xml === false) throw new RuntimeException('El archivo no es un XML válido. Sube el XML de la factura electrónica (el que manda el SRI o el proveedor).');
        $raiz = $xml->getName();
        if ($raiz === 'comprobanteRetencion') throw new RuntimeException('Este XML es un comprobante de retención, no una factura.');
        if ($raiz === 'notaCredito') throw new RuntimeException('Este XML es una nota de crédito, no una factura.');
        if ($raiz !== 'factura') throw new RuntimeException('Este XML no es una factura electrónica del SRI.');
        $trib = $xml->infoTributaria;
        $fac = $xml->infoFactura;
        if (!$trib || !$fac || trim((string) $trib->ruc) === '' || trim((string) $trib->secuencial) === '') {
            throw new RuntimeException('La factura del XML está incompleta (falta el RUC o el número).');
        }
        $numero = str_pad(substr(preg_replace('/\D/', '', (string) $trib->estab), 0, 3), 3, '0', STR_PAD_LEFT) . '-'
                . str_pad(substr(preg_replace('/\D/', '', (string) $trib->ptoEmi), 0, 3), 3, '0', STR_PAD_LEFT) . '-'
                . str_pad(substr(preg_replace('/\D/', '', (string) $trib->secuencial), 0, 9), 9, '0', STR_PAD_LEFT);
        $fecha = self::fecha((string) $fac->fechaEmision);
        if ($fecha === null) throw new RuntimeException('La fecha de la factura del XML no se puede leer.');
        $subtotal = self::num($fac->totalSinImpuestos);
        $total = self::num($fac->importeTotal);
        $iva = 0.0;
        $bases = ['iva' => 0.0, 'cero' => 0.0];
        foreach ($fac->totalConImpuestos->totalImpuesto ?? [] as $imp) {
            if (trim((string) $imp->codigo) !== '2') continue;      // 2 = IVA
            $iva += self::num($imp->valor);
            $bases[self::num($imp->valor) > 0 ? 'iva' : 'cero'] += self::num($imp->baseImponible);
        }
        if ($iva <= 0 && $total > $subtotal) $iva = round($total - $subtotal, 2);
        $items = [];
        foreach ($xml->detalles->detalle ?? [] as $d) {
            $cant = self::num($d->cantidad);
            if ($cant <= 0) continue;
            $sinImp = isset($d->precioTotalSinImpuesto) ? self::num($d->precioTotalSinImpuesto) : $cant * self::num($d->precioUnitario);
            $conIva = false;
            foreach ($d->impuestos->impuesto ?? [] as $imp) {
                if (trim((string) $imp->codigo) === '2' && (self::num($imp->tarifa) > 0 || self::num($imp->valor) > 0)) $conIva = true;
            }
            $items[] = [
                'descripcion' => mb_substr(trim((string) $d->descripcion) ?: 'Ítem de la factura', 0, 300),
                'cantidad' => round($cant, 2),
                'precio_unitario' => round($sinImp / $cant, 4),
                'con_iva' => $conIva,
            ];
        }
        $clave = preg_replace('/\D/', '', (string) $trib->claveAcceso);
        return [
            'numero' => $numero,
            'fecha' => $fecha,
            'ruc_emisor' => preg_replace('/\D/', '', (string) $trib->ruc),
            'razon_emisor' => trim((string) $trib->razonSocial),
            'nombre_comercial' => trim((string) $trib->nombreComercial),
            'direccion_emisor' => trim((string) $trib->dirMatriz),
            'ruc_comprador' => preg_replace('/\D/', '', (string) $fac->identificacionComprador),
            'razon_comprador' => trim((string) $fac->razonSocialComprador),
            'subtotal' => round($subtotal, 2),
            'base_iva' => round($bases['iva'], 2),
            'base_cero' => round($bases['cero'], 2),
            'iva' => round($iva, 2),
            'total' => round($total, 2),
            'clave' => strlen((string) $clave) === 49 ? $clave : null,
            'items' => $items,
        ];
    }
}
