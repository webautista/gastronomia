<?php
/**
 * Pagos parciales (abonos) de un gasto de una práctica — a pedido de Eyaelkys:
 * antes un gasto confirmado solo se podía pagar de una vez. Aquí se ve el
 * progreso (abonado / falta), se registran abonos con fecha y nota, y se
 * pueden eliminar mientras el gasto siga "confirmado". Cuando los abonos
 * completan el monto confirmado el gasto pasa solo a "pagado" (ver
 * registrarAbonoGasto() en includes/helpers.php y la tabla gastos_pagos en
 * db/schema.sql). La factura NO se exige en los abonos intermedios, solo en
 * el que completa el monto (igual que para marcar un gasto como pagado de
 * una vez, ver gasto_pagar.php): puede venir de antes (Facturas) o subirse aquí.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas_gastos', 'ver', $base);
$puedeEditar = can($usuarioActual, 'practicas_gastos', 'editar');

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

$practicaId = (int) $gasto['practica_id'];
$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$practicaId]);
$practica = $stmt->fetch();
$volver = 'detalle.php?id=' . $practicaId . '&tab=gastos';

if ($gasto['estado'] === 'proyectado') {
    flash('Primero confirma el gasto: los pagos parciales se registran sobre un gasto confirmado.', 'error');
    redirect($volver);
}

$tablaLista = tablaGastosPagosExiste(db());
$errores = [];
$montoForm = '';
$fechaForm = date('Y-m-d');
$notaForm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Ver el mismo bloque en gasto_pagar.php: la imagen superó el límite
    // total de subida del servidor y PHP descartó todo el POST.
    $errores[] = 'La imagen es demasiado pesada para este servidor. Tu navegador ya intenta reducirla automáticamente; si el problema sigue, prueba con otra foto o con una de menor resolución.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    requirePermission($usuarioActual, 'practicas_gastos', 'editar', $base);
    $accion = $_POST['accion'] ?? '';
    $usuarioId = (int) $usuarioActual['id'];
    $usuarioNombre = $usuarioActual['nombre'];
    $confirmado = (float) ($gasto['monto_confirmado'] ?? $gasto['monto']);
    $pagosPrevios = pagosDeGasto(db(), $id);
    $abonadoPrevio = array_sum(array_map(fn ($p) => (float) $p['monto'], $pagosPrevios));

    if (!$tablaLista) {
        $errores[] = 'Falta ejecutar setup.php una vez para activar los pagos parciales.';
    } elseif ($gasto['estado'] !== 'confirmado') {
        $errores[] = 'Este gasto ya está pagado: no se pueden registrar ni quitar pagos parciales. Si hay que corregir algo, usa Editar.';
    } elseif ($accion === 'abonar') {
        $montoForm = trim((string) ($_POST['monto'] ?? ''));
        $monto = round((float) $montoForm, 2);
        $fechaForm = $_POST['fecha'] ?? '';
        $notaForm = trim((string) ($_POST['nota'] ?? ''));
        if ($monto <= 0) {
            $errores[] = 'El monto del pago debe ser mayor a 0.';
        }
        if (!$fechaForm || !strtotime($fechaForm)) {
            $errores[] = 'La fecha del pago no es válida.';
        }
        if (mb_strlen($notaForm) > 255) {
            $errores[] = 'La nota es demasiado larga (máximo 255 caracteres).';
        }
        $completa = !$errores && ($abonadoPrevio + $monto) >= $confirmado - 0.005;
        $archivosNuevos = archivosSubidos('factura');
        $rutasNuevas = [];
        if (!$errores && $completa) {
            // Este pago completa el monto: hace falta al menos una factura
            // (la ya cargada antes, o la que se suba aquí mismo).
            if (!facturasDeGasto(db(), $gasto) && !$archivosNuevos) {
                $errores[] = 'Este pago completa el monto del gasto, así que hace falta al menos una imagen de la factura para dejarlo como pagado. Súbela aquí o desde "Facturas".';
            }
        }
        if (!$errores && $archivosNuevos) {
            foreach ($archivosNuevos as $archivo) {
                [$ruta, $errorSubida] = guardarFacturaSubida($archivo, $id);
                if ($errorSubida) {
                    $errores[] = ($archivo['name'] !== '' ? $archivo['name'] . ': ' : '') . $errorSubida;
                } else {
                    $rutasNuevas[] = $ruta;
                }
            }
            if ($errores) {
                foreach ($rutasNuevas as $rutaHuerfana) {
                    @unlink(dirname(__DIR__) . '/' . $rutaHuerfana);
                }
                $rutasNuevas = [];
            }
        }
        if (!$errores) {
            foreach ($rutasNuevas as $rutaNueva) {
                if (tablaGastosFacturasExiste(db())) {
                    agregarFacturaGasto(db(), $id, $rutaNueva, $usuarioId, $usuarioNombre);
                } else {
                    db()->prepare('UPDATE gastos SET factura = ? WHERE id = ?')->execute([$rutaNueva, $id]);
                }
            }
            $res = registrarAbonoGasto(db(), $gasto, $monto, $fechaForm, $notaForm, $usuarioId, $usuarioNombre);
            if ($res['completo']) {
                flash('Pago registrado. Con este pago se completó el monto: el gasto quedó como pagado (' . money($res['abonado']) . ').');
                redirect($volver);
            }
            flash('Pago parcial registrado. Falta ' . money($confirmado - $res['abonado']) . ' por pagar.');
            redirect('gasto_abonar.php?id=' . $id);
        }
    } elseif ($accion === 'quitar_abono') {
        $pagoId = intOrNull($_POST['pago_id'] ?? null);
        $pagoQuitar = null;
        foreach ($pagosPrevios as $p) {
            if ((int) $p['id'] === $pagoId) {
                $pagoQuitar = $p;
            }
        }
        if (!$pagoQuitar) {
            $errores[] = 'Ese pago ya no existe.';
        } else {
            db()->prepare('DELETE FROM gastos_pagos WHERE id = ? AND gasto_id = ?')->execute([$pagoId, $id]);
            registrarCambioGasto(db(), $id, 'pago_parcial_quitado', sprintf('%.2f', $pagoQuitar['monto']) . ' (' . $pagoQuitar['fecha'] . ')' . ($pagoQuitar['nota'] ? ' — ' . $pagoQuitar['nota'] : ''), null, $usuarioId, $usuarioNombre);
            flash('Pago parcial eliminado.');
            redirect('gasto_abonar.php?id=' . $id);
        }
    }
    // Se recarga el gasto por si cambió algo (factura principal, estado).
    $stmt = db()->prepare('SELECT g.*, cg.nombre AS categoria FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id WHERE g.id = ?');
    $stmt->execute([$id]);
    $gasto = $stmt->fetch();
}

$pagos = pagosDeGasto(db(), $id);
$abonado = array_sum(array_map(fn ($p) => (float) $p['monto'], $pagos));
$esPagado = $gasto['estado'] === 'pagado';
$confirmado = (float) ($gasto['monto_confirmado'] ?? $gasto['monto']);
$falta = max(0.0, $confirmado - $abonado);
$pctAbonado = $confirmado > 0 ? min(100, round($abonado / $confirmado * 100)) : 0;
$facturasCargadas = count(facturasDeGasto(db(), $gasto));
if ($montoForm === '') {
    $montoForm = $falta > 0 ? sprintf('%.2f', $falta) : '';
}

$pageTitle = 'Pagos parciales del gasto';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <a href="' . e($volver) . '">' . e($practica['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Pagos parciales</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Pagos parciales</h1><p><?= e($gasto['descripcion']) ?> · <?= e($gasto['categoria']) ?><?= $gasto['proveedor'] && $gasto['proveedor'] !== '—' ? ' · ' . e($gasto['proveedor']) : '' ?> · <span class="chip chip-muted"><?= e(etiquetaEstadoGasto($gasto['estado'])) ?></span></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>
<?php if (!$tablaLista): ?>
  <div class="alert alert-error">Para registrar pagos parciales hay que ejecutar setup.php una vez.</div>
<?php endif; ?>

<div class="summary-grid">
  <div class="stat-tile stat-tile--gold">
    <div class="stat-label">Monto confirmado</div>
    <div class="stat-value"><?= money($confirmado) ?></div>
    <div class="stat-hint">Lo que hay que pagar en total</div>
  </div>
  <div class="stat-tile stat-tile--sage">
    <div class="stat-label"><?= $esPagado ? 'Pagado' : 'Abonado' ?></div>
    <div class="stat-value"><?= money($esPagado ? (float) ($gasto['monto_pagado'] ?? $abonado) : $abonado) ?></div>
    <div class="stat-hint"><?= count($pagos) ?> pago<?= count($pagos) === 1 ? '' : 's' ?> parcial<?= count($pagos) === 1 ? '' : 'es' ?> registrado<?= count($pagos) === 1 ? '' : 's' ?></div>
  </div>
  <div class="stat-tile <?= $falta > 0.005 ? 'stat-tile--terracotta' : 'stat-tile--sage' ?>">
    <div class="stat-label">Falta por pagar</div>
    <div class="stat-value"><?= money($esPagado ? 0 : $falta) ?></div>
    <div class="stat-hint"><?= $esPagado ? 'Gasto pagado por completo' : $pctAbonado . '% pagado' ?></div>
  </div>
</div>

<div class="card card-pad form-card" style="margin-bottom:16px;">
  <div class="meter-row"><span>Progreso del pago</span><span class="mono"><?= money($abonado) ?> / <?= money($confirmado) ?></span></div>
  <div class="meter <?= $falta > 0.005 ? 'warn' : '' ?>"><span style="width:<?= (int) $pctAbonado ?>%"></span></div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;"><h2 class="section-title" style="margin:0;">Pagos registrados (<?= count($pagos) ?>)</h2></div>
  <?php if (!$pagos): ?>
    <p class="cell-muted" style="padding:12px 16px 16px;margin:0;">Todavía no hay pagos parciales registrados para este gasto.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Fecha</th><th>Nota</th><th>Registró</th><th style="white-space:nowrap;text-align:right;">Monto</th><?php if ($puedeEditar && !$esPagado): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($pagos as $p): ?>
          <tr>
            <td style="white-space:nowrap;"><?= fmtDate($p['fecha']) ?></td>
            <td class="cell-muted"><?= e($p['nota'] ?: '—') ?></td>
            <td class="cell-muted"><?= e($p['registrado_por_nombre']) ?></td>
            <td class="mono" style="white-space:nowrap;text-align:right;"><?= money($p['monto']) ?></td>
            <?php if ($puedeEditar && !$esPagado): ?>
            <td style="text-align:right;">
              <form method="post" data-confirm="¿Eliminar este pago parcial?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="quitar_abono">
                <input type="hidden" name="pago_id" value="<?= (int) $p['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar pago"><?= icon('trash') ?></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right;"><b>Total abonado</b></td><td class="mono" style="white-space:nowrap;text-align:right;"><b><?= money($abonado) ?></b></td><?php if ($puedeEditar && !$esPagado): ?><td></td><?php endif; ?></tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($puedeEditar && $tablaLista && !$esPagado): ?>
<div class="card card-pad form-card">
  <div class="section-title-row" style="margin-bottom:10px;">
    <div class="section-icon is-gold"><?= icon('plus') ?></div>
    <h2 class="section-title" style="margin:0;">Registrar un pago</h2>
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="abonar">
    <div class="field-row">
      <div class="field">
        <label for="monto">Monto del pago (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" step="0.01" required value="<?= e($montoForm) ?>">
        <div class="hint">Falta <b class="mono"><?= money($falta) ?></b>. Si este pago completa (o supera) el monto, el gasto queda como pagado.</div>
      </div>
      <div class="field">
        <label for="fecha">Fecha del pago</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($fechaForm) ?>">
      </div>
    </div>
    <div class="field">
      <label for="nota">Nota (opcional)</label>
      <input type="text" id="nota" name="nota" maxlength="255" placeholder="Ej. Adelanto en efectivo, transferencia, segundo abono…" value="<?= e($notaForm) ?>">
    </div>
    <div class="field">
      <label for="factura">Factura (fotos)<?= $facturasCargadas ? '' : ' — obligatoria solo si este pago completa el monto' ?></label>
      <input type="file" id="factura" name="factura[]" accept="image/jpeg,image/png,image/webp" multiple>
      <div class="hint"><?= $facturasCargadas ? 'Ya hay ' . $facturasCargadas . ' factura' . ($facturasCargadas === 1 ? '' : 's') . ' cargada' . ($facturasCargadas === 1 ? '' : 's') . '; puedes agregar más (opcional). ' : 'Los pagos intermedios no piden factura; el que complete el monto sí necesita al menos una. ' ?>Puedes elegir varias imágenes a la vez. JPG, PNG o WEBP, hasta 5 MB cada una. También puedes cargarlas desde <a href="gasto_facturas.php?id=<?= $id ?>">Facturas</a>.</div>
    </div>
    <div class="form-actions">
      <a class="btn btn-secondary" href="<?= e($volver) ?>">Volver a Gastos</a>
      <button class="btn btn-primary" type="submit">Registrar pago</button>
    </div>
  </form>
</div>
<?php else: ?>
<div class="form-actions" style="margin-top:16px;">
  <a class="btn btn-secondary" href="<?= e($volver) ?>">Volver a Gastos</a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
