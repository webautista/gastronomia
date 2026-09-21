<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos_recetas', 'crear', $base);

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

// Categorías presentes entre las recetas todavía sin asignar, para el
// filtro de arriba — solo las que de verdad hay algo que filtrar (sección
// 29, pedido de Eyaelkys de poder filtrar y buscar en este selector).
$categoriasDisponibles = [];
foreach ($disponibles as $rc) {
    $categoriasDisponibles[(int) $rc['categoria_id']] = $rc['categoria'];
}
asort($categoriasDisponibles);

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
      <div data-role="filtro-recetas-wrap">
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
          <div class="search" style="flex:1;min-width:180px;">
            <?= icon('search') ?>
            <input type="text" data-role="filtro-recetas-buscar" placeholder="Buscar receta...">
          </div>
          <?php if (count($categoriasDisponibles) > 1): ?>
            <select data-role="filtro-recetas-categoria" style="max-width:220px;">
              <option value="">Todas las categorías</option>
              <?php foreach ($categoriasDisponibles as $catId => $catNombre): ?>
                <option value="<?= (int) $catId ?>"><?= e($catNombre) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="check-list">
          <?php foreach ($disponibles as $rc): ?>
            <label class="check-row" data-nombre="<?= e($rc['nombre']) ?>" data-categoria="<?= e($rc['categoria']) ?>" data-cat="<?= (int) $rc['categoria_id'] ?>">
              <input type="checkbox" name="receta_ids[]" value="<?= (int) $rc['id'] ?>">
              <span><span class="cname"><?= e($rc['nombre']) ?></span><br><span class="csub"><?= e($rc['categoria']) ?> · base <?= (int) $rc['porciones_base'] ?> porciones</span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="cell-muted" data-role="filtro-recetas-vacio" style="display:none;margin-top:10px;">Ninguna receta coincide con el filtro.</p>
      </div>
      <div class="form-actions">
        <a class="btn btn-secondary" href="detalle.php?id=<?= $id ?>&tab=recetas">Cancelar</a>
        <button class="btn btn-primary" type="submit">Agregar seleccionadas</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
