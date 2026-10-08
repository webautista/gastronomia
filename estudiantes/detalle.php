<?php
/**
 * Fondo del estudiante: depósitos (dinero que un padre adelanta) y
 * aplicaciones (lo que ya se usó en cuotas de eventos/prácticas), con el
 * saldo disponible calculado siempre en vivo. Depositar es un permiso
 * aparte (estudiantes_fondo) de aplicar (eventos_fondo/practicas_fondo,
 * ver eventos/aplicar_fondo.php y practicas/aplicar_fondo.php) — a pedido
 * explícito de Eyaelkys, para poder asignar uno sin el otro. Por eso aquí
 * solo se pueden corregir/eliminar depósitos: una aplicación se deshace
 * desde la pantalla del evento/práctica donde se aplicó, con su propio
 * permiso (ver aplicarFondoEstudiante()/eliminarMovimientoFondo() en
 * includes/helpers.php).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'estudiantes_fondo', 'ver', $base);
$puedeDepositar = can($usuarioActual, 'estudiantes_fondo', 'crear');
$puedeEliminarDeposito = can($usuarioActual, 'estudiantes_fondo', 'eliminar');
// Generar la invitación de auto-registro (Paso 5) usa el mismo permiso que
// ya protege el CRUD de estudiantes/form.php, no uno del fondo.
$puedeGestionarCuenta = can($usuarioActual, 'estudiantes', 'editar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT * FROM estudiantes WHERE id = ?');
$stmt->execute([$id]);
$estudiante = $stmt->fetch();
if (!$estudiante) {
    flash('Ese estudiante ya no existe.', 'error');
    redirect('index.php');
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'depositar') {
        requirePermission($usuarioActual, 'estudiantes_fondo', 'crear', $base);
        $monto = isset($_POST['monto']) ? (float) $_POST['monto'] : 0;
        $metodo = $_POST['metodo'] ?? '';
        $fecha = $_POST['fecha'] ?? '';
        $nota = trim((string) ($_POST['nota'] ?? ''));

        if ($monto <= 0) {
            $errores[] = 'El monto debe ser mayor a 0.';
        }
        if (!in_array($metodo, ['efectivo', 'transferencia'], true)) {
            $errores[] = 'Elige cómo se hizo el depósito: efectivo o transferencia bancaria.';
        }
        if (!$fecha || !strtotime($fecha)) {
            $errores[] = 'La fecha no es válida.';
        }
        if (mb_strlen($nota) > 150) {
            $nota = mb_substr($nota, 0, 150);
        }

        if (!$errores) {
            depositarFondoEstudiante(db(), $id, $monto, $metodo, $fecha, $nota, $usuarioActual['id'], $usuarioActual['nombre']);
            flash('Depósito registrado.');
            redirect('detalle.php?id=' . $id);
        }
    } elseif ($accion === 'eliminar_deposito') {
        requirePermission($usuarioActual, 'estudiantes_fondo', 'eliminar', $base);
        $movimientoId = intOrNull($_POST['movimiento_id'] ?? null);
        if ($movimientoId) {
            $stmtChk = db()->prepare('SELECT tipo FROM fondo_movimientos WHERE id = ? AND estudiante_id = ?');
            $stmtChk->execute([$movimientoId, $id]);
            if ($stmtChk->fetchColumn() !== 'deposito') {
                flash('Eso no es un depósito. Una aplicación se deshace desde el evento o práctica donde se usó.', 'error');
                redirect('detalle.php?id=' . $id);
            }
            $errDel = eliminarMovimientoFondo(db(), $movimientoId, $id);
            if ($errDel) {
                flash($errDel[0], 'error');
            } else {
                flash('Depósito eliminado.');
            }
        }
        redirect('detalle.php?id=' . $id);
    } elseif ($accion === 'generar_restablecimiento') {
        // Enlace para que el estudiante (que ya tiene cuenta) elija una
        // contraseña nueva sin que nadie la vea — ver restablecer.php.
        requirePermission($usuarioActual, 'estudiantes', 'editar', $base);
        $usuarioDeEst = false;
        if (!empty($estudiante['usuario_id'])) {
            $stmt = db()->prepare('SELECT activo FROM usuarios WHERE id = ?');
            $stmt->execute([(int) $estudiante['usuario_id']]);
            $usuarioDeEst = $stmt->fetch();
        }
        if (!$usuarioDeEst) {
            flash('Este estudiante todavía no tiene cuenta de acceso: genera primero una invitación.', 'error');
        } elseif (!$usuarioDeEst['activo']) {
            flash('Su usuario está inactivo: actívalo primero desde Usuarios y roles.', 'error');
        } else {
            try {
                crearRestablecimiento(db(), (int) $estudiante['usuario_id'], (int) $usuarioActual['id']);
                flash('Enlace de restablecimiento generado. Cópialo y compártelo con el estudiante.');
            } catch (PDOException $ex) {
                flash('No se pudo generar el enlace: falta ejecutar setup.php una vez para crear la tabla de restablecimientos.', 'error');
            }
        }
        redirect('detalle.php?id=' . $id);
    } elseif ($accion === 'generar_invitacion') {
        requirePermission($usuarioActual, 'estudiantes', 'editar', $base);
        if ($estudiante['usuario_id']) {
            flash('Este estudiante ya tiene una cuenta de acceso.', 'error');
        } else {
            crearInvitacion(db(), 'estudiante', $id, $usuarioActual['id']);
            flash('Enlace de invitación generado. Cópialo y compártelo con el estudiante.');
        }
        redirect('detalle.php?id=' . $id);
    }
}

$saldoFondo = saldoFondoEstudiante(db(), $id);
$historial = historialFondoEstudiante(db(), $id);

// Estado de la cuenta con hora de PHP (igual que las listas): vigente / vencida / sin invitación.
$estadoAcceso = estadoAccesoPersonas(db(), 'estudiante', [$estudiante])[$id];
$invitacionActiva = $estadoAcceso['estado'] === 'vigente' ? $estadoAcceso['invitacion'] : null;
$restPend = !empty($estudiante['usuario_id']) ? $estadoAcceso['restablecimiento'] : null;
$usuarioVinculado = null;
if (!empty($estudiante['usuario_id'])) {
    $stmt = db()->prepare('SELECT usuario, activo FROM usuarios WHERE id = ?');
    $stmt->execute([(int) $estudiante['usuario_id']]);
    $usuarioVinculado = $stmt->fetch() ?: null;
}

$pageTitle = $estudiante['nombre'];
$activeNav = 'estudiantes';
$breadcrumb = '<a href="index.php">Estudiantes</a> &nbsp;/&nbsp; <b>' . e($estudiante['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1><?= e($estudiante['nombre']) ?></h1>
    <p>Fondo del estudiante</p>
  </div>
</div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Cuenta de acceso</h2>
  <?php if (!empty($estudiante['usuario_id'])): ?>
    <p>
      <span class="chip chip-success"><?= icon('check') ?> Con acceso</span>
      <?php if ($usuarioVinculado): ?>&nbsp; Usuario: <b class="mono"><?= e($usuarioVinculado['usuario']) ?></b><?php endif; ?>
      <?php if ($usuarioVinculado && !$usuarioVinculado['activo']): ?> <span class="chip chip-muted">Inactivo</span><?php endif; ?>
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

    <?php if ($puedeGestionarCuenta && $usuarioVinculado && $usuarioVinculado['activo']): ?>
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

    <?php if ($puedeGestionarCuenta): ?>
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

<div class="card card-pad" style="margin-bottom:16px;">
  <div class="cell-muted" style="font-size:.82rem;">Saldo disponible</div>
  <div class="mono" style="font-size:1.6rem;font-weight:700;"><?= money($saldoFondo) ?></div>
</div>

<?php if ($puedeDepositar): ?>
<div class="card card-pad form-card" style="max-width:560px;margin-bottom:16px;">
  <h2 class="section-title" style="margin-top:0;">Depositar al fondo</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="accion" value="depositar">
    <div class="field-row">
      <div class="field">
        <label for="monto">Monto (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" step="0.01" required value="<?= e((string) ($_POST['monto'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($_POST['fecha'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="field">
        <label for="metodo">Método</label>
        <select id="metodo" name="metodo" required>
          <option value="">Elige uno...</option>
          <option value="efectivo" <?= ($_POST['metodo'] ?? '') === 'efectivo' ? 'selected' : '' ?>>Efectivo</option>
          <option value="transferencia" <?= ($_POST['metodo'] ?? '') === 'transferencia' ? 'selected' : '' ?>>Transferencia bancaria</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="nota">Nota (opcional)</label>
      <input type="text" id="nota" name="nota" maxlength="150" placeholder="Ej. depósito de octubre" value="<?= e($_POST['nota'] ?? '') ?>">
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Registrar depósito</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="page-head" style="margin-bottom:0;padding:16px 16px 0;">
    <h2 class="section-title" style="margin:0;">Historial del fondo</h2>
  </div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Método</th><th>Dónde</th><th>Nota</th><th>Registrado por</th><th></th></tr></thead>
    <tbody>
      <?php if (!$historial): ?>
        <tr><td colspan="8" class="cell-muted" style="text-align:center;padding:24px;">Todavía no hay movimientos en el fondo.</td></tr>
      <?php endif; ?>
      <?php foreach ($historial as $mov): ?>
        <tr>
          <td class="cell-muted"><?= fmtDate($mov['fecha']) ?></td>
          <td>
            <?php if ($mov['tipo'] === 'deposito'): ?>
              <span class="chip chip-success">Depósito</span>
            <?php else: ?>
              <span class="chip chip-neutral">Aplicación</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= money($mov['monto']) ?></td>
          <td class="cell-muted">
            <?php if ($mov['tipo'] === 'deposito' && $mov['metodo']): ?>
              <span class="chip <?= $mov['metodo'] === 'efectivo' ? 'chip-success' : 'chip-neutral' ?>"><?= e(etiquetaMetodoPago($mov['metodo'])) ?></span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="cell-muted">
            <?php if ($mov['tipo'] === 'aplicacion' && $mov['entidad_nombre']): ?>
              <?= e($mov['entidad_nombre']) ?>
              <a href="<?= e($base) ?>/<?= $mov['entidad_tipo'] === 'evento' ? 'eventos' : 'practicas' ?>/aplicar_fondo.php?id=<?= (int) $mov['entidad_id'] ?>&estudiante_id=<?= $id ?>" style="margin-left:6px;font-size:.82rem;">ver</a>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="cell-muted"><?= e($mov['nota'] ?? '') ?></td>
          <td class="cell-muted"><?= e($mov['registrado_por_nombre']) ?></td>
          <td class="row-actions">
            <?php if ($mov['tipo'] === 'deposito' && $puedeEliminarDeposito): ?>
              <form method="post" data-confirm="¿Eliminar este depósito de <?= e(money($mov['monto'])) ?>?">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="eliminar_deposito">
                <input type="hidden" name="movimiento_id" value="<?= (int) $mov['id'] ?>">
                <button class="icon-btn" type="submit" title="Eliminar depósito"><?= icon('trash') ?></button>
              </form>
            <?php endif; ?>
          </td>
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
