<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'recetas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'recetas', 'editar');
$puedeCrear = can($usuarioActual, 'recetas', 'crear');
$puedeEliminar = can($usuarioActual, 'recetas', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'recetas', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM recetas WHERE id = ?')->execute([$id]);
        flash('Receta eliminada.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = 'SELECT r.*, cr.nombre AS categoria,
               (SELECT COUNT(*) FROM ingredientes i WHERE i.receta_id = r.id) AS num_ingredientes,
               (SELECT COUNT(*) FROM evento_receta er WHERE er.receta_id = r.id) AS num_eventos
        FROM recetas r
        JOIN categorias_receta cr ON cr.id = r.categoria_id';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE r.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY r.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$recetas = $stmt->fetchAll();

// Traer los nombres de ingredientes de un tirón para el resumen de cada tarjeta.
$ingredientesPorReceta = [];
if ($recetas) {
    $ids = array_column($recetas, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtIng = db()->prepare("SELECT receta_id, nombre FROM ingredientes WHERE receta_id IN ($in) ORDER BY orden ASC, id ASC");
    $stmtIng->execute($ids);
    foreach ($stmtIng->fetchAll() as $fila) {
        $ingredientesPorReceta[$fila['receta_id']][] = $fila['nombre'];
    }
}

$pageTitle = 'Recetas';
$activeNav = 'recetas';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Recetas</h1>
    <p>Catálogo de recetas e ingredientes base por porción.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nueva receta</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar receta..." value="<?= e($busqueda) ?>">
  </form>
</div>

<?php if (!$recetas): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
    <div>No hay recetas que coincidan con tu búsqueda.</div>
  </div></div>
<?php else: ?>
  <div class="event-grid">
    <?php foreach ($recetas as $rc): ?>
      <?php $nombresIng = $ingredientesPorReceta[$rc['id']] ?? []; ?>
      <div class="event-card">
        <div class="event-card-top">
          <div>
            <h3><?= e($rc['nombre']) ?></h3>
            <div class="cell-muted"><?= e($rc['categoria']) ?></div>
          </div>
          <?php if ($puedeEditar || $puedeEliminar): ?>
            <div class="row-actions">
              <?php if ($puedeEditar): ?>
                <a class="icon-btn" href="form.php?id=<?= (int) $rc['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="index.php" data-confirm="¿Eliminar la receta &quot;<?= e($rc['nombre']) ?>&quot;? También se quitará de los eventos que la usan.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $rc['id'] ?>">
                  <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="event-meta">
          <span><?= icon('portion') ?> Base: <?= (int) $rc['porciones_base'] ?> porciones</span>
          <span><?= (int) $rc['num_ingredientes'] ?> ingredientes</span>
        </div>
        <div style="font-size:.82rem;color:var(--text-secondary);margin-bottom:8px;">
          <?= e(implode(', ', array_slice($nombresIng, 0, 4))) ?><?= count($nombresIng) > 4 ? '…' : '' ?>
        </div>
        <div class="mini-row"><span>Usada en</span><span><?= (int) $rc['num_eventos'] ?> evento<?= $rc['num_eventos'] == 1 ? '' : 's' ?></span></div>
        <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
          <details class="prep-details">
            <summary><?= icon('book') ?> Ver preparación</summary>
            <div class="prep-text"><?= nl2br(e($rc['preparacion'])) ?></div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
