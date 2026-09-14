<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

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
    'SELECT * FROM estudiantes
     WHERE id NOT IN (SELECT estudiante_id FROM evento_estudiante WHERE evento_id = ?)
     ORDER BY nombre ASC'
);
$stmt->execute([$id]);
$disponibles = $stmt->fetchAll();

$pageTitle = 'Agregar estudiantes';
$activeNav = 'eventos';
$base = '..';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $id . '">' . e($evento['nombre']) . '</a> &nbsp;/&nbsp; <b>Agregar estudiantes</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Agregar estudiantes al evento</h1><p><?= e($evento['nombre']) ?></p></div></div>

<div class="card card-pad form-card" style="max-width:560px;">
  <?php if (!$disponibles): ?>
    <p class="cell-muted">Todos los estudiantes de la lista maestra ya están asignados a este evento.</p>
    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Volver</a>
      <a class="btn btn-primary" href="<?= e($base) ?>/estudiantes/form.php">Crear nuevo estudiante</a>
    </div>
  <?php else: ?>
    <form method="post" action="detalle.php?id=<?= $id ?>&tab=estudiantes">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="accion" value="asignar_estudiantes">
      <div class="check-list">
        <?php foreach ($disponibles as $st): ?>
          <label class="check-row">
            <input type="checkbox" name="estudiante_ids[]" value="<?= (int) $st['id'] ?>">
            <span><span class="cname"><?= e($st['nombre']) ?></span><br><span class="csub"><?= e($st['grupo']) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-actions">
        <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Cancelar</a>
        <button class="btn btn-primary" type="submit">Agregar seleccionados</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
