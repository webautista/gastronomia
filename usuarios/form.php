<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'usuarios', $id ? 'editar' : 'crear', $base);

function contarAdminsActivos(PDO $pdo, ?int $excluirId = null): int
{
    // Se identifica por es_sistema (no por el nombre) porque el nombre de un
    // rol normal se puede editar, pero el rol de sistema siempre es el mismo.
    $sql = "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
            WHERE r.es_sistema = 1 AND u.activo = 1";
    $params = [];
    if ($excluirId) {
        $sql .= ' AND u.id <> ?';
        $params[] = $excluirId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$roles = db()->query('SELECT * FROM roles ORDER BY nombre ASC')->fetchAll();
$esPropioUsuario = false;

$usuario = ['nombre' => '', 'usuario' => '', 'email' => '', 'rol_id' => $roles[0]['id'] ?? null, 'activo' => 1];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese usuario ya no existe.', 'error');
        redirect('index.php');
    }
    $usuario = $encontrado;
    $esPropioUsuario = (int) $id === (int) $usuarioActual['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $usuario['nombre']  = trim($_POST['nombre'] ?? '');
    $usuario['usuario'] = trim($_POST['usuario'] ?? '');
    $usuario['email']   = trim($_POST['email'] ?? '');
    $usuario['rol_id']  = intOrNull($_POST['rol_id'] ?? null);
    $usuario['activo']  = $esPropioUsuario ? 1 : (isset($_POST['activo']) ? 1 : 0);
    $passwordNueva = $_POST['password'] ?? '';

    if ($usuario['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($usuario['usuario'] === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario['usuario'])) {
        $errores[] = 'El usuario debe tener 3-50 caracteres (letras, números, punto, guion).';
    } else {
        $stmtDup = db()->prepare('SELECT id FROM usuarios WHERE usuario = ? AND id <> ?');
        $stmtDup->execute([$usuario['usuario'], $id ?? 0]);
        if ($stmtDup->fetch()) {
            $errores[] = 'Ya existe otro usuario con ese nombre de usuario.';
        }
    }
    if ($usuario['email'] !== '' && !filter_var($usuario['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no tiene un formato válido.';
    }
    if (!in_array($usuario['rol_id'], array_column($roles, 'id'), true)) {
        $errores[] = 'Rol no válido.';
    }
    if (!$id && trim($passwordNueva) === '') {
        $errores[] = 'La contraseña es obligatoria para un usuario nuevo.';
    }
    if (trim($passwordNueva) !== '' && strlen($passwordNueva) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }

    // Salvaguarda: no dejar el sistema sin ningún administrador activo.
    if (!$errores && $id) {
        $rolNuevoEsAdmin = false;
        foreach ($roles as $r) {
            if ((int) $r['id'] === (int) $usuario['rol_id'] && $r['es_sistema']) {
                $rolNuevoEsAdmin = true;
            }
        }
        $quedariaSinAdmin = (!$rolNuevoEsAdmin || !$usuario['activo']) && contarAdminsActivos(db(), $id) === 0;
        if ($quedariaSinAdmin) {
            $errores[] = 'Esta acción dejaría el sistema sin ningún administrador activo.';
        }
    }

    if (!$errores) {
        if ($id) {
            if (trim($passwordNueva) !== '') {
                $hash = password_hash($passwordNueva, PASSWORD_DEFAULT);
                $stmt = db()->prepare('UPDATE usuarios SET nombre=?, usuario=?, email=?, rol_id=?, activo=?, password_hash=? WHERE id=?');
                $stmt->execute([$usuario['nombre'], $usuario['usuario'], $usuario['email'] ?: null, $usuario['rol_id'], $usuario['activo'], $hash, $id]);
            } else {
                $stmt = db()->prepare('UPDATE usuarios SET nombre=?, usuario=?, email=?, rol_id=?, activo=? WHERE id=?');
                $stmt->execute([$usuario['nombre'], $usuario['usuario'], $usuario['email'] ?: null, $usuario['rol_id'], $usuario['activo'], $id]);
            }
            flash('Usuario actualizado.');
        } else {
            $hash = password_hash($passwordNueva, PASSWORD_DEFAULT);
            $stmt = db()->prepare('INSERT INTO usuarios (nombre, usuario, email, password_hash, rol_id, activo) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$usuario['nombre'], $usuario['usuario'], $usuario['email'] ?: null, $hash, $usuario['rol_id'], $usuario['activo']]);
            flash('Usuario creado.');
        }
        redirect('index.php');
    }
}

$pageTitle = $id ? 'Editar usuario' : 'Nuevo usuario';
$activeNav = 'usuarios';
$breadcrumb = '<a href="index.php">Usuarios y roles</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1><?php if ($esPropioUsuario): ?><p>Estás editando tu propia cuenta.</p><?php endif; ?></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre completo</label>
      <input type="text" id="nombre" name="nombre" required value="<?= e($usuario['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="usuario">Usuario (para iniciar sesión)</label>
        <input type="text" id="usuario" name="usuario" required value="<?= e($usuario['usuario']) ?>" autocomplete="off">
      </div>
      <div class="field">
        <label for="email">Email (opcional, no se usa para entrar)</label>
        <input type="email" id="email" name="email" value="<?= e($usuario['email'] ?? '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="rol_id">Rol</label>
        <select id="rol_id" name="rol_id" <?= $esPropioUsuario ? 'disabled' : '' ?>>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $usuario['rol_id'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($esPropioUsuario): ?>
          <input type="hidden" name="rol_id" value="<?= (int) $usuario['rol_id'] ?>">
          <small class="cell-muted">No puedes cambiar tu propio rol.</small>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="password"><?= $id ? 'Nueva contraseña (déjalo en blanco para no cambiarla)' : 'Contraseña' ?></label>
        <input type="password" id="password" name="password" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
      </div>
    </div>

    <?php if (!$esPropioUsuario): ?>
      <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin:14px 0;">
        <input type="checkbox" name="activo" value="1" <?= $usuario['activo'] ? 'checked' : '' ?>> Usuario activo (puede iniciar sesión)
      </label>
    <?php endif; ?>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear usuario' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
