<?php
/**
 * Pantalla pública para elegir una contraseña nueva con el enlace de un solo
 * uso que genera un administrador (usuarios/index.php, padres/detalle.php,
 * estudiantes/detalle.php → crearRestablecimiento() en includes/helpers.php).
 * Nadie ve la contraseña nueva: la escribe la propia persona aquí. No
 * requiere sesión — como login.php e invitacion.php no usa layout_top.php.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$restablecimiento = $token !== '' ? obtenerRestablecimientoValido(db(), $token) : null;

$errores = [];

if ($restablecimiento && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    $resultado = consumirRestablecimiento(db(), $restablecimiento, $password, $password2);
    if ($resultado['ok']) {
        // Si había otra sesión abierta en este navegador, se cierra: que entre
        // con la contraseña nueva.
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Contraseña actualizada. Ya puedes iniciar sesión con la nueva.');
        redirect('login.php');
    }
    $errores = $resultado['errores'];
}

$cssVersion = @filemtime(__DIR__ . '/assets/css/app.css') ?: '1';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nueva contraseña · Fogón Eventos</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./assets/css/app.css?v=<?= e((string) $cssVersion) ?>">
<style>
  body.login-body{
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:radial-gradient(1200px 600px at 15% -10%, var(--accent-soft), transparent 60%),
               radial-gradient(1000px 500px at 110% 10%, var(--copper-soft), transparent 55%),
               var(--bg);
    padding:20px;
  }
  .login-card{
    width:100%;max-width:420px;background:var(--surface);border:1px solid var(--border);
    border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:36px 32px;
    animation:loginIn .5s ease both;
  }
  @keyframes loginIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  .login-brand{display:flex;flex-direction:column;align-items:center;gap:6px;margin-bottom:22px;text-align:center;}
  .login-brand .emoji{font-size:34px;line-height:1;}
  .login-brand h1{font-family:'Fraunces',serif;font-size:1.4rem;margin:2px 0 0;}
  .login-brand p{margin:0;color:var(--text-secondary);font-size:.86rem;}
  .login-back{display:block;text-align:center;margin-top:18px;font-size:.84rem;color:var(--text-secondary);}
  .login-back:hover{color:var(--accent);}
</style>
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-brand">
      <div class="emoji">🔥</div>
      <h1>Fogón Eventos</h1>
      <?php if ($restablecimiento): ?>
        <p>Hola, <?= e($restablecimiento['usuario_nombre']) ?>. Elige tu contraseña nueva.</p>
      <?php else: ?>
        <p>Restablecer contraseña</p>
      <?php endif; ?>
    </div>

    <?php if (!$restablecimiento): ?>
      <div class="alert alert-error">
        Este enlace ya no es válido: puede que ya se haya usado, que haya vencido, que la dirección esté incompleta
        o que tu usuario esté desactivado. Pide al taller que te genere un enlace nuevo.
      </div>
      <a class="login-back" href="./login.php">← Ir a iniciar sesión</a>
    <?php else: ?>

      <?php if ($errores): ?>
        <div class="alert alert-error" style="margin-bottom:16px;"><?= implode('<br>', array_map('e', $errores)) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <p class="cell-muted" style="margin:0 0 14px;font-size:.86rem;">Tu usuario es <b class="mono"><?= e($restablecimiento['usuario_login']) ?></b>.</p>
        <div class="field">
          <label for="password">Contraseña nueva</label>
          <input type="password" id="password" name="password" required autofocus minlength="6" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password2">Repite la contraseña nueva</label>
          <input type="password" id="password2" name="password2" required minlength="6" autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:6px;">Guardar contraseña nueva</button>
      </form>

      <a class="login-back" href="./login.php">← Ir a iniciar sesión</a>
    <?php endif; ?>
  </div>
</body>
</html>
