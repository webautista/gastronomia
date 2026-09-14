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

function contarAdminsActivos(PDO $pdo): int
{
    // Se identifica por es_sistema (no por el nombre) porque el nombre de un
    // rol normal se puede editar, pero el rol de sistema siempre es el mismo.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
         WHERE r.es_sistema = 1 AND u.activo = 1"
    );
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $id = intOrNull($_POST['id'] ?? null);

    if ($accion === 'eliminar') {
        requirePermission($usuarioActual, 'usuarios', 'eliminar', $base);
        if ($id === (int) $usuarioActual['id']) {
            flash('No puedes eliminar tu propio usuario.', 'error');
        } elseif ($id) {
            $stmt = db()->prepare('SELECT u.*, r.nombre AS rol_nombre, r.es_sistema FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u && $u['es_sistema'] && $u['activo'] && contarAdminsActivos(db()) <= 1) {
                flash('No puedes eliminar al último administrador activo.', 'error');
            } elseif ($u) {
                db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                flash('Usuario eliminado.');
            }
        }
    } elseif ($accion === 'toggle') {
        requirePermission($usuarioActual, 'usuarios', 'editar', $base);
        if ($id === (int) $usuarioActual['id']) {
            flash('No puedes desactivar tu propio usuario.', 'error');
        } elseif ($id) {
            $stmt = db()->prepare('SELECT u.*, r.nombre AS rol_nombre, r.es_sistema FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u && $u['activo'] && $u['es_sistema'] && contarAdminsActivos(db()) <= 1) {
                flash('No puedes desactivar al último administrador activo.', 'error');
            } elseif ($u) {
                db()->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ?')->execute([$id]);
                flash('Estado actualizado.');
            }
        }
    }
    redirect('index.php');
}

$usuarios = db()->query(
    'SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.nombre ASC'
)->fetchAll();

$pageTitle = 'Usuarios y roles';
$activeNav = 'usuarios';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Usuarios y roles</h1>
    <p>Cuentas de acceso al sistema y el rol que define qué pueden hacer.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo usuario</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <a class="btn btn-secondary btn-sm" href="../roles/index.php"><?= icon('shield') ?> Administrar roles</a>
</div>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td class="cell-name"><?= e($u['nombre']) ?><?= (int) $u['id'] === (int) $usuarioActual['id'] ? ' <span class="chip chip-neutral">Tú</span>' : '' ?></td>
          <td class="cell-muted mono"><?= e($u['usuario']) ?></td>
          <td class="cell-muted"><?= e($u['email'] ?? '—') ?></td>
          <td class="cell-muted"><?= e($u['rol_nombre']) ?></td>
          <td><span class="chip <?= $u['activo'] ? 'chip-success' : 'chip-muted' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $u['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php if ((int) $u['id'] !== (int) $usuarioActual['id']): ?>
                <form method="post" action="index.php" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn-secondary btn-sm" type="submit"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($puedeEliminar && (int) $u['id'] !== (int) $usuarioActual['id']): ?>
              <form method="post" action="index.php" style="display:inline;" data-confirm="¿Eliminar a &quot;<?= e($u['nombre']) ?>&quot;?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
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
