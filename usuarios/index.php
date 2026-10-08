<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'usuarios', 'ver', $base);
$puedeEditar = can($usuarioActual, 'usuarios', 'editar');
$puedeCrear = can($usuarioActual, 'usuarios', 'crear');
$puedeEliminar = can($usuarioActual, 'usuarios', 'eliminar');

function contarAdminsActivos(PDO $pdo): int
{
    // Se identifica por es_sistema (no por el nombre) porque el nombre de un
    // rol normal se puede editar, pero el rol de sistema siempre es el mismo.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
         WHERE r.es_sistema = 1 AND u.activo = 1"
    );
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $id = intOrNull($_POST['id'] ?? null);

    if ($accion === 'eliminar') {
        requirePermission($usuarioActual, 'usuarios', 'eliminar', $base);
        if ($id === (int) $usuarioActual['id']) {
            flash('No puedes eliminar tu propio usuario.', 'error');
        } elseif ($id) {
            $stmt = db()->prepare('SELECT u.*, r.nombre AS rol_nombre, r.es_sistema FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u && $u['es_sistema'] && $u['activo'] && contarAdminsActivos(db()) <= 1) {
                flash('No puedes eliminar al último administrador activo.', 'error');
            } elseif ($u) {
                db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                flash('Usuario eliminado.');
            }
        }
    } elseif ($accion === 'generar_restablecimiento') {
        // Enlace de un solo uso para que la persona elija su contraseña nueva
        // sin que nadie la vea (restablecer.php). Para la propia contraseña
        // está "Mi cuenta" (cuenta.php).
        requirePermission($usuarioActual, 'usuarios', 'editar', $base);
        $stmt = db()->prepare('SELECT id, activo FROM usuarios WHERE id = ?');
        $stmt->execute([(int) $id]);
        $u = $stmt->fetch();
        if ($id === (int) $usuarioActual['id']) {
            flash('Para cambiar tu propia contraseña usa el candado de abajo a la izquierda (Mi cuenta).', 'error');
        } elseif (!$u) {
            flash('Ese usuario ya no existe.', 'error');
        } elseif (!$u['activo']) {
            flash('El usuario está inactivo: actívalo primero para poder restablecer su contraseña.', 'error');
        } else {
            try {
                crearRestablecimiento(db(), (int) $id, (int) $usuarioActual['id']);
                flash('Enlace de restablecimiento generado. Cópialo y envíaselo a la persona.');
                redirect('index.php?enlace=' . (int) $id);
            } catch (PDOException $ex) {
                flash('No se pudo generar el enlace: falta ejecutar setup.php una vez para crear la tabla de restablecimientos.', 'error');
            }
        }
    } elseif ($accion === 'toggle') {
        requirePermission($usuarioActual, 'usuarios', 'editar', $base);
        if ($id === (int) $usuarioActual['id']) {
            flash('No puedes desactivar tu propio usuario.', 'error');
        } elseif ($id) {
            $stmt = db()->prepare('SELECT u.*, r.nombre AS rol_nombre, r.es_sistema FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u && $u['activo'] && $u['es_sistema'] && contarAdminsActivos(db()) <= 1) {
                flash('No puedes desactivar al último administrador activo.', 'error');
            } elseif ($u) {
                db()->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ?')->execute([$id]);
                flash('Estado actualizado.');
            }
        }
    }
    redirect('index.php');
}

/**
 * Texto de "Última conexión" para la lista: [principal, detalle exacto].
 * NULL = todavía no hay registro (la columna se llena desde que se corrió
 * setup.php con la migración de ultimo_acceso; accesos anteriores no se
 * pueden reconstruir). El registro se refresca cada ~5 minutos mientras
 * navegan, así que "hace N min" es aproximado a ese margen.
 */
function textoUltimoAcceso(?string $dt): array
{
    if (!$dt) {
        return ['Sin registro', null];
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return ['Sin registro', null];
    }
    $exacto = date('d/m/Y h:i A', $ts);
    $seg = max(0, time() - $ts);
    if ($seg < 600) {
        $rel = 'Hace unos minutos';
    } elseif ($seg < 3600) {
        $rel = 'Hace ' . (int) floor($seg / 60) . ' min';
    } elseif ($seg < 86400) {
        $h = (int) floor($seg / 3600);
        $rel = 'Hace ' . $h . ($h === 1 ? ' hora' : ' horas');
    } elseif ($seg < 86400 * 30) {
        $d = (int) floor($seg / 86400);
        $rel = $d === 1 ? 'Ayer' : 'Hace ' . $d . ' días';
    } else {
        $rel = date('d/m/Y', $ts);
    }
    return [$rel, $exacto];
}

$usuarios = db()->query(
    'SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.nombre ASC'
)->fetchAll();

