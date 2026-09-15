<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'ingredientes', $id ? 'editar' : 'crear', $base);

$categorias = db()->query('SELECT * FROM categorias_ingrediente WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$unidades = db()->query('SELECT * FROM unidades_medida WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();

$ing = [
    'nombre' => '', 'categoria_id' => '', 'icono' => '', 'unidad_id' => '',
    'unidad_compra_id' => '', 'contenido_por_compra' => '1', 'precio_compra' => '',
    'nota_compra' => '',
];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM ingredientes_catalogo WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese ingrediente ya no existe.', 'error');
        redirect('index.php');
    }
    $ing = $encontrado;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ing['nombre'] = trim($_POST['nombre'] ?? '');
    $ing['categoria_id'] = intOrNull($_POST['categoria_id'] ?? null);
    $ing['icono'] = trim($_POST['icono'] ?? '');
    $ing['unidad_id'] = intOrNull($_POST['unidad_id'] ?? null);
    $ing['unidad_compra_id'] = intOrNull($_POST['unidad_compra_id'] ?? null);
    $ing['contenido_por_compra'] = trim($_POST['contenido_por_compra'] ?? '1');
    $ing['precio_compra'] = trim($_POST['precio_compra'] ?? '0');
    $ing['nota_compra'] = trim($_POST['nota_compra'] ?? '');

    if ($ing['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if (!in_array($ing['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }
    if (!in_array($ing['unidad_id'], array_column($unidades, 'id'), true)) {
        $errores[] = 'Unidad de uso no válida.';
    }
    // Si no se eligió unidad de compra, se asume igual a la unidad de uso
    // (el caso más común: se compra y se usa en la misma unidad).
    $unidadCompraFinal = $ing['unidad_compra_id'] ?: $ing['unidad_id'];
    if (!in_array($unidadCompraFinal, array_column($unidades, 'id'), true)) {
        $errores[] = 'Unidad de compra no válida.';
    }
    $contenido = (float) str_replace(',', '.', $ing['contenido_por_compra']);
    if ($contenido <= 0) {
        $errores[] = 'El contenido por unidad de compra debe ser mayor a 0.';
    }
    $precioCompra = (float) str_replace(',', '.', $ing['precio_compra']);
    if ($precioCompra < 0) {
        $errores[] = 'El precio de compra no puede ser negativo.';
    }
    if (!$errores) {
        $stmtDup = db()->prepare('SELECT id FROM ingredientes_catalogo WHERE nombre = ? AND id <> ?');
        $stmtDup->execute([$ing['nombre'], $id ?? 0]);
        if ($stmtDup->fetch()) {
            $errores[] = 'Ya existe otro ingrediente con ese nombre.';
        }
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare(
                'UPDATE ingredientes_catalogo
                 SET nombre=?, categoria_id=?, icono=?, unidad_id=?, unidad_compra_id=?, contenido_por_compra=?, precio_compra=?, nota_compra=?
                 WHERE id=?'
            );
            $stmt->execute([
                $ing['nombre'], $ing['categoria_id'], $ing['icono'] ?: null, $ing['unidad_id'],
                $unidadCompraFinal, $contenido, $precioCompra, $ing['nota_compra'] ?: null, $id,
            ]);
            flash('Ingrediente actualizado.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO ingredientes_catalogo (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $ing['nombre'], $ing['categoria_id'], $ing['icono'] ?: null, $ing['unidad_id'],
                $unidadCompraFinal, $contenido, $precioCompra, $ing['nota_compra'] ?: null,
            ]);
            flash('Ingrediente agregado.');
        }
        redirect('index.php');
    }
}

