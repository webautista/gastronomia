<?php
/**
 * Listado de padres/tutores (entidad propia desde esta ronda — antes era
 * un campo de texto suelto en Estudiantes, ver migrarPadresDesdeTextoLibre()
 * en setup.php). Desde aquí se administra el CRUD y se vincula/desvincula
 * a los estudiantes de cada padre (ver detalle.php).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'padres', 'ver', $base);
$puedeEditar = can($usuarioActual, 'padres', 'editar');
$puedeCrear = can($usuarioActual, 'padres', 'crear');
$puedeEliminar = can($usuarioActual, 'padres', 'eliminar');

// Eliminar padre (POST) — bloqueado si tiene estudiantes vinculados o una
// cuenta de acceso ya creada, para no dejar datos huérfanos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'padres', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        $stmt = db()->prepare('SELECT usuario_id, (SELECT COUNT(*) FROM padre_estudiante WHERE padre_id = padres.id) AS num_estudiantes FROM padres WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        if (!$fila) {
            flash('Ese padre/tutor ya no existe.', 'error');
        } elseif ((int) $fila['num_estudiantes'] > 0) {
            flash('No se puede eliminar: todavía tiene estudiantes vinculados. Quítalos primero desde su ficha.', 'error');
        } elseif ($fila['usuario_id']) {
            flash('No se puede eliminar: ya tiene una cuenta de acceso creada. Desactívala primero desde Usuarios y roles.', 'error');
        } else {
            db()->prepare('DELETE FROM padres WHERE id = ?')->execute([$id]);
            flash('Padre/tutor eliminado.');
        }
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = "SELECT p.*,
               (SELECT COUNT(*) FROM padre_estudiante pe WHERE pe.padre_id = p.id) AS num_estudiantes
        FROM padres p";
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE p.nombre LIKE ? OR p.telefono LIKE ? OR p.email LIKE ?';
    $like = '%' . $busqueda . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY p.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$padres = $stmt->fetchAll();

$pageTitle = 'Padres';
$activeNav = 'padres';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Padres</h1>
    <p>Padres/tutores y los estudiantes que tienen vinculados.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo padre/tutor</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar por nombre, teléfono o email..." value="<?= e($busqueda) ?>">
  </form>
</div>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>Nombre</th><th>Teléfono</th><th>Email</th><th>Estudiantes</th><th>Acceso</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$padres): ?>
        <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Sin resultados.</td></tr>
      <?php endif; ?>
      <?php foreach ($padres as $pa): ?>
        <tr>
          <td class="cell-name"><a href="detalle.php?id=<?= (int) $pa['id'] ?>"><?= e($pa['nombre']) ?></a></td>
          <td class="cell-muted mono"><?= e($pa['telefono'] ?? '—') ?></td>
          <td class="cell-muted"><?= e($pa['email'] ?? '—') ?></td>
          <td class="cell-muted"><?= (int) $pa['num_estudiantes'] ?></td>
          <td>
            <?php if ($pa['usuario_id']): ?>
              <span class="chip chip-success"><?= icon('check') ?> Con acceso</span>
            <?php else: ?>
              <span class="chip chip-muted">Sin acceso</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="icon-btn" href="detalle.php?id=<?= (int) $pa['id'] ?>" title="Ver detalle"><?= icon('eye') ?></a>
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $pa['id'] ?>" title="Editar"><?= icon('edit') ?></a>
            <?php endif; ?>
            <?php if ($puedeEliminar): ?>
              <form method="post" action="index.php" data-confirm="¿Eliminar a &quot;<?= e($pa['nombre']) ?>&quot;?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $pa['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
