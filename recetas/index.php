<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'recetas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'recetas', 'editar');
$puedeCrear = can($usuarioActual, 'recetas', 'crear');
$puedeEliminar = can($usuarioActual, 'recetas', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'recetas', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM recetas WHERE id = ?')->execute([$id]);
        flash('Receta eliminada.');
    }
    redirect('index.php');
}

// Duplicar: copia la receta completa (datos base + ingredientes, con su
// reemplazo/al gusto/opcional/acciones de preparación) como una receta
// nueva, para el caso de "la misma receta con pequeñas variaciones" — se
// abre directo en el formulario de edición para hacer esos ajustes. La
// foto NO se copia (es un archivo físico compartido: borrar una copia
// borraría el archivo de la otra), así que la copia empieza sin foto.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'duplicar') {
    requirePermission($usuarioActual, 'recetas', 'crear', $base);
    csrfCheck();
    $idOrig = intOrNull($_POST['id'] ?? null);
    $stmtOrig = db()->prepare('SELECT * FROM recetas WHERE id = ?');
    $stmtOrig->execute([$idOrig]);
    $original = $idOrig ? $stmtOrig->fetch() : null;
    if (!$original) {
        flash('Esa receta ya no existe.', 'error');
        redirect('index.php');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmtNueva = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion, foto) VALUES (?,?,?,?,?,NULL)');
        $stmtNueva->execute([$original['nombre'] . ' (copia)', $original['descripcion'], $original['categoria_id'], $original['porciones_base'], $original['preparacion']]);
        $nuevoId = (int) $pdo->lastInsertId();

        $stmtIngOrig = $pdo->prepare('SELECT * FROM ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC');
        $stmtIngOrig->execute([$idOrig]);
        $stmtInsIng = $pdo->prepare('INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, reemplazo, al_gusto, opcional, orden) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmtAccOrig = $pdo->prepare('SELECT accion_id FROM ingrediente_accion WHERE receta_ingrediente_id = ?');
        $stmtInsAcc = $pdo->prepare('INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES (?,?)');
        foreach ($stmtIngOrig->fetchAll() as $fila) {
            $stmtInsIng->execute([$nuevoId, $fila['ingrediente_id'], $fila['nombre'], $fila['cantidad'], $fila['unidad_id'], $fila['costo_unitario'], $fila['reemplazo'], $fila['al_gusto'], $fila['opcional'], $fila['orden']]);
            $nuevoIngId = (int) $pdo->lastInsertId();
            $stmtAccOrig->execute([$fila['id']]);
            foreach ($stmtAccOrig->fetchAll(PDO::FETCH_COLUMN) as $accId) {
                $stmtInsAcc->execute([$nuevoIngId, $accId]);
            }
        }
        $pdo->commit();
        flash('Receta duplicada como "' . $original['nombre'] . ' (copia)". Ajusta lo que necesites y guarda.');
        redirect('form.php?id=' . $nuevoId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('No se pudo duplicar la receta. Intenta de nuevo.', 'error');
        redirect('index.php');
    }
}

$busqueda = trim($_GET['q'] ?? '');
$categoriaFiltro = intOrNull($_GET['cat'] ?? null);

$sql = 'SELECT r.*, cr.nombre AS categoria,
               (SELECT COUNT(*) FROM ingredientes i WHERE i.receta_id = r.id) AS num_ingredientes,
               (SELECT COUNT(*) FROM evento_receta er WHERE er.receta_id = r.id) AS num_eventos
        FROM recetas r
        JOIN categorias_receta cr ON cr.id = r.categoria_id';
