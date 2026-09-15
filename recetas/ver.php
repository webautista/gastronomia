<?php
/**
 * Vista de solo lectura de una receta: para verla completa (foto,
 * ingredientes, preparación) sin pasar por el formulario de edición.
 * Pensada también para el rol Padres, que solo tiene permiso "ver" en
 * recetas.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'recetas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'recetas', 'editar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare(
    'SELECT r.*, cr.nombre AS categoria FROM recetas r
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$receta = $stmt->fetch();
if (!$receta) {
    flash('Esa receta ya no existe.', 'error');
    redirect('index.php');
}

$stmtIng = db()->prepare(
    'SELECT i.*, um.abreviatura AS unidad, um.nombre AS unidad_nombre, ic.icono
     FROM ingredientes i
     JOIN unidades_medida um ON um.id = i.unidad_id
     LEFT JOIN ingredientes_catalogo ic ON ic.id = i.ingrediente_id
     WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
);
$stmtIng->execute([$id]);
$ingredientesReceta = $stmtIng->fetchAll();

$porcionesBase = max(1, (int) $receta['porciones_base']);
$costoTotalBase = 0;
foreach ($ingredientesReceta as $ing) {
    $costoTotalBase += (float) $ing['cantidad'] * (float) $ing['costo_unitario'];
}

$pageTitle = $receta['nombre'];
$activeNav = 'recetas';
$breadcrumb = '<a href="index.php">Recetas</a> &nbsp;/&nbsp; <b>' . e($receta['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1><?= e($receta['nombre']) ?></h1>
    <p><?= e($receta['categoria']) ?> · base <?= $porcionesBase ?> porciones</p>
  </div>
  <?php if ($puedeEditar): ?>
    <a class="btn btn-secondary" href="form.php?id=<?= (int) $receta['id'] ?>"><?= icon('edit') ?> Editar</a>
  <?php endif; ?>
</div>

<?php if (!empty($receta['foto'])): ?>
  <img class="recipe-photo-preview" style="max-width:420px;" src="<?= e($base . '/' . $receta['foto']) ?>" alt="Foto de <?= e($receta['nombre']) ?>">
<?php endif; ?>

<div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
  <div class="recipe-card-head">
    <div><h4>Ingredientes</h4></div>
    <div class="portion-control">
      <span>Ver cantidades para</span>
      <input type="number" min="1" value="<?= $porcionesBase ?>" data-role="porciones-input">
      <span>porciones</span>
    </div>
  </div>
  <?php if (!$ingredientesReceta): ?>
    <div class="empty"><?= icon('boxEmpty') ?><div>Esta receta todavía no tiene ingredientes.</div></div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Costo est.</th></tr></thead>
    <tbody>
      <?php foreach ($ingredientesReceta as $ing): ?>
        <tr>
          <td class="cell-name"><?= $ing['icono'] ? e($ing['icono']) . ' ' : '' ?><?= e($ing['nombre']) ?></td>
          <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>"><?= numFmt($ing['cantidad']) ?> <?= e($ing['unidad']) ?></td>
          <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= money((float) $ing['cantidad'] * (float) $ing['costo_unitario']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="2" style="text-align:right;font-weight:600;">Costo estimado de ingredientes</td><td class="mono" style="font-weight:600;" data-role="costo-total"><?= money($costoTotalBase) ?></td></tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php if (trim((string) ($receta['preparacion'] ?? '')) !== ''): ?>
  <div class="card card-pad" style="margin-top:16px;">
    <h2 class="section-title"><?= icon('book') ?> Preparación</h2>
    <div class="prep-text"><?= nl2br(e($receta['preparacion'])) ?></div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