$pageTitle = $id ? 'Editar ingrediente' : 'Nuevo ingrediente';
$activeNav = 'ingredientes';
$breadcrumb = '<a href="index.php">Ingredientes</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card" style="max-width:760px;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field-row">
      <div class="field" style="flex:1;">
        <label for="nombre">Nombre del ingrediente</label>
        <input type="text" id="nombre" name="nombre" required value="<?= e($ing['nombre']) ?>" placeholder="Ej. Cebolla roja">
      </div>
      <div class="field" style="max-width:110px;">
        <label for="icono">Ícono</label>
        <input type="text" id="icono" name="icono" maxlength="8" value="<?= e($ing['icono'] ?? '') ?>" placeholder="🧅" style="font-size:1.2rem;text-align:center;">
      </div>
    </div>
    <div class="hint" style="margin-top:-10px;margin-bottom:14px;">El ícono es opcional: pega un emoji que represente el ingrediente (ej. 🍅, 🧄, 🥩).</div>

    <div class="field-row">
      <div class="field">
        <label for="categoria_id">Categoría</label>
        <select id="categoria_id" name="categoria_id" required>
          <option value="">— Elige una —</option>
          <?php foreach ($categorias as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $ing['categoria_id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">¿Falta una categoría? Agrégala en <a href="../configuracion/catalogos.php?tipo=categorias_ingrediente">Configuración</a>.</div>
      </div>
      <div class="field">
        <label for="unidad_id">Unidad de uso en receta</label>
        <select id="unidad_id" name="unidad_id" required>
          <option value="">— Elige una —</option>
          <?php foreach ($unidades as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $ing['unidad_id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="hint">La unidad en que se escribe la cantidad al usarlo dentro de una receta.</div>
      </div>
    </div>

    <h2 class="section-title" style="margin-top:22px;">Cómo se compra</h2>
    <p class="cell-muted" style="font-size:.85rem;margin-top:-6px;">
      Si se compra en una presentación distinta a como se usa (ej. un cartón de 30 huevos, pero la receta pide huevos por unidad), indícalo aquí. El costo por unidad de uso se calcula solo.
    </p>
    <div class="field-row">
      <div class="field">
        <label for="unidad_compra_id">Unidad de compra</label>
        <select id="unidad_compra_id" name="unidad_compra_id">
          <option value="">— Igual que la unidad de uso —</option>
          <?php foreach ($unidades as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($ing['unidad_compra_id'] ?? 0) ? 'selected' : '' ?>><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="contenido_por_compra">Contenido por unidad de compra</label>
        <input type="number" step="any" min="0.001" id="contenido_por_compra" name="contenido_por_compra" value="<?= e((string) $ing['contenido_por_compra']) ?>">
        <div class="hint">Cuántas unidades de uso trae. Ej. 30 (huevos por cartón). Si se compra igual que se usa, deja 1.</div>
      </div>
      <div class="field">
        <label for="precio_compra">Precio de esa unidad de compra (RD$)</label>
        <input type="number" step="any" min="0" id="precio_compra" name="precio_compra" required value="<?= e((string) $ing['precio_compra']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="nota_compra">Nota de compra (opcional)</label>
      <input type="text" id="nota_compra" name="nota_compra" placeholder="Ej. Cartón de 30 unidades en Bravo" value="<?= e($ing['nota_compra'] ?? '') ?>">
    </div>

    <div class="card" style="background:var(--surface-2);padding:12px 16px;margin-top:6px;">
      Costo estimado por unidad de uso: <b id="costoPreview" class="mono">—</b>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Agregar ingrediente' ?></button>
    </div>
  </form>
</div>

<script>
  (function () {
    var contenido = document.getElementById('contenido_por_compra');
    var precio = document.getElementById('precio_compra');
    var preview = document.getElementById('costoPreview');
    function actualizar() {
      var c = parseFloat(contenido.value);
      var p = parseFloat(precio.value);
      if (!c || c <= 0 || isNaN(p)) { preview.textContent = '—'; return; }
      preview.textContent = 'RD$ ' + (p / c).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    contenido.addEventListener('input', actualizar);
    precio.addEventListener('input', actualizar);
    actualizar();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
