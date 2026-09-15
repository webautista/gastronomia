<?php
/**
 * Catálogo maestro de ingredientes: nombre, categoría, ícono, unidad de
 * uso en receta y precio de referencia (calculado desde cómo se compra).
 * Este es el listado que alimenta el selector con búsqueda en el
 * formulario de recetas — no texto libre.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'ingredientes', 'ver', $base);
$puedeEditar = can($usuarioActual, 'ingredientes', 'editar');
$puedeCrear = can($usuarioActual, 'ingredientes', 'crear');
$puedeEliminar = can($usuarioActual, 'ingredientes', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $id = intOrNull($_POST['id'] ?? null);

    if ($accion === 'toggle' && $puedeEditar && $id) {
        db()->prepare('UPDATE ingredientes_catalogo SET activo = 1 - activo WHERE id = ?')->execute([$id]);
        flash('Estado actualizado.');
    } elseif ($accion === 'eliminar' && $puedeEliminar && $id) {
        $stmtUso = db()->prepare('SELECT COUNT(*) FROM ingredientes WHERE ingrediente_id = ?');
        $stmtUso->execute([$id]);
        $usos = (int) $stmtUso->fetchColumn();
        if ($usos > 0) {
            flash("No se puede eliminar: $usos receta(s) lo están usando. Puedes desactivarlo en su lugar.", 'error');
        } else {
            db()->prepare('DELETE FROM ingredientes_catalogo WHERE id = ?')->execute([$id]);
            flash('Ingrediente eliminado.');
        }
    }
    redirect('index.php' . (isset($_GET['q']) || isset($_GET['cat']) ? '?' . http_build_query($_GET) : ''));
}

$busqueda = trim($_GET['q'] ?? '');
$categoriaFiltro = intOrNull($_GET['cat'] ?? null);

$sql = 'SELECT i.*, c.nombre AS categoria_nombre, u.nombre AS unidad_nombre, u.abreviatura AS unidad_abrev,
               (SELECT COUNT(*) FROM ingredientes ing WHERE ing.ingrediente_id = i.id) AS num_recetas
        FROM ingredientes_catalogo i
        JOIN categorias_ingrediente c ON c.id = i.categoria_id
        JOIN unidades_medida u ON u.id = i.unidad_id
        WHERE 1=1';
$params = [];
if ($busqueda !== '') {
    $sql .= ' AND i.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
if ($categoriaFiltro) {
    $sql .= ' AND i.categoria_id = ?';
    $params[] = $categoriaFiltro;
}
$sql .= ' ORDER BY c.orden ASC, i.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$ingredientes = $stmt->fetchAll();

$categorias = db()->query('SELECT * FROM categorias_ingrediente ORDER BY orden ASC, nombre ASC')->fetchAll();

$pageTitle = 'Ingredientes';
$activeNav = 'ingredientes';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Ingredientes</h1>
    <p>Catálogo maestro con precio de referencia. Se usa para elegir ingredientes en las recetas en vez de escribirlos a mano.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo ingrediente</a>
  <?php endif; ?>
</div>

<div class="toolbar" style="flex-wrap:wrap;gap:8px;">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar ingrediente..." value="<?= e($busqueda) ?>">
    <?php if ($categoriaFiltro): ?><input type="hidden" name="cat" value="<?= (int) $categoriaFiltro ?>"><?php endif; ?>
  </form>
  <select id="filtroCategoria" style="max-width:220px;">
    <option value="">Todas las categorías</option>
    <?php foreach ($categorias as $c): ?>
      <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $categoriaFiltro ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<script>
  document.getElementById('filtroCategoria').addEventListener('change', function () {
    var params = new URLSearchParams();
    if (this.value) { params.set('cat', this.value); }
    var q = <?= json_encode($busqueda, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (q) { params.set('q', q); }
    window.location = 'index.php' + (params.toString() ? '?' + params.toString() : '');
  });
</script>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th></th><th>Nombre</th><th>Categoría</th><th>Unidad de uso</th><th>Precio de referencia</th><th>En recetas</th><th>Estado</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$ingredientes): ?>
        <tr><td colspan="8" class="cell-muted" style="text-align:center;padding:24px;">Sin resultados.</td></tr>
      <?php endif; ?>
      <?php foreach ($ingredientes as $ing): ?>
        <tr>
          <td style="font-size:1.2rem;text-align:center;"><?= e($ing['icono'] ?: '🍽️') ?></td>
          <td class="cell-name"><?= e($ing['nombre']) ?></td>
          <td class="cell-muted"><?= e($ing['categoria_nombre']) ?></td>
          <td class="cell-muted"><?= e($ing['unidad_nombre']) ?> (<?= e($ing['unidad_abrev']) ?>)</td>
          <td class="mono"><?= money(costoPorUnidadUso($ing)) ?> / <?= e($ing['unidad_abrev']) ?></td>
          <td class="cell-muted"><?= (int) $ing['num_recetas'] ?></td>
          <td><span class="chip <?= $ing['activo'] ? 'chip-success' : 'chip-muted' ?>"><?= $ing['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $ing['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $ing['id'] ?>">
                <button class="btn btn-secondary btn-sm" type="submit"><?= $ing['activo'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            <?php endif; ?>
            <?php if ($puedeEliminar): ?>
              <form method="post" style="display:inline;" data-confirm="¿Eliminar &quot;<?= e($ing['nombre']) ?>&quot;? Solo se puede si no está en uso en ninguna receta.">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $ing['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
