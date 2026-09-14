<?php
// DIAGNOSTICO TEMPORAL v2 - quitar estas 3 lineas apenas se resuelva el error 500.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'recetas', $id ? 'editar' : 'crear', $base);

$receta = ['nombre' => '', 'categoria_id' => '', 'porciones_base' => '', 'preparacion' => ''];
$ingredientes = [['nombre' => '', 'cantidad' => '', 'unidad_id' => '', 'costo_unitario' => '']];
$errores = [];

$categorias = db()->query('SELECT * FROM categorias_receta WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$unidades = db()->query('SELECT * FROM unidades_medida WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();

if ($id) {
    $stmt = db()->prepare('SELECT * FROM recetas WHERE id = ?');
    $stmt->execute([$id]);
    $encontrada = $stmt->fetch();
    if (!$encontrada) {
        flash('Esa receta ya no existe.', 'error');
        redirect('index.php');
    }
    $receta = $encontrada;

    $stmt = db()->prepare('SELECT * FROM ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC');
    $stmt->execute([$id]);
    $filas = $stmt->fetchAll();
    if ($filas) {
        $ingredientes = $filas;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $receta['nombre']         = trim($_POST['nombre'] ?? '');
    $receta['categoria_id']   = intOrNull($_POST['categoria_id'] ?? null);
    $receta['porciones_base'] = intOrNull($_POST['porciones_base'] ?? null);
    $receta['preparacion']    = trim($_POST['preparacion'] ?? '');

    $ingNombres  = $_POST['ing_nombre'] ?? [];
    $ingCantidad = $_POST['ing_cantidad'] ?? [];
    $ingUnidad   = $_POST['ing_unidad_id'] ?? [];
    $ingCosto    = $_POST['ing_costo'] ?? [];

    $unidadesValidas = array_column($unidades, 'id');
    $unidadPorDefecto = $unidadesValidas[0] ?? null;

    $ingredientesNuevos = [];
    foreach ($ingNombres as $i => $nombreIng) {
        $nombreIng = trim($nombreIng);
        if ($nombreIng === '') {
            continue;
        }
        $unidadId = intOrNull($ingUnidad[$i] ?? null);
        if (!in_array($unidadId, $unidadesValidas, true)) {
            $unidadId = $unidadPorDefecto;
        }
        $ingredientesNuevos[] = [
            'nombre'         => $nombreIng,
            'cantidad'       => (float) ($ingCantidad[$i] ?? 0),
            'unidad_id'      => $unidadId,
            'costo_unitario' => (float) ($ingCosto[$i] ?? 0),
        ];
    }

    if ($receta['nombre'] === '') {
        $errores[] = 'El nombre de la receta es obligatorio.';
    }
    if (!$receta['porciones_base'] || $receta['porciones_base'] < 1) {
        $errores[] = 'Las porciones base deben ser un número mayor a 0.';
    }
    if (!in_array($receta['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE recetas SET nombre=?, categoria_id=?, porciones_base=?, preparacion=? WHERE id=?');
                $stmt->execute([$receta['nombre'], $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null, $id]);
                $pdo->prepare('DELETE FROM ingredientes WHERE receta_id = ?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO recetas (nombre, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?)');
                $stmt->execute([$receta['nombre'], $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null]);
                $id = (int) $pdo->lastInsertId();
            }

            $stmtIng = $pdo->prepare('INSERT INTO ingredientes (receta_id, nombre, cantidad, unidad_id, costo_unitario, orden) VALUES (?,?,?,?,?,?)');
            foreach ($ingredientesNuevos as $orden => $ing) {
                $stmtIng->execute([$id, $ing['nombre'], $ing['cantidad'], $ing['unidad_id'], $ing['costo_unitario'], $orden]);
            }

            $pdo->commit();
            flash($id ? 'Receta actualizada.' : 'Receta creada.');
            redirect('index.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errores[] = 'No se pudo guardar la receta. Intenta de nuevo.';
        }
    } else {
        // Conservar lo que el usuario escribió si hubo errores de validación. Un cambio
        $ingredientes = $ingredientesNuevos ?: $ingredientes;
    }
}

$pageTitle = $id ? 'Editar receta' : 'Nueva receta';
$activeNav = 'recetas';
$breadcrumb = '<a href="index.php">Recetas</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card" style="max-width:760px;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre de la receta</label>
      <input type="text" id="nombre" name="nombre" required value="<?= e($receta['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="categoria_id">Categoría</label>
        <select id="categoria_id" name="categoria_id">
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === (int) $receta['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (can($usuarioActual, 'configuracion', 'ver')): ?>
          <div class="hint">¿Falta una categoría? Agrégala en <a href="../configuracion/catalogos.php?tipo=categorias_receta">Configuración</a>.</div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="porciones_base">Porciones base</label>
        <input type="number" id="porciones_base" name="porciones_base" min="1" required value="<?= e((string) $receta['porciones_base']) ?>">
      </div>
    </div>

    <div class="field">
      <label>Ingredientes (por las porciones base indicadas)</label>
      <div id="ingRows">
        <?php foreach ($ingredientes as $ing): ?>
          <div class="ing-row" data-ing-row>
            <input type="text" name="ing_nombre[]" placeholder="Ingrediente" value="<?= e($ing['nombre']) ?>">
            <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad" value="<?= e((string) $ing['cantidad']) ?>">
            <select name="ing_unidad_id[]">
              <?php foreach ($unidades as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($ing['unidad_id'] ?? 0) ? 'selected' : '' ?>><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <input type="number" step="any" name="ing_costo[]" placeholder="Costo/unid RD$" value="<?= e((string) $ing['costo_unitario']) ?>">
            <button type="button" class="icon-btn" onclick="this.closest('[data-ing-row]').remove()"><?= icon('x') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="addIngRow" style="margin-top:4px;"><?= icon('plus') ?> Agregar ingrediente</button>
      <div class="hint">Cantidad y costo por unidad son opcionales para el cálculo estimado de costo. La unidad se elige del catálogo de medidas.</div>
    </div>

    <div class="field">
      <label for="preparacion">Preparación (pasos a seguir)</label>
      <textarea id="preparacion" name="preparacion" rows="8" placeholder="1. Precalentar el horno a...&#10;2. Mezclar...&#10;3. ..."><?= e($receta['preparacion']) ?></textarea>
      <div class="hint">Opcional. Describe los pasos en el orden en que se deben seguir.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear receta' ?></button>
    </div>
  </form>
</div>

<template id="ingRowTemplate">
  <div class="ing-row" data-ing-row>
    <input type="text" name="ing_nombre[]" placeholder="Ingrediente">
    <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad">
    <select name="ing_unidad_id[]">
      <?php foreach ($unidades as $u): ?>
        <option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <input type="number" step="any" name="ing_costo[]" placeholder="Costo/unid RD$">
    <button type="button" class="icon-btn" onclick="this.closest('[data-ing-row]').remove()"><?= icon('x') ?></button>
  </div>
</template>
<script>
  document.getElementById('addIngRow').addEventListener('click', function () {
    var tpl = document.getElementById('ingRowTemplate');
    document.getElementById('ingRows').appendChild(tpl.content.cloneNode(true));
  });
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
