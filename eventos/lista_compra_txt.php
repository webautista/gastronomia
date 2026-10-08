<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos_lista_compra', 'ver', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$id]);
$evento = $stmt->fetch();
if (!$evento) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT receta_id, porciones_necesarias FROM evento_receta WHERE evento_id = ?');
$stmt->execute([$id]);
$recetas = $stmt->fetchAll();

$decisionesCompra = cargarDecisionesCompra(db(), 'evento', $id);
$consolidado = listaCompraConsolidada(db(), $recetas, $decisionesCompra);
$titulo = 'Lista de compra — ' . $evento['nombre'] . ' (' . fmtFechaEvento($evento['fecha'], !empty($evento['fecha_tentativa'])) . ')';
$responsableCompra = obtenerResponsableCompra(db(), 'evento', $id);
$texto = renderListaCompraTexto($titulo, $consolidado, $responsableCompra['nombre'] ?? null);

$nombreArchivo = 'lista-compra-' . preg_replace('/[^a-z0-9]+/i', '-', $evento['nombre']) . '.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($texto));
echo $texto;
