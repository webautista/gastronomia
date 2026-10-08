<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$base = '.';
$usuarioActual = requireLogin($base);

// Si quien inició sesión es un padre/tutor o un estudiante con cuenta
// propia, su "panel" es el panel personalizado (fondo, pagos, y para el
// estudiante también sus recetas/lista de compra) — no este dashboard
// general, pensado para el equipo del taller (presupuestos y datos de
// TODOS los eventos y estudiantes). panel.php sigue siendo el destino fijo
// después de iniciar sesión (login.php e invitacion.php redirigen aquí);
// desde aquí se reenvía a donde corresponda según quién es.
$stmtPadre = db()->prepare('SELECT id FROM padres WHERE usuario_id = ?');
$stmtPadre->execute([$usuarioActual['id']]);
if ($stmtPadre->fetchColumn()) {
    redirect('panel_padre.php');
}
$stmtEst = db()->prepare('SELECT id FROM estudiantes WHERE usuario_id = ?');
$stmtEst->execute([$usuarioActual['id']]);
if ($stmtEst->fetchColumn()) {
    redirect('panel_estudiante.php');
}

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
    $cuotaManual = $ev['cuota_confirmada_manual'] !== null ? (float) $ev['cuota_confirmada_manual'] : null;
    $cuotas = calcularCuotas($costoRecetas, $resumenGastos, (int) $ev['num_estudiantes'], $cuotaManual);
    $ev['gastado'] = $resumenGastos['material_usado'] + $resumenGastos['otros_usado'];
    $ev['presupuesto_total'] = $cuotas['total_proyeccion'];
    $ev['total_confirmado'] = $cuotas['meta_recaudo'];
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
    $cuotaManual = $p['cuota_confirmada_manual'] !== null ? (float) $p['cuota_confirmada_manual'] : null;
    $cuotas = calcularCuotas($costoMateriales, $resumenGastos, (int) $p['num_estudiantes'], $cuotaManual);
    $p['gastado'] = $resumenGastos['material_usado'] + $resumenGastos['otros_usado'];
    $p['presupuesto_total'] = $cuotas['total_proyeccion'];
    $p['total_confirmado'] = $cuotas['meta_recaudo'];
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
    <div class="stat-hint"><?= $proximo ? fmtFechaEvento($proximo['fecha'], !empty($proximo['fecha_tentativa'])) : 'Sin eventos programados' ?></div>
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
  <div class="event-grid">
    <?php foreach ($eventos as $ev):
      $pctRecaudado = $ev['total_confirmado'] > 0 ? round($ev['recaudado'] / $ev['total_confirmado'] * 100) : 0;
    ?>
      <div class="dash-card" style="cursor:pointer;" onclick="window.location='eventos/detalle.php?id=<?= (int) $ev['id'] ?>'">
        <div class="dash-card-head">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="dash-icon"><?= icon('calendar') ?></div>
              <div>
                <div class="dash-title"><?= e($ev['nombre']) ?></div>
                <div class="dash-meta">
                  <span><?= icon('calendar') ?> <?= fmtFechaEvento($ev['fecha'], !empty($ev['fecha_tentativa'])) ?> <?= chipFechaTentativa(!empty($ev['fecha_tentativa'])) ?></span>
                  <?php if ($ev['lugar']): ?><span><?= icon('pin') ?> <?= e($ev['lugar']) ?></span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="dash-chips">
              <span class="chip <?= chipEstadoClase($ev['estado']) ?>"><?= e($ev['estado']) ?></span>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="dash-stats">
            <div class="dash-stat"><div class="dash-stat-label">Proyectado</div><div class="dash-stat-value"><?= money($ev['presupuesto_total']) ?></div></div>
            <div class="dash-stat"><div class="dash-stat-label">Usado</div><div class="dash-stat-value"><?= money($ev['gastado']) ?></div></div>
            <div class="dash-stat is-gold"><div class="dash-stat-label">Cuota confirmada</div><div class="dash-stat-value"><?= money($ev['cuota_confirmada']) ?></div></div>
            <div class="dash-stat is-sage"><div class="dash-stat-label">Estudiantes al día</div><div class="dash-stat-value"><?= (int) $ev['num_pagados'] ?>/<?= (int) $ev['num_estudiantes'] ?></div></div>
          </div>
          <div>
            <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($ev['recaudado']) ?> / <?= money($ev['total_confirmado']) ?></span></div>
            <div class="meter <?= meterClase($pctRecaudado) ?>"><span style="width:<?= min($pctRecaudado, 100) ?>%"></span></div>
          </div>
          <div class="dash-footer">
            <a class="btn btn-secondary btn-sm" href="eventos/detalle.php?id=<?= (int) $ev['id'] ?>" onclick="event.stopPropagation()">Ver detalle</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
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
  <div class="event-grid">
    <?php foreach ($practicas as $p):
      $pctRecaudado = $p['total_confirmado'] > 0 ? round($p['recaudado'] / $p['total_confirmado'] * 100) : 0;
    ?>
      <div class="dash-card" style="cursor:pointer;" onclick="window.location='practicas/detalle.php?id=<?= (int) $p['id'] ?>'">
        <div class="dash-card-head is-practica">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="dash-icon"><?= icon('whisk') ?></div>
              <div>
                <div class="dash-title"><?= e($p['nombre']) ?></div>
                <div class="dash-meta">
                  <span><?= icon('calendar') ?> <?= fmtDate($p['fecha']) ?></span>
                  <?php if ($p['materia']): ?><span><?= icon('book') ?> <?= e($p['materia']) ?></span><?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="dash-stats">
            <div class="dash-stat"><div class="dash-stat-label">Proyectado</div><div class="dash-stat-value"><?= money($p['presupuesto_total']) ?></div></div>
            <div class="dash-stat"><div class="dash-stat-label">Usado</div><div class="dash-stat-value"><?= money($p['gastado']) ?></div></div>
            <div class="dash-stat is-gold"><div class="dash-stat-label">Cuota confirmada</div><div class="dash-stat-value"><?= money($p['cuota_confirmada']) ?></div></div>
            <div class="dash-stat is-sage"><div class="dash-stat-label">Estudiantes al día</div><div class="dash-stat-value"><?= (int) $p['num_pagados'] ?>/<?= (int) $p['num_estudiantes'] ?></div></div>
          </div>
          <div>
            <div class="meter-row"><span>Recaudado</span><span class="mono"><?= money($p['recaudado']) ?> / <?= money($p['total_confirmado']) ?></span></div>
            <div class="meter <?= meterClase($pctRecaudado) ?>"><span style="width:<?= min($pctRecaudado, 100) ?>%"></span></div>
          </div>
          <div class="dash-footer">
            <a class="btn btn-secondary btn-sm" href="practicas/detalle.php?id=<?= (int) $p['id'] ?>" onclick="event.stopPropagation()">Ver detalle</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
