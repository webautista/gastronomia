<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos', 'editar', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
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
    'SELECT r.*, cr.nombre AS categoria
     FROM recetas r
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE r.id NOT IN (SELECT receta_id FROM evento_receta WHERE evento_id = ?)
     ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$disponibles = $stmt->fetchAll();

$pageTitle = 'Agregar recetas';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '">' . e($evento['nombre']) . '</a> &nbsp;/&nbsp; <b>Agregar recetas</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Agregar receta al evento</h1><p><?= e($evento['nombre']) ?> · al agregarla se usarán <?= (int) $evento['porciones'] ?> porciones por defecto (puedes ajustarlo después).</p></div></div>

<div class="card card-pad form-card" style="max-width:560px;">
  <?php if (!$disponibles): ?>
    <p class="cell-muted">Todas las recetas del catálogo ya están asignadas a este evento.</p>
    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=recetas">Volver</a>
      <a class="btn btn-primary" href="<?= e($base) ?>/recetas/form.php">Crear nueva receta</a>
    </div>
  <?php else: ?>
    <form method="post" action="detalle.php?id=<?= $id ?>&tab=recetas">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="accion" value="asignar_recetas">
      <div class="check-list">
        <?php foreach ($disponibles as $rc): ?>
          <label class="check-row">
            <input type="checkbox" name="receta_ids[]" value="<?= (int) $rc['id'] ?>">
            <span><span class="cname"><?= e($rc['nombre']) ?></span><br><span class="csub"><?= e($rc['categoria']) ?> · base <?= (int) $rc['porciones_base'] ?> porciones</span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-actions">
        <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=recetas">Cancelar</a>
        <button class="btn btn-primary" type="submit">Agregar seleccionadas</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
