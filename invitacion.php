<?php
/**
 * Pantalla pública de auto-registro: un padre/tutor llega aquí con el
 * enlace que le generó el administrador desde padres/detalle.php (ver
 * crearInvitacion() en includes/helpers.php) y crea su propio usuario y
 * contraseña. No requiere sesión — es, junto con login.php, una de las
 * pocas páginas del sitio que no pasa por includes/layout_top.php.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    redirect('panel.php');
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invitacion = $token !== '' ? obtenerInvitacionValida(db(), $token) : null;

$errores = [];
$usuarioForm = '';

if ($invitacion && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $usuarioForm = trim($_POST['usuario'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');

    if ($password !== $password2) {
        $errores[] = 'Las contraseñas no coinciden.';
    } else {
        $resultado = consumirInvitacionRegistro(db(), $invitacion, $usuarioForm, $password);
        if (!$resultado['ok']) {
            $errores = $resultado['errores'];
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $resultado['usuario_id'];
            registrarAccesoUsuario((int) $resultado['usuario_id']);
            redirect('panel.php');
        }
    }
}

$cssVersion = @filemtime(__DIR__ . '/assets/css/app.css') ?: '1';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crear tu cuenta · Fogón Eventos</title>
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
      <?php if ($invitacion): ?>
        <p>Hola, <?= e($invitacion['entidad_nombre']) ?>. Crea tu usuario y contraseña para acceder.</p>
      <?php else: ?>
        <p>Enlace de invitación</p>
      <?php endif; ?>
    </div>

    <?php if (!$invitacion): ?>
      <div class="alert alert-error">
        Este enlace ya no es válido: puede que ya se haya usado, que haya vencido, o que la dirección esté incompleta.
        Pide al taller que te genere un enlace nuevo.
      </div>
      <a class="login-back" href="./login.php">← Ya tengo una cuenta, iniciar sesión</a>
    <?php else: ?>

      <?php if ($errores): ?>
        <div class="alert alert-error" style="margin-bottom:16px;"><?= implode('<br>', array_map('e', $errores)) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
          <label for="usuario">Elige tu usuario</label>
          <input type="text" id="usuario" name="usuario" required autofocus autocomplete="off" value="<?= e($usuarioForm) ?>">
        </div>
        <div class="field">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password2">Repite la contraseña</label>
          <input type="password" id="password2" name="password2" required minlength="6" autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:6px;">Crear mi cuenta</button>
      </form>

      <a class="login-back" href="./login.php">← Ya tengo una cuenta, iniciar sesión</a>
    <?php endif; ?>
  </div>
</body>
</html>
