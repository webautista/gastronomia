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
    'SELECT i.*, um.abreviatura AS unidad, um.nombre AS unidad_nombre, um.es_entera AS unidad_entera, ic.icono
     FROM ingredientes i
     JOIN unidades_medida um ON um.id = i.unidad_id
     LEFT JOIN ingredientes_catalogo ic ON ic.id = i.ingrediente_id
     WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
);
$stmtIng->execute([$id]);
$ingredientesReceta = $stmtIng->fetchAll();

// Acciones/cortes marcados por línea, para mostrarlos como chips junto al
// nombre del ingrediente (ej. "Espinaca — Cocida y Picada").
$accionesPorFila = [];
if ($ingredientesReceta) {
    $idsFilas = array_column($ingredientesReceta, 'id');
    $in = implode(',', array_fill(0, count($idsFilas), '?'));
    $stmtAcc = db()->prepare(
        "SELECT ia.receta_ingrediente_id, ac.nombre FROM ingrediente_accion ia
         JOIN acciones_ingrediente ac ON ac.id = ia.accion_id
         WHERE ia.receta_ingrediente_id IN ($in) ORDER BY ac.orden ASC, ac.nombre ASC"
    );
    $stmtAcc->execute($idsFilas);
    foreach ($stmtAcc->fetchAll() as $fa) {
        $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = $fa['nombre'];
    }
}

$porcionesBase = max(1, (int) $receta['porciones_base']);
$costoTotalBase = costoTotalReceta(db(), $id);

$pageTitle = $receta['nombre'];
$activeNav = 'recetas';
$breadcrumb = '<a href="index.php">Recetas</a> &nbsp;/&nbsp; <b>' . e($receta['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1><?= e($receta['nombre']) ?></h1>
    <p><?= e($receta['categoria']) ?> · base <?= $porcionesBase ?> porciones</p>
    <?php if (trim((string) ($receta['descripcion'] ?? '')) !== ''): ?>
      <p style="max-width:640px;"><?= nl2br(e($receta['descripcion'])) ?></p>
    <?php endif; ?>
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
      <?php foreach ($ingredientesReceta as $ing): $esAlGusto = !empty($ing['al_gusto']); ?>
        <tr>
          <td class="cell-name">
            <?= $ing['icono'] ? e($ing['icono']) . ' ' : '' ?><?= e($ing['nombre']) ?>
            <?php if (!empty($ing['opcional'])): ?> <span class="chip chip-muted" style="font-size:.68rem;">Opcional</span><?php endif; ?>
            <?php if (!empty($ing['reemplazo'])): ?><div class="cell-muted" style="font-size:.78rem;">o <?= e($ing['reemplazo']) ?></div><?php endif; ?>
            <?php if (!empty($accionesPorFila[$ing['id']])): ?><div class="cell-muted" style="font-size:.78rem;"><?= e(implode(', ', $accionesPorFila[$ing['id']])) ?></div><?php endif; ?>
          </td>
          <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>" data-entera="<?= !empty($ing['unidad_entera']) ? '1' : '0' ?>" data-al-gusto="<?= $esAlGusto ? '1' : '0' ?>"><?= $esAlGusto ? 'Al gusto' : numFmt($ing['cantidad']) . ' ' . e($ing['unidad']) . fraccionSufijo((float) $ing['cantidad']) ?></td>
          <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= $esAlGusto ? '—' : money(montoLineaReceta((float) $ing['cantidad'], (float) $ing['costo_unitario'], (bool) ($ing['unidad_entera'] ?? false))) ?></td>
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
    <div class="prep-text"><?= e($receta['preparacion']) ?></div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
