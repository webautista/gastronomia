<?php
/**
 * Estado de cuenta consolidado de un estudiante — ver reportes/index.php
 * (pestaña "Estado de cuenta") y estadoCuentaEstudiante() en
 * includes/helpers.php. Junta en una sola pantalla lo que hoy está repartido
 * evento por evento/práctica: todas sus participaciones con su cuota
 * vigente/pagado/pendiente, más el fondo completo (saldo e historial).
 * Solo lectura — ningún formulario aquí; los pagos y depósitos se siguen
 * registrando desde pago_estudiante.php/aplicar_fondo.php/estudiantes/detalle.php.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'reportes', 'ver', $base);

$puedeVerEventos = can($usuarioActual, 'eventos', 'ver');
$puedeVerPracticas = can($usuarioActual, 'practicas', 'ver');
$puedeVerFichaEstudiante = can($usuarioActual, 'estudiantes_fondo', 'ver');

$id = intOrNull($_GET['id'] ?? null);
$datos = $id ? estadoCuentaEstudiante(db(), $id) : null;
if (!$datos) {
    flash('Ese estudiante ya no existe.', 'error');
    redirect('index.php?tab=cuenta');
}
$estudiante = $datos['estudiante'];

$stmtPadres = db()->prepare(
    'SELECT p.nombre, p.telefono, p.email FROM padre_estudiante pe JOIN padres p ON p.id = pe.padre_id WHERE pe.estudiante_id = ? ORDER BY p.nombre ASC'
);
$stmtPadres->execute([$id]);
$padres = $stmtPadres->fetchAll();

$pageTitle = 'Estado de cuenta';
$activeNav = 'reportes';
$bodyClass = 'report-print';
$breadcrumb = '<a href="index.php?tab=cuenta">Reportes</a> &nbsp;/&nbsp; <b>' . e($estudiante['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Estado de cuenta</h1>
    <p><?= e($estudiante['nombre']) ?><?= $estudiante['grupo'] ? ' · ' . e($estudiante['grupo']) : '' ?></p>
  </div>
  <div class="no-print">
    <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
  </div>
</div>

<div class="card card-pad no-print" style="margin-bottom:16px;">
  <div class="person-meta">
    <span><?= icon('phone') ?> <?= $estudiante['telefono'] ? e($estudiante['telefono']) : 'Sin teléfono' ?></span>
    <span><?= icon('mail') ?> <?= $estudiante['email'] ? e($estudiante['email']) : 'Sin email' ?></span>
  </div>
  <div class="person-meta" style="margin-top:6px;">
    <span><?= icon('heart') ?>
      <?php if ($padres): ?>
        <?= e(implode(', ', array_map(fn ($p) => $p['nombre'] . ($p['telefono'] ? ' (' . $p['telefono'] . ')' : ''), $padres))) ?>
      <?php else: ?>
        Sin padre/madre o tutor vinculado
      <?php endif; ?>
    </span>
  </div>
  <?php if ($puedeVerFichaEstudiante): ?>
    <div class="stat-hint" style="margin-top:10px;"><a href="<?= e($base) ?>/estudiantes/detalle.php?id=<?= $id ?>">Ver ficha completa del estudiante</a> (para depositar al fondo o corregir un movimiento).</div>
  <?php endif; ?>
</div>

<div class="summary-grid summary-grid-4">
  <div class="stat-tile">
    <div class="stat-label">Total en cuotas</div>
    <div class="stat-value"><?= money($datos['total_cuota']) ?></div>
    <div class="stat-hint"><?= count($datos['participaciones']) ?> participación<?= count($datos['participaciones']) === 1 ? '' : 'es' ?></div>
  </div>
  <div class="stat-tile stat-tile--sage">
    <div class="stat-label">Total pagado</div>
    <div class="stat-value"><?= money($datos['total_pagado']) ?></div>
  </div>
  <div class="stat-tile stat-tile--terracotta">
    <div class="stat-label">Total pendiente</div>
    <div class="stat-value"><?= money($datos['total_pendiente']) ?></div>
  </div>
  <div class="stat-tile stat-tile--gold">
    <div class="stat-label">Saldo en el fondo</div>
    <div class="stat-value"><?= money($datos['saldo_fondo']) ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;"><h2 class="section-title" style="margin:0;">Eventos y prácticas</h2></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Evento / Práctica</th><th>Fecha</th><th>Cuota</th><th>Pagado</th><th>Pendiente</th></tr></thead>
    <tbody>
      <?php if (!$datos['participaciones']): ?>
        <tr><td colspan="5" class="cell-muted" style="text-align:center;padding:24px;">Este estudiante todavía no ha sido asignado a ningún evento ni práctica.</td></tr>
      <?php endif; ?>
      <?php foreach ($datos['participaciones'] as $p): ?>
        <?php
          $hrefEntidad = ($p['tipo'] === 'evento' ? $base . '/eventos/' : $base . '/practicas/') . 'detalle.php?id=' . $p['id'] . '&tab=estudiantes';
          $puedeLinkear = $p['tipo'] === 'evento' ? $puedeVerEventos : $puedeVerPracticas;
          $alDia = $p['pendiente'] <= 0.005;
        ?>
        <tr>
          <td>
            <?php if ($puedeLinkear): ?><a href="<?= e($hrefEntidad) ?>"><?= e($p['nombre']) ?></a><?php else: ?><?= e($p['nombre']) ?><?php endif; ?>
            <span class="chip chip-muted" style="margin-left:6px;font-size:.7rem;"><?= $p['tipo'] === 'evento' ? 'Evento' : 'Práctica' ?></span>
          </td>
          <td class="cell-muted"><?= fmtDate($p['fecha']) ?></td>
          <td class="mono"><?= money($p['cuota_confirmada']) ?></td>
          <td class="mono"><?= money($p['monto_pagado']) ?></td>
          <td>
            <?php if ($alDia): ?>
              <span class="chip chip-success"><?= icon('check') ?> Al día</span>
            <?php else: ?>
              <span class="chip chip-warning mono"><?= money($p['pendiente']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;"><h2 class="section-title" style="margin:0;">Historial del fondo</h2></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Método</th><th>Dónde</th><th>Nota</th></tr></thead>
    <tbody>
      <?php if (!$datos['historial_fondo']): ?>
        <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Todavía no hay movimientos en el fondo.</td></tr>
      <?php endif; ?>
      <?php foreach ($datos['historial_fondo'] as $mov): ?>
        <tr>
          <td class="cell-muted"><?= fmtDate($mov['fecha']) ?></td>
          <td>
            <?php if ($mov['tipo'] === 'deposito'): ?>
              <span class="chip chip-success">Depósito</span>
            <?php else: ?>
              <span class="chip chip-neutral">Aplicación</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= money($mov['monto']) ?></td>
          <td class="cell-muted">
            <?php if ($mov['tipo'] === 'deposito' && $mov['metodo']): ?>
              <span class="chip <?= $mov['metodo'] === 'efectivo' ? 'chip-success' : 'chip-neutral' ?>"><?= e(etiquetaMetodoPago($mov['metodo'])) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="cell-muted"><?= ($mov['tipo'] === 'aplicacion' && $mov['entidad_nombre']) ? e($mov['entidad_nombre']) : '—' ?></td>
          <td class="cell-muted"><?= e($mov['nota'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="form-actions no-print" style="margin-top:16px;">
  <a class="btn btn-secondary" href="index.php?tab=cuenta">Volver</a>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
