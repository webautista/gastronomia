<?php
/**
 * Reportes generales — a pedido explícito de Eyaelkys. Empezó con "estudiantes
 * con saldo a favor, padres con deuda pendiente" (quedó pendiente desde la
 * sección del fondo/portal de padres) y se amplió después de una lluvia de
 * ideas de qué reportes hacían falta. Cuatro pestañas:
 *
 * - Cartera: quién tiene un pendiente REAL ahora mismo, usando la cuota
 *   vigente de cada evento/práctica (calcularCuotas() — el ajuste manual si
 *   existe, si no el cálculo automático), no la deuda teórica contra el
 *   costo real. No filtra por estado ni fecha: un evento ya Finalizado con
 *   pendiente sigue siendo dinero por cobrar.
 * - Fondo: quién tiene saldo a favor disponible en su fondo sin aplicar
 *   todavía.
 * - Estado de cuenta: directorio de estudiantes para entrar al detalle
 *   consolidado de uno (estado_cuenta.php) — todas sus participaciones y su
 *   fondo en una sola vista.
 * - Cierre financiero: directorio de eventos/prácticas para entrar al cierre
 *   de uno (cierre.php) — costo real vs. proyectado, gastos por categoría y
 *   estado de pago de cada estudiante.
 *
 * Un solo módulo de permiso ("reportes", solo "ver" tiene efecto) para las
 * cuatro pestañas y las dos pantallas de detalle — ver la nota junto al
 * módulo en db/schema.sql.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'reportes', 'ver', $base);

// Para no ofrecer un enlace que lleve a un 403: cada fila solo enlaza al
// evento/práctica o a la ficha del estudiante si el rol además puede verlos.
$puedeVerEventos = can($usuarioActual, 'eventos', 'ver');
$puedeVerPracticas = can($usuarioActual, 'practicas', 'ver');
$puedeVerFichaEstudiante = can($usuarioActual, 'estudiantes_fondo', 'ver');

$tabsValidos = ['cartera', 'fondo', 'cuenta', 'cierre'];
$tab = $_GET['tab'] ?? 'cartera';
if (!in_array($tab, $tabsValidos, true)) {
    $tab = 'cartera';
}
$busqueda = trim($_GET['q'] ?? '');
$padresPorEstudiante = mapaPadresPorEstudiante(db());

if ($tab === 'cartera') {
    $filasCartera = reporteCartera(db());
    if ($busqueda !== '') {
        $filasCartera = array_values(array_filter(
            $filasCartera,
            fn ($f) => mb_stripos($f['estudiante_nombre'], $busqueda) !== false
        ));
    }
    // Ya vienen ordenadas por nombre de estudiante desde reporteCartera().
    $gruposCartera = [];
    foreach ($filasCartera as $f) {
        $gruposCartera[$f['estudiante_id']]['nombre'] = $f['estudiante_nombre'];
        $gruposCartera[$f['estudiante_id']]['filas'][] = $f;
    }
    $totalPendienteGeneral = array_sum(array_column($filasCartera, 'pendiente'));
} elseif ($tab === 'fondo') {
    $filasFondo = reporteFondoSaldos(db());
    if ($busqueda !== '') {
        $filasFondo = array_values(array_filter(
            $filasFondo,
            fn ($f) => mb_stripos($f['estudiante_nombre'], $busqueda) !== false
        ));
    }
    $totalFondoGeneral = array_sum(array_column($filasFondo, 'saldo'));
} elseif ($tab === 'cuenta') {
    // Directorio liviano (sin calcular cuotas todavía, eso pasa una vez que
    // se entra a UN estudiante en estado_cuenta.php) para no recalcular la
    // cuota de cada evento/práctica de cada estudiante solo para listarlos.
    $sql = "SELECT e.id, e.nombre, ge.nombre AS grupo FROM estudiantes e LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id";
    $params = [];
    if ($busqueda !== '') {
        $sql .= ' WHERE e.nombre LIKE ?';
        $params[] = '%' . $busqueda . '%';
    }
    $sql .= ' ORDER BY e.nombre ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $listaEstudiantes = $stmt->fetchAll();
} else {
    $eventosLista = db()->query(
        "SELECT ev.id, ev.nombre, ev.fecha, es.nombre AS estado FROM eventos ev JOIN estados_evento es ON es.id = ev.estado_id"
    )->fetchAll();
    $practicasLista = db()->query('SELECT id, nombre, fecha FROM practicas')->fetchAll();
    $listaCierre = [];
    foreach ($eventosLista as $e) {
        $listaCierre[] = ['tipo' => 'evento', 'id' => (int) $e['id'], 'nombre' => $e['nombre'], 'fecha' => $e['fecha'], 'estado' => $e['estado']];
    }
    foreach ($practicasLista as $p) {
        $listaCierre[] = ['tipo' => 'practica', 'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'fecha' => $p['fecha'], 'estado' => null];
    }
    if ($busqueda !== '') {
        $listaCierre = array_values(array_filter($listaCierre, fn ($f) => mb_stripos($f['nombre'], $busqueda) !== false));
    }
    usort($listaCierre, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));
}

$placeholdersBusqueda = [
    'cartera' => 'Buscar por estudiante...',
    'fondo' => 'Buscar por estudiante...',
    'cuenta' => 'Buscar por estudiante...',
    'cierre' => 'Buscar por evento o práctica...',
];

$pageTitle = 'Reportes';
$activeNav = 'reportes';
$breadcrumb = '<b>Reportes</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Reportes</h1><p>Vista de conjunto de la parte financiera. El detalle completo de cada evento, práctica o estudiante sigue estando en su propia pantalla.</p></div></div>

<div class="tabs no-print">
  <a class="tab <?= $tab === 'cartera' ? 'active' : '' ?>" href="index.php?tab=cartera"><?= icon('alertTriangle') ?> Cartera</a>
  <a class="tab <?= $tab === 'fondo' ? 'active' : '' ?>" href="index.php?tab=fondo"><?= icon('wallet') ?> Fondo de estudiantes</a>
  <a class="tab <?= $tab === 'cuenta' ? 'active' : '' ?>" href="index.php?tab=cuenta"><?= icon('users') ?> Estado de cuenta</a>
  <a class="tab <?= $tab === 'cierre' ? 'active' : '' ?>" href="index.php?tab=cierre"><?= icon('clipboardList') ?> Cierre financiero</a>
</div>

<div class="toolbar no-print">
  <form class="search" method="get" action="index.php">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="<?= e($placeholdersBusqueda[$tab]) ?>" value="<?= e($busqueda) ?>">
  </form>
  <?php if ($tab === 'cartera' || $tab === 'fondo'): ?>
    <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
  <?php endif; ?>
</div>

<?php if ($tab === 'cartera'): ?>

  <div class="summary-grid">
    <div class="stat-tile stat-tile--terracotta">
      <div class="stat-label">Total pendiente</div>
      <div class="stat-value"><?= money($totalPendienteGeneral) ?></div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Estudiantes con deuda</div>
      <div class="stat-value"><?= count($gruposCartera) ?></div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Partidas pendientes</div>
      <div class="stat-value"><?= count($filasCartera) ?></div>
      <div class="stat-hint">eventos/prácticas con algo por cobrar</div>
    </div>
  </div>

  <?php if (!$gruposCartera): ?>
    <div class="card"><div class="empty"><?= icon('check') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin pendientes<?= $busqueda !== '' ? ' para esa búsqueda' : '' ?></div>
      <div><?= $busqueda !== '' ? 'Prueba con otro nombre.' : 'Ningún estudiante tiene un pendiente real en este momento.' ?></div>
    </div></div>
  <?php else: ?>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Evento / Práctica</th><th>Fecha</th><th>Cuota</th><th>Pagado</th><th>Pendiente</th></tr></thead>
      <tbody>
        <?php foreach ($gruposCartera as $estudianteId => $grupo): ?>
          <?php $subtotal = array_sum(array_column($grupo['filas'], 'pendiente')); ?>
          <tr style="background:var(--surface-2);">
            <td colspan="4">
              <b><?= e($grupo['nombre']) ?></b>
              <?php if (!empty($padresPorEstudiante[$estudianteId])): ?>
                <span class="cell-muted" style="font-size:.82rem;"> · <?= e(implode(', ', $padresPorEstudiante[$estudianteId])) ?></span>
              <?php endif; ?>
              <a class="no-print" href="estado_cuenta.php?id=<?= $estudianteId ?>" style="margin-left:8px;font-size:.82rem;">estado de cuenta</a>
              <?php if ($puedeVerFichaEstudiante): ?>
                <a class="no-print" href="<?= e($base) ?>/estudiantes/detalle.php?id=<?= $estudianteId ?>" style="margin-left:8px;font-size:.82rem;">ver ficha</a>
              <?php endif; ?>
            </td>
            <td style="background:var(--surface-2);"><b class="mono"><?= money($subtotal) ?></b></td>
          </tr>
          <?php foreach ($grupo['filas'] as $f): ?>
            <?php
              $hrefEntidad = ($f['entidad_tipo'] === 'evento' ? $base . '/eventos/' : $base . '/practicas/') . 'detalle.php?id=' . $f['entidad_id'] . '&tab=estudiantes';
              $puedeLinkear = $f['entidad_tipo'] === 'evento' ? $puedeVerEventos : $puedeVerPracticas;
            ?>
            <tr>
              <td>
                <?php if ($puedeLinkear): ?><a href="<?= e($hrefEntidad) ?>"><?= e($f['entidad_nombre']) ?></a><?php else: ?><?= e($f['entidad_nombre']) ?><?php endif; ?>
                <span class="chip chip-muted" style="margin-left:6px;font-size:.7rem;"><?= $f['entidad_tipo'] === 'evento' ? 'Evento' : 'Práctica' ?></span>
                <?php if ($f['entidad_estado']): ?><span class="chip chip-muted" style="font-size:.7rem;"><?= e($f['entidad_estado']) ?></span><?php endif; ?>
              </td>
              <td class="cell-muted"><?= fmtDate($f['entidad_fecha']) ?></td>
              <td class="mono"><?= money($f['cuota']) ?></td>
              <td class="mono"><?= money($f['pagado']) ?></td>
              <td class="mono"><span class="chip chip-warning"><?= money($f['pendiente']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4" style="text-align:right;"><b>Total pendiente</b></td><td class="mono"><b><?= money($totalPendienteGeneral) ?></b></td></tr>
      </tfoot>
    </table>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'fondo'): ?>

  <div class="summary-grid" style="grid-template-columns:repeat(2,1fr);">
    <div class="stat-tile stat-tile--sage">
      <div class="stat-label">Total en fondos</div>
      <div class="stat-value"><?= money($totalFondoGeneral) ?></div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Estudiantes con saldo</div>
      <div class="stat-value"><?= count($filasFondo) ?></div>
    </div>
  </div>

  <?php if (!$filasFondo): ?>
    <div class="card"><div class="empty"><?= icon('wallet') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin saldos<?= $busqueda !== '' ? ' para esa búsqueda' : '' ?></div>
      <div><?= $busqueda !== '' ? 'Prueba con otro nombre.' : 'Ningún estudiante tiene saldo disponible en su fondo ahora mismo.' ?></div>
    </div></div>
  <?php else: ?>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Estudiante</th><th>Contacto</th><th>Último movimiento</th><th>Saldo</th><th class="no-print"></th></tr></thead>
      <tbody>
        <?php foreach ($filasFondo as $f): ?>
          <tr>
            <td><b><?= e($f['estudiante_nombre']) ?></b></td>
            <td class="cell-muted" style="font-size:.85rem;"><?= !empty($padresPorEstudiante[$f['estudiante_id']]) ? e(implode(', ', $padresPorEstudiante[$f['estudiante_id']])) : '—' ?></td>
            <td class="cell-muted"><?= fmtDate($f['ultimo_movimiento']) ?></td>
            <td class="mono"><span class="chip chip-success"><?= money($f['saldo']) ?></span></td>
            <td class="no-print">
              <a href="estado_cuenta.php?id=<?= (int) $f['estudiante_id'] ?>">estado de cuenta</a>
              <?php if ($puedeVerFichaEstudiante): ?> · <a href="<?= e($base) ?>/estudiantes/detalle.php?id=<?= (int) $f['estudiante_id'] ?>">ver fondo</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right;"><b>Total</b></td><td class="mono"><b><?= money($totalFondoGeneral) ?></b></td><td class="no-print"></td></tr>
      </tfoot>
    </table>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'cuenta'): ?>

  <?php if (!$listaEstudiantes): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin resultados</div>
      <div>No hay estudiantes que coincidan con tu búsqueda.</div>
    </div></div>
  <?php else: ?>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Estudiante</th><th>Grupo</th><th>Contacto</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($listaEstudiantes as $est): ?>
          <tr>
            <td><b><?= e($est['nombre']) ?></b></td>
            <td class="cell-muted"><?= e($est['grupo'] ?? 'Sin grupo') ?></td>
            <td class="cell-muted" style="font-size:.85rem;"><?= !empty($padresPorEstudiante[$est['id']]) ? e(implode(', ', $padresPorEstudiante[$est['id']])) : '—' ?></td>
            <td><a href="estado_cuenta.php?id=<?= (int) $est['id'] ?>">Ver estado de cuenta →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>

  <?php if (!$listaCierre): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin resultados</div>
      <div>No hay eventos ni prácticas que coincidan con tu búsqueda.</div>
    </div></div>
  <?php else: ?>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Fecha</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($listaCierre as $f): ?>
          <tr>
            <td>
              <b><?= e($f['nombre']) ?></b>
              <span class="chip chip-muted" style="margin-left:6px;font-size:.7rem;"><?= $f['tipo'] === 'evento' ? 'Evento' : 'Práctica' ?></span>
              <?php if ($f['estado']): ?><span class="chip chip-muted" style="font-size:.7rem;"><?= e($f['estado']) ?></span><?php endif; ?>
            </td>
            <td class="cell-muted"><?= fmtDate($f['fecha']) ?></td>
            <td><a href="cierre.php?tipo=<?= $f['tipo'] ?>&id=<?= $f['id'] ?>">Ver cierre →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
