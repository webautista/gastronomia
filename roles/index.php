<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'usuarios', 'ver', $base);
$puedeEditar = can($usuarioActual, 'usuarios', 'editar');
$puedeCrear = can($usuarioActual, 'usuarios', 'crear');
$puedeEliminar = can($usuarioActual, 'usuarios', 'eliminar');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'usuarios', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        $stmt = db()->prepare('SELECT es_sistema FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $rol = $stmt->fetch();
        if (!$rol) {
            flash('Ese rol ya no existe.', 'error');
        } elseif ((int) $rol['es_sistema'] === 1) {
            flash('El rol "Administrador" es parte del sistema y no se puede eliminar.', 'error');
        } else {
            $stmtUso = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id = ?');
            $stmtUso->execute([$id]);
            $enUso = (int) $stmtUso->fetchColumn();
            if ($enUso > 0) {
                flash("No se puede eliminar: $enUso usuario(s) tienen este rol.", 'error');
            } else {
                db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
                flash('Rol eliminado.');
            }
        }
    }
    redirect('index.php');
}

$roles = db()->query(
    'SELECT r.*, (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS num_usuarios
     FROM roles r ORDER BY r.es_sistema DESC, r.nombre ASC'
)->fetchAll();

$pageTitle = 'Roles';
$activeNav = 'usuarios';
$breadcrumb = '<a href="../usuarios/index.php">Usuarios y roles</a> &nbsp;/&nbsp; <b>Roles</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Roles y permisos</h1>
    <p>Define qué puede ver, crear, editar o eliminar cada rol, pantalla por pantalla.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo rol</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <a class="btn btn-secondary btn-sm" href="../usuarios/index.php">← Usuarios</a>
</div>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Rol</th><th>Descripción</th><th>Usuarios</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($roles as $rol): ?>
        <tr>
          <td class="cell-name"><?= e($rol['nombre']) ?> <?php if ($rol['es_sistema']): ?><span class="chip chip-neutral">Sistema</span><?php endif; ?></td>
          <td class="cell-muted"><?= e($rol['descripcion'] ?? '') ?></td>
          <td class="cell-muted"><?= (int) $rol['num_usuarios'] ?></td>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $rol['id'] ?>" title="Editar permisos"><?= icon('edit') ?></a>
            <?php endif; ?>
            <?php if ($puedeEliminar && !$rol['es_sistema']): ?>
              <form method="post" action="index.php" data-confirm="¿Eliminar el rol &quot;<?= e($rol['nombre']) ?>&quot;?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $rol['id'] ?>">
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
