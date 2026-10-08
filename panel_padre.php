<?php
/**
 * Panel personal de un padre/tutor con cuenta de acceso (ver
 * consumirInvitacionRegistro() en includes/helpers.php e invitacion.php):
 * solo lectura, filtrado a sus propios hijos vinculados (padre_estudiante),
 * mostrando el fondo de cada uno y los pagos hechos en cada evento/práctica
 * en el que participa, indicando el método (efectivo, transferencia o
 * fondo). panel.php redirige aquí automáticamente a quien inicia sesión
 * siendo un padre vinculado.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$base = '.';
$usuarioActual = requireLogin($base);

$stmt = db()->prepare('SELECT * FROM padres WHERE usuario_id = ?');
$stmt->execute([$usuarioActual['id']]);
$padre = $stmt->fetch();
if (!$padre) {
    // No es un padre vinculado (ej. un administrador entrando por la URL a
    // mano) — panel.php decide a dónde debe ir en realidad.
    redirect('panel.php');
}

$stmt = db()->prepare(
    'SELECT e.*, ge.nombre AS grupo
     FROM padre_estudiante pe JOIN estudiantes e ON e.id = pe.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id
     WHERE pe.padre_id = ? ORDER BY e.nombre ASC'
);
$stmt->execute([$padre['id']]);
$hijos = $stmt->fetchAll();

foreach ($hijos as &$h) {
    $h['saldo_fondo'] = saldoFondoEstudiante(db(), (int) $h['id']);
    $h['participaciones'] = participacionesEstudiante(db(), (int) $h['id']);
    $h['total_pagado'] = array_sum(array_column($h['participaciones'], 'monto_pagado'));
    $h['total_pendiente'] = array_sum(array_column($h['participaciones'], 'pendiente'));
}
unset($h);

$pageTitle = 'Mi panel';
$activeNav = 'panel';
$breadcrumb = '<b>Mi panel</b>';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Hola, <?= e($padre['nombre']) ?></h1>
    <p>Esto es lo que tienen tus estudiantes vinculados en el sistema.</p>
  </div>
</div>

<?php if (!$hijos): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Todavía no tienes estudiantes vinculados</div>
    <div>Si esto no es correcto, contacta al taller.</div>
  </div></div>
<?php endif; ?>

<?php foreach ($hijos as $h): ?>
  <div class="child-card">
    <div class="child-card-head">
      <div style="display:flex;align-items:center;gap:14px;">
        <div class="child-avatar"><?= e(iniciales($h['nombre'])) ?></div>
        <div>
          <div class="child-name"><?= e($h['nombre']) ?></div>
          <div class="child-sub"><?= e($h['grupo'] ?? 'Sin grupo asignado') ?></div>
        </div>
      </div>
      <div class="child-balance">
        <div class="child-balance-label">Saldo en el fondo</div>
        <div class="child-balance-value"><?= money($h['saldo_fondo']) ?></div>
      </div>
    </div>

    <div class="mini-stat-row">
      <div class="mini-stat is-sage">
        <div class="mini-stat-label">Total pagado</div>
        <div class="mini-stat-value"><?= money($h['total_pagado']) ?></div>
      </div>
      <div class="mini-stat is-danger">
        <div class="mini-stat-label">Pendiente por pagar</div>
        <div class="mini-stat-value"><?= money($h['total_pendiente']) ?></div>
      </div>
      <div class="mini-stat is-gold">
        <div class="mini-stat-label">Eventos y prácticas</div>
        <div class="mini-stat-value"><?= count($h['participaciones']) ?></div>
      </div>
    </div>

    <div class="part-list">
      <?php if (!$h['participaciones']): ?>
        <p class="cell-muted" style="margin:8px 0 0;">Todavía no está asignado a ningún evento o práctica.</p>
      <?php endif; ?>

      <?php foreach ($h['participaciones'] as $part):
        $pct = $part['cuota_confirmada'] > 0 ? round($part['monto_pagado'] / $part['cuota_confirmada'] * 100) : ($part['monto_pagado'] > 0 ? 100 : 0);
      ?>
        <div class="part-card">
          <div class="part-card-head">
            <div class="part-icon <?= e($part['tipo']) ?>"><?= $part['tipo'] === 'evento' ? icon('calendar') : icon('whisk') ?></div>
            <div>
              <div class="part-title"><?= e($part['nombre']) ?></div>
              <div class="part-date"><?= fmtFechaEvento($part['fecha'], !empty($part['fecha_tentativa'])) ?></div>
            </div>
            <div class="part-progress">
              <div class="meter-row" style="margin-bottom:0;"><span>Pagado</span><span class="mono"><?= money($part['monto_pagado']) ?> / <?= money($part['cuota_confirmada']) ?></span></div>
              <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
            </div>
          </div>
          <div class="part-money-row" style="margin-bottom:10px;">
            <span>Pendiente: <b class="mono"><?= money($part['pendiente']) ?></b></span>
          </div>

          <?php if (!$part['historial_pagos']): ?>
            <p class="cell-muted" style="margin:0;font-size:.85rem;">Sin pagos registrados todavía.</p>
          <?php else: ?>
            <div class="pago-row-list">
              <?php foreach ($part['historial_pagos'] as $pago): ?>
                <div class="pago-row">
                  <span class="pago-fecha"><?= fmtDate($pago['fecha_pago']) ?></span>
                  <span class="pago-monto"><?= money($pago['monto']) ?></span>
                  <span class="chip <?= claseChipMetodoPago($pago['metodo']) ?>"><?= e(etiquetaMetodoPago($pago['metodo'])) ?></span>
                  <?php if ($pago['nota']): ?><span class="pago-nota"><?= e($pago['nota']) ?></span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
