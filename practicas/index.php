<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'practicas', 'editar');
$puedeCrear = can($usuarioActual, 'practicas', 'crear');
$puedeEliminar = can($usuarioActual, 'practicas', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'practicas', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM practicas WHERE id = ?')->execute([$id]);
        flash('Práctica eliminada.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = 'SELECT p.*,
          (SELECT COUNT(*) FROM practica_receta pr WHERE pr.practica_id = p.id) AS num_recetas
        FROM practicas p';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE p.nombre LIKE ? OR p.materia LIKE ? OR p.maestro_responsable LIKE ?';
    $params = ['%' . $busqueda . '%', '%' . $busqueda . '%', '%' . $busqueda . '%'];
}
$sql .= ' ORDER BY p.fecha DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$practicas = $stmt->fetchAll();

$puedeVerGastos = can($usuarioActual, 'gastos', 'ver');

// El costo estimado de materiales se calcula aquí igual que en el detalle
// (misma lista de compra consolidada), para que el listado muestre el
// mismo número que verían al entrar a la práctica. La cuota se calcula
// igual que en eventos/index.php: costo de materiales (o el gasto real si
// ya lo superó) más otros gastos, dividido entre los estudiantes asignados.
foreach ($practicas as &$p) {
    $p['costo_materiales'] = costoRecetasConsolidado(db(), 'practica', (int) $p['id']);

    $stmtEst = db()->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ?');
    $stmtEst->execute([(int) $p['id']]);
    $p['num_estudiantes'] = (int) $stmtEst->fetchColumn();

    $resumenGastos = resumenGastosVinculo(db(), 'practica_id', (int) $p['id']);
    $cuotas = calcularCuotas($p['costo_materiales'], $resumenGastos, $p['num_estudiantes']);
    $p['cuota_confirmada'] = $cuotas['confirmada'];
}
unset($p);

$pageTitle = 'Prácticas';
$activeNav = 'practicas';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Prácticas</h1>
    <p>Recetas asignadas a cada sesión de práctica y el gasto de materiales que hace falta.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nueva práctica</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar por nombre, materia o maestro..." value="<?= e($busqueda) ?>">
  </form>
</div>

<?php if (!$practicas): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin prácticas</div>
    <div>No hay prácticas que coincidan con tu búsqueda.</div>
  </div></div>
<?php else: ?>
  <div class="event-grid">
    <?php foreach ($practicas as $p): ?>
      <div class="event-card">
        <div class="event-card-top">
          <div><h3><a href="detalle.php?id=<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a></h3></div>
        </div>
        <div class="event-meta">
          <span><?= icon('calendar') ?> <?= fmtDate($p['fecha']) ?></span>
          <?php if ($p['materia']): ?><span><?= icon('book') ?> <?= e($p['materia']) ?></span><?php endif; ?>
        </div>
        <?php if ($p['maestro_responsable']): ?>
          <div class="cell-muted" style="font-size:.82rem;margin-bottom:8px;"><?= icon('users') ?> <?= e($p['maestro_responsable']) ?></div>
        <?php endif; ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div class="mini-row"><span>Recetas asignadas</span><span class="mono"><?= (int) $p['num_recetas'] ?></span></div>
          <div class="mini-row"><span>Costo estimado de materiales</span><span class="mono"><?= money($p['costo_materiales']) ?></span></div>
          <?php if ($p['num_estudiantes'] > 0): ?>
            <div class="mini-row"><span>Estudiantes asignados</span><span class="mono"><?= (int) $p['num_estudiantes'] ?></span></div>
            <div class="mini-row"><span>Cuota confirmada</span><span class="mono"><?= money($p['cuota_confirmada']) ?></span></div>
          <?php elseif ($puedeVerGastos): ?>
            <div class="mini-row"><span>Cuota</span><span class="cell-muted">Asigna estudiantes para calcularla</span></div>
          <?php endif; ?>
        </div>
        <div class="row-actions" style="justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
          <a class="btn btn-secondary btn-sm" href="detalle.php?id=<?= (int) $p['id'] ?>">Ver detalle</a>
          <?php if ($puedeEditar || $puedeEliminar): ?>
            <div class="row-actions">
              <?php if ($puedeEditar): ?>
                <a class="icon-btn" href="form.php?id=<?= (int) $p['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="index.php" data-confirm="¿Eliminar la práctica &quot;<?= e($p['nombre']) ?>&quot;? Se perderán las recetas asignadas a ella.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
