<?php
/**
 * Alta rápida de un ingrediente desde el modal del formulario de recetas
 * (botón "+ Nuevo ingrediente" en cada fila), sin salir de la pantalla.
 * Devuelve JSON. Usa el mismo permiso que ya se exige para crear/editar
 * recetas — quien puede construir una receta puede completar el catálogo
 * sobre la marcha — además de "ingredientes: crear" para quien lo use
 * directamente desde la pantalla de Ingredientes.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$usuarioActual = currentUser();
if (!$usuarioActual) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'errores' => ['Tu sesión expiró. Recarga la página e inicia sesión de nuevo.']]);
    exit;
}

$permitido = can($usuarioActual, 'recetas', 'crear') || can($usuarioActual, 'recetas', 'editar') || can($usuarioActual, 'ingredientes', 'crear');
if (!$permitido) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'errores' => ['No tienes permiso para agregar ingredientes.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errores' => ['Método no permitido.']]);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errores' => ['Token de seguridad inválido. Recarga la página e intenta de nuevo.']]);
    exit;
}

$nombre = trim($_POST['nombre'] ?? '');
$categoriaId = intOrNull($_POST['categoria_id'] ?? null);
$icono = trim($_POST['icono'] ?? '');
$unidadId = intOrNull($_POST['unidad_id'] ?? null);
$precioCompra = (float) str_replace(',', '.', trim($_POST['precio_compra'] ?? '0'));

$categorias = array_column(db()->query('SELECT id FROM categorias_ingrediente')->fetchAll(), 'id');
$unidades = array_column(db()->query('SELECT id FROM unidades_medida')->fetchAll(), 'id');

$errores = [];
if ($nombre === '') {
    $errores[] = 'El nombre es obligatorio.';
}
if (!in_array($categoriaId, $categorias, true)) {
    $errores[] = 'Elige una categoría válida.';
}
if (!in_array($unidadId, $unidades, true)) {
    $errores[] = 'Elige una unidad de uso válida.';
}
if ($precioCompra < 0) {
    $errores[] = 'El precio no puede ser negativo.';
}
if (!$errores) {
    $stmtDup = db()->prepare('SELECT id FROM ingredientes_catalogo WHERE nombre = ?');
    $stmtDup->execute([$nombre]);
    if ($stmtDup->fetch()) {
        $errores[] = 'Ya existe un ingrediente con ese nombre. Búscalo en el selector.';
    }
}

if ($errores) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => $errores]);
    exit;
}

// Alta rápida: compra = uso, contenido 1 (lo más común). Si luego alguien
// necesita afinar la unidad de compra o el contenido del paquete, puede
// editarlo desde la pantalla de Ingredientes.
$stmt = db()->prepare(
    'INSERT INTO ingredientes_catalogo (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra)
     VALUES (?,?,?,?,?,1,?)'
);
$stmt->execute([$nombre, $categoriaId, $icono ?: null, $unidadId, $unidadId, $precioCompra]);
$nuevoId = (int) db()->lastInsertId();

$stmt = db()->prepare(
    'SELECT i.*, u.nombre AS unidad_nombre, u.abreviatura AS unidad_abrev
     FROM ingredientes_catalogo i JOIN unidades_medida u ON u.id = i.unidad_id
     WHERE i.id = ?'
);
$stmt->execute([$nuevoId]);
$nuevo = $stmt->fetch();

echo json_encode([
    'ok' => true,
    'ingrediente' => [
        'id' => (int) $nuevo['id'],
        'nombre' => $nuevo['nombre'],
        'icono' => $nuevo['icono'],
        'categoria_id' => (int) $nuevo['categoria_id'],
        'unidad_id' => (int) $nuevo['unidad_id'],
        'unidad_nombre' => $nuevo['unidad_nombre'],
        'unidad_abrev' => $nuevo['unidad_abrev'],
        'costo_unitario' => round(costoPorUnidadUso($nuevo), 2),
    ],
]);
