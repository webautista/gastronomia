<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$tabsValidos = ['resumen', 'estudiantes', 'recetas', 'gastos'];
$tab = in_array($_GET['tab'] ?? '', $tabsValidos, true) ? $_GET['tab'] : 'resumen';

function cargarEvento(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
    $stmt->execute([$id]);
    $ev = $stmt->fetch();
    return $ev ?: null;
}

$evento = cargarEvento($id);
if (!$evento) {
    flash('Ese evento ya no existe.', 'error');
    redirect('index.php');
}

/* ---------------- Acciones POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $pdo = db();

    if ($accion === 'toggle_pago') {
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if ($estudianteId) {
            $stmt = $pdo->prepare('SELECT pagado FROM evento_estudiante WHERE evento_id=? AND estudiante_id=?');
            $stmt->execute([$id, $estudianteId]);
            $actual = $stmt->fetchColumn();
            $nuevoPagado = $actual ? 0 : 1;
            $fechaPago = $nuevoPagado ? date('Y-m-d') : null;
            $stmt = $pdo->prepare('UPDATE evento_estudiante SET pagado=?, fecha_pago=? WHERE evento_id=? AND estudiante_id=?');
            $stmt->execute([$nuevoPagado, $fechaPago, $id, $estudianteId]);
        }
    } elseif ($accion === 'quitar_estudiante') {
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if ($estudianteId) {
            $pdo->prepare('DELETE FROM evento_estudiante WHERE evento_id=? AND estudiante_id=?')->execute([$id, $estudianteId]);
        }
    } elseif ($accion === 'asignar_estudiantes') {
        $ids = array_map('intval', $_POST['estudiante_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO evento_estudiante (evento_id, estudiante_id, pagado) VALUES (?,?,0)');
        foreach ($ids as $eid) {
            if ($eid > 0) {
                $stmt->execute([$id, $eid]);
            }
        }
    } elseif ($accion === 'quitar_receta') {
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        if ($recetaId) {
            $pdo->prepare('DELETE FROM evento_receta WHERE evento_id=? AND receta_id=?')->execute([$id, $recetaId]);
        }
    } elseif ($accion === 'asignar_recetas') {
        $ids = array_map('intval', $_POST['receta_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO evento_receta (evento_id, receta_id, porciones_necesarias) VALUES (?,?,?)');
        foreach ($ids as $rid) {
            if ($rid > 0) {
                $stmt->execute([$id, $rid, $evento['porciones']]);
            }
        }
    } elseif ($accion === 'actualizar_porciones') {
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        $porciones = intOrNull($_POST['porciones_necesarias'] ?? null);
        if ($recetaId && $porciones && $porciones > 0) {
            $pdo->prepare('UPDATE evento_receta SET porciones_necesarias=? WHERE evento_id=? AND receta_id=?')
                ->execute([$porciones, $id, $recetaId]);
        }
    } elseif ($accion === 'quitar_gasto') {
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        if ($gastoId) {
            $pdo->prepare('DELETE FROM gastos WHERE id=? AND evento_id=?')->execute([$gastoId, $id]);
        }
    }

    redirect('detalle.php?id=' . $id . '&tab=' . $tab);
}

/* ---------------- Datos para mostrar ---------------- */
$stmt = db()->prepare(
    'SELECT ee.pagado, ee.fecha_pago, es.* FROM evento_estudiante ee
     JOIN estudiantes es ON es.id = ee.estudiante_id
     WHERE ee.evento_id = ? ORDER BY es.nombre ASC'
);
$stmt->execute([$id]);
$estudiantesEvento = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT er.porciones_necesarias, r.* FROM evento_receta er
     JOIN recetas r ON r.id = er.receta_id
     WHERE er.evento_id = ? ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$recetasEvento = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM gastos WHERE evento_id = ? ORDER BY fecha DESC, id DESC');
$stmt->execute([$id]);
$gastosEvento = $stmt->fetchAll();

$gastado = array_sum(array_column($gastosEvento, 'monto'));
$pct = $evento['presupuesto'] > 0 ? round($gastado / $evento['presupuesto'] * 100) : 0;
$numPagados = count(array_filter($estudiantesEvento, fn($a) => (int) $a['pagado'] === 1));
$esperado = count($estudiantesEvento) * $evento['cuota'];
$recaudado = $numPagados * $evento['cuota'];
$pctPago = $esperado > 0 ? round($recaudado / $esperado * 100) : 0;

$pageTitle = $evento['nombre'];
$activeNav = 'eventos';
$base = '..';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <b>' . e($evento['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($evento['nombre']) ?></h1>
    <div class="event-meta" style="margin-top:6px;">
      <span><?= icon('calendar') ?> <?= fmtDate($evento['fecha']) ?></span>
      <span><?= icon('pin') ?> <?= e($evento['lugar']) ?></span>
      <span class="chip <?= chipEstadoClase($evento['estado']) ?>"><?= e($evento['estado']) ?></span>
    </div>
  </div>
  <a class="btn btn-secondary" href="form.php?id=<?= (int) $evento['id'] ?>"><?= icon('edit') ?> Editar</a>
