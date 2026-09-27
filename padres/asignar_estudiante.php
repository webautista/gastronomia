<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'padres', 'editar', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM padres WHERE id = ?');
$stmt->execute([$id]);
$padre = $stmt->fetch();
if (!$padre) {
    flash('Ese padre/tutor ya no existe.', 'error');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ids = array_map('intval', $_POST['estudiante_ids'] ?? []);
    if ($ids) {
        $stmt = db()->prepare('INSERT IGNORE INTO padre_estudiante (padre_id, estudiante_id) VALUES (?, ?)');
        foreach ($ids as $estudianteId) {
            $stmt->execute([$id, $estudianteId]);
        }
        flash(count($ids) . ' estudiante(s) vinculado(s).');
    }
    redirect('detalle.php?id=' . $id);
}

$stmt = db()->prepare(
    'SELECT e.*, ge.nombre AS grupo
     FROM estudiantes e
     LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id
     WHERE e.id NOT IN (SELECT estudiante_id FROM padre_estudiante WHERE padre_id = ?)
     ORDER BY e.nombre ASC'
);
$stmt->execute([$id]);
$disponibles = $stmt->fetchAll();

$pageTitle = 'Vincular estudiantes';
$activeNav = 'padres';
$breadcrumb = '<a href="index.php">Padres</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '">' . e($padre['nombre']) . '</a> &nbsp;/&nbsp; <b>Vincular estudiantes</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Vincular estudiantes</h1><p><?= e($padre['nombre']) ?></p></div></div>

<div class="card card-pad form-card" style="max-width:560px;">
  <?php if (!$disponibles): ?>
    <p class="cell-muted">Todos los estudiantes de la lista maestra ya están vinculados a este padre/tutor.</p>
    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>">Volver</a>
      <a class="btn btn-primary" href="<?= e($base) ?>/estudiantes/form.php">Crear nuevo estudiante</a>
    </div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <div class="check-list">
        <?php foreach ($disponibles as $st): ?>
          <label class="check-row">
            <input type="checkbox" name="estudiante_ids[]" value="<?= (int) $st['id'] ?>">
            <span><span class="cname"><?= e($st['nombre']) ?></span><br><span class="csub"><?= e($st['grupo'] ?? '—') ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-actions">
        <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>">Cancelar</a>
        <button class="btn btn-primary" type="submit">Vincular seleccionados</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
