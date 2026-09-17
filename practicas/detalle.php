<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'practicas', 'editar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$tabsValidos = ['recetas', 'compras'];
$tab = in_array($_GET['tab'] ?? '', $tabsValidos, true) ? $_GET['tab'] : 'recetas';

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$id]);
$practica = $stmt->fetch();
if (!$practica) {
    flash('Esa práctica ya no existe.', 'error');
    redirect('index.php');
}

/* ---------------- Acciones POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $pdo = db();

    if ($accion === 'quitar_receta') {
        requirePermission($usuarioActual, 'practicas', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        if ($recetaId) {
            $pdo->prepare('DELETE FROM practica_receta WHERE practica_id=? AND receta_id=?')->execute([$id, $recetaId]);
        }
    } elseif ($accion === 'asignar_recetas') {
        requirePermission($usuarioActual, 'practicas', 'editar', $base);
        $ids = array_map('intval', $_POST['receta_ids'] ?? []);
        $stmtPorciones = $pdo->prepare('SELECT porciones_base FROM recetas WHERE id = ?');
        $stmt = $pdo->prepare('INSERT IGNORE INTO practica_receta (practica_id, receta_id, porciones_necesarias) VALUES (?,?,?)');
        foreach ($ids as $rid) {
            if ($rid > 0) {
                $stmtPorciones->execute([$rid]);
                $porcionesBase = max(1, (int) $stmtPorciones->fetchColumn());
                $stmt->execute([$id, $rid, $porcionesBase]);
            }
        }
    } elseif ($accion === 'actualizar_porciones') {
        requirePermission($usuarioActual, 'practicas', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        $porciones = intOrNull($_POST['porciones_necesarias'] ?? null);
        if ($recetaId && $porciones && $porciones > 0) {
            $pdo->prepare('UPDATE practica_receta SET porciones_necesarias=? WHERE practica_id=? AND receta_id=?')
                ->execute([$porciones, $id, $recetaId]);
        }
    }

    redirect('detalle.php?id=' . $id . '&tab=' . $tab);
}

/* ---------------- Datos para mostrar ---------------- */
$stmt = db()->prepare(
    'SELECT pr.porciones_necesarias, r.*, cr.nombre AS categoria FROM practica_receta pr
     JOIN recetas r ON r.id = pr.receta_id
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE pr.practica_id = ? ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$recetasPractica = $stmt->fetchAll();

$costoMateriales = 0;
foreach ($recetasPractica as &$rc) {
    $porcionesBase = max(1, (int) $rc['porciones_base']);
    $stmtIng = db()->prepare(
        'SELECT i.*, um.abreviatura AS unidad, um.es_entera AS unidad_entera FROM ingredientes i
         JOIN unidades_medida um ON um.id = i.unidad_id
         WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
    );
    $stmtIng->execute([$rc['id']]);
    $rc['ingredientes'] = $stmtIng->fetchAll();
    $rc['costo_total'] = 0;
    foreach ($rc['ingredientes'] as $ing) {
        if (!empty($ing['al_gusto'])) {
            continue;
        }
        $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
        $esEntera = (bool) ($ing['unidad_entera'] ?? false);
        $rc['costo_total'] += montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
    }
    $costoMateriales += $rc['costo_total'];
}
unset($rc);

// Acciones/cortes marcados por línea (igual que en eventos/detalle.php).
$accionesPorFila = [];
$idsFilasTodas = [];
foreach ($recetasPractica as $rc) {
    foreach ($rc['ingredientes'] as $ing) {
        $idsFilasTodas[] = (int) $ing['id'];
    }
}
if ($idsFilasTodas) {
    $in = implode(',', array_fill(0, count($idsFilasTodas), '?'));
    $stmtAcc = db()->prepare(
        "SELECT ia.receta_ingrediente_id, ac.nombre FROM ingrediente_accion ia
         JOIN acciones_ingrediente ac ON ac.id = ia.accion_id
         WHERE ia.receta_ingrediente_id IN ($in) ORDER BY ac.orden ASC, ac.nombre ASC"
    );
    $stmtAcc->execute($idsFilasTodas);
    foreach ($stmtAcc->fetchAll() as $fa) {
        $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = $fa['nombre'];
    }
}

$consolidado = null;
if ($tab === 'compras') {
    $recetasParaLista = array_map(fn($rc) => ['receta_id' => $rc['id'], 'porciones_necesarias' => $rc['porciones_necesarias']], $recetasPractica);
    $consolidado = listaCompraConsolidada(db(), $recetasParaLista);
}

$pageTitle = $practica['nombre'];
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <b>' . e($practica['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($practica['nombre']) ?></h1>
    <div class="event-meta" style="margin-top:6px;">
      <span><?= icon('calendar') ?> <?= fmtDate($practica['fecha']) ?></span>
      <?php if ($practica['materia']): ?><span><?= icon('book') ?> <?= e($practica['materia']) ?></span><?php endif; ?>
      <?php if ($practica['maestro_responsable']): ?><span><?= icon('users') ?> <?= e($practica['maestro_responsable']) ?></span><?php endif; ?>
    </div>
  </div>
  <?php if ($puedeEditar): ?>
    <a class="btn btn-secondary" href="form.php?id=<?= (int) $practica['id'] ?>"><?= icon('edit') ?> Editar</a>
  <?php endif; ?>
</div>

<?php if (trim((string) ($practica['notas'] ?? '')) !== ''): ?>
  <div class="alert" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary);margin-bottom:18px;"><?= nl2br(e($practica['notas'])) ?></div>
<?php endif; ?>

