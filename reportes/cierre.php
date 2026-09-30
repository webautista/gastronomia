<?php
/**
 * Cierre financiero de un evento o práctica — ver reportes/index.php
 * (pestaña "Cierre financiero") y cierreFinanciero() en includes/helpers.php.
 * Junta en una sola pantalla imprimible lo que hoy está repartido en varias
 * pestañas del detalle (Resumen + Gastos + Estudiantes): costo real vs.
 * proyectado, cuota sugerida vs. confirmada (con su nota si hay un ajuste
 * manual), gastos por categoría, y el estado de pago de cada estudiante.
 * Solo lectura — para cerrar/archivar un evento o práctica ya terminado,
 * aunque también sirve para uno todavía en curso.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'reportes', 'ver', $base);

$puedeVerEventos = can($usuarioActual, 'eventos', 'ver');
$puedeVerPracticas = can($usuarioActual, 'practicas', 'ver');

$tipo = $_GET['tipo'] ?? '';
$id = intOrNull($_GET['id'] ?? null);
if (!in_array($tipo, ['evento', 'practica'], true) || !$id) {
    redirect('index.php?tab=cierre');
}

// Dos vistas de la misma pantalla, para poder imprimir/guardar como PDF
// cualquiera de las dos (a pedido de Eyaelkys): la vista completa de
// siempre (con el detalle de pago de cada estudiante, uso interno) y una
// vista resumida sin esa tabla, pensada para compartir con las familias sin
// mostrarles el estado de pago de los demás estudiantes.
$conEstudiantes = !empty($_GET['detalle']);
$hrefVista = 'cierre.php?tipo=' . urlencode($tipo) . '&id=' . $id;

$datos = cierreFinanciero(db(), $tipo, $id);
if (!$datos) {
    flash('Ese ' . ($tipo === 'evento' ? 'evento' : 'práctica') . ' ya no existe.', 'error');
    redirect('index.php?tab=cierre');
}
$entidad = $datos['entidad'];
$cuotas = $datos['cuotas'];
$hrefEntidad = ($tipo === 'evento' ? $base . '/eventos/' : $base . '/practicas/') . 'detalle.php?id=' . $id;
$puedeLinkear = $tipo === 'evento' ? $puedeVerEventos : $puedeVerPracticas;

$catTotales = $datos['gastos_por_categoria'];
$maxCat = max(1, ...(array_values($catTotales) ?: [1]));
$paletaCategorias = ['var(--copper)', 'var(--accent)', 'var(--success)', 'var(--accent-strong)'];

$pageTitle = 'Cierre financiero';
$activeNav = 'reportes';
$bodyClass = 'report-print';
$breadcrumb = '<a href="index.php?tab=cierre">Reportes</a> &nbsp;/&nbsp; <b>' . e($entidad['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Cierre financiero</h1>
    <p>
      <?php if ($puedeLinkear): ?><a href="<?= e($hrefEntidad) ?>"><?= e($entidad['nombre']) ?></a><?php else: ?><?= e($entidad['nombre']) ?><?php endif; ?>
      · <?= fmtDate($entidad['fecha']) ?>
      <?php if ($tipo === 'evento'): ?>
        <?php if (!empty($entidad['lugar'])): ?> · <?= e($entidad['lugar']) ?><?php endif; ?>
        · <span class="chip chip-muted"><?= e($entidad['estado_nombre']) ?></span>
      <?php else: ?>
        <?php if (!empty($entidad['materia'])): ?> · <?= e($entidad['materia']) ?><?php endif; ?>
        <?php if (!empty($entidad['maestro_responsable'])): ?> · <?= e($entidad['maestro_responsable']) ?><?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="no-print">
    <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= !$conEstudiantes ? 'active' : '' ?>" href="<?= e($hrefVista) ?>"><?= icon('heart') ?> Para compartir con los padres</a>
  <a class="tab <?= $conEstudiantes ? 'active' : '' ?>" href="<?= e($hrefVista) ?>&detalle=1"><?= icon('users') ?> Vista interna, con estudiantes</a>
</div>

<p class="cell-muted" style="margin:-10px 0 20px;font-size:.82rem;">
  <?php if ($conEstudiantes): ?>
    Incluye el estado de pago de cada estudiante — para uso interno, no para compartir con las familias.
  <?php else: ?>
    Resumen general de <?= $tipo === 'evento' ? 'este evento' : 'esta práctica' ?>, sin el detalle de pago de cada estudiante. Lista para compartir con las familias.
  <?php endif; ?>
</p>

<div class="summary-grid summary-grid-5">
  <div class="stat-tile stat-tile--wine">
    <div class="stat-label">Costo estimado</div>
    <div class="stat-value"><?= money($datos['costo_estimado']) ?></div>
    <div class="stat-hint">costo base de las recetas, antes de marcar "Ya lo tienes" o comprar paquete completo</div>
  </div>
  <div class="stat-tile stat-tile--gold">
    <div class="stat-label">Monto lista de compra</div>
    <div class="stat-value"><?= money($datos['costo_recetas']) ?></div>
    <div class="stat-hint">lo que de verdad falta comprar, ya con "Ya lo tienes"/paquete completo aplicado</div>
  </div>
  <div class="stat-tile">
    <div class="stat-label">Costo real</div>
    <div class="stat-value"><?= money($cuotas['total_confirmado']) ?></div>
    <div class="stat-hint">materiales (recetas o gasto real, el mayor) + otros gastos ya confirmados/pagados</div>
  </div>
  <div class="stat-tile stat-tile--terracotta">
    <div class="stat-label">Cuota <?= $cuotas['cuota_ajustada'] ? 'confirmada (ajustada)' : 'confirmada' ?></div>
    <div class="stat-value"><?= money($cuotas['confirmada']) ?></div>
    <?php if ($cuotas['cuota_ajustada']): ?>
      <div class="stat-hint">sugerida: <b class="mono"><?= money($cuotas['confirmada_sugerida']) ?></b><?php if (!empty($entidad['cuota_confirmada_manual_nota'])): ?> · "<?= e($entidad['cuota_confirmada_manual_nota']) ?>"<?php endif; ?></div>
    <?php else: ?>
      <div class="stat-hint">por estudiante · <?= $datos['cantidad_estudiantes'] ?> asignado<?= $datos['cantidad_estudiantes'] === 1 ? '' : 's' ?></div>
    <?php endif; ?>
  </div>
  <div class="stat-tile stat-tile--sage">
    <div class="stat-label">Recaudado</div>
    <div class="stat-value"><?= money($datos['recaudado']) ?></div>
    <div class="stat-hint">de <?= money($cuotas['meta_recaudo']) ?> esperado (<?= $cuotas['meta_recaudo'] > 0 ? round($datos['recaudado'] / $cuotas['meta_recaudo'] * 100) : 0 ?>%)</div>
  </div>
</div>

<div class="summary-grid" style="grid-template-columns:1fr 1fr;">
  <div class="card card-pad">
    <div class="section-title-row"><span class="section-icon is-gold"><?= icon('receipt') ?></span><h2 class="section-title">Gastos por categoría</h2></div>
    <?php if (!$catTotales): ?>
      <div class="cell-muted">Aún no hay gastos registrados.</div>
    <?php else: ?>
      <div class="bar-list">
        <?php $iCat = 0; foreach ($catTotales as $cat => $val): ?>
          <div class="bar-list-row">
            <span class="cell-muted"><?= e($cat) ?></span>
            <div class="bar-track"><div class="bar-fill" style="width:<?= round($val / $maxCat * 100) ?>%;background:<?= $paletaCategorias[$iCat % count($paletaCategorias)] ?>;"></div></div>
            <span class="mono" style="text-align:right;"><?= money($val) ?></span>
          </div>
        <?php $iCat++; endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="section-title-row"><span class="section-icon is-wine"><?= icon('portion') ?></span><h2 class="section-title">Proyección vs. costo real</h2></div>
    <div style="display:flex;justify-content:space-between;align-items:center;font-size:.85rem;padding:6px 0;border-bottom:1px solid var(--border);">
      <span class="cell-muted">Proyección total (todo lo aún no confirmado incluido)</span><span class="mono" style="font-weight:700;"><?= money($cuotas['total_proyeccion']) ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;font-size:.85rem;padding:6px 0;">
      <span class="cell-muted">Materiales (proyectado en recetas o gasto real, el mayor)</span><span class="mono"><?= money($cuotas['materiales_final']) ?></span>
    </div>
    <?php if ($cuotas['material_excedido']): ?>
      <div class="stat-hint" style="margin-top:10px;color:var(--danger);"><?= icon('alertTriangle') ?> El gasto real en materiales superó lo proyectado por <?= money($cuotas['material_exceso']) ?>.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;"><h2 class="section-title" style="margin:0;">Gastos</h2></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>Fecha</th><th>Estado</th><th>Monto</th></tr></thead>
    <tbody>
      <?php if (!$datos['gastos']): ?>
        <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Todavía no hay gastos registrados.</td></tr>
      <?php endif; ?>
      <?php foreach ($datos['gastos'] as $g): ?>
        <tr>
          <td><?= e($g['categoria']) ?></td>
          <td class="cell-muted"><?= e($g['descripcion']) ?></td>
          <td class="cell-muted"><?= e($g['proveedor'] ?: '—') ?></td>
          <td class="cell-muted"><?= fmtDate($g['fecha']) ?></td>
          <td>
            <?php if ($g['estado'] === 'pagado'): ?><span class="chip chip-success">Pagado</span>
            <?php elseif ($g['estado'] === 'confirmado'): ?><span class="chip chip-warning">Confirmado</span>
            <?php else: ?><span class="chip chip-muted">Proyectado</span><?php endif; ?>
          </td>
          <td class="mono"><?= money(montoEfectivoGasto($g)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($datos['gastos']): ?>
    <tfoot>
      <tr><td colspan="5" style="text-align:right;"><b>Total</b></td><td class="mono"><b><?= money(array_sum($catTotales)) ?></b></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<?php if ($conEstudiantes): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;"><h2 class="section-title" style="margin:0;">Estudiantes</h2></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Estudiante</th><th>Pagado</th><th>Pendiente</th></tr></thead>
    <tbody>
      <?php if (!$datos['estudiantes']): ?>
        <tr><td colspan="3" class="cell-muted" style="text-align:center;padding:24px;">No hay estudiantes asignados.</td></tr>
      <?php endif; ?>
      <?php foreach ($datos['estudiantes'] as $e): ?>
        <?php $alDia = $e['pendiente'] <= 0.005; ?>
        <tr>
          <td><?= e($e['estudiante_nombre']) ?></td>
          <td class="mono"><?= money($e['pagado']) ?></td>
          <td>
            <?php if ($alDia): ?>
              <span class="chip chip-success"><?= icon('check') ?> Al día</span>
            <?php else: ?>
              <span class="chip chip-warning mono"><?= money($e['pendiente']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($datos['estudiantes']): ?>
    <tfoot>
      <tr><td style="text-align:right;"><b>Total</b></td><td class="mono"><b><?= money($datos['recaudado']) ?></b></td><td class="mono"><b><?= money(array_sum(array_column($datos['estudiantes'], 'pendiente'))) ?></b></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="form-actions no-print" style="margin-top:16px;">
  <a class="btn btn-secondary" href="index.php?tab=cierre">Volver</a>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
