<?php
use TuRenta\{Eventos, Ventas};

/** Clientes: lista con búsqueda y alta rápida (POST ?accion=crear). */
$nid = (int) $u['negocio_id'];
$eventos = new Eventos(db());
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$error = null;
$d = ['nombre' => '', 'telefono' => '', 'identificacion' => '', 'email' => '', 'origen' => ''];

if (es_post()) {
    exigir(es_comercial($u), 'Solo el dueño y los vendedores pueden crear clientes.');
    verificar_csrf();
    foreach ($d as $k => $_) $d[$k] = campo($k);
    try {
        if ($d['telefono'] !== '' && \TuRenta\WhatsApp::normalizarTelefono($d['telefono']) === null) {
            throw new RuntimeException('Revisa el celular (ej. 0991234567).');
        }
        $antes = (int) db()->query('SELECT count(*) FROM clientes WHERE negocio_id = ' . $nid)->fetchColumn();
        $id = $eventos->guardarCliente($nid, $d);
        $despues = (int) db()->query('SELECT count(*) FROM clientes WHERE negocio_id = ' . $nid)->fetchColumn();
        aviso($despues > $antes ? 'Cliente guardado.' : 'Ese celular o cédula ya era de un cliente: abrimos su ficha.');
        redirigir('cliente', ['id' => $id]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$clientes = $eventos->clientes($nid, $q, 300);
$total = (int) db()->query('SELECT count(*) FROM clientes WHERE negocio_id = ' . $nid)->fetchColumn();
vista('clientes', ['clientes' => $clientes, 'q' => $q, 'error' => $error, 'd' => $d, 'total' => $total,
                   'puedeCrear' => es_comercial($u), 'abrirNuevo' => $error !== null || isset($_GET['nuevo'])], 'Clientes · TuRenta');