<div class="summary-grid" style="grid-template-columns:1fr 1fr;">
  <div class="card card-pad">
    <div class="stat-label">Costo estimado de materiales</div>
    <div class="stat-value"><?= money($costoMateriales) ?></div>
    <div class="stat-hint">Según las recetas y porciones asignadas a esta práctica.</div>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Recetas asignadas</div>
    <div class="stat-value"><?= count($recetasPractica) ?></div>
    <div class="stat-hint">Ver la pestaña "Lista de Compra" para el consolidado de ingredientes.</div>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $tab === 'recetas' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=recetas">Recetas</a>
  <a class="tab <?= $tab === 'compras' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=compras"><?= icon('clipboardList') ?> Lista de Compra</a>
</div>

<?php if ($tab === 'recetas'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Las cantidades se recalculan según las porciones que necesitas preparar.</div>
    <div class="row-actions">
      <?php if (count($recetasPractica) > 1): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-role="toggle-todas-recetas" data-contenedor="lista-recetas-practica">Colapsar todo</button>
      <?php endif; ?>
      <?php if ($puedeEditar): ?>
        <a class="btn btn-secondary btn-sm" href="asignar_receta.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar receta</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$recetasPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
      <div>Agrega recetas del catálogo para calcular los ingredientes.</div>
    </div></div>
  <?php endif; ?>
  <div data-role="lista-recetas-practica">
  <?php foreach ($recetasPractica as $rc):
    $porcionesBase = max(1, (int) $rc['porciones_base']);
    $ingredientesReceta = $rc['ingredientes'];
    $costoTotal = $rc['costo_total'];
  ?>
    <div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
      <div class="recipe-card-head">
        <div style="display:flex;align-items:center;gap:8px;">
          <button class="icon-btn no-print" type="button" data-role="recipe-collapse-toggle" title="Colapsar/expandir"><?= icon('chevronDown') ?></button>
          <div>
            <h4><?= e($rc['nombre']) ?></h4>
            <div class="cell-muted"><?= e($rc['categoria']) ?> · base <?= $porcionesBase ?> porciones · <span class="mono" data-role="costo-total-badge"><?= money($costoTotal) ?></span></div>
          </div>
        </div>
        <?php if ($puedeEditar): ?>
          <form method="post" class="no-print" style="display:flex;align-items:center;gap:14px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="actualizar_porciones">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <div class="portion-control">
              <span>Porciones a preparar</span>
              <input type="number" min="1" name="porciones_necesarias" value="<?= (int) $rc['porciones_necesarias'] ?>" data-role="porciones-input">
            </div>
            <button class="btn btn-secondary btn-sm" type="submit">Actualizar</button>
          </form>
          <form method="post" class="no-print" data-confirm="¿Quitar la receta &quot;<?= e($rc['nombre']) ?>&quot; de esta práctica?">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="quitar_receta">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <button class="icon-btn" type="submit" title="Quitar receta"><?= icon('trash') ?></button>
          </form>
        <?php else: ?>
          <div class="stat-hint"><?= (int) $rc['porciones_necesarias'] ?> porciones a preparar</div>
        <?php endif; ?>
      </div>
      <div class="recipe-card-body">
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Ingrediente</th><th>Cantidad base</th><th>Cantidad necesaria</th><th>Costo est.</th></tr></thead>
        <tbody>
          <?php foreach ($ingredientesReceta as $ing):
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
                <?php if (!empty($accionesPorFila[$ing['id']])): ?><div class="cell-muted" style="font-size:.78rem;"><?= e(implode(', ', $accionesPorFila[$ing['id']])) ?></div><?php endif; ?>
              </td>
              <td class="cell-muted mono"><?= $esAlGusto ? 'Al gusto' : numFmt($ing['cantidad']) . ' ' . e($ing['unidad']) ?></td>
              <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>" data-entera="<?= $esEntera ? '1' : '0' ?>" data-al-gusto="<?= $esAlGusto ? '1' : '0' ?>"><?= $esAlGusto ? 'Al gusto' : numFmt($cantidad) . ' ' . e($ing['unidad']) ?></td>
              <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= $esAlGusto ? '—' : money($costo) ?></td>
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
    </div>
  <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'compras'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Ingredientes de todas las recetas de esta práctica, sumados y organizados para ir al súper.</div>
    <div class="row-actions">
      <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
      <a class="btn btn-secondary btn-sm" href="lista_compra_txt.php?id=<?= $id ?>"><?= icon('download') ?> Descargar (.txt)</a>
    </div>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Recetas</th><th>Costo est.</th></tr></thead>
      <tbody>
        <?php if (!$consolidado['lineas'] && !$consolidado['al_gusto']): ?>
          <tr><td colspan="4" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay recetas asignadas a esta práctica.</td></tr>
        <?php endif; ?>
        <?php foreach ($consolidado['lineas'] as $l): ?>
          <tr>
            <td class="cell-name"><?= e($l['nombre']) ?></td>
            <td class="mono"><?= numFmt($l['cantidad']) ?> <?= e($l['unidad']) ?></td>
            <td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $l['recetas'])) ?></td>
            <td class="mono"><?= money($l['monto']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($consolidado['al_gusto'] as $ag): ?>
          <tr>
            <td class="cell-name"><?= e($ag['nombre']) ?> <span class="chip chip-muted" style="font-size:.68rem;">Al gusto</span></td>
            <td class="cell-muted mono">—</td>
            <td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $ag['recetas'])) ?></td>
            <td class="cell-muted mono">—</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($consolidado['lineas']): ?>
      <tfoot>
        <tr><td colspan="3" style="text-align:right;font-weight:600;">Costo estimado total</td><td class="mono" style="font-weight:600;"><?= money($consolidado['total']) ?></td></tr>
      </tfoot>
      <?php endif; ?>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
