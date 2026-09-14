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

$base = $base ?? '.';
$activeNav = $activeNav ?? 'panel';
$pageTitle = $pageTitle ?? 'Fogón Eventos';
$breadcrumb = $breadcrumb ?? ('<b>' . e($pageTitle) . '</b>');

$navItems = [
    'panel'       => ['label' => 'Panel',       'icon' => 'grid',     'href' => $base . '/index.php'],
    'eventos'     => ['label' => 'Eventos',     'icon' => 'calendar', 'href' => $base . '/eventos/index.php'],
    'estudiantes' => ['label' => 'Estudiantes', 'icon' => 'users',    'href' => $base . '/estudiantes/index.php'],
    'recetas'     => ['label' => 'Recetas',     'icon' => 'book',     'href' => $base . '/recetas/index.php'],
];

$flash = flashGet();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Fogón Eventos</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css">
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
    <div class="sidebar-foot">Fogón Eventos &middot; Jardín de Novias</div>
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
