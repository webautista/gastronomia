<?php
/**
 * Historial de pagos de un estudiante en un evento: cada pago (parcial o
 * completo) es su propia fila, con fecha, monto y método (Efectivo /
 * Transferencia bancaria) — reemplaza el registro de un solo monto
 * acumulado que había antes en la pestaña "Estudiantes y pagos"
 * (eventos/detalle.php), a pedido explícito de Eyaelkys. Ver
 * pagos_estudiante en db/schema.sql y registrarPagoEstudiante() /
 * recomputarMontoPagadoEstudiante() en includes/helpers.php: esta pantalla
 * nunca toca evento_estudiante.monto_pagado directo, siempre pasa por esas
 * funciones para que ese total (leído por panel.php, index.php, etc.) se
 * mantenga en sincronía con la suma real del historial.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos_estudiantes', 'ver', $base);
$puedeEditar = can($usuarioActual, 'eventos_estudiantes', 'editar');

$id = intOrNull($_GET['id'] ?? null);
$estudianteId = intOrNull($_GET['estudiante_id'] ?? null);
if (!$id || !$estudianteId) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$id]);
$evento = $stmt->fetch();
if (!$evento) {
    flash('Ese evento ya no existe.', 'error');
    redirect('index.php');
}

$stmt = db()->prepare(
    'SELECT ee.monto_pagado, est.*, ge.nombre AS grupo
     FROM evento_estudiante ee
     JOIN estudiantes est ON est.id = ee.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = est.grupo_id
     WHERE ee.evento_id = ? AND ee.estudiante_id = ?'
);
$stmt->execute([$id, $estudianteId]);
$estudiante = $stmt->fetch();
if (!$estudiante) {
    flash('Ese estudiante ya no está asignado a este evento.', 'error');
    redirect('detalle.php?id=' . $id . '&tab=estudiantes');
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    requirePermission($usuarioActual, 'eventos_estudiantes', 'editar', $base);
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_pago') {
        $monto = isset($_POST['monto']) ? (float) $_POST['monto'] : 0;
        $metodo = $_POST['metodo'] ?? '';
        $fechaPago = $_POST['fecha_pago'] ?? '';
        $nota = trim((string) ($_POST['nota'] ?? ''));

        if ($monto <= 0) {
            $errores[] = 'El monto debe ser mayor a 0.';
        }
        if (!in_array($metodo, ['efectivo', 'transferencia'], true)) {
            $errores[] = 'Elige cómo se hizo el pago: efectivo o transferencia bancaria.';
        }
        if (!$fechaPago || !strtotime($fechaPago)) {
            $errores[] = 'La fecha del pago no es válida.';
        }
        if (mb_strlen($nota) > 150) {
            $nota = mb_substr($nota, 0, 150);
        }

        if (!$errores) {
            registrarPagoEstudiante(db(), 'evento', $id, $estudianteId, $monto, $metodo, $fechaPago, $nota);
            flash('Pago registrado.');
            redirect('pago_estudiante.php?id=' . $id . '&estudiante_id=' . $estudianteId);
        }
    } elseif ($accion === 'eliminar_pago') {
        $pagoId = intOrNull($_POST['pago_id'] ?? null);
        if ($pagoId) {
            eliminarPagoEstudiante(db(), 'evento', $id, $estudianteId, $pagoId);
            flash('Pago eliminado.');
        }
        redirect('pago_estudiante.php?id=' . $id . '&estudiante_id=' . $estudianteId);
    }
}

// Cuota confirmada vigente, con la misma lógica que la pestaña "Estudiantes
// y pagos" de detalle.php (calcularCuotas(), includes/helpers.php) — así el
// "pendiente" que se muestra aquí siempre coincide con el de esa pestaña.
$costoRecetas = costoRecetasConsolidado(db(), 'evento', $id);
$resumenGastos = resumenGastosVinculo(db(), 'evento_id', $id);
$stmt = db()->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ?');
$stmt->execute([$id]);
$cantidadEstudiantes = (int) $stmt->fetchColumn();
$cuotas = calcularCuotas($costoRecetas, $resumenGastos, $cantidadEstudiantes);
$cuotaConfirmada = $cuotas['confirmada'];

$montoPagado = (float) $estudiante['monto_pagado'];
$pendiente = max(0, $cuotaConfirmada - $montoPagado);
$alDia = $pendiente <= 0.005;

$historial = historialPagosEstudiante(db(), 'evento', $id, $estudianteId);

$pageTitle = 'Historial de pagos';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '&tab=estudiantes">' . e($evento['nombre']) . '</a> &nbsp;/&nbsp; <b>Pagos de ' . e($estudiante['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Historial de pagos</h1><p><?= e($estudiante['nombre']) ?><?= $estudiante['grupo'] ? ' · ' . e($estudiante['grupo']) : '' ?> · <?= e($evento['nombre']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px;">
  <div class="summary-grid" style="grid-template-columns:repeat(3,1fr);">
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Cuota confirmada</div>
      <div class="mono" style="font-size:1.15rem;font-weight:600;"><?= money($cuotaConfirmada) ?></div>
    </div>
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Total pagado</div>
      <div class="mono" style="font-size:1.15rem;font-weight:600;"><?= money($montoPagado) ?></div>
    </div>
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Pendiente</div>
      <div>
        <?php if ($alDia): ?>
          <span class="chip chip-success"><?= icon('check') ?> Al día</span>
        <?php else: ?>
          <span class="chip chip-warning mono"><?= money($pendiente) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($puedeEditar): ?>
<div class="card card-pad form-card" style="max-width:640px;margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Agregar pago</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="agregar_pago">
    <div class="field-row">
      <div class="field">
        <label for="monto">Monto (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" step="0.01" required value="<?= e((string) ($_POST['monto'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="fecha_pago">Fecha del pago</label>
        <input type="date" id="fecha_pago" name="fecha_pago" required value="<?= e($_POST['fecha_pago'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="field">
        <label for="metodo">Método</label>
        <select id="metodo" name="metodo" required>
          <option value="">Elige uno...</option>
          <option value="efectivo" <?= ($_POST['metodo'] ?? '') === 'efectivo' ? 'selected' : '' ?>>Efectivo</option>
          <option value="transferencia" <?= ($_POST['metodo'] ?? '') === 'transferencia' ? 'selected' : '' ?>>Transferencia bancaria</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="nota">Nota (opcional)</label>
      <input type="text" id="nota" name="nota" maxlength="150" placeholder="Ej. abono de la cuota de octubre" value="<?= e($_POST['nota'] ?? '') ?>">
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Registrar pago</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Nota</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (!$historial): ?>
        <tr><td colspan="<?= $puedeEditar ? 5 : 4 ?>" class="cell-muted" style="text-align:center;padding:24px;">Todavía no hay pagos registrados.</td></tr>
      <?php endif; ?>
      <?php foreach ($historial as $pago): ?>
        <tr>
          <td class="cell-muted"><?= fmtDate($pago['fecha_pago']) ?></td>
          <td class="mono"><?= money($pago['monto']) ?></td>
          <td>
            <?php
              $claseMetodo = $pago['metodo'] === 'efectivo' ? 'chip-success' : ($pago['metodo'] === 'transferencia' ? 'chip-neutral' : 'chip-muted');
            ?>
            <span class="chip <?= $claseMetodo ?>"><?= e(etiquetaMetodoPago($pago['metodo'])) ?></span>
          </td>
          <td class="cell-muted"><?= e($pago['nota'] ?? '') ?></td>
          <?php if ($puedeEditar): ?>
            <td class="row-actions">
              <form method="post" data-confirm="¿Eliminar este pago de <?= e(money($pago['monto'])) ?>? No se puede deshacer.">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar_pago">
                <input type="hidden" name="pago_id" value="<?= (int) $pago['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar pago"><?= icon('trash') ?></button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="form-actions" style="margin-top:16px;">
  <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Volver</a>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