</div>

<div class="summary-grid">
  <div class="card card-pad">
    <div class="stat-label">Presupuesto</div>
    <div class="meter-row" style="margin-top:8px;"><span class="mono"><?= money($gastado) ?> gastado</span><span><?= (int) $pct ?>%</span></div>
    <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
    <div class="stat-hint" style="margin-top:8px;">Presupuesto total: <b class="mono"><?= money($evento['presupuesto']) ?></b></div>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Cuota y recaudo</div>
    <div class="meter-row" style="margin-top:8px;"><span class="mono"><?= money($recaudado) ?> recaudado</span><span><?= (int) $pctPago ?>%</span></div>
    <div class="meter <?= meterClase($pctPago) ?>"><span style="width:<?= min($pctPago, 100) ?>%"></span></div>
    <div class="stat-hint" style="margin-top:8px;">Cuota: <b class="mono"><?= money($evento['cuota']) ?></b> · Esperado: <b class="mono"><?= money($esperado) ?></b></div>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Porciones a preparar</div>
    <div class="stat-value"><?= (int) $evento['porciones'] ?></div>
    <div class="stat-hint"><?= count($recetasEvento) ?> receta<?= count($recetasEvento) === 1 ? '' : 's' ?> asignada<?= count($recetasEvento) === 1 ? '' : 's' ?></div>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $tab === 'resumen' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=resumen">Resumen</a>
  <a class="tab <?= $tab === 'estudiantes' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Estudiantes y pagos</a>
  <a class="tab <?= $tab === 'recetas' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=recetas">Recetas e ingredientes</a>
  <a class="tab <?= $tab === 'gastos' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=gastos">Gastos</a>
</div>

<?php if ($tab === 'resumen'):
  $catTotales = [];
  foreach ($gastosEvento as $g) {
      $catTotales[$g['categoria']] = ($catTotales[$g['categoria']] ?? 0) + (float) $g['monto'];
  }
  $maxCat = max(1, ...(array_values($catTotales) ?: [1]));
  $pendientes = count($estudiantesEvento) - $numPagados;
  $maxPay = max(1, $numPagados, $pendientes);
?>
  <div class="summary-grid" style="grid-template-columns:1fr 1fr;">
    <div class="card card-pad">
      <h2 class="section-title">Gastos por categoría</h2>
      <?php if (!$catTotales): ?>
        <div class="cell-muted">Aún no hay gastos registrados.</div>
      <?php else: ?>
        <div class="bar-list">
          <?php foreach ($catTotales as $cat => $val): ?>
            <div class="bar-list-row">
              <span class="cell-muted"><?= e($cat) ?></span>
              <div class="bar-track"><div class="bar-fill" style="width:<?= round($val / $maxCat * 100) ?>%"></div></div>
              <span class="mono" style="text-align:right;"><?= money($val) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card card-pad">
      <h2 class="section-title">Estado de pagos</h2>
      <div class="bar-list">
        <div class="bar-list-row"><span class="cell-muted">Pagado</span><div class="bar-track"><div class="bar-fill alt" style="width:<?= round($numPagados / $maxPay * 100) ?>%"></div></div><span class="mono" style="text-align:right;"><?= $numPagados ?></span></div>
        <div class="bar-list-row"><span class="cell-muted">Pendiente</span><div class="bar-track"><div class="bar-fill" style="width:<?= round($pendientes / $maxPay * 100) ?>%"></div></div><span class="mono" style="text-align:right;"><?= $pendientes ?></span></div>
      </div>
      <div class="stat-hint" style="margin-top:14px;"><?= count($estudiantesEvento) ?> estudiante<?= count($estudiantesEvento) === 1 ? '' : 's' ?> asignado<?= count($estudiantesEvento) === 1 ? '' : 's' ?> a este evento.</div>
    </div>
  </div>

