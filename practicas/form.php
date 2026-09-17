<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'practicas', $id ? 'editar' : 'crear', $base);

$practica = [
    'nombre' => '', 'fecha' => date('Y-m-d'), 'maestro_responsable' => '', 'materia' => '', 'notas' => '',
];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
    $stmt->execute([$id]);
    $encontrada = $stmt->fetch();
    if (!$encontrada) {
        flash('Esa práctica ya no existe.', 'error');
        redirect('index.php');
    }
    $practica = $encontrada;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $practica['nombre'] = trim($_POST['nombre'] ?? '');
    $practica['fecha'] = $_POST['fecha'] ?? '';
    $practica['maestro_responsable'] = trim($_POST['maestro_responsable'] ?? '');
    $practica['materia'] = trim($_POST['materia'] ?? '');
    $practica['notas'] = trim($_POST['notas'] ?? '');

    if ($practica['nombre'] === '') {
        $errores[] = 'El nombre de la práctica es obligatorio.';
    }
    if (!$practica['fecha'] || !strtotime($practica['fecha'])) {
        $errores[] = 'La fecha no es válida.';
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare('UPDATE practicas SET nombre=?, fecha=?, maestro_responsable=?, materia=?, notas=? WHERE id=?');
            $stmt->execute([$practica['nombre'], $practica['fecha'], $practica['maestro_responsable'] ?: null, $practica['materia'] ?: null, $practica['notas'] ?: null, $id]);
            flash('Práctica actualizada.');
            redirect('detalle.php?id=' . $id);
        } else {
            $stmt = db()->prepare('INSERT INTO practicas (nombre, fecha, maestro_responsable, materia, notas) VALUES (?,?,?,?,?)');
            $stmt->execute([$practica['nombre'], $practica['fecha'], $practica['maestro_responsable'] ?: null, $practica['materia'] ?: null, $practica['notas'] ?: null]);
            $nuevoId = (int) db()->lastInsertId();
            flash('Práctica creada.');
            redirect('detalle.php?id=' . $nuevoId);
        }
    }
}

$pageTitle = $id ? 'Editar práctica' : 'Nueva práctica';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre de la práctica</label>
      <input type="text" id="nombre" name="nombre" required placeholder="Ej. Práctica de repostería básica" value="<?= e($practica['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($practica['fecha']) ?>">
      </div>
      <div class="field">
        <label for="materia">Materia</label>
        <input type="text" id="materia" name="materia" placeholder="Ej. Repostería I" value="<?= e($practica['materia']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="maestro_responsable">Maestro responsable</label>
      <input type="text" id="maestro_responsable" name="maestro_responsable" placeholder="Ej. Chef Ana Ramírez" value="<?= e($practica['maestro_responsable']) ?>">
    </div>

    <div class="field">
      <label for="notas">Notas</label>
      <textarea id="notas" name="notas" rows="3" placeholder="Ej. Traer termómetro de cocina"><?= e($practica['notas']) ?></textarea>
      <div class="hint">Opcional. Observaciones libres sobre la práctica.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="<?= $id ? 'detalle.php?id=' . (int) $id : 'index.php' ?>">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear práctica' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
