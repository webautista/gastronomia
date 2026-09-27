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
  <div class="card card-pad" style="margin-bottom:18px;">
    <div class="page-head" style="margin-bottom:12px;">
      <div>
        <h2 class="section-title" style="margin:0;"><?= e($h['nombre']) ?></h2>
        <p class="cell-muted" style="margin:2px 0 0;"><?= e($h['grupo'] ?? '—') ?></p>
      </div>
      <div style="text-align:right;">
        <div class="cell-muted" style="font-size:.82rem;">Saldo en el fondo</div>
        <div class="mono" style="font-size:1.3rem;font-weight:700;"><?= money($h['saldo_fondo']) ?></div>
      </div>
    </div>

    <?php if (!$h['participaciones']): ?>
      <p class="cell-muted">Todavía no está asignado a ningún evento o práctica.</p>
    <?php endif; ?>

    <?php foreach ($h['participaciones'] as $part): ?>
      <div style="margin-top:14px;">
        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
          <?= $part['tipo'] === 'evento' ? icon('calendar') : icon('whisk') ?>
          <b><?= e($part['nombre']) ?></b>
          <span class="cell-muted" style="font-size:.85rem;"><?= fmtDate($part['fecha']) ?></span>
          <span class="cell-muted" style="font-size:.85rem;margin-left:auto;">
            Cuota <b class="mono"><?= money($part['cuota_confirmada']) ?></b>
            &nbsp;·&nbsp; Pagado <b class="mono"><?= money($part['monto_pagado']) ?></b>
            &nbsp;·&nbsp; Pendiente <b class="mono"><?= money($part['pendiente']) ?></b>
          </span>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Fecha de pago</th><th>Monto</th><th>Método</th><th>Nota</th></tr></thead>
            <tbody>
              <?php if (!$part['historial_pagos']): ?>
                <tr><td colspan="4" class="cell-muted" style="text-align:center;padding:16px;">Sin pagos registrados todavía.</td></tr>
              <?php endif; ?>
              <?php foreach ($part['historial_pagos'] as $pago): ?>
                <tr>
                  <td class="cell-muted"><?= fmtDate($pago['fecha_pago']) ?></td>
                  <td class="mono"><?= money($pago['monto']) ?></td>
                  <td><span class="chip <?= claseChipMetodoPago($pago['metodo']) ?>"><?= e(etiquetaMetodoPago($pago['metodo'])) ?></span></td>
                  <td class="cell-muted"><?= e($pago['nota'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
