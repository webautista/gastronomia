<?php
/**
 * Home pública de Fogón Eventos.
 * No requiere sesión: cualquier visitante puede ver el hero, la información
 * del programa y los próximos eventos (solo datos públicos: nombre, fecha,
 * lugar y cuota — nunca presupuesto ni gastos internos).
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/icons.php';

$stmt = db()->query(
    "SELECT ev.nombre, ev.fecha, ev.lugar, ev.cuota
     FROM eventos ev
     JOIN estados_evento es ON es.id = ev.estado_id
     WHERE es.nombre <> 'Finalizado' AND ev.fecha >= CURDATE()
     ORDER BY ev.fecha ASC
     LIMIT 6"
);
$proximosEventos = $stmt->fetchAll();

$cssVersion = @filemtime(__DIR__ . '/assets/css/app.css') ?: '1';
$homeCssVersion = @filemtime(__DIR__ . '/assets/css/home.css') ?: '1';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fogón Eventos · Aprende cocinando de verdad</title>
<meta name="description" content="Fogón Eventos: el programa de eventos gastronómicos donde estudiantes de gastronomía planifican, cocinan y sirven experiencias reales.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= e((string) $cssVersion) ?>">
<link rel="stylesheet" href="assets/css/home.css?v=<?= e((string) $homeCssVersion) ?>">
</head>
<body class="home">

<header class="home-topbar">
  <div class="home-brand"><span class="flame"><?= icon('flame') ?></span> Fogón Eventos</div>
  <nav class="home-nav-links">
    <a href="#programa">Sobre el programa</a>
    <a href="#eventos">Próximos eventos</a>
  </nav>
  <a class="btn-hero" href="login.php"><?= icon('lock') ?> Iniciar sesión</a>
</header>

<section class="hero">
  <div class="hero-floaties" aria-hidden="true">
    <span class="floaty f1">🍳</span>
    <span class="floaty f2">🥐</span>
    <span class="floaty f3">🍰</span>
    <span class="floaty f4">🥑</span>
    <span class="floaty f5">🍋</span>
  </div>
  <div class="hero-inner">
    <span class="hero-eyebrow"><?= icon('sparkle') ?> Programa de gastronomía</span>
    <h1>Aprende cocinando <span class="accent-word">de verdad</span>, evento a evento</h1>
    <p class="lead">Fogón Eventos es donde los estudiantes de gastronomía dejan el aula y organizan experiencias reales: menú, presupuesto, equipo y servicio, de principio a fin.</p>
    <div class="hero-ctas">
      <a class="btn-hero" href="#eventos"><?= icon('calendar') ?> Ver próximos eventos</a>
      <a class="btn-hero-ghost" href="#programa"><?= icon('whisk') ?> Sobre el programa</a>
    </div>
  </div>
  <div class="scroll-cue">Descubre más <?= icon('arrowRight') ?></div>
</section>

<section class="home-section" id="programa">
  <div class="reveal">
    <div class="section-eyebrow">Sobre el programa</div>
    <h2 class="section-heading">Del cuaderno de recetas al servicio en vivo</h2>
    <p class="section-sub">Cada evento es un mini-restaurante montado por los propios estudiantes: ellos escalan las recetas, controlan el gasto y sirven a un público real.</p>
  </div>
  <div class="feature-grid">
    <div class="feature-card reveal">
      <div class="feature-icon i-wine"><?= icon('whisk') ?></div>
      <h3>Aprender haciendo</h3>
      <p>Cada receta del catálogo se escala en vivo según las porciones que necesita el evento — nada de teoría suelta.</p>
    </div>
    <div class="feature-card reveal">
      <div class="feature-icon i-gold"><?= icon('portion') ?></div>
      <h3>Presupuesto real</h3>
      <p>Los estudiantes registran gastos, cuotas y pagos de cada evento, entendiendo el costo real de cocinar para un grupo.</p>
    </div>
    <div class="feature-card reveal">
      <div class="feature-icon i-sage"><?= icon('users') ?></div>
      <h3>Trabajo en equipo</h3>
      <p>Grupos, tareas y roles se coordinan evento por evento, igual que en una cocina profesional.</p>
    </div>
  </div>
</section>

<section class="home-section" id="eventos">
  <div class="reveal">
    <div class="section-eyebrow">Agenda</div>
    <h2 class="section-heading">Próximos eventos</h2>
    <p class="section-sub">Estas son las próximas fechas donde el programa abre sus puertas. La cuota cubre ingredientes y logística del evento.</p>
  </div>

  <?php if (!$proximosEventos): ?>
    <div class="home-empty reveal">
      <?= icon('boxEmpty') ?>
      <p style="margin:10px 0 0;">Todavía no hay eventos programados. Vuelve pronto — se anuncian aquí en cuanto se confirman.</p>
    </div>
  <?php else: ?>
    <div class="evt-public-grid">
      <?php foreach ($proximosEventos as $ev): ?>
        <div class="evt-public-card reveal">
          <div class="evt-public-top">
            <span class="evt-public-date"><?= icon('calendar') ?> <?= e(fmtDate($ev['fecha'])) ?></span>
          </div>
          <div class="evt-public-body">
            <h3><?= e($ev['nombre']) ?></h3>
            <div class="evt-public-meta">
              <?php if ($ev['lugar']): ?><span><?= icon('pin') ?> <?= e($ev['lugar']) ?></span><?php endif; ?>
            </div>
            <div class="evt-public-cuota">
              <span>Cuota por estudiante</span>
              <b><?= money($ev['cuota']) ?></b>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="home-cta reveal">
  <h2>¿Formas parte del programa?</h2>
  <p>Entra con tu usuario para planificar eventos, actualizar recetas y llevar el control de cada actividad.</p>
  <a class="btn-hero" href="login.php"><?= icon('lock') ?> Iniciar sesión</a>
</div>

<footer class="home-footer">
  <span>© <?= date('Y') ?> Fogón Eventos</span>
  <a href="login.php">Acceso interno →</a>
</footer>

<script>
(function () {
  if (!('IntersectionObserver' in window)) { return; }
  // Solo ocultamos los .reveal (vía la clase js-reveal en <body>) cuando
  // sabemos que el observer va a correr y a mostrarlos de nuevo — así el
  // contenido nunca se queda invisible si JS tarda, falla o está desactivado.
  document.body.classList.add('js-reveal');
  var items = document.querySelectorAll('.reveal');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('in-view');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });
  items.forEach(function (el) { io.observe(el); });
})();
</script>
</body>
</html>
