<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$id = intOrNull($_GET['id'] ?? null);
$estudiante = ['nombre' => '', 'telefono' => '', 'email' => '', 'grupo' => ''];
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $estudiante['nombre']   = trim($_POST['nombre'] ?? '');
    $estudiante['telefono'] = trim($_POST['telefono'] ?? '');
    $estudiante['email']    = trim($_POST['email'] ?? '');
    $estudiante['grupo']    = trim($_POST['grupo'] ?? '');

    if ($estudiante['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($estudiante['email'] !== '' && !filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no tiene un formato válido.';
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare('UPDATE estudiantes SET nombre=?, telefono=?, email=?, grupo=? WHERE id=?');
            $stmt->execute([$estudiante['nombre'], $estudiante['telefono'], $estudiante['email'], $estudiante['grupo'], $id]);
            flash('Estudiante actualizado.');
        } else {
            $stmt = db()->prepare('INSERT INTO estudiantes (nombre, telefono, email, grupo) VALUES (?,?,?,?)');
            $stmt->execute([$estudiante['nombre'], $estudiante['telefono'], $estudiante['email'], $estudiante['grupo']]);
            flash('Estudiante agregado.');
        }
        redirect('index.php');
    }
}

$pageTitle = $id ? 'Editar estudiante' : 'Nuevo estudiante';
$activeNav = 'estudiantes';
$base = '..';
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
        <label for="grupo">Grupo / clase</label>
        <input type="text" id="grupo" name="grupo" placeholder="Ej. Repostería Avanzada" value="<?= e($estudiante['grupo']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($estudiante['email']) ?>">
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Agregar estudiante' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
