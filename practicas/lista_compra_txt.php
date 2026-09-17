<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas', 'ver', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$id]);
$practica = $stmt->fetch();
if (!$practica) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT receta_id, porciones_necesarias FROM practica_receta WHERE practica_id = ?');
$stmt->execute([$id]);
$recetas = $stmt->fetchAll();

$consolidado = listaCompraConsolidada(db(), $recetas);
$titulo = 'Lista de compra — ' . $practica['nombre'] . ' (' . fmtDate($practica['fecha']) . ')';
$texto = renderListaCompraTexto($titulo, $consolidado);

$nombreArchivo = 'lista-compra-' . preg_replace('/[^a-z0-9]+/i', '-', $practica['nombre']) . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($texto));
echo $texto;