// Enlaces de restablecimiento sin usar, por usuario (para el chip de la lista).
$restablecimientos = [];
foreach ($usuarios as $u) {
    $r = restablecimientoPendiente(db(), (int) $u['id']);
    if ($r) {
        $restablecimientos[(int) $u['id']] = $r;
    }
}

// Tarjeta con el enlace recién generado (o el vigente que se quiere volver a ver).
$enlaceUsuario = null;
$enlaceId = intOrNull($_GET['enlace'] ?? null);
if ($enlaceId && can($usuarioActual, 'usuarios', 'editar') && isset($restablecimientos[$enlaceId]) && $restablecimientos[$enlaceId]['vigente']) {
    foreach ($usuarios as $u) {
        if ((int) $u['id'] === $enlaceId) {
            $enlaceUsuario = $u;
        }
    }
}

$pageTitle = 'Usuarios y roles';
$activeNav = 'usuarios';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Usuarios y roles</h1>
    <p>Cuentas de acceso al sistema y el rol que define qué pueden hacer.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo usuario</a>
  <?php endif; ?>
</div>

<?php if ($enlaceUsuario): ?>
  <?php $rEnlace = $restablecimientos[(int) $enlaceUsuario['id']]; ?>
  <div class="card card-pad" style="margin-bottom:16px;">
    <h2 class="section-title" style="margin-top:0;">Enlace para restablecer la contraseña de <?= e($enlaceUsuario['nombre']) ?></h2>
    <div class="field" style="max-width:620px;">
      <label>Enlace (vence <?= e(tiempoHasta($rEnlace['expira_en'])) ?> · el <?= fmtDate($rEnlace['expira_en']) ?>)</label>
      <input type="text" class="mono" readonly onclick="this.select()" value="<?= e(urlRestablecimiento($rEnlace['token'])) ?>">
      <small class="cell-muted">Envíaselo por WhatsApp, correo, etc. Es de un solo uso: la persona elige su contraseña nueva y nadie más la ve. Si generas otro enlace, este deja de servir.</small>
    </div>
  </div>
<?php endif; ?>

<div class="toolbar">
  <a class="btn btn-secondary btn-sm" href="../roles/index.php"><?= icon('shield') ?> Administrar roles</a>
</div>

<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Última conexión</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td class="cell-name"><?= e($u['nombre']) ?><?= (int) $u['id'] === (int) $usuarioActual['id'] ? ' <span class="chip chip-neutral">Tú</span>' : '' ?></td>
          <td class="cell-muted mono"><?= e($u['usuario']) ?></td>
          <td class="cell-muted"><?= e($u['email'] ?? '—') ?></td>
          <td class="cell-muted"><?= e($u['rol_nombre']) ?></td>
          <?php [$accesoTxt, $accesoExacto] = textoUltimoAcceso($u['ultimo_acceso'] ?? null); ?>
          <td class="cell-muted"<?= $accesoExacto ? ' title="' . e($accesoExacto) . '"' : '' ?>>
            <?= e($accesoTxt) ?>
            <?php if ($accesoExacto): ?><div style="font-size:.78rem;color:var(--text-tertiary);"><?= e($accesoExacto) ?></div><?php endif; ?>
          </td>
          <?php $rst = $restablecimientos[(int) $u['id']] ?? null; ?>
          <td>
            <span class="chip <?= $u['activo'] ? 'chip-success' : 'chip-muted' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span>
            <?php if ($rst && $rst['vigente']): ?>
              <a class="chip chip-warning" href="index.php?enlace=<?= (int) $u['id'] ?>" title="Hay un enlace para restablecer su contraseña que todavía no usa (vence <?= e(tiempoHasta($rst['expira_en'])) ?>). Clic para verlo."><?= icon('clock') ?> Restablecimiento pendiente</a>
            <?php elseif ($rst): ?>
              <span class="chip chip-muted" title="El enlace para restablecer su contraseña venció sin usarse">Enlace vencido</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <a class="icon-btn" href="form.php?id=<?= (int) $u['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php if ((int) $u['id'] !== (int) $usuarioActual['id']): ?>
                <?php if ($u['activo']): ?>
                  <form method="post" action="index.php" style="display:inline;" data-confirm="¿Generar un enlace para que &quot;<?= e($u['nombre']) ?>&quot; elija una contraseña nueva?<?= $rst && $rst['vigente'] ? ' El enlace anterior dejará de servir.' : '' ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="accion" value="generar_restablecimiento">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="icon-btn icon-btn-neutral" type="submit" title="Generar enlace para restablecer contraseña"><?= icon('lock') ?></button>
                  </form>
                <?php endif; ?>
                <form method="post" action="index.php" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn-secondary btn-sm" type="submit"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($puedeEliminar && (int) $u['id'] !== (int) $usuarioActual['id']): ?>
              <form method="post" action="index.php" style="display:inline;" data-confirm="¿Eliminar a &quot;<?= e($u['nombre']) ?>&quot;?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
