<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'estudiantes', 'ver', $base);
$puedeEditar = can($usuarioActual, 'estudiantes', 'editar');
$puedeCrear = can($usuarioActual, 'estudiantes', 'crear');
$puedeEliminar = can($usuarioActual, 'estudiantes', 'eliminar');

// Eliminar estudiante (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'estudiantes', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM estudiantes WHERE id = ?')->execute([$id]);
        flash('Estudiante eliminado.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

$sql = 'SELECT e.*, ge.nombre AS grupo,
               (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.estudiante_id = e.id) AS num_eventos
        FROM estudiantes e
        LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE e.nombre LIKE ? OR ge.nombre LIKE ?';
    $like = '%' . $busqueda . '%';
    $params = [$like, $like];
}
$sql .= ' ORDER BY e.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();

$pageTitle = 'Estudiantes';
$activeNav = 'estudiantes';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Estudiantes</h1>
    <p>Lista maestra reutilizable en todos los eventos.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo estudiante</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar por nombre o grupo..." value="<?= e($busqueda) ?>">
  </form>
</div>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>Nombre</th><th>Grupo</th><th>Teléfono</th><th>Email</th><th>Eventos</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$estudiantes): ?>
        <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Sin resultados.</td></tr>
      <?php endif; ?>
      <?php foreach ($estudiantes as $st): ?>
        <tr>
          <td class="cell-name"><?= e($st['nombre']) ?></td>
          <td class="cell-muted"><?= e($st['grupo'] ?? '—') ?></td>
          <td class="cell-muted mono"><?= e($st['telefono']) ?></td>
          <td class="cell-muted"><?= e($st['email']) ?></td>
          <td class="cell-muted"><?= (int) $st['num_eventos'] ?></td>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $st['id'] ?>" title="Editar"><?= icon('edit') ?></a>
            <?php endif; ?>
            <?php if ($puedeEliminar): ?>
              <form method="post" action="index.php" data-confirm="¿Eliminar a &quot;<?= e($st['nombre']) ?>&quot; de la lista maestra? También se quitará de los eventos donde esté asignado.">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
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
