<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos', 'ver', $base);
$puedeEditar = can($usuarioActual, 'eventos', 'editar');
$puedeCrear = can($usuarioActual, 'eventos', 'crear');
$puedeEliminar = can($usuarioActual, 'eventos', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'eventos', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM eventos WHERE id = ?')->execute([$id]);
        flash('Evento eliminado.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = 'SELECT ev.*, es.nombre AS estado,
          (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS num_estudiantes,
          (SELECT COALESCE(SUM(ee.monto_pagado),0) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS recaudado
        FROM eventos ev
        JOIN estados_evento es ON es.id = ev.estado_id';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE ev.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY ev.fecha ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();

// La cuota ya no se guarda: se calcula aquí mismo para cada evento igual
// que en su detalle (costo de recetas, o el gasto real en materiales si ya
// lo superó, más los demás gastos, dividido entre los estudiantes
// asignados), para que la lista siempre muestre el mismo número que
// verían al entrar al evento.
foreach ($eventos as &$ev) {
    $costoRecetas = costoRecetasConsolidado(db(), 'evento', (int) $ev['id']);
    $resumenGastos = resumenGastosVinculo(db(), 'evento_id', (int) $ev['id']);
    $cuotas = calcularCuotas($costoRecetas, $resumenGastos, (int) $ev['num_estudiantes']);
    $ev['costo_recetas'] = $costoRecetas;
    $ev['total_proyeccion'] = $cuotas['total_proyeccion'];
    $ev['total_confirmado'] = $cuotas['total_confirmado'];
    $ev['cuota_proyectada'] = $cuotas['proyectada'];
    $ev['cuota_confirmada'] = $cuotas['confirmada'];

    $stmtPag = db()->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ? AND monto_pagado >= ?');
    $stmtPag->execute([(int) $ev['id'], $cuotas['confirmada'] - 0.005]);
    $ev['num_pagados'] = (int) $stmtPag->fetchColumn();
}
unset($ev);

$pageTitle = 'Eventos';
$activeNav = 'eventos';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Eventos</h1>
    <p>Inversión, cuota y estado de cada evento.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo evento</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar evento..." value="<?= e($busqueda) ?>">
  </form>
</div>

<?php if (!$eventos): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin eventos</div>
    <div>No hay eventos que coincidan con tu búsqueda.</div>
  </div></div>
<?php else: ?>
  <div class="event-grid">
    <?php foreach ($eventos as $ev): ?>
      <div class="event-card">
        <div class="event-card-top">
          <div><h3><a href="detalle.php?id=<?= (int) $ev['id'] ?>"><?= e($ev['nombre']) ?></a></h3></div>
          <div class="row-actions">
            <?php if (empty($ev['cuota_publica'])): ?>
              <span class="chip chip-muted" title="La cuota de este evento no se muestra en la página pública"><?= icon('lock') ?> No pública</span>
            <?php endif; ?>
            <span class="chip <?= chipEstadoClase($ev['estado']) ?>"><?= e($ev['estado']) ?></span>
          </div>
        </div>
        <div class="event-meta">
          <span><?= icon('calendar') ?> <?= fmtDate($ev['fecha']) ?></span>
          <span><?= icon('pin') ?> <?= e($ev['lugar']) ?></span>
          <span><?= icon('portion') ?> <?= (int) $ev['porciones'] ?> porciones</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div class="mini-row"><span>Inversión total</span><span class="mono"><?= money($ev['total_proyeccion']) ?></span></div>
          <?php if ((int) $ev['num_estudiantes'] > 0): ?>
            <div class="mini-row"><span>Cuota proyectada</span><span class="mono"><?= money($ev['cuota_proyectada']) ?></span></div>
            <div class="mini-row"><span>Cuota confirmada</span><span class="mono"><?= money($ev['cuota_confirmada']) ?></span></div>
            <div>
              <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($ev['recaudado']) ?> / <?= money($ev['total_confirmado']) ?></span></div>
              <div class="meter <?= meterClase($ev['total_confirmado'] > 0 ? round($ev['recaudado'] / $ev['total_confirmado'] * 100) : 0) ?>"><span style="width:<?= $ev['total_confirmado'] > 0 ? min(round($ev['recaudado'] / $ev['total_confirmado'] * 100), 100) : 0 ?>%"></span></div>
            </div>
            <div class="mini-row"><span>Estudiantes al día</span><span><?= (int) $ev['num_pagados'] ?>/<?= (int) $ev['num_estudiantes'] ?></span></div>
          <?php else: ?>
            <div class="mini-row"><span>Cuota</span><span class="cell-muted">Asigna estudiantes para calcularla</span></div>
          <?php endif; ?>
        </div>
        <div class="row-actions" style="justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
          <a class="btn btn-secondary btn-sm" href="detalle.php?id=<?= (int) $ev['id'] ?>">Ver detalle</a>
          <?php if ($puedeEditar || $puedeEliminar): ?>
            <div class="row-actions">
              <?php if ($puedeEditar): ?>
                <a class="icon-btn" href="form.php?id=<?= (int) $ev['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="index.php" data-confirm="¿Eliminar el evento &quot;<?= e($ev['nombre']) ?>&quot;? Se perderán sus estudiantes asignados, recetas y gastos registrados.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
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
