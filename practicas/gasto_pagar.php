<?php
/** Marcar un gasto de una práctica como pagado — mismo patrón que eventos/gasto_pagar.php. */
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

$stmt = db()->prepare('SELECT g.*, cg.nombre AS categoria FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id WHERE g.id = ? AND g.practica_id IS NOT NULL AND g.eliminado_en IS NULL');
$stmt->execute([$id]);
$gasto = $stmt->fetch();
if (!$gasto) {
    flash('Ese gasto ya no existe.', 'error');
    redirect('index.php');
}
if ($gasto['estado'] !== 'confirmado') {
    flash('Ese gasto no está en estado "confirmado" — solo un gasto confirmado se puede marcar como pagado.', 'error');
    redirect('detalle.php?id=' . $gasto['practica_id'] . '&tab=gastos');
}

$practicaId = (int) $gasto['practica_id'];
$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$practicaId]);
$practica = $stmt->fetch();

$montoPagado = $gasto['monto_confirmado'] ?? $gasto['monto'];
$fechaPago = date('Y-m-d');
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Ver el mismo bloque en eventos/gasto_pagar.php: si la foto supera el
    // límite total de subida del servidor (post_max_size), PHP descarta
    // todo el POST —incluido el token de seguridad— antes de llegar aquí,
    // sin ningún código de error en $_FILES. La compresión de imagen en el
    // navegador (assets/js/app.js) debería evitar esto casi siempre; este
    // aviso queda como respaldo si la imagen igual llega demasiado pesada.
    $errores[] = 'La imagen es demasiado pesada para este servidor. Tu navegador ya intenta reducirla automáticamente; si el problema sigue, prueba con otra foto o con una de menor resolución.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $montoPagado = (float) ($_POST['monto_pagado'] ?? 0);
    $fechaPago = $_POST['fecha_pago'] ?? '';

    if ($montoPagado <= 0) {
        $errores[] = 'El monto pagado debe ser mayor a 0.';
    }
    if (!$fechaPago || !strtotime($fechaPago)) {
        $errores[] = 'La fecha de pago no es válida.';
    }

    // La factura puede venir de antes (cargada desde "Facturas" cuando el
    // gasto todavía estaba proyectado o confirmado) o subirse aquí mismo;
    // hace falta al menos una de las dos. Si ya hay y se sube otra, se suma.
    $facturasPrevias = facturasDeGasto(db(), $gasto);
    $archivosNuevos = archivosSubidos('factura');
    $rutasNuevas = [];
    if (!$errores) {
        if (!$facturasPrevias && !$archivosNuevos) {
            $errores[] = 'Debes cargar al menos una imagen de la factura para poder marcar el gasto como pagado.';
        } else {
            // Se pueden subir varias imágenes a la vez (páginas de una factura grande).
            foreach ($archivosNuevos as $archivo) {
                [$ruta, $errorSubida] = guardarFacturaSubida($archivo, $id);
                if ($errorSubida) {
                    $errores[] = ($archivo['name'] !== '' ? $archivo['name'] . ': ' : '') . $errorSubida;
                } else {
                    $rutasNuevas[] = $ruta;
                }
            }
            if ($errores) {
                // Si alguna falló no se marca como pagado: las que ya se
                // habían guardado en el servidor en este intento se descartan.
                foreach ($rutasNuevas as $rutaHuerfana) {
                    @unlink(dirname(__DIR__) . '/' . $rutaHuerfana);
                }
                $rutasNuevas = [];
            }
        }
    }

    if (!$errores) {
        db()->prepare("UPDATE gastos SET estado='pagado', monto_pagado=?, fecha_pago=? WHERE id=?")
            ->execute([$montoPagado, $fechaPago, $id]);
        foreach ($rutasNuevas as $rutaNueva) {
            if (tablaGastosFacturasExiste(db())) {
                agregarFacturaGasto(db(), $id, $rutaNueva, (int) $usuarioActual['id'], $usuarioActual['nombre']);
            } else {
                // Aún sin setup.php: se guarda como siempre en gastos.factura
                // (una sola, la última).
                db()->prepare('UPDATE gastos SET factura = ? WHERE id = ?')->execute([$rutaNueva, $id]);
            }
        }
        flash('Gasto marcado como pagado.');
        redirect('detalle.php?id=' . $practicaId . '&tab=gastos');
    }
}

$pageTitle = 'Marcar gasto como pagado';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $practicaId . '&tab=gastos">' . e($practica['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Marcar como pagado</b>';
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

    <?php $facturasPrevias = $facturasPrevias ?? facturasDeGasto(db(), $gasto); ?>
    <div class="field">
      <label>Facturas ya cargadas (<?= count($facturasPrevias) ?>)</label>
      <?php if ($facturasPrevias): ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
          <?php foreach ($facturasPrevias as $f): ?>
            <a href="<?= e($base . '/' . $f['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e($base . '/' . $f['ruta']) ?>" alt="Factura" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"></a>
          <?php endforeach; ?>
        </div>
        <div class="hint" style="margin-bottom:10px;">Estas facturas ya cuentan para marcar el gasto como pagado. Puedes <a href="gasto_facturas.php?id=<?= $id ?>">agregar o quitar facturas aquí</a> antes de continuar.</div>
      <?php else: ?>
        <p class="cell-muted" style="margin-top:0;">Todavía no hay ninguna factura cargada para este gasto.</p>
      <?php endif; ?>
      <label for="factura"><?= $facturasPrevias ? 'Agregar más facturas (opcional)' : 'Factura (foto)' ?></label>
      <input type="file" id="factura" name="factura[]" accept="image/jpeg,image/png,image/webp" multiple <?= $facturasPrevias ? '' : 'required' ?>>
      <div class="hint"><?= $facturasPrevias ? 'Opcional: ya hay al menos una factura.' : 'Obligatoria si todavía no hay ninguna.' ?> Puedes elegir varias imágenes a la vez (páginas de una misma factura). JPG, PNG o WEBP, hasta 5 MB cada una.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $practicaId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Marcar como pagado</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
