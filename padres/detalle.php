<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'padres', 'ver', $base);
$puedeEditar = can($usuarioActual, 'padres', 'editar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM padres WHERE id = ?');
$stmt->execute([$id]);
$padre = $stmt->fetch();
if (!$padre) {
    flash('Ese padre/tutor ya no existe.', 'error');
    redirect('index.php');
}

// Desvincular un estudiante (POST) — solo quita el vínculo, nunca borra al
// estudiante ni al padre.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'desvincular') {
    requirePermission($usuarioActual, 'padres', 'editar', $base);
    csrfCheck();
    $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
    if ($estudianteId) {
        db()->prepare('DELETE FROM padre_estudiante WHERE padre_id = ? AND estudiante_id = ?')->execute([$id, $estudianteId]);
        flash('Estudiante desvinculado de este padre/tutor.');
    }
    redirect('detalle.php?id=' . $id);
}

$stmt = db()->prepare(
    'SELECT e.*, ge.nombre AS grupo
     FROM padre_estudiante pe
     JOIN estudiantes e ON e.id = pe.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id
     WHERE pe.padre_id = ?
     ORDER BY e.nombre ASC'
);
$stmt->execute([$id]);
$estudiantesVinculados = $stmt->fetchAll();

$usuarioVinculado = null;
if ($padre['usuario_id']) {
    $stmt = db()->prepare('SELECT nombre, usuario, activo FROM usuarios WHERE id = ?');
    $stmt->execute([$padre['usuario_id']]);
    $usuarioVinculado = $stmt->fetch() ?: null;
}

$pageTitle = $padre['nombre'];
$activeNav = 'padres';
$breadcrumb = '<a href="index.php">Padres</a> &nbsp;/&nbsp; <b>' . e($padre['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1><?= e($padre['nombre']) ?></h1>
    <p>
      <?= e($padre['telefono'] ?? '—') ?>
      <?php if ($padre['email']): ?> · <?= e($padre['email']) ?><?php endif; ?>
    </p>
  </div>
  <?php if ($puedeEditar): ?>
    <a class="btn btn-secondary" href="form.php?id=<?= (int) $padre['id'] ?>"><?= icon('edit') ?> Editar</a>
  <?php endif; ?>
</div>

<div class="card card-pad" style="margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Cuenta de acceso</h2>
  <?php if ($usuarioVinculado): ?>
    <p>
      <span class="chip chip-success"><?= icon('check') ?> Con acceso</span>
      &nbsp; Usuario: <b class="mono"><?= e($usuarioVinculado['usuario']) ?></b>
      <?php if (!$usuarioVinculado['activo']): ?> <span class="chip chip-muted">Inactivo</span><?php endif; ?>
    </p>
  <?php else: ?>
    <p class="cell-muted">
      Todavía no tiene cuenta de acceso al sistema.
      <?php // El botón de "Generar enlace de invitación" para auto-registro llega con el fondo (siguiente pieza de este trabajo). ?>
    </p>
  <?php endif; ?>
</div>

<div class="card card-pad">
  <div class="page-head" style="margin-bottom:12px;">
    <h2 class="section-title" style="margin:0;">Estudiantes vinculados</h2>
    <?php if ($puedeEditar): ?>
      <a class="btn btn-primary" href="asignar_estudiante.php?id=<?= (int) $padre['id'] ?>"><?= icon('plus') ?> Vincular estudiante</a>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>Nombre</th><th>Grupo</th><th>Teléfono</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php if (!$estudiantesVinculados): ?>
        <tr><td colspan="<?= $puedeEditar ? 4 : 3 ?>" class="cell-muted" style="text-align:center;padding:24px;">Todavía no hay estudiantes vinculados.</td></tr>
      <?php endif; ?>
      <?php foreach ($estudiantesVinculados as $est): ?>
        <tr>
          <td class="cell-name"><?= e($est['nombre']) ?></td>
          <td class="cell-muted"><?= e($est['grupo'] ?? '—') ?></td>
          <td class="cell-muted mono"><?= e($est['telefono'] ?? '—') ?></td>
          <?php if ($puedeEditar): ?>
            <td class="row-actions">
              <form method="post" data-confirm="¿Desvincular a &quot;<?= e($est['nombre']) ?>&quot; de este padre/tutor?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="desvincular">
                <input type="hidden" name="estudiante_id" value="<?= (int) $est['id'] ?>">
                <button class="icon-btn" type="submit" title="Desvincular"><?= icon('x') ?></button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="form-actions" style="margin-top:16px;">
  <a class="btn btn-secondary" href="index.php">Volver</a>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
