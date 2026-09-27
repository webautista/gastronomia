<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'padres', $id ? 'editar' : 'crear', $base);

$padre = ['nombre' => '', 'telefono' => '', 'email' => ''];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM padres WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese padre/tutor ya no existe.', 'error');
        redirect('index.php');
    }
    $padre = $encontrado;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $padre['nombre']   = trim($_POST['nombre'] ?? '');
    $padre['telefono'] = trim($_POST['telefono'] ?? '');
    $padre['email']    = trim($_POST['email'] ?? '');

    if ($padre['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($padre['email'] !== '' && !filter_var($padre['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no tiene un formato válido.';
    }

    if (!$errores) {
        $telefono = $padre['telefono'] !== '' ? $padre['telefono'] : null;
        $email = $padre['email'] !== '' ? $padre['email'] : null;
        if ($id) {
            $stmt = db()->prepare('UPDATE padres SET nombre=?, telefono=?, email=? WHERE id=?');
            $stmt->execute([$padre['nombre'], $telefono, $email, $id]);
            flash('Padre/tutor actualizado.');
            redirect('detalle.php?id=' . $id);
        } else {
            $stmt = db()->prepare('INSERT INTO padres (nombre, telefono, email) VALUES (?,?,?)');
            $stmt->execute([$padre['nombre'], $telefono, $email]);
            $nuevoId = (int) db()->lastInsertId();
            flash('Padre/tutor agregado. Ahora puedes vincularle estudiantes.');
            redirect('detalle.php?id=' . $nuevoId);
        }
    }
}

$pageTitle = $id ? 'Editar padre/tutor' : 'Nuevo padre/tutor';
$activeNav = 'padres';
$breadcrumb = '<a href="index.php">Padres</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
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
      <input type="text" id="nombre" name="nombre" required value="<?= e($padre['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="telefono">Teléfono</label>
        <input type="text" id="telefono" name="telefono" placeholder="809-555-0100" value="<?= e($padre['telefono'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($padre['email'] ?? '') ?>">
      </div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="<?= $id ? 'detalle.php?id=' . $id : 'index.php' ?>">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Agregar padre/tutor' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
