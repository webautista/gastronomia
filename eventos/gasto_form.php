<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'gastos', 'crear', $base);

$eventoId = intOrNull($_GET['evento_id'] ?? null);
if (!$eventoId) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$eventoId]);
$evento = $stmt->fetch();
if (!$evento) {
    flash('Ese evento ya no existe.', 'error');
    redirect('index.php');
}

$categorias = db()->query('SELECT * FROM categorias_gasto WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$categoriaPorDefecto = $categorias[0]['id'] ?? null;
$gasto = ['categoria_id' => $categoriaPorDefecto, 'descripcion' => '', 'proveedor' => '', 'monto' => '', 'fecha' => date('Y-m-d')];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $gasto['categoria_id'] = intOrNull($_POST['categoria_id'] ?? null);
    $gasto['descripcion']  = trim($_POST['descripcion'] ?? '');
    $gasto['proveedor']    = trim($_POST['proveedor'] ?? '');
    $gasto['monto']        = (float) ($_POST['monto'] ?? 0);
    $gasto['fecha']        = $_POST['fecha'] ?? '';

    if (!in_array($gasto['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }
    if ($gasto['descripcion'] === '') {
        $errores[] = 'La descripción es obligatoria.';
    }
    if ($gasto['monto'] <= 0) {
        $errores[] = 'El monto debe ser mayor a 0.';
    }
    if (!$gasto['fecha'] || !strtotime($gasto['fecha'])) {
        $errores[] = 'La fecha no es válida.';
    }

    if (!$errores) {
        $stmt = db()->prepare('INSERT INTO gastos (evento_id, categoria_id, descripcion, proveedor, monto, fecha) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$eventoId, $gasto['categoria_id'], $gasto['descripcion'], $gasto['proveedor'] ?: '—', $gasto['monto'], $gasto['fecha']]);
        flash('Gasto registrado.');
        redirect('detalle.php?id=' . $eventoId . '&tab=gastos');
    }
}

$pageTitle = 'Registrar gasto';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $eventoId . '">' . e($evento['nombre']) . '</a> &nbsp;/&nbsp; <b>Registrar gasto</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Registrar gasto</h1><p><?= e($evento['nombre']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field-row">
      <div class="field">
        <label for="categoria_id">Categoría</label>
        <select id="categoria_id" name="categoria_id">
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === (int) $gasto['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($gasto['fecha']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="descripcion">Descripción</label>
      <input type="text" id="descripcion" name="descripcion" required placeholder="Ej. Compra de ingredientes" value="<?= e($gasto['descripcion']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="proveedor">Proveedor</label>
        <input type="text" id="proveedor" name="proveedor" placeholder="Ej. Mercado La Sirena" value="<?= e($gasto['proveedor']) ?>">
      </div>
      <div class="field">
        <label for="monto">Monto (RD$)</label>
        <input type="number" id="monto" name="monto" min="0" step="0.01" required value="<?= e((string) $gasto['monto']) ?>">
      </div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $eventoId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Registrar gasto</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
