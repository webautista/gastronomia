<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$id = intOrNull($_GET['id'] ?? null);
$estados = ['Planificado', 'En curso', 'Finalizado'];
$evento = [
    'nombre' => '', 'fecha' => date('Y-m-d'), 'lugar' => '',
    'presupuesto' => '', 'cuota' => '', 'porciones' => '', 'estado' => 'Planificado',
];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese evento ya no existe.', 'error');
        redirect('index.php');
    }
    $evento = $encontrado;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $evento['nombre']      = trim($_POST['nombre'] ?? '');
    $evento['fecha']       = $_POST['fecha'] ?? '';
    $evento['lugar']       = trim($_POST['lugar'] ?? '');
    $evento['presupuesto'] = (float) ($_POST['presupuesto'] ?? 0);
    $evento['cuota']       = (float) ($_POST['cuota'] ?? 0);
    $evento['porciones']   = intOrNull($_POST['porciones'] ?? null) ?? 0;
    $evento['estado']      = $_POST['estado'] ?? 'Planificado';

    if ($evento['nombre'] === '') {
        $errores[] = 'El nombre del evento es obligatorio.';
    }
    if (!$evento['fecha'] || !strtotime($evento['fecha'])) {
        $errores[] = 'La fecha no es válida.';
    }
    if (!in_array($evento['estado'], $estados, true)) {
        $errores[] = 'Estado no válido.';
    }
    if ($evento['porciones'] < 1) {
        $errores[] = 'Las porciones a preparar deben ser mayores a 0.';
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare('UPDATE eventos SET nombre=?, fecha=?, lugar=?, presupuesto=?, cuota=?, porciones=?, estado=? WHERE id=?');
            $stmt->execute([$evento['nombre'], $evento['fecha'], $evento['lugar'], $evento['presupuesto'], $evento['cuota'], $evento['porciones'], $evento['estado'], $id]);
            flash('Evento actualizado.');
            redirect('detalle.php?id=' . $id);
        } else {
            $stmt = db()->prepare('INSERT INTO eventos (nombre, fecha, lugar, presupuesto, cuota, porciones, estado) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$evento['nombre'], $evento['fecha'], $evento['lugar'], $evento['presupuesto'], $evento['cuota'], $evento['porciones'], $evento['estado']]);
            $nuevoId = (int) db()->lastInsertId();
            flash('Evento creado.');
            redirect('detalle.php?id=' . $nuevoId);
        }
    }
}

$pageTitle = $id ? 'Editar evento' : 'Nuevo evento';
$activeNav = 'eventos';
$base = '..';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
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
      <label for="nombre">Nombre del evento</label>
      <input type="text" id="nombre" name="nombre" required placeholder="Ej. Buffet de fin de curso" value="<?= e($evento['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($evento['fecha']) ?>">
      </div>
      <div class="field">
        <label for="lugar">Lugar</label>
        <input type="text" id="lugar" name="lugar" placeholder="Ej. Salón principal" value="<?= e($evento['lugar']) ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="presupuesto">Presupuesto (RD$)</label>
        <input type="number" id="presupuesto" name="presupuesto" min="0" step="0.01" required value="<?= e((string) $evento['presupuesto']) ?>">
      </div>
      <div class="field">
        <label for="cuota">Cuota por estudiante (RD$)</label>
        <input type="number" id="cuota" name="cuota" min="0" step="0.01" required value="<?= e((string) $evento['cuota']) ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="porciones">Porciones a preparar</label>
        <input type="number" id="porciones" name="porciones" min="1" required value="<?= e((string) $evento['porciones']) ?>">
      </div>
      <div class="field">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
          <?php foreach ($estados as $es): ?>
            <option value="<?= e($es) ?>" <?= $es === $evento['estado'] ? 'selected' : '' ?>><?= e($es) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="<?= $id ? 'detalle.php?id=' . (int) $id : 'index.php' ?>">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear evento' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
