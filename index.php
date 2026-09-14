<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

$stmt = db()->query(
    'SELECT ev.*,
       (SELECT COALESCE(SUM(g.monto),0) FROM gastos g WHERE g.evento_id = ev.id) AS gastado,
       (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS num_estudiantes,
       (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id AND ee.pagado = 1) AS num_pagados
     FROM eventos ev ORDER BY ev.fecha ASC'
);
$eventos = $stmt->fetchAll();

$activos = array_filter($eventos, fn($e) => $e['estado'] !== 'Finalizado');
$proximo = null;
foreach ($activos as $e) {
    if ($proximo === null || $e['fecha'] < $proximo['fecha']) {
        $proximo = $e;
    }
}

$numEstudiantes = (int) db()->query('SELECT COUNT(*) FROM estudiantes')->fetchColumn();

$pendCount = 0;
$pendMonto = 0.0;
foreach ($eventos as $ev) {
    $pendientes = $ev['num_estudiantes'] - $ev['num_pagados'];
    $pendCount += $pendientes;
    $pendMonto += $pendientes * $ev['cuota'];
}

$pageTitle = 'Panel general';
$activeNav = 'panel';
$base = '.';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Panel general</h1>
    <p>Resumen de la operación de eventos gastronómicos.</p>
  </div>
  <a class="btn btn-primary" href="eventos/form.php"><?= icon('plus') ?> Nuevo evento</a>
</div>

<div class="stat-grid">
  <div class="stat-tile">
    <div class="stat-label">Eventos activos</div>
    <div class="stat-value"><?= count($activos) ?></div>
    <div class="stat-hint">de <?= count($eventos) ?> en total</div>
  </div>
  <div class="stat-tile">
    <div class="stat-label">Próximo evento</div>
    <div class="stat-value" style="font-size:1.05rem;"><?= $proximo ? e($proximo['nombre']) : '—' ?></div>
    <div class="stat-hint"><?= $proximo ? fmtDate($proximo['fecha']) : 'Sin eventos programados' ?></div>
  </div>
  <div class="stat-tile">
    <div class="stat-label">Estudiantes registrados</div>
    <div class="stat-value"><?= $numEstudiantes ?></div>
    <div class="stat-hint">lista maestra reutilizable</div>
  </div>
  <div class="stat-tile">
    <div class="stat-label">Pagos pendientes</div>
    <div class="stat-value num" style="color:var(--warning)"><?= $pendCount ?></div>
    <div class="stat-hint"><?= money($pendMonto) ?> por cobrar</div>
  </div>
</div>

<div class="card card-pad">
  <h2 class="section-title">Eventos</h2>
  <?php if (!$eventos): ?>
    <div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Aún no hay eventos</div>
      <div>Crea tu primer evento para empezar a planificarlo.</div>
    </div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Evento</th><th>Fecha</th><th>Estado</th><th>Presupuesto usado</th><th>Pagos</th></tr></thead>
    <tbody>
      <?php foreach ($eventos as $ev):
        $pct = $ev['presupuesto'] > 0 ? round($ev['gastado'] / $ev['presupuesto'] * 100) : 0;
      ?>
        <tr style="cursor:pointer;" onclick="window.location='eventos/detalle.php?id=<?= (int) $ev['id'] ?>'">
          <td class="cell-name"><?= e($ev['nombre']) ?></td>
          <td class="cell-muted"><?= fmtDate($ev['fecha']) ?></td>
          <td><span class="chip <?= chipEstadoClase($ev['estado']) ?>"><?= e($ev['estado']) ?></span></td>
          <td style="min-width:150px;">
            <div class="meter-row"><span><?= money($ev['gastado']) ?></span><span><?= (int) $pct ?>%</span></div>
            <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
          </td>
          <td class="cell-muted"><?= (int) $ev['num_pagados'] ?>/<?= (int) $ev['num_estudiantes'] ?> pagado<?= $ev['num_pagados'] == 1 ? '' : 's' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