$where = [];
$params = [];
if ($busqueda !== '') {
    $where[] = 'r.nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
if ($categoriaFiltro) {
    $where[] = 'r.categoria_id = ?';
    $params[] = $categoriaFiltro;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY r.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$recetas = $stmt->fetchAll();

// Traer los nombres de ingredientes de un tirón para el resumen de cada
// tarjeta, y el costo de preparación en vivo de cada receta (misma función
// compartida que usan la vista de receta y el detalle de evento).
$ingredientesPorReceta = [];
if ($recetas) {
    $ids = array_column($recetas, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtIng = db()->prepare("SELECT receta_id, nombre FROM ingredientes WHERE receta_id IN ($in) ORDER BY orden ASC, id ASC");
    $stmtIng->execute($ids);
    foreach ($stmtIng->fetchAll() as $fila) {
        $ingredientesPorReceta[$fila['receta_id']][] = $fila['nombre'];
    }
}
foreach ($recetas as &$rc) {
    $rc['costo_preparacion'] = costoTotalReceta(db(), (int) $rc['id']);
}
unset($rc);

// Categorías de receta para el filtro de arriba, con el conteo de recetas
// de cada una (todas las recetas, sin importar la búsqueda de texto).
$categoriasReceta = db()->query('SELECT * FROM categorias_receta WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$conteoPorCategoriaReceta = [];
foreach (db()->query('SELECT categoria_id, COUNT(*) AS total FROM recetas GROUP BY categoria_id')->fetchAll() as $fila) {
    $conteoPorCategoriaReceta[(int) $fila['categoria_id']] = (int) $fila['total'];
}
$totalRecetas = (int) db()->query('SELECT COUNT(*) FROM recetas')->fetchColumn();

$pageTitle = 'Recetas';
$activeNav = 'recetas';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Recetas</h1>
    <p>Catálogo de recetas e ingredientes base por porción.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nueva receta</a>
  <?php endif; ?>
</div>

<div class="toolbar" style="flex-wrap:wrap;gap:8px;">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar receta..." value="<?= e($busqueda) ?>">
    <?php if ($categoriaFiltro): ?><input type="hidden" name="cat" value="<?= (int) $categoriaFiltro ?>"><?php endif; ?>
  </form>
</div>

<div class="card card-pad" style="margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Categorías</h2>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <a class="chip <?= !$categoriaFiltro ? 'chip-success' : 'chip-neutral' ?>" href="index.php<?= $busqueda !== '' ? '?q=' . urlencode($busqueda) : '' ?>" style="text-decoration:none;">Todas: <b><?= $totalRecetas ?></b></a>
    <?php foreach ($categoriasReceta as $catR): ?>
      <?php
        $n = $conteoPorCategoriaReceta[(int) $catR['id']] ?? 0;
        $paramsChip = ['cat' => (int) $catR['id']];
        if ($busqueda !== '') { $paramsChip['q'] = $busqueda; }
      ?>
      <a class="chip <?= (int) $catR['id'] === (int) $categoriaFiltro ? 'chip-success' : ($n === 0 ? 'chip-muted' : 'chip-neutral') ?>" href="index.php?<?= http_build_query($paramsChip) ?>" style="text-decoration:none;"><?= e($catR['nombre']) ?>: <b><?= $n ?></b></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$recetas): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
    <div>No hay recetas que coincidan con tu búsqueda.</div>
  </div></div>
<?php else: ?>
  <div class="event-grid">
    <?php foreach ($recetas as $rc): ?>
      <?php $nombresIng = $ingredientesPorReceta[$rc['id']] ?? []; ?>
      <div class="event-card">
        <?php if (!empty($rc['foto'])): ?>
          <a href="ver.php?id=<?= (int) $rc['id'] ?>">
            <img src="<?= e($base . '/' . $rc['foto']) ?>" alt="Foto de <?= e($rc['nombre']) ?>" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:var(--radius-md);margin-bottom:10px;">
          </a>
        <?php endif; ?>
        <div class="event-card-top">
          <div>
            <h3><?= e($rc['nombre']) ?></h3>
            <div class="cell-muted"><?= e($rc['categoria']) ?></div>
          </div>
          <div class="row-actions">
            <a class="icon-btn" href="ver.php?id=<?= (int) $rc['id'] ?>" title="Ver receta"><?= icon('eye') ?></a>
              <?php if ($puedeCrear): ?>
                <form method="post" action="index.php">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="duplicar">
                  <input type="hidden" name="id" value="<?= (int) $rc['id'] ?>">
                  <button class="icon-btn" type="submit" title="Duplicar receta (para crear una variación)"><?= icon('copy') ?></button>
                </form>
              <?php endif; ?>
              <?php if ($puedeEditar): ?>
                <a class="icon-btn" href="form.php?id=<?= (int) $rc['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="index.php" data-confirm="¿Eliminar la receta &quot;<?= e($rc['nombre']) ?>&quot;? También se quitará de los eventos que la usan.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $rc['id'] ?>">
                  <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
        </div>
        <div class="event-meta">
          <span><?= icon('portion') ?> Base: <?= (int) $rc['porciones_base'] ?> porciones</span>
          <span><?= (int) $rc['num_ingredientes'] ?> ingredientes</span>
        </div>
        <div class="mini-row"><span>Costo de preparación</span><span class="mono"><?= money($rc['costo_preparacion']) ?></span></div>
        <div style="font-size:.82rem;color:var(--text-secondary);margin:6px 0 8px;">
          <?= e(implode(', ', array_slice($nombresIng, 0, 4))) ?><?= count($nombresIng) > 4 ? '…' : '' ?>
        </div>
        <div class="mini-row"><span>Usada en</span><span><?= (int) $rc['num_eventos'] ?> evento<?= $rc['num_eventos'] == 1 ? '' : 's' ?></span></div>
        <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
          <details class="prep-details">
            <summary><?= icon('book') ?> Ver preparación</summary>
            <div class="prep-text"><?= nl2br(e($rc['preparacion'])) ?></div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
