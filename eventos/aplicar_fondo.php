<?php
/**
 * Aplicar el fondo del estudiante a la cuota de este evento — permiso
 * aparte (eventos_fondo) del pago directo (eventos_estudiantes), a pedido
 * explícito de Eyaelkys, para poder asignar uno sin el otro. Reutiliza
 * aplicarFondoEstudiante()/eliminarMovimientoFondo() (includes/helpers.php),
 * que a su vez reutiliza registrarPagoEstudiante()/pagos_estudiante con
 * metodo='fondo' — por eso una aplicación hecha aquí también aparece en el
 * historial de pagos normal (pago_estudiante.php), ya marcada como "Fondo
 * del estudiante" y sin opción de editar/eliminar ahí (se corrige desde
 * aquí, para que el movimiento del fondo nunca se desincronice del pago).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos_fondo', 'ver', $base);
$puedeAplicar = can($usuarioActual, 'eventos_fondo', 'crear');
$puedeDeshacer = can($usuarioActual, 'eventos_fondo', 'eliminar');

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
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'aplicar') {
        requirePermission($usuarioActual, 'eventos_fondo', 'crear', $base);
        $monto = isset($_POST['monto']) ? (float) $_POST['monto'] : 0;
        $fecha = $_POST['fecha'] ?? '';
        $nota = trim((string) ($_POST['nota'] ?? ''));

        if (!$fecha || !strtotime($fecha)) {
            $errores[] = 'La fecha no es válida.';
        }
        if (mb_strlen($nota) > 150) {
            $nota = mb_substr($nota, 0, 150);
        }

        if (!$errores) {
            $errores = aplicarFondoEstudiante(db(), 'evento', $id, $estudianteId, $monto, $fecha, $nota, $usuarioActual['id'], $usuarioActual['nombre']);
            if (!$errores) {
                flash('Se aplicaron ' . money($monto) . ' del fondo a este evento.');
                redirect('aplicar_fondo.php?id=' . $id . '&estudiante_id=' . $estudianteId);
            }
        }
    } elseif ($accion === 'deshacer') {
        requirePermission($usuarioActual, 'eventos_fondo', 'eliminar', $base);
        $movimientoId = intOrNull($_POST['movimiento_id'] ?? null);
        if ($movimientoId) {
            $errDeshacer = eliminarMovimientoFondo(db(), $movimientoId, $estudianteId);
            if ($errDeshacer) {
                flash($errDeshacer[0], 'error');
            } else {
                flash('Aplicación revertida: el monto vuelve a estar disponible en el fondo.');
            }
        }
        redirect('aplicar_fondo.php?id=' . $id . '&estudiante_id=' . $estudianteId);
    }
}

// Cuota confirmada vigente, misma lógica que pago_estudiante.php y la
// pestaña "Estudiantes y pagos" de detalle.php.
$costoRecetas = costoRecetasConsolidado(db(), 'evento', $id);
$resumenGastos = resumenGastosVinculo(db(), 'evento_id', $id);
$stmt = db()->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ?');
$stmt->execute([$id]);
$cantidadEstudiantes = (int) $stmt->fetchColumn();
$cuotaManual = $evento['cuota_confirmada_manual'] !== null ? (float) $evento['cuota_confirmada_manual'] : null;
$cuotas = calcularCuotas($costoRecetas, $resumenGastos, $cantidadEstudiantes, $cuotaManual);
$cuotaConfirmada = $cuotas['confirmada'];

$montoPagado = (float) $estudiante['monto_pagado'];
$pendiente = max(0, $cuotaConfirmada - $montoPagado);
$alDia = $pendiente <= 0.005;

$saldoFondo = saldoFondoEstudiante(db(), $estudianteId);

// Solo las aplicaciones hechas a ESTE evento, para no mezclar con las de
// otros eventos/prácticas del mismo estudiante (esas se ven completas desde
// Estudiantes → Fondo).
$historialCompleto = historialFondoEstudiante(db(), $estudianteId);
$aplicacionesAqui = array_values(array_filter($historialCompleto, function ($m) use ($id) {
    return $m['tipo'] === 'aplicacion' && $m['entidad_tipo'] === 'evento' && (int) $m['entidad_id'] === $id;
}));

$pageTitle = 'Aplicar fondo';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '&tab=estudiantes">' . e($evento['nombre']) . '</a> &nbsp;/&nbsp; <b>Fondo de ' . e($estudiante['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Aplicar fondo</h1><p><?= e($estudiante['nombre']) ?><?= $estudiante['grupo'] ? ' · ' . e($estudiante['grupo']) : '' ?> · <?= e($evento['nombre']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px;">
  <div class="summary-grid" style="grid-template-columns:repeat(4,1fr);">
    <div>
      <div class="cell-muted" style="font-size:.82rem;">Saldo en el fondo</div>
      <div class="mono" style="font-size:1.15rem;font-weight:600;"><?= money($saldoFondo) ?></div>
    </div>
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
  <div class="stat-hint" style="margin-top:14px;">
    <a href="<?= e($base) ?>/estudiantes/detalle.php?id=<?= $estudianteId ?>">Ver fondo completo del estudiante</a> (depósitos y aplicaciones en todos los eventos/prácticas).
  </div>
</div>

<?php if ($puedeAplicar): ?>
<div class="card card-pad form-card" style="max-width:560px;margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Aplicar del fondo a este evento</h2>
  <?php if ($saldoFondo <= 0.005): ?>
    <p class="cell-muted">Este estudiante no tiene saldo disponible en su fondo todavía.</p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="aplicar">
    <div class="field-row">
      <div class="field">
        <label for="monto">Monto a aplicar (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" max="<?= e((string) $saldoFondo) ?>" step="0.01" required value="<?= e((string) ($_POST['monto'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($_POST['fecha'] ?? date('Y-m-d')) ?>">
      </div>
    </div>
    <div class="field">
      <label for="nota">Nota (opcional)</label>
      <input type="text" id="nota" name="nota" maxlength="150" placeholder="Ej. cuota de octubre" value="<?= e($_POST['nota'] ?? '') ?>">
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Aplicar del fondo</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Fecha</th><th>Monto</th><th>Nota</th><th>Registrado por</th><?php if ($puedeDeshacer): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (!$aplicacionesAqui): ?>
        <tr><td colspan="<?= $puedeDeshacer ? 5 : 4 ?>" class="cell-muted" style="text-align:center;padding:24px;">Todavía no se ha aplicado el fondo a este evento.</td></tr>
      <?php endif; ?>
      <?php foreach ($aplicacionesAqui as $mov): ?>
        <tr>
          <td class="cell-muted"><?= fmtDate($mov['fecha']) ?></td>
          <td class="mono"><?= money($mov['monto']) ?></td>
          <td class="cell-muted"><?= e($mov['nota'] ?? '') ?></td>
          <td class="cell-muted"><?= e($mov['registrado_por_nombre']) ?></td>
          <?php if ($puedeDeshacer): ?>
            <td class="row-actions">
              <form method="post" data-confirm="¿Deshacer esta aplicación de <?= e(money($mov['monto'])) ?>? El monto vuelve a estar disponible en el fondo.">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="deshacer">
                <input type="hidden" name="movimiento_id" value="<?= (int) $mov['id'] ?>">
                <button class="icon-btn" type="submit" title="Deshacer"><?= icon('x') ?></button>
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
