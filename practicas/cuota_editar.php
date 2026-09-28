<?php
/**
 * Ajustar manualmente la cuota confirmada de una práctica — ver
 * eventos/cuota_editar.php para la explicación completa (mismo mecanismo,
 * aplicado a practicas.cuota_confirmada_manual en vez de eventos.*).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas_gastos', 'editar', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$id]);
$practica = $stmt->fetch();
if (!$practica) {
    flash('Esa práctica ya no existe.', 'error');
    redirect('index.php');
}

$costoMateriales = costoRecetasConsolidado(db(), 'practica', $id);
$resumenGastos = resumenGastosVinculo(db(), 'practica_id', $id);
$stmt = db()->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ?');
$stmt->execute([$id]);
$cantidadEstudiantes = (int) $stmt->fetchColumn();

$cuotaManualActual = $practica['cuota_confirmada_manual'] !== null ? (float) $practica['cuota_confirmada_manual'] : null;
$cuotas = calcularCuotas($costoMateriales, $resumenGastos, $cantidadEstudiantes, $cuotaManualActual);
$cuotaSugerida = $cuotas['confirmada_sugerida'];

$valorCuota = $cuotaManualActual !== null ? (string) $cuotaManualActual : '';
$notaActual = $practica['cuota_confirmada_manual_nota'] ?? '';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $valorCuota = trim($_POST['cuota'] ?? '');
    $notaNueva = trim($_POST['nota'] ?? '');
    if (mb_strlen($notaNueva) > 255) {
        $notaNueva = mb_substr($notaNueva, 0, 255);
    }

    $cuotaNuevaManual = null;
    if ($valorCuota !== '') {
        if (!is_numeric($valorCuota) || (float) $valorCuota <= 0) {
            $errores[] = 'La cuota debe ser un número mayor a 0, o déjala vacía para volver al cálculo automático.';
        } else {
            $cuotaNuevaManual = round((float) $valorCuota, 2);
        }
    }

    if (!$errores) {
        db()->prepare('UPDATE practicas SET cuota_confirmada_manual = ?, cuota_confirmada_manual_nota = ? WHERE id = ?')
            ->execute([$cuotaNuevaManual, $notaNueva !== '' ? $notaNueva : null, $id]);

        registrarCambioCuota(
            db(),
            'practica',
            $id,
            $cuotaManualActual,
            $cuotaNuevaManual,
            $notaNueva,
            (int) $usuarioActual['id'],
            $usuarioActual['nombre']
        );

        flash($cuotaNuevaManual !== null ? 'Cuota confirmada ajustada a ' . money($cuotaNuevaManual) . '.' : 'Se quitó el ajuste — la cuota vuelve a calcularse automáticamente.');
        redirect('detalle.php?id=' . $id . '&tab=estudiantes');
    }
}

$historial = historialCuota(db(), 'practica', $id);

$pageTitle = 'Ajustar cuota confirmada';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '&tab=estudiantes">' . e($practica['nombre']) . '</a> &nbsp;/&nbsp; <b>Ajustar cuota</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Ajustar cuota confirmada</h1><p><?= e($practica['nombre']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px;">
  <div class="summary-grid" style="grid-template-columns:repeat(2,1fr);">
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Cuota sugerida (cálculo automático)</div>
      <div class="mono" style="font-size:1.15rem;font-weight:600;"><?= money($cuotaSugerida) ?></div>
    </div>
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Estudiantes asignados</div>
      <div class="mono" style="font-size:1.15rem;font-weight:600;"><?= $cantidadEstudiantes ?></div>
    </div>
  </div>
  <div class="stat-hint" style="margin-top:14px;">
    La cuota sugerida es lo que de verdad costó (recetas + gastos) dividido entre los estudiantes. Si el taller decide cobrar un monto distinto — por logística, redondeo o cualquier otro motivo — fíjalo abajo: se usará esa cifra en vez de la sugerida en todo el sistema (paneles de padres y estudiantes, pagos, fondo). La cuota sugerida se sigue mostrando como referencia.
  </div>
</div>

<div class="card card-pad form-card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label for="cuota">Cuota confirmada (RD$)</label>
      <input type="number" id="cuota" name="cuota" min="0.01" step="0.01" placeholder="Ej. 220.00" value="<?= e($valorCuota) ?>">
      <div class="hint">Déjalo vacío para quitar el ajuste y volver a la cuota sugerida.</div>
    </div>
    <div class="field">
      <label for="nota">Nota (opcional)</label>
      <input type="text" id="nota" name="nota" maxlength="255" placeholder="Ej. acordado por tema de logística" value="<?= e($notaActual) ?>">
    </div>
    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Cancelar</a>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</div>

<?php if ($historial): ?>
<div class="card card-pad" style="margin-top:16px;">
  <div class="section-title-row" style="margin-bottom:10px;">
    <div class="section-icon is-gold"><?= icon('clock') ?></div>
    <h2 class="section-title" style="margin:0;">Historial de ajustes</h2>
  </div>
  <div class="pago-row-list">
    <?php foreach ($historial as $h): ?>
      <div class="pago-row" style="flex-wrap:wrap;">
        <span class="pago-fecha"><?= fmtDate(substr($h['creado_en'], 0, 10)) ?> <span class="cell-muted" style="font-size:.78rem;"><?= date('g:i a', strtotime($h['creado_en'])) ?></span></span>
        <span class="cell-muted" style="font-size:.85rem;">
          <?= $h['valor_anterior'] !== null ? e(money((float) $h['valor_anterior'])) : '<span class="cell-muted">automático</span>' ?>
          →
          <?= $h['valor_nuevo'] !== null ? e(money((float) $h['valor_nuevo'])) : '<span class="cell-muted">automático</span>' ?>
        </span>
        <?php if (!empty($h['nota'])): ?><span class="cell-muted" style="font-size:.85rem;font-style:italic;">"<?= e($h['nota']) ?>"</span><?php endif; ?>
        <span class="chip chip-muted"><?= e($h['registrado_por_nombre']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
