<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$base = '.';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'panel', 'ver', $base);
$puedeCrearEvento = can($usuarioActual, 'eventos', 'crear');

$stmt = db()->query(
    'SELECT ev.*, es.nombre AS estado,
       (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS num_estudiantes,
       (SELECT COALESCE(SUM(ee.monto_pagado),0) FROM evento_estudiante ee WHERE ee.evento_id = ev.id) AS recaudado
     FROM eventos ev
     JOIN estados_evento es ON es.id = ev.estado_id
     ORDER BY ev.fecha ASC'
);
$eventos = $stmt->fetchAll();

// El "gastado" (presupuesto usado) y la cuota ya no se leen de columnas
// fijas (presupuesto/cuota, en desuso): se calculan igual que en el
// detalle del evento, para que este panel nunca muestre un número
// distinto al que se vería al entrar al evento. "Gastado" cuenta solo lo
// confirmado/pagado — un gasto todavía proyectado nunca cuenta como
// presupuesto usado (el bug reportado: una partida proyectada de
// RD$3,000 aparecía como "usada" sin haberse confirmado).
// La barra de la tabla ya no mide el gasto (eso puede seguir en cero
// mientras no se compre nada, aunque los estudiantes ya hayan pagado, y
// entonces parecía que no había entrado dinero) — mide lo recaudado
// contra la cuota confirmada, igual que en eventos/index.php, y al lado
// se muestra el proyectado y lo usado como referencia.
foreach ($eventos as &$ev) {
    $costoRecetas = costoRecetasConsolidado(db(), 'evento', (int) $ev['id']);
    $resumenGastos = resumenGastosVinculo(db(), 'evento_id', (int) $ev['id']);
    $cuotas = calcularCuotas($costoRecetas, $resumenGastos, (int) $ev['num_estudiantes']);
    $ev['gastado'] = $resumenGastos['material_usado'] + $resumenGastos['otros_usado'];
    $ev['presupuesto_total'] = $cuotas['total_proyeccion'];
    $ev['total_confirmado'] = $cuotas['total_confirmado'];
    $ev['cuota_confirmada'] = $cuotas['confirmada'];

    $stmtPag = db()->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ? AND monto_pagado >= ?');
    $stmtPag->execute([(int) $ev['id'], $cuotas['confirmada'] - 0.005]);
    $ev['num_pagados'] = (int) $stmtPag->fetchColumn();
}
unset($ev);

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
    $pendMonto += $pendientes * $ev['cuota_confirmada'];
}

// Mismo resumen que arriba para Eventos, pero para Prácticas — no tienen
// estado_id (no aplica "Finalizado"/"Planificado" a una práctica), así que
// la tabla muestra Materia en su lugar. El resto (presupuesto usado, cuota
// y pagos) se calcula exactamente igual que en practicas/index.php.
$stmt = db()->query(
    'SELECT p.*,
       (SELECT COUNT(*) FROM practica_estudiante pe WHERE pe.practica_id = p.id) AS num_estudiantes,
       (SELECT COALESCE(SUM(pe.monto_pagado),0) FROM practica_estudiante pe WHERE pe.practica_id = p.id) AS recaudado
     FROM practicas p
     ORDER BY p.fecha ASC'
);
$practicas = $stmt->fetchAll();

foreach ($practicas as &$p) {
    $costoMateriales = costoRecetasConsolidado(db(), 'practica', (int) $p['id']);
    $resumenGastos = resumenGastosVinculo(db(), 'practica_id', (int) $p['id']);
    $cuotas = calcularCuotas($costoMateriales, $resumenGastos, (int) $p['num_estudiantes']);
    $p['gastado'] = $resumenGastos['material_usado'] + $resumenGastos['otros_usado'];
    $p['presupuesto_total'] = $cuotas['total_proyeccion'];
    $p['total_confirmado'] = $cuotas['total_confirmado'];
    $p['cuota_confirmada'] = $cuotas['confirmada'];

    $stmtPag = db()->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ? AND monto_pagado >= ?');
    $stmtPag->execute([(int) $p['id'], $cuotas['confirmada'] - 0.005]);
    $p['num_pagados'] = (int) $stmtPag->fetchColumn();
}
unset($p);

