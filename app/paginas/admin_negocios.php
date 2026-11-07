<?php
use TuRenta\PanelTukan;

$filtro = (string) ($_GET['f'] ?? '');
if ($filtro !== '' && $filtro !== 'papelera' && !isset(PanelTukan::ESTADOS[$filtro])) $filtro = '';
$plan = (string) ($_GET['plan'] ?? '');
$listaPlanes = planes()->lista();
if ($plan !== '' && !in_array($plan, array_column($listaPlanes, 'codigo'), true)) $plan = '';
$buscar = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
// "papelera" (Anexo A): los negocios borrados que todavía se pueden restaurar
$negocios = (new PanelTukan(db()))->negocios($filtro === 'papelera' ? '' : $filtro, $buscar, null, $plan, $filtro === 'papelera');
vista_admin('admin_negocios', compact('negocios', 'filtro', 'buscar', 'plan', 'listaPlanes'), 'Negocios · Panel Tukán');
