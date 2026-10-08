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
          (SELECT COUNT(*) FROM practica_receta pr WHERE pr.practica_id = p.id) AS num_recetas,
          (SELECT COALESCE(SUM(pe.monto_pagado),0) FROM practica_estudiante pe WHERE pe.practica_id = p.id) AS recaudado
        FROM practicas p';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE p.nombre LIKE ? OR p.materia LIKE ? OR p.maestro_responsable LIKE ?';
    $params = ['%' . $busqueda . '%', '%' . $busqueda . '%', '%' . $busqueda . '%'];
}
$sql .= ' ORDER BY p.fecha ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$practicas = $stmt->fetchAll();

$puedeVerGastos = can($usuarioActual, 'practicas_gastos', 'ver');
// Responsable de la compra (sección 45): lo ve quien puede ver la pestaña
// Estudiantes o la Lista de Compra de la práctica.
$puedeVerResponsableCompra = can($usuarioActual, 'practicas_estudiantes', 'ver') || can($usuarioActual, 'practicas_lista_compra', 'ver');

// El costo estimado de materiales se calcula aquí igual que en el detalle
// (misma lista de compra consolidada), para que el listado muestre el
// mismo número que verían al entrar a la práctica. La cuota y el progreso
// de recaudo se calculan igual que en eventos/index.php: costo de
// materiales (o el gasto real si ya lo superó) más otros gastos, dividido
// entre los estudiantes asignados — con la misma barra de progreso
// (.meter/meterClase()) que ya se usa ahí, para poder ver de un vistazo
// cuánto se ha pagado sin tener que entrar a cada práctica.
foreach ($practicas as &$p) {
    $p['costo_materiales'] = costoRecetasConsolidado(db(), 'practica', (int) $p['id']);

    $stmtEst = db()->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ?');
    $stmtEst->execute([(int) $p['id']]);
    $p['num_estudiantes'] = (int) $stmtEst->fetchColumn();

    $resumenGastos = resumenGastosVinculo(db(), 'practica_id', (int) $p['id']);
    $cuotaManual = $p['cuota_confirmada_manual'] !== null ? (float) $p['cuota_confirmada_manual'] : null;
    $cuotas = calcularCuotas($p['costo_materiales'], $resumenGastos, $p['num_estudiantes'], $cuotaManual);
    $p['cuota_proyectada'] = $cuotas['proyectada'];
    $p['cuota_confirmada'] = $cuotas['confirmada'];
    $p['cuota_ajustada'] = $cuotas['cuota_ajustada'];
    $p['total_confirmado'] = $cuotas['meta_recaudo'];

    $stmtPag = db()->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ? AND monto_pagado >= ?');
    $stmtPag->execute([(int) $p['id'], $cuotas['confirmada'] - 0.005]);
    $p['num_pagados'] = (int) $stmtPag->fetchColumn();
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
      <div class="dash-card">
        <div class="dash-card-head is-practica">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="dash-icon"><?= icon('whisk') ?></div>
              <div>
                <div class="dash-title"><a href="detalle.php?id=<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a></div>
                <div class="dash-meta">
                  <span><?= icon('calendar') ?> <?= fmtDate($p['fecha']) ?></span>
                  <?php if ($p['materia']): ?><span><?= icon('book') ?> <?= e($p['materia']) ?></span><?php endif; ?>
                  <?php if ($p['maestro_responsable']): ?><span><?= icon('users') ?> <?= e($p['maestro_responsable']) ?></span><?php endif; ?>
                  <?php if ($puedeVerResponsableCompra): $respCompra = obtenerResponsableCompra(db(), 'practica', (int) $p['id']); ?>
                    <span title="Responsable de hacer la compra"><?= icon('basket') ?> Compra: <?= $respCompra ? '<b>' . e($respCompra['nombre']) . '</b>' : 'sin asignar' ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="dash-stats">
            <div class="dash-stat"><div class="dash-stat-label">Recetas asignadas</div><div class="dash-stat-value"><?= (int) $p['num_recetas'] ?></div></div>
            <div class="dash-stat"><div class="dash-stat-label">Costo materiales</div><div class="dash-stat-value"><?= money($p['costo_materiales']) ?></div></div>
            <?php if ($p['num_estudiantes'] > 0): ?>
              <div class="dash-stat"><div class="dash-stat-label">Estudiantes asignados</div><div class="dash-stat-value"><?= (int) $p['num_estudiantes'] ?></div></div>
              <div class="dash-stat is-gold"><div class="dash-stat-label">Cuota confirmada</div><div class="dash-stat-value"><?= money($p['cuota_confirmada']) ?></div></div>
              <div class="dash-stat is-sage"><div class="dash-stat-label">Estudiantes al día</div><div class="dash-stat-value"><?= (int) $p['num_pagados'] ?>/<?= (int) $p['num_estudiantes'] ?></div></div>
            <?php elseif ($puedeVerGastos): ?>
              <div class="dash-stat-empty">Asigna estudiantes para calcular la cuota.</div>
            <?php endif; ?>
          </div>
          <?php if ($p['num_estudiantes'] > 0): ?>
            <div>
              <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($p['recaudado']) ?> / <?= money($p['total_confirmado']) ?></span></div>
              <div class="meter <?= meterClase($p['total_confirmado'] > 0 ? round($p['recaudado'] / $p['total_confirmado'] * 100) : 0) ?>"><span style="width:<?= $p['total_confirmado'] > 0 ? min(round($p['recaudado'] / $p['total_confirmado'] * 100), 100) : 0 ?>%"></span></div>
            </div>
          <?php endif; ?>
          <div class="dash-footer">
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
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
