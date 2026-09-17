<?php
/**
 * Marcar un gasto ya confirmado como pagado: exige la fecha de pago y una
 * imagen de la factura (ver includes/helpers.php, montoEfectivoGasto() y
 * el ciclo de vida proyectado → confirmado → pagado documentado en
 * db/schema.sql, tabla gastos). El monto pagado es editable por separado
 * del monto confirmado, para conservar el historial de las tres etapas.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'gastos', 'editar', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT g.*, cg.nombre AS categoria FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id WHERE g.id = ? AND g.evento_id IS NOT NULL AND g.eliminado_en IS NULL');
$stmt->execute([$id]);
$gasto = $stmt->fetch();
if (!$gasto) {
    flash('Ese gasto ya no existe.', 'error');
    redirect('index.php');
}
if ($gasto['estado'] !== 'confirmado') {
    flash('Ese gasto no está en estado "confirmado" — solo un gasto confirmado se puede marcar como pagado.', 'error');
    redirect('detalle.php?id=' . $gasto['evento_id'] . '&tab=gastos');
}

$eventoId = (int) $gasto['evento_id'];
$stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$eventoId]);
$evento = $stmt->fetch();

$montoPagado = $gasto['monto_confirmado'] ?? $gasto['monto'];
$fechaPago = date('Y-m-d');
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $montoPagado = (float) ($_POST['monto_pagado'] ?? 0);
    $fechaPago = $_POST['fecha_pago'] ?? '';

    if ($montoPagado <= 0) {
        $errores[] = 'El monto pagado debe ser mayor a 0.';
    }
    if (!$fechaPago || !strtotime($fechaPago)) {
        $errores[] = 'La fecha de pago no es válida.';
    }

    $facturaFinal = null;
    if (!$errores) {
        if (!isset($_FILES['factura']) || $_FILES['factura']['error'] === UPLOAD_ERR_NO_FILE) {
            $errores[] = 'Debes cargar una imagen de la factura para poder marcar el gasto como pagado.';
        } elseif ($_FILES['factura']['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir la factura. Intenta de nuevo.';
        } else {
            $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = @mime_content_type($_FILES['factura']['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                $errores[] = 'La factura debe ser una imagen JPG, PNG o WEBP.';
            } elseif ($_FILES['factura']['size'] > 5 * 1024 * 1024) {
                $errores[] = 'La imagen de la factura no puede pesar más de 5 MB.';
            } else {
                $directorioDestino = __DIR__ . '/../assets/uploads/facturas';
                if (!is_dir($directorioDestino)) {
                    mkdir($directorioDestino, 0775, true);
                }
                $nombreArchivo = 'factura_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $tiposPermitidos[$mime];
                if (move_uploaded_file($_FILES['factura']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                    $facturaFinal = 'assets/uploads/facturas/' . $nombreArchivo;
                } else {
                    $errores[] = 'No se pudo guardar la factura en el servidor. Vuelve a intentarlo.';
                }
            }
        }
    }

    if (!$errores) {
        db()->prepare("UPDATE gastos SET estado='pagado', monto_pagado=?, fecha_pago=?, factura=? WHERE id=?")
            ->execute([$montoPagado, $fechaPago, $facturaFinal, $id]);
        flash('Gasto marcado como pagado.');
        redirect('detalle.php?id=' . $eventoId . '&tab=gastos');
    }
}

$pageTitle = 'Marcar gasto como pagado';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $eventoId . '&tab=gastos">' . e($evento['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Marcar como pagado</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Marcar gasto como pagado</h1><p><?= e($gasto['descripcion']) ?> · <?= e($gasto['categoria']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <p class="cell-muted" style="margin-top:0;">Monto confirmado: <b class="mono"><?= money($gasto['monto_confirmado'] ?? $gasto['monto']) ?></b></p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field-row">
      <div class="field">
        <label for="monto_pagado">Monto pagado (RD$)</label>
        <input type="number" id="monto_pagado" name="monto_pagado" min="0.01" step="0.01" required value="<?= e((string) $montoPagado) ?>">
      </div>
      <div class="field">
        <label for="fecha_pago">Fecha de pago</label>
        <input type="date" id="fecha_pago" name="fecha_pago" required value="<?= e($fechaPago) ?>">
      </div>
    </div>

    <div class="field">
      <label for="factura">Factura (foto)</label>
      <input type="file" id="factura" name="factura" accept="image/jpeg,image/png,image/webp" required>
      <div class="hint">Obligatoria. JPG, PNG o WEBP, hasta 5 MB.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $eventoId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Marcar como pagado</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
