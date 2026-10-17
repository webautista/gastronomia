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
 *   estado de pago de cada estudiante. Separado (a pedido de Eyaelkys) en
 *   dos bloques, Eventos y Prácticas, y dentro de cada uno "En curso y
 *   pendientes" arriba y "Finalizados" abajo (plegado). Un evento es
 *   finalizado si su estado es Finalizado; una práctica, que no tiene
 *   estado, si su fecha ya pasó.
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
        "SELECT ev.*, es.nombre AS estado FROM eventos ev JOIN estados_evento es ON es.id = ev.estado_id"
    )->fetchAll();
    $practicasLista = db()->query('SELECT id, nombre, fecha FROM practicas')->fetchAll();
    // Cierres ya "cerrados" (congelados, ver cierres_financieros): se marcan
    // con un chip en la lista. Tolera que la tabla no exista todavía.
    $cierresCerrados = [];
    try {
        foreach (db()->query('SELECT entidad_tipo, entidad_id FROM cierres_financieros')->fetchAll() as $c) {
            $cierresCerrados[$c['entidad_tipo'] . ':' . $c['entidad_id']] = true;
        }
    } catch (Throwable $ex) {
        $cierresCerrados = [];
    }
    $hoy = date('Y-m-d');
    $listaCierre = [];
    foreach ($eventosLista as $e) {
        $listaCierre[] = ['tipo' => 'evento', 'id' => (int) $e['id'], 'nombre' => $e['nombre'], 'fecha' => $e['fecha'], 'fecha_tentativa' => !empty($e['fecha_tentativa']), 'estado' => $e['estado'], 'finalizado' => $e['estado'] === 'Finalizado', 'cerrado' => isset($cierresCerrados['evento:' . $e['id']])];
    }
    foreach ($practicasLista as $p) {
        $listaCierre[] = ['tipo' => 'practica', 'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'fecha' => $p['fecha'], 'estado' => null, 'finalizado' => $p['fecha'] < $hoy, 'cerrado' => isset($cierresCerrados['practica:' . $p['id']])];
    }
    if ($busqueda !== '') {
        $listaCierre = array_values(array_filter($listaCierre, fn ($f) => mb_stripos($f['nombre'], $busqueda) !== false));
    }
    usort($listaCierre, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));
    // Dos bloques (eventos / prácticas) y, dentro de cada uno, pendientes
    // arriba y finalizados abajo; cada lista conserva el orden por fecha.
    $gruposCierre = [
        'evento'   => ['titulo' => 'Eventos',   'icono' => 'calendar', 'pendientes' => [], 'finalizados' => []],
        'practica' => ['titulo' => 'Prácticas', 'icono' => 'whisk',    'pendientes' => [], 'finalizados' => []],
    ];
    foreach ($listaCierre as $f) {
        $gruposCierre[$f['tipo']][$f['finalizado'] ? 'finalizados' : 'pendientes'][] = $f;
    }
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
              <td class="cell-muted"><?= fmtFechaEvento($f['entidad_fecha'], $f['entidad_fecha_tentativa'] ?? false) ?></td>
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
  <?php
  // Una fila de la lista: nombre + estado (solo eventos; el tipo ya lo dice
  // el bloque) + chip si el cierre ya está cerrado, fecha y enlace.
  $filaCierre = function (array $f): string {
      ob_start(); ?>
      <tr>
        <td>
          <b><?= e($f['nombre']) ?></b>
          <?php if ($f['estado']): ?><span class="chip chip-muted" style="margin-left:6px;font-size:.7rem;"><?= e($f['estado']) ?></span><?php endif; ?>
          <?php if (!empty($f['cerrado'])): ?><span class="chip chip-success" style="font-size:.7rem;" title="El cierre financiero ya está cerrado (congelado)"><?= icon('lock') ?> Cierre cerrado</span><?php endif; ?>
        </td>
        <td class="cell-muted"><?= fmtFechaEvento($f['fecha'], $f['fecha_tentativa'] ?? false) ?></td>
        <td><a href="cierre.php?tipo=<?= e($f['tipo']) ?>&id=<?= (int) $f['id'] ?>">Ver cierre →</a></td>
      </tr>
      <?php return ob_get_clean();
  };
  ?>
  <?php foreach ($gruposCierre as $g):
      $totalGrupo = count($g['pendientes']) + count($g['finalizados']);
      if ($totalGrupo === 0) { continue; }
  ?>
  <div class="section-title-row" style="margin-top:6px;"><span class="section-icon is-gold"><?= icon($g['icono']) ?></span><h2 class="section-title"><?= e($g['titulo']) ?> <span class="cell-muted" style="font-weight:400;font-size:.9rem;">(<?= $totalGrupo ?>)</span></h2></div>
  <div class="card" style="margin-bottom:22px;">
    <div class="cierre-grupo-titulo">En curso y pendientes (<?= count($g['pendientes']) ?>)</div>
    <?php if ($g['pendientes']): ?>
    <div class="table-wrap">
    <table class="table tabla-cierre">
      <colgroup><col style="width:58%"><col style="width:27%"><col style="width:15%"></colgroup>
      <tbody><?php foreach ($g['pendientes'] as $f) { echo $filaCierre($f); } ?></tbody>
    </table>
    </div>
    <?php else: ?>
      <div class="cell-muted" style="padding:6px 16px 16px;font-size:.88rem;">No hay nada pendiente.</div>
    <?php endif; ?>
    <?php if ($g['finalizados']): ?>
    <details class="cierre-finalizados"<?= $busqueda !== '' ? ' open' : '' ?>>
      <summary><?= icon('chevronDown') ?> Finalizados (<?= count($g['finalizados']) ?>)</summary>
      <div class="table-wrap">
      <table class="table tabla-cierre">
        <colgroup><col style="width:58%"><col style="width:27%"><col style="width:15%"></colgroup>
        <tbody><?php foreach ($g['finalizados'] as $f) { echo $filaCierre($f); } ?></tbody>
      </table>
      </div>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
