<?php
/**
 * Facturas de un gasto de una práctica: ver, agregar y quitar, en CUALQUIER etapa
 * (proyectado, confirmado o pagado) — a pedido de Eyaelkys, antes la única
 * factura se cargaba de golpe al marcar el gasto como pagado, y no había
 * dónde guardar una factura cuando ya se tenía pero el gasto todavía no se
 * pagaba. Un gasto puede tener varias (ver la tabla gastos_facturas en
 * db/schema.sql). Marcar el gasto como pagado exige que haya al menos una
 * (ver gasto_pagar.php), y uno ya pagado nunca se queda sin ninguna: la
 * última no se puede quitar. Quitar una factura no borra el archivo del
 * servidor, solo la quita de la lista y queda anotado en el historial del
 * gasto.
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

$errores = [];
$tablaLista = tablaGastosFacturasExiste(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Ver el mismo bloque en gasto_pagar.php: la foto superó el límite
    // total de subida del servidor y PHP descartó todo el POST.
    $errores[] = 'La imagen es demasiado pesada para este servidor. Tu navegador ya intenta reducirla automáticamente; si el problema sigue, prueba con otra foto o con una de menor resolución.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    requirePermission($usuarioActual, 'practicas_gastos', 'editar', $base);
    $accion = $_POST['accion'] ?? '';
    $usuarioId = (int) $usuarioActual['id'];
    $usuarioNombre = $usuarioActual['nombre'];

    if (!$tablaLista) {
        $errores[] = 'Falta ejecutar setup.php una vez para activar las facturas múltiples.';
    } elseif ($accion === 'agregar') {
        // Se pueden elegir varias imágenes a la vez (ej. las páginas de una
        // factura grande). Las que sí se pueden guardar quedan guardadas
        // aunque otra falle; los errores se avisan por archivo.
        $archivos = archivosSubidos('factura');
        if (!$archivos) {
            $errores[] = 'Elige al menos una imagen de factura para agregar.';
        } else {
            $agregadas = 0;
            foreach ($archivos as $archivo) {
                [$ruta, $errorSubida] = guardarFacturaSubida($archivo, $id);
                if ($errorSubida) {
                    $errores[] = ($archivo['name'] !== '' ? $archivo['name'] . ': ' : '') . $errorSubida;
                } else {
                    agregarFacturaGasto(db(), $id, $ruta, $usuarioId, $usuarioNombre);
                    $agregadas++;
                }
            }
            if ($agregadas > 0 && !$errores) {
                flash($agregadas === 1 ? 'Factura agregada.' : $agregadas . ' facturas agregadas.');
                redirect('gasto_facturas.php?id=' . $id);
            } elseif ($agregadas > 0) {
                flash($agregadas . ' factura(s) agregada(s); las demás tuvieron problemas (ver abajo).');
            }
        }
    } elseif ($accion === 'quitar') {
        $facturaId = intOrNull($_POST['factura_id'] ?? null);
        $restantes = count(facturasDeGasto(db(), $gasto));
        if (!$facturaId) {
            $errores[] = 'Factura no válida.';
        } elseif ($gasto['estado'] === 'pagado' && $restantes <= 1) {
            $errores[] = 'Un gasto ya pagado tiene que conservar al menos una factura. Agrega la nueva primero y luego quita la anterior.';
        } elseif (quitarFacturaGasto(db(), $id, $facturaId, $usuarioId, $usuarioNombre)) {
            flash('Factura quitada.');
            redirect('gasto_facturas.php?id=' . $id);
        } else {
            $errores[] = 'Esa factura ya no existe.';
        }
    }
    // Se recarga el gasto: puede haber cambiado gastos.factura.
    $stmt = db()->prepare('SELECT g.*, cg.nombre AS categoria FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id WHERE g.id = ?');
    $stmt->execute([$id]);
    $gasto = $stmt->fetch();
}

$facturas = facturasDeGasto(db(), $gasto);
$estadoLabel = etiquetaEstadoGasto($gasto['estado']);

$pageTitle = 'Facturas del gasto';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $practicaId . '&tab=gastos">' . e($practica['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Facturas</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Facturas del gasto</h1><p><?= e($gasto['descripcion']) ?> · <?= e($gasto['categoria']) ?> · <b class="mono"><?= money(montoEfectivoGasto($gasto)) ?></b> · <span class="chip chip-muted"><?= e($estadoLabel) ?></span></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>
<?php if (!$tablaLista): ?>
  <div class="alert alert-error">Para guardar varias facturas por gasto hay que ejecutar setup.php una vez. Mientras tanto solo se pueden ver las facturas que ya existían.</div>
<?php endif; ?>

<div class="card card-pad form-card">
  <div class="section-title-row" style="margin-bottom:10px;">
    <div class="section-icon is-gold"><?= icon('receipt') ?></div>
    <h2 class="section-title" style="margin:0;">Facturas cargadas (<?= count($facturas) ?>)</h2>
  </div>
  <?php if (!$facturas): ?>
    <p class="cell-muted" style="margin-top:0;">Este gasto todavía no tiene facturas<?= $gasto['estado'] === 'confirmado' ? ' — hace falta al menos una para poder marcarlo como pagado' : '' ?>.</p>
  <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:16px;">
      <?php foreach ($facturas as $f): ?>
        <div style="width:200px;">
          <a href="<?= e($base . '/' . $f['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e($base . '/' . $f['ruta']) ?>" alt="Factura" style="width:200px;height:200px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"></a>
          <div class="cell-muted" style="font-size:.76rem;margin:6px 0;">
            <?php if ($f['creado_en']): ?><?= fmtDate(substr($f['creado_en'], 0, 10)) ?><?php if ($f['subido_por']): ?> · <?= e($f['subido_por']) ?><?php endif; ?><?php else: ?>Factura original<?php endif; ?>
          </div>
          <?php if ($puedeEditar && $tablaLista && $f['id'] !== null && !($gasto['estado'] === 'pagado' && count($facturas) <= 1)): ?>
            <form method="post" data-confirm="¿Quitar esta factura del gasto?">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="accion" value="quitar">
              <input type="hidden" name="factura_id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-secondary btn-sm" type="submit"><?= icon('trash') ?> Quitar</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($puedeEditar && $tablaLista): ?>
<div class="card card-pad form-card" style="margin-top:16px;">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="agregar">
    <div class="field">
      <label for="factura">Agregar facturas (fotos)</label>
      <input type="file" id="factura" name="factura[]" accept="image/jpeg,image/png,image/webp" multiple required>
      <div class="hint">Puedes elegir varias imágenes a la vez (por ejemplo, las páginas de una factura grande). JPG, PNG o WEBP, hasta 5 MB cada una. No hace falta que el gasto esté pagado: las facturas quedan guardadas y cuentan cuando lo marques como pagado.</div>
    </div>
    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $practicaId ?>&tab=gastos">Volver a Gastos</a>
      <?php if ($gasto['estado'] === 'confirmado'): ?>
        <a class="btn btn-secondary" href="gasto_pagar.php?id=<?= $id ?>"><?= icon('receipt') ?> Marcar como pagado</a>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">Agregar</button>
    </div>
  </form>
</div>
<?php else: ?>
<div class="form-actions" style="margin-top:16px;">
  <a class="btn btn-secondary" href="detalle.php?id=<?= $practicaId ?>&tab=gastos">Volver a Gastos</a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
