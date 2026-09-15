<?php
/**
 * Encabezado común de todas las páginas.
 *
 * Antes de incluir este archivo, cada página debe definir:
 *   $pageTitle  (string)              — título de la página
 *   $activeNav  ('panel'|'eventos'|'estudiantes'|'recetas')
 *   $base       (string, opcional)    — ruta relativa a la raíz de la app
 *                                        ('.' para archivos de raíz, '..'
 *                                        para archivos dentro de una subcarpeta)
 *   $breadcrumb (string HTML, opcional) — si no se define, se usa $pageTitle
 */
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/auth.php';

$base = $base ?? '.';
$activeNav = $activeNav ?? 'panel';
$pageTitle = $pageTitle ?? 'Fogón Eventos';
$breadcrumb = $breadcrumb ?? ('<b>' . e($pageTitle) . '</b>');

// Cada página protegida ya llamó a requireLogin() antes de llegar aquí,
// así que debería haber un usuario — currentUser() vuelve a leerlo de
// sesión (cacheado, no repite la consulta).
$usuarioActual = currentUser();

$todosLosNavItems = [
    'panel'        => ['label' => 'Panel',              'icon' => 'grid',     'href' => $base . '/panel.php'],
    'eventos'      => ['label' => 'Eventos',             'icon' => 'calendar', 'href' => $base . '/eventos/index.php'],
    'estudiantes'  => ['label' => 'Estudiantes',         'icon' => 'users',    'href' => $base . '/estudiantes/index.php'],
    'recetas'      => ['label' => 'Recetas',             'icon' => 'book',     'href' => $base . '/recetas/index.php'],
    'ingredientes' => ['label' => 'Ingredientes',         'icon' => 'basket',   'href' => $base . '/ingredientes/index.php'],
    'configuracion'=> ['label' => 'Configuración',       'icon' => 'settings', 'href' => $base . '/configuracion/catalogos.php'],
    'usuarios'     => ['label' => 'Usuarios y roles',    'icon' => 'shield',   'href' => $base . '/usuarios/index.php'],
];
// El menú solo muestra los módulos que el rol del usuario puede ver.
$navItems = array_filter($todosLosNavItems, fn ($clave) => can($usuarioActual, $clave, 'ver'), ARRAY_FILTER_USE_KEY);

$flash = flashGet();

// "Cache buster": la versión cambia sola cada vez que se modifica app.css,
// para forzar a navegadores y cachés (CDN, LiteSpeed, etc.) a pedir la
// version más reciente en vez de servir una copia vieja guardada.
$cssVersion = @filemtime(__DIR__ . '/../assets/css/app.css') ?: '1';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Fogón Eventos</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css?v=<?= e((string) $cssVersion) ?>">
</head>
<body>
<div class="app">
  <div class="overlay" id="overlay"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
      <div class="flame"><?= icon('flame') ?></div>
      <div>
        <div class="brand-name">Fogón</div>
        <div class="brand-sub">Eventos gastronómicos</div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach ($navItems as $key => $item): ?>
        <a class="nav-item <?= $key === $activeNav ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
          <?= icon($item['icon']) ?><span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot user-foot">
      <div class="user-foot-info">
        <div class="user-foot-name"><?= e($usuarioActual['nombre'] ?? '') ?></div>
        <div class="user-foot-rol"><?= e($usuarioActual['rol_nombre'] ?? '') ?></div>
      </div>
      <a class="icon-btn" href="<?= e($base) ?>/logout.php" title="Cerrar sesión"><?= icon('logout') ?></a>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:10px;">
        <button class="hamburger" id="hamburgerBtn" aria-label="Abrir menú" type="button"><?= icon('menu') ?></button>
        <div class="crumb"><?= $breadcrumb ?></div>
      </div>
    </div>
    <div class="content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['tipo'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['mensaje']) ?></div>
      <?php endif; ?>
