<?php
/**
 * "Mi cuenta": cada usuario (administrador, padre/tutor, estudiante, el rol
 * que sea) cambia su propia contraseña escribiendo primero la actual. No
 * depende de ningún permiso de módulo: cualquiera con sesión puede cambiar
 * la suya, y solo la suya. Si olvidó la actual, no entra aquí — pide un
 * enlace de restablecimiento (ver restablecer.php).
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$base = '.';
$usuarioActual = requireLogin($base);

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $resultado = cambiarPasswordPropia(
        db(),
        (int) $usuarioActual['id'],
        (string) ($_POST['password_actual'] ?? ''),
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password2'] ?? '')
    );
    if ($resultado['ok']) {
        // Nuevo id de sesión tras un cambio de credenciales (la sesión actual sigue abierta).
        session_regenerate_id(true);
        flash('Tu contraseña se cambió correctamente.');
        redirect('cuenta.php');
    }
    $errores = $resultado['errores'];
}

$pageTitle = 'Mi cuenta';
$activeNav = 'cuenta';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Mi cuenta</h1>
    <p><?= e($usuarioActual['nombre'] ?? '') ?> · usuario <span class="mono"><?= e($usuarioActual['usuario'] ?? '') ?></span></p>
  </div>
</div>

<div class="card card-pad" style="max-width:520px;">
  <h2 class="section-title" style="margin-top:0;">Cambiar mi contraseña</h2>

  <?php if ($errores): ?>
    <div class="alert alert-error" style="margin-bottom:16px;"><?= implode('<br>', array_map('e', $errores)) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label for="password_actual">Contraseña actual</label>
      <input type="password" id="password_actual" name="password_actual" required autocomplete="current-password">
    </div>
    <div class="field">
      <label for="password">Contraseña nueva</label>
      <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
      <small class="cell-muted">Mínimo 6 caracteres.</small>
    </div>
    <div class="field">
      <label for="password2">Repite la contraseña nueva</label>
      <input type="password" id="password2" name="password2" required minlength="6" autocomplete="new-password">
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('lock') ?> Guardar contraseña</button>
  </form>

  <p class="cell-muted" style="margin:18px 0 0;font-size:.86rem;">
    ¿No recuerdas la contraseña actual? Cierra sesión y pide al taller un enlace para restablecerla.
  </p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