$pageTitle = 'Panel general';
$activeNav = 'panel';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Panel general</h1>
    <p>Resumen de la operación de eventos gastronómicos.</p>
  </div>
  <?php if ($puedeCrearEvento): ?>
    <a class="btn btn-primary" href="eventos/form.php"><?= icon('plus') ?> Nuevo evento</a>
  <?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat-tile stat-tile--wine">
    <div class="stat-label">Eventos activos</div>
    <div class="stat-value"><?= count($activos) ?></div>
    <div class="stat-hint">de <?= count($eventos) ?> en total</div>
  </div>
  <div class="stat-tile stat-tile--gold">
    <div class="stat-label">Próximo evento</div>
    <div class="stat-value" style="font-size:1.05rem;"><?= $proximo ? e($proximo['nombre']) : '—' ?></div>
    <div class="stat-hint"><?= $proximo ? fmtDate($proximo['fecha']) : 'Sin eventos programados' ?></div>
  </div>
  <div class="stat-tile stat-tile--sage">
    <div class="stat-label">Estudiantes registrados</div>
    <div class="stat-value"><?= $numEstudiantes ?></div>
    <div class="stat-hint">lista maestra reutilizable</div>
  </div>
  <div class="stat-tile stat-tile--terracotta">
    <div class="stat-label">Pagos pendientes</div>
    <div class="stat-value num"><?= $pendCount ?></div>
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
    <thead><tr><th>Evento</th><th>Fecha</th><th>Estado</th><th>Presupuesto</th><th>Pagos</th></tr></thead>
    <tbody>
      <?php foreach ($eventos as $ev):
        $pctRecaudado = $ev['total_confirmado'] > 0 ? round($ev['recaudado'] / $ev['total_confirmado'] * 100) : 0;
      ?>
        <tr style="cursor:pointer;" onclick="window.location='eventos/detalle.php?id=<?= (int) $ev['id'] ?>'">
          <td class="cell-name"><?= e($ev['nombre']) ?></td>
          <td class="cell-muted"><?= fmtDate($ev['fecha']) ?></td>
          <td><span class="chip <?= chipEstadoClase($ev['estado']) ?>"><?= e($ev['estado']) ?></span></td>
          <td style="min-width:180px;">
            <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($ev['recaudado']) ?> / <?= money($ev['total_confirmado']) ?></span></div>
            <div class="meter <?= meterClase($pctRecaudado) ?>"><span style="width:<?= min($pctRecaudado, 100) ?>%"></span></div>
            <div class="cell-muted" style="font-size:.78rem;margin-top:4px;">Proyectado <?= money($ev['presupuesto_total']) ?> · Usado <?= money($ev['gastado']) ?></div>
          </td>
          <td class="cell-muted"><?= (int) $ev['num_pagados'] ?>/<?= (int) $ev['num_estudiantes'] ?> pagado<?= $ev['num_pagados'] == 1 ? '' : 's' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card card-pad" style="margin-top:18px;">
  <h2 class="section-title">Prácticas</h2>
  <?php if (!$practicas): ?>
    <div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Aún no hay prácticas</div>
      <div>Crea tu primera práctica para empezar a planificarla.</div>
    </div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Práctica</th><th>Fecha</th><th>Materia</th><th>Presupuesto</th><th>Pagos</th></tr></thead>
    <tbody>
      <?php foreach ($practicas as $p):
        $pctRecaudado = $p['total_confirmado'] > 0 ? round($p['recaudado'] / $p['total_confirmado'] * 100) : 0;
      ?>
        <tr style="cursor:pointer;" onclick="window.location='practicas/detalle.php?id=<?= (int) $p['id'] ?>'">
          <td class="cell-name"><?= e($p['nombre']) ?></td>
          <td class="cell-muted"><?= fmtDate($p['fecha']) ?></td>
          <td class="cell-muted"><?= $p['materia'] ? e($p['materia']) : '—' ?></td>
          <td style="min-width:180px;">
            <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($p['recaudado']) ?> / <?= money($p['total_confirmado']) ?></span></div>
            <div class="meter <?= meterClase($pctRecaudado) ?>"><span style="width:<?= min($pctRecaudado, 100) ?>%"></span></div>
            <div class="cell-muted" style="font-size:.78rem;margin-top:4px;">Proyectado <?= money($p['presupuesto_total']) ?> · Usado <?= money($p['gastado']) ?></div>
          </td>
          <td class="cell-muted"><?= (int) $p['num_pagados'] ?>/<?= (int) $p['num_estudiantes'] ?> pagado<?= $p['num_pagados'] == 1 ? '' : 's' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
