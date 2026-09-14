<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Si ya hay sesión activa, no tiene sentido mostrar el login de nuevo.
if (currentUser()) {
    redirect('panel.php');
}

$error = null;
$usuarioForm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $usuarioForm = trim($_POST['usuario'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($usuarioForm === '' || $password === '') {
        $error = 'Escribe tu usuario y contraseña.';
    } elseif (intentarLogin($usuarioForm, $password)) {
        $destino = $_SESSION['login_redirect'] ?? 'panel.php';
        unset($_SESSION['login_redirect']);
        redirect($destino !== '' ? $destino : 'panel.php');
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$cssVersion = @filemtime(__DIR__ . '/assets/css/app.css') ?: '1';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · Fogón Eventos</title>
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
    width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);
    border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:36px 32px;
    animation:loginIn .5s ease both;
  }
  @keyframes loginIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  .login-brand{display:flex;flex-direction:column;align-items:center;gap:6px;margin-bottom:26px;text-align:center;}
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
      <p>Inicia sesión para continuar</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin-bottom:16px;"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <div class="field">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" autofocus required autocomplete="username" value="<?= e($usuarioForm) ?>">
      </div>
      <div class="field">
        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:6px;">Iniciar sesión</button>
    </form>

    <a class="login-back" href="./index.php">← Volver al inicio</a>
  </div>
</body>
</html>
