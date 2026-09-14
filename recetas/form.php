<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$id = intOrNull($_GET['id'] ?? null);
$receta = ['nombre' => '', 'categoria' => 'Plato fuerte', 'porciones_base' => ''];
$ingredientes = [['nombre' => '', 'cantidad' => '', 'unidad' => '', 'costo_unitario' => '']];
$errores = [];
$categorias = ['Plato fuerte', 'Postre', 'Aperitivo', 'Panadería', 'Bebida'];

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
    $receta['categoria']      = $_POST['categoria'] ?? 'Plato fuerte';
    $receta['porciones_base'] = intOrNull($_POST['porciones_base'] ?? null);

    $ingNombres  = $_POST['ing_nombre'] ?? [];
    $ingCantidad = $_POST['ing_cantidad'] ?? [];
    $ingUnidad   = $_POST['ing_unidad'] ?? [];
    $ingCosto    = $_POST['ing_costo'] ?? [];

    $ingredientesNuevos = [];
    foreach ($ingNombres as $i => $nombreIng) {
        $nombreIng = trim($nombreIng);
        if ($nombreIng === '') {
            continue;
        }
        $ingredientesNuevos[] = [
            'nombre'         => $nombreIng,
            'cantidad'       => (float) ($ingCantidad[$i] ?? 0),
            'unidad'         => trim($ingUnidad[$i] ?? '') ?: 'unid',
            'costo_unitario' => (float) ($ingCosto[$i] ?? 0),
        ];
    }

    if ($receta['nombre'] === '') {
        $errores[] = 'El nombre de la receta es obligatorio.';
    }
    if (!$receta['porciones_base'] || $receta['porciones_base'] < 1) {
        $errores[] = 'Las porciones base deben ser un número mayor a 0.';
    }
    if (!$categorias || !in_array($receta['categoria'], $categorias, true)) {
        $errores[] = 'Categoría no válida.';
    }

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE recetas SET nombre=?, categoria=?, porciones_base=? WHERE id=?');
                $stmt->execute([$receta['nombre'], $receta['categoria'], $receta['porciones_base'], $id]);
                $pdo->prepare('DELETE FROM ingredientes WHERE receta_id = ?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO recetas (nombre, categoria, porciones_base) VALUES (?,?,?)');
                $stmt->execute([$receta['nombre'], $receta['categoria'], $receta['porciones_base']]);
                $id = (int) $pdo->lastInsertId();
            }

            $stmtIng = $pdo->prepare('INSERT INTO ingredientes (receta_id, nombre, cantidad, unidad, costo_unitario, orden) VALUES (?,?,?,?,?,?)');
            foreach ($ingredientesNuevos as $orden => $ing) {
                $stmtIng->execute([$id, $ing['nombre'], $ing['cantidad'], $ing['unidad'], $ing['costo_unitario'], $orden]);
            }

            $pdo->commit();
            flash($id ? 'Receta actualizada.' : 'Receta creada.');
            redirect('index.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errores[] = 'No se pudo guardar la receta. Intenta de nuevo.';
        }
    } else {
        // Conservar lo que el usuario escribió si hubo errores de validación.
        $ingredientes = $ingredientesNuevos ?: $ingredientes;
    }
}

$pageTitle = $id ? 'Editar receta' : 'Nueva receta';
$activeNav = 'recetas';
$base = '..';
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
        <label for="categoria">Categoría</label>
        <select id="categoria" name="categoria">
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $cat === $receta['categoria'] ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
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
            <input type="text" name="ing_unidad[]" placeholder="Unidad" value="<?= e($ing['unidad']) ?>">
            <input type="number" step="any" name="ing_costo[]" placeholder="Costo/unid RD$" value="<?= e((string) $ing['costo_unitario']) ?>">
            <button type="button" class="icon-btn" onclick="this.closest('[data-ing-row]').remove()"><?= icon('x') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="addIngRow" style="margin-top:4px;"><?= icon('plus') ?> Agregar ingrediente</button>
      <div class="hint">Cantidad, unidad (g, ml, unid) y costo por unidad son opcionales para el cálculo estimado de costo.</div>
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
    <input type="text" name="ing_unidad[]" placeholder="Unidad">
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
