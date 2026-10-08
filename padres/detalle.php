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

// Generar (o reemplazar) el enlace de invitación para que este padre/tutor
// se cree su propia cuenta de acceso (ver crearInvitacion() en
// includes/helpers.php e invitacion.php en la raíz del sitio).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_invitacion') {
    requirePermission($usuarioActual, 'padres', 'editar', $base);
    csrfCheck();
    if ($padre['usuario_id']) {
        flash('Este padre/tutor ya tiene una cuenta de acceso.', 'error');
    } else {
        crearInvitacion(db(), 'padre', $id, $usuarioActual['id']);
        flash('Enlace de invitación generado. Cópialo y compártelo con el padre/tutor.');
    }
    redirect('detalle.php?id=' . $id);
}

// Generar el enlace para que este padre/tutor (que ya tiene cuenta) elija una
// contraseña nueva sin que nadie la vea — ver restablecer.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_restablecimiento') {
    requirePermission($usuarioActual, 'padres', 'editar', $base);
    csrfCheck();
    $stmt = db()->prepare('SELECT activo FROM usuarios WHERE id = ?');
    $stmt->execute([(int) $padre['usuario_id']]);
    $usuarioDePadre = $padre['usuario_id'] ? $stmt->fetch() : false;
    if (!$usuarioDePadre) {
        flash('Este padre/tutor todavía no tiene cuenta de acceso: genera primero una invitación.', 'error');
    } elseif (!$usuarioDePadre['activo']) {
        flash('Su usuario está inactivo: actívalo primero desde Usuarios y roles.', 'error');
    } else {
        try {
            crearRestablecimiento(db(), (int) $padre['usuario_id'], (int) $usuarioActual['id']);
            flash('Enlace de restablecimiento generado. Cópialo y compártelo con el padre/tutor.');
        } catch (PDOException $ex) {
            flash('No se pudo generar el enlace: falta ejecutar setup.php una vez para crear la tabla de restablecimientos.', 'error');
        }
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

// Estado de la cuenta con hora de PHP (igual que las listas): vigente / vencida / sin invitación.
$estadoAcceso = estadoAccesoPersonas(db(), 'padre', [$padre])[$id];
$invitacionActiva = $estadoAcceso['estado'] === 'vigente' ? $estadoAcceso['invitacion'] : null;
$restPend = $padre['usuario_id'] ? $estadoAcceso['restablecimiento'] : null;

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

    <?php if ($restPend && $restPend['vigente']): ?>
      <div class="field" style="margin-top:10px;max-width:520px;">
        <label>Enlace para restablecer la contraseña (vence <?= e(tiempoHasta($restPend['expira_en'])) ?> · el <?= fmtDate($restPend['expira_en']) ?>)</label>
        <input type="text" class="mono" readonly onclick="this.select()" value="<?= e(urlRestablecimiento($restPend['token'])) ?>">
        <small class="cell-muted">Cópialo y envíalo por WhatsApp, correo, etc. Es de un solo uso: la persona elige su contraseña nueva y nadie más la ve.</small>
      </div>
    <?php elseif ($restPend): ?>
      <p class="cell-muted" style="margin-top:8px;">El último enlace de restablecimiento (generado el <?= fmtDate($restPend['creado_en']) ?>) venció sin usarse.</p>
    <?php endif; ?>

    <?php if ($puedeEditar && $usuarioVinculado['activo']): ?>
      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="accion" value="generar_restablecimiento">
        <button class="btn btn-secondary" type="submit">
          <?= icon('lock') ?> <?= $restPend && $restPend['vigente'] ? 'Generar un enlace nuevo de restablecimiento' : 'Generar enlace para restablecer contraseña' ?>
        </button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="cell-muted">Todavía no tiene cuenta de acceso al sistema.</p>

    <?php if ($estadoAcceso['estado'] === 'vencida'): ?>
      <p style="margin-top:8px;">
        <span class="chip chip-danger">Invitación vencida</span>
        <span class="cell-muted"><?= e(detalleEstadoAcceso($estadoAcceso)) ?>. Genera un enlace nuevo.</span>
      </p>
    <?php elseif ($estadoAcceso['estado'] === 'vigente'): ?>
      <p style="margin-top:8px;">
        <span class="chip chip-warning"><?= icon('clock') ?> Invitación vigente</span>
        <span class="cell-muted"><?= e(detalleEstadoAcceso($estadoAcceso)) ?>. Si ya la contactaste, está a la espera de que la use.</span>
      </p>
    <?php endif; ?>

    <?php if ($invitacionActiva): ?>
      <div class="field" style="margin-top:10px;max-width:520px;">
        <label>Enlace de invitación (vence el <?= fmtDate($invitacionActiva['expira_en']) ?>)</label>
        <input type="text" class="mono" readonly onclick="this.select()" value="<?= e(urlInvitacion($invitacionActiva['token'])) ?>">
        <small class="cell-muted">Cópialo y envíalo por WhatsApp, correo, etc. Es de un solo uso.</small>
      </div>
    <?php endif; ?>

    <?php if ($puedeEditar): ?>
      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="accion" value="generar_invitacion">
        <button class="btn btn-secondary" type="submit">
          <?= icon('link') ?> <?= $invitacionActiva ? 'Generar un enlace nuevo' : 'Generar enlace de invitación' ?>
        </button>
      </form>
    <?php endif; ?>
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
