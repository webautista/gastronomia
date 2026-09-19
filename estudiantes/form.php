<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'estudiantes', $id ? 'editar' : 'crear', $base);

$grupos = db()->query('SELECT * FROM grupos_estudiante WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();

$estudiante = ['nombre' => '', 'telefono' => '', 'email' => '', 'padre_tutor' => '', 'telefono_padre_tutor' => '', 'grupo_id' => null, 'grupo_nuevo' => ''];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM estudiantes WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese estudiante ya no existe.', 'error');
        redirect('index.php');
    }
    $estudiante = $encontrado;
    $estudiante['grupo_nuevo'] = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $estudiante['nombre']               = trim($_POST['nombre'] ?? '');
    $estudiante['telefono']             = trim($_POST['telefono'] ?? '');
    $estudiante['email']                = trim($_POST['email'] ?? '');
    $estudiante['padre_tutor']          = trim($_POST['padre_tutor'] ?? '');
    $estudiante['telefono_padre_tutor'] = trim($_POST['telefono_padre_tutor'] ?? '');
    $estudiante['grupo_id']    = intOrNull($_POST['grupo_id'] ?? null);
    $estudiante['grupo_nuevo'] = trim($_POST['grupo_nuevo'] ?? '');

    if ($estudiante['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($estudiante['email'] !== '' && !filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no tiene un formato válido.';
    }

    $grupoIdFinal = null;
    if ($estudiante['grupo_nuevo'] !== '') {
        // El grupo nuevo escrito a mano tiene prioridad sobre el seleccionado.
        $stmtIns = db()->prepare('INSERT IGNORE INTO grupos_estudiante (nombre) VALUES (?)');
        $stmtIns->execute([$estudiante['grupo_nuevo']]);
        $stmtSel = db()->prepare('SELECT id FROM grupos_estudiante WHERE nombre = ?');
        $stmtSel->execute([$estudiante['grupo_nuevo']]);
        $grupoIdFinal = (int) $stmtSel->fetchColumn();
    } elseif ($estudiante['grupo_id']) {
        if (!in_array($estudiante['grupo_id'], array_column($grupos, 'id'), true)) {
            $errores[] = 'Grupo no válido.';
        } else {
            $grupoIdFinal = $estudiante['grupo_id'];
        }
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare('UPDATE estudiantes SET nombre=?, telefono=?, email=?, padre_tutor=?, telefono_padre_tutor=?, grupo_id=? WHERE id=?');
            $stmt->execute([$estudiante['nombre'], $estudiante['telefono'], $estudiante['email'], $estudiante['padre_tutor'], $estudiante['telefono_padre_tutor'], $grupoIdFinal, $id]);
            flash('Estudiante actualizado.');
        } else {
            $stmt = db()->prepare('INSERT INTO estudiantes (nombre, telefono, email, padre_tutor, telefono_padre_tutor, grupo_id) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$estudiante['nombre'], $estudiante['telefono'], $estudiante['email'], $estudiante['padre_tutor'], $estudiante['telefono_padre_tutor'], $grupoIdFinal]);
            flash('Estudiante agregado.');
        }
        redirect('index.php');
    }
}

$pageTitle = $id ? 'Editar estudiante' : 'Nuevo estudiante';
$activeNav = 'estudiantes';
$breadcrumb = '<a href="index.php">Estudiantes</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div><h1><?= e($pageTitle) ?></h1></div>
</div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre completo</label>
      <input type="text" id="nombre" name="nombre" required value="<?= e($estudiante['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="telefono">Teléfono</label>
        <input type="text" id="telefono" name="telefono" placeholder="809-555-0100" value="<?= e($estudiante['telefono']) ?>">
      </div>
      <div class="field">
        <label for="grupo_id">Grupo / clase</label>
        <select id="grupo_id" name="grupo_id">
          <option value="">— Sin grupo —</option>
          <?php foreach ($grupos as $gr): ?>
            <option value="<?= (int) $gr['id'] ?>" <?= (int) $gr['id'] === (int) $estudiante['grupo_id'] ? 'selected' : '' ?>><?= e($gr['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="grupo_nuevo">O escribe un grupo nuevo</label>
      <input type="text" id="grupo_nuevo" name="grupo_nuevo" placeholder="Ej. Repostería Avanzada" value="<?= e($estudiante['grupo_nuevo']) ?>">
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($estudiante['email']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="padre_tutor">Padre, madre o tutor</label>
        <input type="text" id="padre_tutor" name="padre_tutor" placeholder="Nombre del responsable" value="<?= e($estudiante['padre_tutor']) ?>">
      </div>
      <div class="field">
        <label for="telefono_padre_tutor">Teléfono del padre/tutor</label>
        <input type="text" id="telefono_padre_tutor" name="telefono_padre_tutor" placeholder="809-555-0100" value="<?= e($estudiante['telefono_padre_tutor']) ?>">
      </div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Agregar estudiante' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
