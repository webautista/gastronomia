<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM eventos WHERE id = ?')->execute([$id]);
        flash('Evento eliminado.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = 'SELECT ev.*,
          (SELECT COALESCE(SUM(g.monto),0) FROM gastos g WHERE g.evento_id = ev.id) AS gastado,
          (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS num_estudiantes,
          (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id AND ee.pagado = 1) AS num_pagados
        FROM eventos ev';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE ev.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY ev.fecha ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();

$pageTitle = 'Eventos';
$activeNav = 'eventos';
$base = '..';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Eventos</h1>
    <p>Presupuesto, cuota y estado de cada evento.</p>
  </div>
  <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo evento</a>
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
    <?php foreach ($eventos as $ev):
      $pct = $ev['presupuesto'] > 0 ? round($ev['gastado'] / $ev['presupuesto'] * 100) : 0;
      $esperado = $ev['num_estudiantes'] * $ev['cuota'];
      $recaudado = $ev['num_pagados'] * $ev['cuota'];
    ?>
      <div class="event-card">
        <div class="event-card-top">
          <div><h3><a href="detalle.php?id=<?= (int) $ev['id'] ?>"><?= e($ev['nombre']) ?></a></h3></div>
          <div class="row-actions">
            <span class="chip <?= chipEstadoClase($ev['estado']) ?>"><?= e($ev['estado']) ?></span>
          </div>
        </div>
        <div class="event-meta">
          <span><?= icon('calendar') ?> <?= fmtDate($ev['fecha']) ?></span>
          <span><?= icon('pin') ?> <?= e($ev['lugar']) ?></span>
          <span><?= icon('portion') ?> <?= (int) $ev['porciones'] ?> porciones</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div>
            <div class="meter-row"><span>Presupuesto</span><span class="mono"><?= money($ev['gastado']) ?> / <?= money($ev['presupuesto']) ?></span></div>
            <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
          </div>
          <div class="mini-row"><span>Cuota por estudiante</span><span class="mono"><?= money($ev['cuota']) ?></span></div>
          <div class="mini-row"><span>Recaudado</span><span class="mono"><?= money($recaudado) ?> / <?= money($esperado) ?></span></div>
          <div class="mini-row"><span>Estudiantes pagados</span><span><?= (int) $ev['num_pagados'] ?>/<?= (int) $ev['num_estudiantes'] ?></span></div>
        </div>
        <div class="row-actions" style="justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
          <a class="btn btn-secondary btn-sm" href="detalle.php?id=<?= (int) $ev['id'] ?>">Ver detalle</a>
          <div class="row-actions">
            <a class="icon-btn" href="form.php?id=<?= (int) $ev['id'] ?>" title="Editar"><?= icon('edit') ?></a>
            <form method="post" action="index.php" data-confirm="¿Eliminar el evento &quot;<?= e($ev['nombre']) ?>&quot;? Se perderán sus estudiantes asignados, recetas y gastos registrados.">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
              <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
