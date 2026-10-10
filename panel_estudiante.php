<?php
/**
 * Panel personal de un estudiante con cuenta de acceso (ver
 * consumirInvitacionRegistro() en includes/helpers.php e invitacion.php,
 * generada desde estudiantes/detalle.php): solo lectura, mostrando su
 * propio fondo, todos los eventos/prácticas en los que participa con sus
 * pagos (indicando el método), y las recetas asignadas a cada uno con la
 * lista de compra — lo mismo que vería el equipo del taller en la pestaña
 * "Recetas e ingredientes"/"Lista de Compra" de ese evento/práctica, sin
 * nada editable. panel.php redirige aquí automáticamente a quien inicia
 * sesión siendo un estudiante vinculado.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$base = '.';
$usuarioActual = requireLogin($base);

$stmt = db()->prepare('SELECT * FROM estudiantes WHERE usuario_id = ?');
$stmt->execute([$usuarioActual['id']]);
$estudiante = $stmt->fetch();
if (!$estudiante) {
    // No es un estudiante vinculado (ej. un administrador entrando por la
    // URL a mano) — panel.php decide a dónde debe ir en realidad.
    redirect('panel.php');
}

$saldoFondo = saldoFondoEstudiante(db(), (int) $estudiante['id']);
$historialFondo = historialFondoEstudiante(db(), (int) $estudiante['id']);
$participaciones = participacionesEstudiante(db(), (int) $estudiante['id']);

// El detalle de recetas/lista de compra es la parte más pesada de calcular
// (una consulta por ingrediente de cada receta), así que solo se arma para
// las participaciones que de verdad se van a mostrar.
foreach ($participaciones as &$part) {
    $detalle = recetasConDetalleParaConsulta(db(), $part['tipo'], $part['id']);
    $part['recetas'] = $detalle['recetas'];
    $part['acciones_por_fila'] = $detalle['acciones_por_fila'];

    $recetasParaLista = array_map(
        fn($rc) => ['receta_id' => $rc['id'], 'porciones_necesarias' => $rc['porciones_necesarias'], 'entidad_tipo' => $part['tipo'], 'base_calculo' => $rc['base_calculo'] ?? null],
        $part['recetas']
    );
    $decisionesCompra = cargarDecisionesCompra(db(), $part['tipo'], $part['id']);
    $part['lista_compra'] = listaCompraConsolidada(db(), $recetasParaLista, $decisionesCompra);
}
unset($part);

$totalPagado = array_sum(array_column($participaciones, 'monto_pagado'));
$totalPendiente = array_sum(array_column($participaciones, 'pendiente'));

$pageTitle = 'Mi panel';
$activeNav = 'panel';
$breadcrumb = '<b>Mi panel</b>';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="child-card">
  <div class="child-card-head">
    <div style="display:flex;align-items:center;gap:14px;">
      <div class="child-avatar"><?= e(iniciales($estudiante['nombre'])) ?></div>
      <div>
        <div class="child-name">Hola, <?= e($estudiante['nombre']) ?></div>
        <div class="child-sub">Tu fondo, tus eventos/prácticas y sus recetas</div>
      </div>
    </div>
    <div class="child-balance">
      <div class="child-balance-label">Saldo en tu fondo</div>
      <div class="child-balance-value"><?= money($saldoFondo) ?></div>
    </div>
  </div>

  <div class="mini-stat-row">
    <div class="mini-stat is-sage">
      <div class="mini-stat-label">Total pagado</div>
      <div class="mini-stat-value"><?= money($totalPagado) ?></div>
    </div>
    <div class="mini-stat is-danger">
      <div class="mini-stat-label">Pendiente por pagar</div>
      <div class="mini-stat-value"><?= money($totalPendiente) ?></div>
    </div>
    <div class="mini-stat is-gold">
      <div class="mini-stat-label">Eventos y prácticas</div>
      <div class="mini-stat-value"><?= count($participaciones) ?></div>
    </div>
  </div>

  <div style="padding:6px 24px 22px;">
    <?php if ($historialFondo): ?>
      <details>
        <summary>Ver historial del fondo (<?= count($historialFondo) ?>)</summary>
        <div class="table-wrap" style="margin-top:10px;">
          <table class="table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Método</th><th>Dónde</th><th>Nota</th></tr></thead>
            <tbody>
              <?php foreach ($historialFondo as $mov): ?>
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
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= $mov['tipo'] === 'aplicacion' && $mov['entidad_nombre'] ? e($mov['entidad_nombre']) : '—' ?></td>
                  <td class="cell-muted"><?= e($mov['nota'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php else: ?>
      <p class="cell-muted" style="margin:0;">Todavía no hay movimientos en tu fondo.</p>
    <?php endif; ?>
  </div>
</div>

<h2 class="section-title">Tus eventos y prácticas</h2>

<?php if (!$participaciones): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Todavía no estás asignado a ningún evento o práctica</div>
    <div>Cuando te asignen a uno, aparecerá aquí con sus recetas y tus pagos.</div>
  </div></div>
<?php endif; ?>

<?php foreach ($participaciones as $part):
  $contenedorId = 'recetas-' . $part['tipo'] . '-' . $part['id'];
?>
  <?php $pct = $part['cuota_confirmada'] > 0 ? round($part['monto_pagado'] / $part['cuota_confirmada'] * 100) : ($part['monto_pagado'] > 0 ? 100 : 0); ?>
  <details class="card card-pad" style="margin-bottom:18px;" open>
    <summary style="cursor:pointer;">
      <span style="display:inline-flex;align-items:center;gap:8px;">
        <?= $part['tipo'] === 'evento' ? icon('calendar') : icon('whisk') ?>
        <b><?= e($part['nombre']) ?></b>
        <span class="cell-muted" style="font-size:.85rem;"><?= fmtFechaEvento($part['fecha'], !empty($part['fecha_tentativa'])) ?></span>
      </span>
    </summary>

    <div class="part-card" style="margin-top:14px;">
      <div class="part-card-head">
        <div class="part-icon <?= e($part['tipo']) ?>"><?= $part['tipo'] === 'evento' ? icon('calendar') : icon('whisk') ?></div>
        <div>
          <div class="part-title">Cuota <span class="mono"><?= money($part['cuota_confirmada']) ?></span></div>
          <div class="part-date">Pendiente: <b class="mono"><?= money($part['pendiente']) ?></b></div>
        </div>
        <div class="part-progress">
          <div class="meter-row" style="margin-bottom:0;"><span>Pagado</span><span class="mono"><?= money($part['monto_pagado']) ?> / <?= money($part['cuota_confirmada']) ?></span></div>
          <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
        </div>
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

    <div class="toolbar no-print" style="margin-top:20px;">
      <h3 class="section-title" style="margin:0;">Recetas</h3>
      <?php if (count($part['recetas']) > 1): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-role="toggle-todas-recetas" data-contenedor="<?= e($contenedorId) ?>">Colapsar todo</button>
      <?php endif; ?>
    </div>
    <?php if (!$part['recetas']): ?>
      <p class="cell-muted">Todavía no hay recetas asignadas.</p>
    <?php endif; ?>
    <div data-role="<?= e($contenedorId) ?>">
      <?php foreach ($part['recetas'] as $rc):
        $porcionesBase = (int) $rc['porciones_referencia'];
      ?>
        <div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
          <div class="recipe-card-head">
            <div style="display:flex;align-items:center;gap:8px;">
              <button class="icon-btn no-print" type="button" data-role="recipe-collapse-toggle" title="Colapsar/expandir"><?= icon('chevronDown') ?></button>
              <div>
                <h4><?= e($rc['nombre']) ?></h4>
                <div class="cell-muted"><?= e($rc['categoria']) ?> · <?= e(etiquetaPorcionesReferencia($rc, $rc['entidad_tipo'])) ?> · <span class="mono" data-role="costo-total-badge"><?= money($rc['costo_total']) ?></span></div>
              </div>
            </div>
            <div class="stat-hint"><?= (int) $rc['porciones_necesarias'] ?> porciones a preparar</div>
          </div>
          <div class="recipe-card-body">
          <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Costo est.</th></tr></thead>
            <tbody>
              <?php foreach ($rc['ingredientes'] as $ing):
                $esAlGusto = !empty($ing['al_gusto']);
                $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
                $esEntera = (bool) ($ing['unidad_entera'] ?? false);
                $costo = montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
              ?>
                <tr>
                  <td class="cell-name">
                    <?= e($ing['nombre']) ?>
                    <?php if (!empty($ing['opcional'])): ?> <span class="chip chip-muted" style="font-size:.68rem;">Opcional</span><?php endif; ?>
                    <?php if (!empty($ing['reemplazo'])): ?><div class="cell-muted" style="font-size:.78rem;">o <?= e($ing['reemplazo']) ?></div><?php endif; ?>
                    <?php if (!empty($part['acciones_por_fila'][$ing['id']])): ?><div class="cell-muted" style="font-size:.78rem;"><?= e(implode(', ', $part['acciones_por_fila'][$ing['id']])) ?></div><?php endif; ?>
                  </td>
                  <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>" data-entera="<?= $esEntera ? '1' : '0' ?>" data-al-gusto="<?= $esAlGusto ? '1' : '0' ?>"><?= $esAlGusto ? 'Al gusto' : numFmt($cantidad) . ' ' . e($ing['unidad']) . fraccionSufijo($cantidad) ?></td>
                  <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= $esAlGusto ? '—' : money($costo) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr><td colspan="2" style="text-align:right;font-weight:600;">Costo estimado de ingredientes</td><td class="mono" style="font-weight:600;" data-role="costo-total"><?= money($rc['costo_total']) ?></td></tr>
            </tfoot>
          </table>
          </div>
          <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
            <details class="prep-details">
              <summary><?= icon('book') ?> Ver preparación</summary>
              <div class="prep-text"><?= e($rc['preparacion']) ?></div>
            </details>
          <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($part['recetas']): ?>
      <h3 class="section-title" style="margin-top:20px;">Lista de compra</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Costo est.</th></tr></thead>
          <tbody>
            <?php if (!$part['lista_compra']['lineas'] && !$part['lista_compra']['al_gusto']): ?>
              <tr><td colspan="3" class="cell-muted" style="text-align:center;padding:16px;">Sin ingredientes todavía.</td></tr>
            <?php endif; ?>
            <?php foreach ($part['lista_compra']['lineas'] as $l): ?>
              <tr>
                <td class="cell-name"><?= e($l['nombre']) ?></td>
                <td class="mono">
                  <?= numFmt($l['cantidad']) ?> <?= e($l['unidad']) ?>
                  <?php if (!empty($l['compra'])): $cantCompleta = $l['compra']['cantidad_completa'] ?? $l['compra']['cantidad']; ?>
                    <div class="cell-muted" style="font-size:.78rem;font-weight:400;margin-top:4px;">comprar ≈ <?= numFmt($cantCompleta) ?> <?= e($l['compra']['unidad']) ?></div>
                  <?php elseif (!empty($l['cantidad_entera_a_comprar'])): ?>
                    <div class="cell-muted" style="font-size:.78rem;font-weight:400;margin-top:4px;">comprar <?= numFmt($l['cantidad_entera_a_comprar']) ?> <?= e($l['unidad']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= money($l['monto']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php foreach ($part['lista_compra']['al_gusto'] as $ag): ?>
              <tr>
                <td class="cell-name"><?= e($ag['nombre']) ?> <span class="chip chip-muted" style="font-size:.68rem;">Al gusto</span></td>
                <td class="cell-muted mono">—</td>
                <td class="cell-muted mono">—</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($part['lista_compra']['lineas']): ?>
          <tfoot>
            <tr><td colspan="2" style="text-align:right;font-weight:600;">Costo estimado total</td><td class="mono" style="font-weight:600;"><?= money($part['lista_compra']['total']) ?></td></tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    <?php endif; ?>
  </details>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