<?php elseif ($tab === 'estudiantes'): ?>
  <div class="toolbar">
    <div class="cell-muted"><?= $numPagados ?> de <?= count($estudiantesEvento) ?> estudiantes pagados · <span class="mono"><?= money($recaudado) ?></span> de <span class="mono"><?= money($esperado) ?></span> recaudado</div>
    <a class="btn btn-secondary btn-sm" href="asignar_estudiante.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar estudiante</a>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Grupo</th><th>Teléfono</th><th>Cuota</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php if (!$estudiantesEvento): ?>
          <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay estudiantes asignados a este evento.</td></tr>
        <?php endif; ?>
        <?php foreach ($estudiantesEvento as $a): ?>
          <tr>
            <td class="cell-name"><?= e($a['nombre']) ?></td>
            <td class="cell-muted"><?= e($a['grupo']) ?></td>
            <td class="cell-muted mono"><?= e($a['telefono']) ?></td>
            <td class="mono"><?= money($evento['cuota']) ?></td>
            <td>
              <?php if ($a['pagado']): ?>
                <span class="chip chip-success"><?= icon('check') ?> Pagado · <?= fmtDate($a['fecha_pago']) ?></span>
              <?php else: ?>
                <span class="chip chip-warning">Pendiente</span>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="toggle_pago">
                <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-sm <?= $a['pagado'] ? 'btn-secondary' : 'btn-primary' ?>" type="submit"><?= $a['pagado'] ? 'Marcar pendiente' : 'Marcar pagado' ?></button>
              </form>
              <form method="post" data-confirm="¿Quitar a &quot;<?= e($a['nombre']) ?>&quot; de este evento?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="quitar_estudiante">
                <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                <button class="icon-btn" type="submit" title="Quitar del evento"><?= icon('x') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'recetas'): ?>
  <div class="toolbar">
    <div class="cell-muted">Las cantidades se recalculan según las porciones que necesitas preparar.</div>
    <a class="btn btn-secondary btn-sm" href="asignar_receta.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar receta</a>
  </div>
  <?php if (!$recetasEvento): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
      <div>Agrega recetas del catálogo para calcular los ingredientes.</div>
    </div></div>
  <?php endif; ?>
  <?php foreach ($recetasEvento as $rc):
    $porcionesBase = max(1, (int) $rc['porciones_base']);
    $stmtIng = db()->prepare('SELECT * FROM ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC');
    $stmtIng->execute([$rc['id']]);
    $ingredientesReceta = $stmtIng->fetchAll();
    $factor = $rc['porciones_necesarias'] / $porcionesBase;
    $costoTotal = 0;
  ?>
    <div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
      <div class="recipe-card-head">
        <div>
          <h4><?= e($rc['nombre']) ?></h4>
          <div class="cell-muted"><?= e($rc['categoria']) ?> · base <?= $porcionesBase ?> porciones</div>
        </div>
        <form method="post" style="display:flex;align-items:center;gap:14px;">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="actualizar_porciones">
          <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
          <div class="portion-control">
            <span>Porciones a preparar</span>
            <input type="number" min="1" name="porciones_necesarias" value="<?= (int) $rc['porciones_necesarias'] ?>" data-role="porciones-input">
          </div>
          <button class="btn btn-secondary btn-sm" type="submit">Actualizar</button>
        </form>
        <form method="post" data-confirm="¿Quitar la receta &quot;<?= e($rc['nombre']) ?>&quot; de este evento?">
          <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="accion" value="quitar_receta">
          <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
          <button class="icon-btn" type="submit" title="Quitar receta"><?= icon('trash') ?></button>
        </form>
      </div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Ingrediente</th><th>Cantidad base</th><th>Cantidad necesaria</th><th>Costo est.</th></tr></thead>
        <tbody>
          <?php foreach ($ingredientesReceta as $ing):
            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
            $costo = $cantidad * (float) $ing['costo_unitario'];
            $costoTotal += $costo;
          ?>
            <tr>
              <td class="cell-name"><?= e($ing['nombre']) ?></td>
              <td class="cell-muted mono"><?= numFmt($ing['cantidad']) ?> <?= e($ing['unidad']) ?></td>
              <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>"><?= numFmt($cantidad) ?> <?= e($ing['unidad']) ?></td>
              <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= money($costo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3" style="text-align:right;font-weight:600;">Costo estimado de ingredientes</td><td class="mono" style="font-weight:600;" data-role="costo-total"><?= money($costoTotal) ?></td></tr>
        </tfoot>
      </table>
      </div>
      <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
        <details class="prep-details">
          <summary><?= icon('book') ?> Ver preparación</summary>
          <div class="prep-text"><?= nl2br(e($rc['preparacion'])) ?></div>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($tab === 'gastos'): ?>
  <div class="card card-pad" style="margin-bottom:16px;">
    <div class="meter-row"><span><?= money($gastado) ?> gastado de <?= money($evento['presupuesto']) ?></span><span><?= (int) $pct ?>%</span></div>
    <div class="meter <?= meterClase($pct) ?>"><span style="width:<?= min($pct, 100) ?>%"></span></div>
  </div>
  <div class="toolbar">
    <div class="cell-muted"><?= count($gastosEvento) ?> gasto<?= count($gastosEvento) === 1 ? '' : 's' ?> registrado<?= count($gastosEvento) === 1 ? '' : 's' ?></div>
    <a class="btn btn-secondary btn-sm" href="gasto_form.php?evento_id=<?= $id ?>"><?= icon('plus') ?> Registrar gasto</a>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>Monto</th><th></th></tr></thead>
      <tbody>
        <?php if (!$gastosEvento): ?>
          <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay gastos registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($gastosEvento as $g): ?>
          <tr>
            <td class="cell-muted"><?= fmtDate($g['fecha']) ?></td>
            <td><span class="chip chip-neutral"><?= e($g['categoria']) ?></span></td>
            <td><?= e($g['descripcion']) ?></td>
            <td class="cell-muted"><?= e($g['proveedor']) ?></td>
            <td class="mono"><?= money($g['monto']) ?></td>
            <td class="row-actions">
              <form method="post" data-confirm="¿Eliminar este gasto?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="quitar_gasto">
                <input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                <button class="icon-btn" type="submit"><?= icon('trash') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
