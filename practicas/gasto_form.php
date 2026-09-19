<?php
/** Registrar un gasto de una práctica — mismo patrón que eventos/gasto_form.php. */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas_gastos', 'crear', $base);

$practicaId = intOrNull($_GET['practica_id'] ?? null);
if (!$practicaId) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$practicaId]);
$practica = $stmt->fetch();
if (!$practica) {
    flash('Esa práctica ya no existe.', 'error');
    redirect('index.php');
}

$categorias = db()->query('SELECT * FROM categorias_gasto WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$categoriaPorDefecto = $categorias[0]['id'] ?? null;
$gasto = ['categoria_id' => $categoriaPorDefecto, 'descripcion' => '', 'proveedor' => '', 'monto' => '', 'fecha' => date('Y-m-d'), 'estado' => 'proyectado', 'es_material_receta' => 0];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $gasto['categoria_id'] = intOrNull($_POST['categoria_id'] ?? null);
    $gasto['descripcion']  = trim($_POST['descripcion'] ?? '');
    $gasto['proveedor']    = trim($_POST['proveedor'] ?? '');
    $gasto['monto']        = (float) ($_POST['monto'] ?? 0);
    $gasto['fecha']        = $_POST['fecha'] ?? '';
    $gasto['estado']       = ($_POST['estado'] ?? '') === 'confirmado' ? 'confirmado' : 'proyectado';
    $gasto['es_material_receta'] = !empty($_POST['es_material_receta']) ? 1 : 0;

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
        $montoConfirmadoInicial = $gasto['estado'] === 'confirmado' ? $gasto['monto'] : null;
        $stmt = db()->prepare('INSERT INTO gastos (practica_id, categoria_id, descripcion, proveedor, monto, monto_confirmado, fecha, estado, es_material_receta) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$practicaId, $gasto['categoria_id'], $gasto['descripcion'], $gasto['proveedor'] ?: '—', $gasto['monto'], $montoConfirmadoInicial, $gasto['fecha'], $gasto['estado'], $gasto['es_material_receta']]);
        flash($gasto['estado'] === 'confirmado' ? 'Gasto registrado.' : 'Partida proyectada agregada.');
        redirect('detalle.php?id=' . $practicaId . '&tab=gastos');
    }
}

$pageTitle = 'Registrar gasto';
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $practicaId . '">' . e($practica['nombre']) . '</a> &nbsp;/&nbsp; <b>Registrar gasto</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Registrar gasto</h1><p><?= e($practica['nombre']) ?></p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="estado">¿En qué etapa está?</label>
      <select id="estado" name="estado">
        <option value="proyectado" <?= $gasto['estado'] === 'proyectado' ? 'selected' : '' ?>>Proyectado — todavía no está confirmado, es lo que prevemos que costará</option>
        <option value="confirmado" <?= $gasto['estado'] === 'confirmado' ? 'selected' : '' ?>>Confirmado — ya sabemos que este es el monto que se va a gastar</option>
      </select>
      <div class="hint">Una partida proyectada se puede confirmar más adelante desde la lista de Gastos. Confirmar todavía no es lo mismo que pagar: para eso hace falta la fecha de pago y la factura, un paso aparte una vez esté confirmada.</div>
    </div>

    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
        <input type="checkbox" id="es_material_receta" name="es_material_receta" value="1" style="width:16px;height:16px;" <?= $gasto['es_material_receta'] ? 'checked' : '' ?>>
        Es para materiales de recetas
      </label>
      <div class="hint">Actívalo si este gasto es parte de lo que ya se calculó como costo de materiales de las recetas de la práctica — así no se suma aparte, sino que se compara contra esa proyección.</div>
    </div>

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
      <input type="text" id="descripcion" name="descripcion" required placeholder="Ej. Ingredientes de la práctica" value="<?= e($gasto['descripcion']) ?>">
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
      <a class="btn btn-secondary" href="detalle.php?id=<?= $practicaId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
