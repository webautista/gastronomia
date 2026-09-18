<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos', 'ver', $base);
$puedeEditarEvento = can($usuarioActual, 'eventos', 'editar');
$puedeVerGastos = can($usuarioActual, 'gastos', 'ver');
$puedeCrearGasto = can($usuarioActual, 'gastos', 'crear');
$puedeEditarGasto = can($usuarioActual, 'gastos', 'editar');
$puedeEliminarGasto = can($usuarioActual, 'gastos', 'eliminar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$tabsValidos = ['resumen', 'estudiantes', 'recetas', 'compras'];
if ($puedeVerGastos) {
    $tabsValidos[] = 'gastos';
}
$tab = in_array($_GET['tab'] ?? '', $tabsValidos, true) ? $_GET['tab'] : 'resumen';

function cargarEvento(int $id): ?array
{
    $stmt = db()->prepare('SELECT ev.*, es.nombre AS estado FROM eventos ev JOIN estados_evento es ON es.id = ev.estado_id WHERE ev.id = ?');
    $stmt->execute([$id]);
    $ev = $stmt->fetch();
    return $ev ?: null;
}

$evento = cargarEvento($id);
if (!$evento) {
    flash('Ese evento ya no existe.', 'error');
    redirect('index.php');
}

/* ---------------- Acciones POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $pdo = db();

    if ($accion === 'registrar_pago') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        $monto = isset($_POST['monto_pagado']) ? (float) $_POST['monto_pagado'] : null;
        if ($estudianteId && $monto !== null && $monto >= 0) {
            $fechaPago = $monto > 0 ? date('Y-m-d') : null;
            $stmt = $pdo->prepare('UPDATE evento_estudiante SET monto_pagado=?, pagado=?, fecha_pago=? WHERE evento_id=? AND estudiante_id=?');
            $stmt->execute([$monto, $monto > 0 ? 1 : 0, $fechaPago, $id, $estudianteId]);
        }
    } elseif ($accion === 'quitar_estudiante') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if ($estudianteId) {
            $pdo->prepare('DELETE FROM evento_estudiante WHERE evento_id=? AND estudiante_id=?')->execute([$id, $estudianteId]);
        }
    } elseif ($accion === 'asignar_estudiantes') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $ids = array_map('intval', $_POST['estudiante_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO evento_estudiante (evento_id, estudiante_id, pagado) VALUES (?,?,0)');
        foreach ($ids as $eid) {
            if ($eid > 0) {
                $stmt->execute([$id, $eid]);
            }
        }
    } elseif ($accion === 'quitar_receta') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        if ($recetaId) {
            $pdo->prepare('DELETE FROM evento_receta WHERE evento_id=? AND receta_id=?')->execute([$id, $recetaId]);
        }
    } elseif ($accion === 'asignar_recetas') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $ids = array_map('intval', $_POST['receta_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO evento_receta (evento_id, receta_id, porciones_necesarias) VALUES (?,?,?)');
        foreach ($ids as $rid) {
            if ($rid > 0) {
                $stmt->execute([$id, $rid, $evento['porciones']]);
            }
        }
    } elseif ($accion === 'actualizar_porciones') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        $porciones = intOrNull($_POST['porciones_necesarias'] ?? null);
        if ($recetaId && $porciones && $porciones > 0) {
            $pdo->prepare('UPDATE evento_receta SET porciones_necesarias=? WHERE evento_id=? AND receta_id=?')
                ->execute([$porciones, $id, $recetaId]);
        }
    } elseif ($accion === 'quitar_gasto') {
        requirePermission($usuarioActual, 'gastos', 'eliminar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        if ($gastoId) {
            $stmtG = $pdo->prepare("SELECT estado FROM gastos WHERE id = ? AND evento_id = ? AND eliminado_en IS NULL");
            $stmtG->execute([$gastoId, $id]);
            $estadoGasto = $stmtG->fetchColumn();
            if ($estadoGasto === 'pagado' && ($usuarioActual['rol_nombre'] ?? '') !== 'Administrador') {
                flash('Un gasto ya pagado solo puede eliminarlo un Administrador.', 'error');
            } elseif ($estadoGasto) {
                // Soft delete: se marca eliminado_en en vez de borrar la fila,
                // para conservar el historial completo con fines de auditoría
                // (sobre todo de los gastos que ya se pagaron).
                $pdo->prepare('UPDATE gastos SET eliminado_en = NOW() WHERE id = ?')->execute([$gastoId]);
                flash('Gasto eliminado.');
            }
        }
    } elseif ($accion === 'confirmar_gasto') {
        requirePermission($usuarioActual, 'gastos', 'editar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        $montoConfirmado = isset($_POST['monto_confirmado']) ? (float) $_POST['monto_confirmado'] : 0;
        if ($gastoId && $montoConfirmado > 0) {
            $pdo->prepare("UPDATE gastos SET estado = 'confirmado', monto_confirmado = ? WHERE id = ? AND evento_id = ? AND estado = 'proyectado'")
                ->execute([$montoConfirmado, $gastoId, $id]);
            flash('Gasto confirmado.');
        }
    } elseif ($accion === 'guardar_decision_compra') {
        requirePermission($usuarioActual, 'eventos', 'editar', $base);
        $catalogoId = intOrNull($_POST['catalogo_id'] ?? null);
        $comprarPaquete = !empty($_POST['comprar_paquete']) ? 1 : 0;
        $precioPaquete = isset($_POST['precio_paquete']) && $_POST['precio_paquete'] !== '' ? (float) $_POST['precio_paquete'] : null;
        if ($catalogoId && $precioPaquete !== null && $precioPaquete >= 0) {
            $pdo->prepare(
                'INSERT INTO compra_decisiones (entidad_tipo, entidad_id, ingrediente_catalogo_id, comprar_paquete, precio_paquete)
                 VALUES (\'evento\', ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE comprar_paquete = VALUES(comprar_paquete), precio_paquete = VALUES(precio_paquete)'
            )->execute([$id, $catalogoId, $comprarPaquete, $precioPaquete]);
        }
    }

    redirect('detalle.php?id=' . $id . '&tab=' . $tab);
}

/* ---------------- Datos para mostrar ---------------- */
$stmt = db()->prepare(
    'SELECT ee.pagado, ee.monto_pagado, ee.fecha_pago, est.*, ge.nombre AS grupo FROM evento_estudiante ee
     JOIN estudiantes est ON est.id = ee.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = est.grupo_id
     WHERE ee.evento_id = ? ORDER BY est.nombre ASC'
);
$stmt->execute([$id]);
$estudiantesEvento = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT er.porciones_necesarias, r.*, cr.nombre AS categoria FROM evento_receta er
     JOIN recetas r ON r.id = er.receta_id
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE er.evento_id = ? ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$recetasEvento = $stmt->fetchAll();

// El costo de ingredientes de cada receta se calcula una sola vez aquí
// (no dentro de la pestaña "Recetas"), para mostrar el chip de costo de
// cada tarjeta de receta.
foreach ($recetasEvento as &$rc) {
    $porcionesBase = max(1, (int) $rc['porciones_base']);
    $stmtIng = db()->prepare(
        'SELECT i.*, um.abreviatura AS unidad, um.es_entera AS unidad_entera FROM ingredientes i
         JOIN unidades_medida um ON um.id = i.unidad_id
         WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
    );
    $stmtIng->execute([$rc['id']]);
    $rc['ingredientes'] = $stmtIng->fetchAll();
    $rc['costo_total'] = 0;
    foreach ($rc['ingredientes'] as $ing) {
        // "Al gusto": no hay cantidad medible, así que no entra en el costo
        // (igual que costoTotalReceta() en includes/helpers.php).
        if (!empty($ing['al_gusto'])) {
            continue;
        }
        $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
        $esEntera = (bool) ($ing['unidad_entera'] ?? false);
        $rc['costo_total'] += montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
    }
}
unset($rc);

// El costo que alimenta la tarjeta "Inversión" y la cuota por estudiante
// NO es la simple suma de los chips de arriba (cada uno calculado receta
// por receta, sin verlas juntas): es el mismo total consolidado y
// consciente de las decisiones de compra que ya se muestra en la pestaña
// Lista de Compra (costoRecetasConsolidado(), includes/helpers.php) — para
// que la tarjeta de Inversión nunca diga un número distinto al que dice
// esa pestaña.
$costoRecetasEvento = costoRecetasConsolidado(db(), 'evento', $id);

// Acciones/cortes marcados por línea (ver.php muestra lo mismo), para
// mostrarlos junto al nombre del ingrediente en la pestaña "Recetas".
$accionesPorFila = [];
$idsFilasTodas = [];
foreach ($recetasEvento as $rc) {
    foreach ($rc['ingredientes'] as $ing) {
        $idsFilasTodas[] = (int) $ing['id'];
    }
}
if ($idsFilasTodas) {
    $in = implode(',', array_fill(0, count($idsFilasTodas), '?'));
    $stmtAcc = db()->prepare(
        "SELECT ia.receta_ingrediente_id, ac.nombre FROM ingrediente_accion ia
         JOIN acciones_ingrediente ac ON ac.id = ia.accion_id
         WHERE ia.receta_ingrediente_id IN ($in) ORDER BY ac.orden ASC, ac.nombre ASC"
    );
    $stmtAcc->execute($idsFilasTodas);
    foreach ($stmtAcc->fetchAll() as $fa) {
        $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = $fa['nombre'];
    }
}

// Resumen de gastos del evento en cuatro baldes (material vs. otros ×
// proyectado vs. usado): un gasto solo proyectado NUNCA cuenta como
// "usado" contra el presupuesto (la corrección al bug reportado de
// "presupuesto usado" mostrando dinero que todavía no se había
// confirmado). Los renglones detallados (categoría, proveedor,
// descripción) solo se cargan si el rol puede ver el módulo de gastos.
$resumenGastos = resumenGastosVinculo(db(), 'evento_id', $id);
$materialUsado = $resumenGastos['material_usado'];
$otrosUsado = $resumenGastos['otros_usado'];
$totalProyectado = $resumenGastos['material_proyectado'] + $resumenGastos['otros_proyectado'];
$gastado = $materialUsado + $otrosUsado;

$gastosEvento = [];
if ($puedeVerGastos) {
    $stmt = db()->prepare(
        "SELECT g.*, cg.nombre AS categoria FROM gastos g
         JOIN categorias_gasto cg ON cg.id = g.categoria_id
         WHERE g.evento_id = ? AND g.eliminado_en IS NULL
         ORDER BY FIELD(g.estado,'proyectado','confirmado','pagado'), g.fecha DESC, g.id DESC"
    );
    $stmt->execute([$id]);
    $gastosEvento = $stmt->fetchAll();
}

// La cuota ya no se escribe a mano: siempre es el costo de las recetas (o
// el gasto real en materiales, el que sea mayor) más los "otros" gastos
// del evento, dividido entre los estudiantes asignados. "Proyectada" es la
// estimación más completa (incluye lo aún no confirmado); "confirmada" es
// solo lo que ya es gasto real, y es la que se usa para cobrarle a cada
// estudiante.
$cantidadEstudiantes = count($estudiantesEvento);
$cuotas = calcularCuotas($costoRecetasEvento, $resumenGastos, $cantidadEstudiantes);
$cuotaProyectada = $cuotas['proyectada'];
$cuotaConfirmada = $cuotas['confirmada'];
$totalConfirmadoConRecetas = $cuotas['total_confirmado'];

// Cuánto necesitará el evento en total, calculado como referencia (incluye
// todo lo proyectado todavía sin confirmar, de cualquier balde).
$totalProyeccionInversion = $cuotas['total_proyeccion'];

// "Pagado" queda como referencia histórica; lo que de verdad importa ahora
// es comparar lo que cada quien ya pagó (monto_pagado) contra la cuota
// confirmada VIGENTE — así, si la cuota confirmada sube porque se
// confirmó un gasto nuevo, el sistema muestra sola el complemento
// pendiente sin tener que volver a marcar a nadie como pendiente.
$recaudado = array_sum(array_column($estudiantesEvento, 'monto_pagado'));
$numPagados = count(array_filter($estudiantesEvento, fn($a) => (float) $a['monto_pagado'] >= $cuotaConfirmada - 0.005));
$pctPago = $totalConfirmadoConRecetas > 0 ? round($recaudado / $totalConfirmadoConRecetas * 100) : 0;

$pageTitle = $evento['nombre'];
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <b>' . e($evento['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<?php if (!empty($evento['banner'])): ?>
  <img class="event-banner-hero" src="<?= e($base . '/' . $evento['banner']) ?>" alt="Banner de <?= e($evento['nombre']) ?>">
<?php endif; ?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($evento['nombre']) ?></h1>
    <div class="event-meta" style="margin-top:6px;">
      <span><?= icon('calendar') ?> <?= fmtDate($evento['fecha']) ?></span>
      <span><?= icon('pin') ?> <?= e($evento['lugar']) ?></span>
      <span class="chip <?= chipEstadoClase($evento['estado']) ?>"><?= e($evento['estado']) ?></span>
      <?php if ($puedeVerGastos): ?>
        <?php if (!empty($evento['cuota_publica'])): ?>
          <span class="chip chip-success" title="La cuota de este evento se muestra en la página pública"><?= icon('users') ?> Cuota pública</span>
        <?php else: ?>
          <span class="chip chip-muted" title="La cuota de este evento no se muestra en la página pública — ahí aparece como &quot;Por confirmar&quot;"><?= icon('lock') ?> Cuota no pública</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($puedeEditarEvento): ?>
    <a class="btn btn-secondary" href="form.php?id=<?= (int) $evento['id'] ?>"><?= icon('edit') ?> Editar</a>
  <?php endif; ?>
</div>

<div class="summary-grid">
  <div class="card card-pad">
    <div class="stat-label">Inversión del evento</div>
    <div class="stat-value"><?= money($totalProyeccionInversion) ?></div>
    <?php if ($puedeVerGastos): ?>
      <div class="stat-hint" style="margin-top:8px;">Proyectado en recetas: <b class="mono"><?= money($costoRecetasEvento) ?></b> · Gastado en materiales: <b class="mono"><?= money($materialUsado) ?></b> <?php if ($otrosUsado > 0 || $totalProyectado > 0): ?>· Otros gastos: <b class="mono"><?= money($otrosUsado) ?></b> (+ <?= money($totalProyectado) ?> proyectado)<?php endif; ?></div>
      <?php if ($cuotas['material_excedido']): ?>
        <div class="alert alert-error" style="margin-top:10px;padding:10px 12px;font-size:.85rem;">
          <?= icon('alertTriangle') ?> El gasto en materiales (<?= money($materialUsado) ?>) superó lo proyectado en recetas (<?= money($costoRecetasEvento) ?>) por <b><?= money($cuotas['material_exceso']) ?></b>. Puede que haga falta ajustar la cuota o buscar más fondos.
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="stat-hint">Costo estimado de recetas + gastos del evento.</div>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Cuota y recaudo</div>
    <div class="meter-row" style="margin-top:8px;"><span class="mono"><?= money($recaudado) ?> recaudado</span><span><?= (int) $pctPago ?>%</span></div>
    <div class="meter <?= meterClase($pctPago) ?>"><span style="width:<?= min($pctPago, 100) ?>%"></span></div>
    <?php if ($cantidadEstudiantes > 0): ?>
      <div class="stat-hint" style="margin-top:8px;">Cuota confirmada: <b class="mono"><?= money($cuotaConfirmada) ?></b> · Cuota proyectada: <b class="mono"><?= money($cuotaProyectada) ?></b> por estudiante</div>
    <?php else: ?>
      <div class="stat-hint" style="margin-top:8px;">Asigna estudiantes al evento para calcular la cuota.</div>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Porciones a preparar</div>
    <div class="stat-value"><?= (int) $evento['porciones'] ?></div>
    <div class="stat-hint"><?= count($recetasEvento) ?> receta<?= count($recetasEvento) === 1 ? '' : 's' ?> asignada<?= count($recetasEvento) === 1 ? '' : 's' ?></div>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $tab === 'resumen' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=resumen">Resumen</a>
  <a class="tab <?= $tab === 'estudiantes' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Estudiantes y pagos</a>
  <a class="tab <?= $tab === 'recetas' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=recetas">Recetas e ingredientes</a>
  <a class="tab <?= $tab === 'compras' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=compras"><?= icon('clipboardList') ?> Lista de Compra</a>
  <?php if ($puedeVerGastos): ?>
    <a class="tab <?= $tab === 'gastos' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=gastos">Gastos</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'resumen'):
  $catTotales = [];
  foreach ($gastosEvento as $g) {
      $catTotales[$g['categoria']] = ($catTotales[$g['categoria']] ?? 0) + montoEfectivoGasto($g);
  }
  $maxCat = max(1, ...(array_values($catTotales) ?: [1]));
  $pendientes = count($estudiantesEvento) - $numPagados;
  $maxPay = max(1, $numPagados, $pendientes);
?>
  <div class="summary-grid" style="grid-template-columns:<?= $puedeVerGastos ? '1fr 1fr' : '1fr' ?>;">
    <?php if ($puedeVerGastos): ?>
    <div class="card card-pad">
      <h2 class="section-title">Gastos por categoría</h2>
      <?php if (!$catTotales): ?>
        <div class="cell-muted">Aún no hay gastos registrados.</div>
      <?php else: ?>
        <div class="bar-list">
          <?php foreach ($catTotales as $cat => $val): ?>
            <div class="bar-list-row">
              <span class="cell-muted"><?= e($cat) ?></span>
              <div class="bar-track"><div class="bar-fill" style="width:<?= round($val / $maxCat * 100) ?>%"></div></div>
              <span class="mono" style="text-align:right;"><?= money($val) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card card-pad">
      <h2 class="section-title">Estado de pagos</h2>
      <div class="bar-list">
        <div class="bar-list-row"><span class="cell-muted">Pagado</span><div class="bar-track"><div class="bar-fill alt" style="width:<?= round($numPagados / $maxPay * 100) ?>%"></div></div><span class="mono" style="text-align:right;"><?= $numPagados ?></span></div>
        <div class="bar-list-row"><span class="cell-muted">Pendiente</span><div class="bar-track"><div class="bar-fill" style="width:<?= round($pendientes / $maxPay * 100) ?>%"></div></div><span class="mono" style="text-align:right;"><?= $pendientes ?></span></div>
      </div>
      <div class="stat-hint" style="margin-top:14px;"><?= count($estudiantesEvento) ?> estudiante<?= count($estudiantesEvento) === 1 ? '' : 's' ?> asignado<?= count($estudiantesEvento) === 1 ? '' : 's' ?> a este evento.</div>
    </div>
  </div>

<?php elseif ($tab === 'estudiantes'): ?>
  <div class="toolbar">
    <div class="cell-muted">
      <?= $numPagados ?> de <?= count($estudiantesEvento) ?> estudiantes al día · <span class="mono"><?= money($recaudado) ?></span> de <span class="mono"><?= money($totalConfirmadoConRecetas) ?></span> recaudado
      <?php if ($cantidadEstudiantes > 0): ?>
        · cuota confirmada: <span class="mono"><?= money($cuotaConfirmada) ?></span> c/u
      <?php endif; ?>
    </div>
    <?php if ($puedeEditarEvento): ?>
      <a class="btn btn-secondary btn-sm" href="asignar_estudiante.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar estudiante</a>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Grupo</th><th>Teléfono</th><th>Pagado</th><th>Pendiente</th><th></th></tr></thead>
      <tbody>
        <?php if (!$estudiantesEvento): ?>
          <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay estudiantes asignados a este evento.</td></tr>
        <?php endif; ?>
        <?php foreach ($estudiantesEvento as $a):
          $montoPagado = (float) $a['monto_pagado'];
          $pendienteEstudiante = max(0, $cuotaConfirmada - $montoPagado);
          $alDia = $pendienteEstudiante <= 0.005;
        ?>
          <tr>
            <td class="cell-name"><?= e($a['nombre']) ?></td>
            <td class="cell-muted"><?= e($a['grupo']) ?></td>
            <td class="cell-muted mono"><?= e($a['telefono']) ?></td>
            <td>
              <?php if ($puedeEditarEvento): ?>
                <form method="post" style="display:flex;align-items:center;gap:6px;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="registrar_pago">
                  <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                  <input type="number" name="monto_pagado" min="0" step="0.01" value="<?= e((string) $montoPagado) ?>" style="width:110px;">
                  <button class="btn btn-secondary btn-sm" type="submit">Guardar</button>
                </form>
              <?php else: ?>
                <span class="mono"><?= money($montoPagado) ?></span>
              <?php endif; ?>
              <?php if ($montoPagado > 0 && $a['fecha_pago']): ?>
                <div class="stat-hint" style="margin-top:4px;">Último pago: <?= fmtDate($a['fecha_pago']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($alDia): ?>
                <span class="chip chip-success"><?= icon('check') ?> Al día</span>
              <?php else: ?>
                <span class="chip chip-warning"><?= money($pendienteEstudiante) ?></span>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if ($puedeEditarEvento): ?>
                <?php if (!$alDia): ?>
                  <form method="post" data-confirm="¿Registrar el pago completo de la cuota confirmada (<?= e(money($cuotaConfirmada)) ?>) para &quot;<?= e($a['nombre']) ?>&quot;?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="accion" value="registrar_pago">
                    <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="monto_pagado" value="<?= e((string) $cuotaConfirmada) ?>">
                    <button class="btn btn-primary btn-sm" type="submit">Pagar cuota completa</button>
                  </form>
                <?php endif; ?>
                <form method="post" data-confirm="¿Quitar a &quot;<?= e($a['nombre']) ?>&quot; de este evento?">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="quitar_estudiante">
                  <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                  <button class="icon-btn" type="submit" title="Quitar del evento"><?= icon('x') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'recetas'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Las cantidades se recalculan según las porciones que necesitas preparar.</div>
    <div class="row-actions">
      <?php if (count($recetasEvento) > 1): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-role="toggle-todas-recetas" data-contenedor="lista-recetas-evento">Colapsar todo</button>
      <?php endif; ?>
      <?php if ($puedeEditarEvento): ?>
        <a class="btn btn-secondary btn-sm" href="asignar_receta.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar receta</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$recetasEvento): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
      <div>Agrega recetas del catálogo para calcular los ingredientes.</div>
    </div></div>
  <?php endif; ?>
  <div data-role="lista-recetas-evento">
  <?php foreach ($recetasEvento as $rc):
    $porcionesBase = max(1, (int) $rc['porciones_base']);
    $ingredientesReceta = $rc['ingredientes'];
    $costoTotal = $rc['costo_total'];
  ?>
    <div class="recipe-card" data-recipe-card data-porciones-base="<?= $porcionesBase ?>">
      <div class="recipe-card-head">
        <div style="display:flex;align-items:center;gap:8px;">
          <button class="icon-btn no-print" type="button" data-role="recipe-collapse-toggle" title="Colapsar/expandir"><?= icon('chevronDown') ?></button>
          <div>
            <h4><?= e($rc['nombre']) ?></h4>
            <div class="cell-muted"><?= e($rc['categoria']) ?> · base <?= $porcionesBase ?> porciones · <span class="mono" data-role="costo-total-badge"><?= money($costoTotal) ?></span></div>
          </div>
        </div>
        <?php if ($puedeEditarEvento): ?>
          <form method="post" class="no-print" style="display:flex;align-items:center;gap:14px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="actualizar_porciones">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <div class="portion-control">
              <span>Porciones a preparar</span>
              <input type="number" min="1" name="porciones_necesarias" value="<?= (int) $rc['porciones_necesarias'] ?>" data-role="porciones-input">
            </div>
            <button class="btn btn-secondary btn-sm" type="submit">Actualizar</button>
          </form>
          <form method="post" class="no-print" data-confirm="¿Quitar la receta &quot;<?= e($rc['nombre']) ?>&quot; de este evento?">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="quitar_receta">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <button class="icon-btn" type="submit" title="Quitar receta"><?= icon('trash') ?></button>
          </form>
        <?php else: ?>
          <div class="stat-hint"><?= (int) $rc['porciones_necesarias'] ?> porciones a preparar</div>
        <?php endif; ?>
      </div>
      <div class="recipe-card-body">
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Ingrediente</th><th>Cantidad base</th><th>Cantidad necesaria</th><th>Costo est.</th></tr></thead>
        <tbody>
          <?php foreach ($ingredientesReceta as $ing):
            $esAlGusto = !empty($ing['al_gusto']);
            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
            $esEntera = (bool) ($ing['unidad_entera'] ?? false);
            $costo = montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
          ?>
            <tr>
              <td class="cell-name">
                <?= e($ing['nombre']) ?>
                <?php if (!empty($ing['opcional'])): ?> <span class="chip chip-muted" style="font-size:.68rem;">Opcional</span><?php endif; ?>
                <?php if (!empty($ing['reemplazo'])): ?><div class="cell-muted" style="font-size:.78rem;">o <?= e($ing['reemplazo']) ?></div><?php endif; ?>
                <?php if (!empty($accionesPorFila[$ing['id']])): ?><div class="cell-muted" style="font-size:.78rem;"><?= e(implode(', ', $accionesPorFila[$ing['id']])) ?></div><?php endif; ?>
              </td>
              <td class="cell-muted mono"><?= $esAlGusto ? 'Al gusto' : numFmt($ing['cantidad']) . ' ' . e($ing['unidad']) . fraccionSufijo((float) $ing['cantidad']) ?></td>
              <td class="mono" data-role="cant" data-base="<?= e((string) $ing['cantidad']) ?>" data-unidad="<?= e($ing['unidad']) ?>" data-entera="<?= $esEntera ? '1' : '0' ?>" data-al-gusto="<?= $esAlGusto ? '1' : '0' ?>"><?= $esAlGusto ? 'Al gusto' : numFmt($cantidad) . ' ' . e($ing['unidad']) . fraccionSufijo($cantidad) ?></td>
              <td class="mono" data-role="costo" data-costo="<?= e((string) $ing['costo_unitario']) ?>"><?= $esAlGusto ? '—' : money($costo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3" style="text-align:right;font-weight:600;">Costo estimado de ingredientes</td><td class="mono" style="font-weight:600;" data-role="costo-total"><?= money($costoTotal) ?></td></tr>
        </tfoot>
      </table>
      </div>
      <?php if (trim((string) ($rc['preparacion'] ?? '')) !== ''): ?>
        <details class="prep-details">
          <summary><?= icon('book') ?> Ver preparación</summary>
          <div class="prep-text"><?= e($rc['preparacion']) ?></div>
        </details>
      <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'compras'):
  $recetasParaLista = array_map(fn($rc) => ['receta_id' => $rc['id'], 'porciones_necesarias' => $rc['porciones_necesarias']], $recetasEvento);
  $decisionesCompra = cargarDecisionesCompra(db(), 'evento', $id);
  $consolidado = listaCompraConsolidada(db(), $recetasParaLista, $decisionesCompra);
?>
  <div class="toolbar no-print">
    <div class="cell-muted">Ingredientes de todas las recetas de este evento, sumados y organizados para ir al súper.</div>
    <div class="row-actions">
      <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= icon('printer') ?> Imprimir</button>
      <a class="btn btn-secondary btn-sm" href="lista_compra_txt.php?id=<?= $id ?>"><?= icon('download') ?> Descargar (.txt)</a>
    </div>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ingrediente</th><th>Cantidad</th><th>Recetas</th><th>Costo est.</th></tr></thead>
      <tbody>
        <?php if (!$consolidado['lineas'] && !$consolidado['al_gusto']): ?>
          <tr><td colspan="4" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay recetas asignadas a este evento.</td></tr>
        <?php endif; ?>
        <?php foreach ($consolidado['lineas'] as $l): ?>
          <tr>
            <td class="cell-name"><?= e($l['nombre']) ?></td>
            <td class="mono">
              <?= numFmt($l['cantidad']) ?> <?= e($l['unidad']) ?>
              <?php if (!empty($l['compra'])): $dc = $l['compra_decision']; ?>
                <div class="cell-muted" style="font-size:.78rem;font-weight:400;margin-top:4px;">
                  <?php if ($dc['comprar_paquete']): ?>
                    comprar ≈ <?= numFmt($l['compra']['cantidad']) ?> <?= e($l['compra']['unidad']) ?>
                  <?php else: ?>
                    <span style="text-decoration:line-through;">comprar ≈ <?= numFmt($l['compra']['cantidad']) ?> <?= e($l['compra']['unidad']) ?></span> · ya lo tienes
                  <?php endif; ?>
                </div>
                <?php if ($puedeEditarEvento): ?>
                <form method="post" class="no-print" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-weight:400;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="guardar_decision_compra">
                  <input type="hidden" name="catalogo_id" value="<?= (int) $l['catalogo_id'] ?>">
                  <label style="font-size:.72rem;display:flex;align-items:center;gap:4px;" class="cell-muted">
                    <input type="checkbox" name="comprar_paquete" value="1" <?= $dc['comprar_paquete'] ? 'checked' : '' ?>> Comprar
                  </label>
                  <span class="cell-muted" style="font-size:.72rem;">RD$</span>
                  <input type="number" name="precio_paquete" min="0" step="0.01" value="<?= e((string) $dc['precio_paquete']) ?>" style="width:74px;font-size:.78rem;" title="Precio del paquete (editable)">
                  <button class="btn btn-secondary btn-sm" type="submit" style="font-size:.72rem;padding:2px 8px;">Guardar</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $l['recetas'])) ?></td>
            <td class="mono">
              <?= money($l['monto']) ?>
              <?php if (!empty($l['compra_decision']) && !$l['compra_decision']['comprar_paquete']): ?>
                <div class="cell-muted" style="font-size:.72rem;font-weight:400;">ya lo tienes</div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($consolidado['al_gusto'] as $ag): ?>
          <tr>
            <td class="cell-name"><?= e($ag['nombre']) ?> <span class="chip chip-muted" style="font-size:.68rem;">Al gusto</span></td>
            <td class="cell-muted mono">—</td>
            <td class="cell-muted" style="font-size:.82rem;"><?= e(implode(', ', $ag['recetas'])) ?></td>
            <td class="cell-muted mono">—</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($consolidado['lineas']): ?>
      <tfoot>
        <tr><td colspan="3" style="text-align:right;font-weight:600;">Costo estimado total</td><td class="mono" style="font-weight:600;"><?= money($consolidado['total']) ?></td></tr>
      </tfoot>
      <?php endif; ?>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'gastos'): $esAdmin = ($usuarioActual['rol_nombre'] ?? '') === 'Administrador'; ?>
  <div class="card card-pad" style="margin-bottom:16px;">
    <div class="stat-hint">
      Proyectado en recetas: <b class="mono"><?= money($costoRecetasEvento) ?></b>
      &nbsp;·&nbsp; Gastado en materiales: <b class="mono"><?= money($materialUsado) ?></b>
      &nbsp;·&nbsp; Otros gastos: <b class="mono"><?= money($otrosUsado) ?></b> (+ <?= money($totalProyectado) ?> proyectado sin confirmar)
      &nbsp;=&nbsp; Necesitarían en total ≈ <b class="mono"><?= money($totalProyeccionInversion) ?></b>
    </div>
    <?php if ($cuotas['material_excedido']): ?>
      <div class="alert alert-error" style="margin-top:10px;padding:10px 12px;font-size:.85rem;">
        <?= icon('alertTriangle') ?> El gasto en materiales superó lo proyectado en recetas por <b><?= money($cuotas['material_exceso']) ?></b>. Puede que haga falta ajustar la cuota o buscar más fondos.
      </div>
    <?php endif; ?>
  </div>
  <div class="toolbar">
    <div class="cell-muted"><?= count($gastosEvento) ?> partida<?= count($gastosEvento) === 1 ? '' : 's' ?> registrada<?= count($gastosEvento) === 1 ? '' : 's' ?></div>
    <?php if ($puedeCrearGasto): ?>
      <a class="btn btn-secondary btn-sm" href="gasto_form.php?evento_id=<?= $id ?>"><?= icon('plus') ?> Agregar partida</a>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Estado</th><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>Monto</th><th></th></tr></thead>
      <tbody>
        <?php if (!$gastosEvento): ?>
          <tr><td colspan="7" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay gastos ni partidas proyectadas.</td></tr>
        <?php endif; ?>
        <?php foreach ($gastosEvento as $g): ?>
          <tr>
            <td>
              <?php if ($g['estado'] === 'proyectado'): ?>
                <span class="chip chip-warning">Proyectado</span>
              <?php elseif ($g['estado'] === 'confirmado'): ?>
                <span class="chip chip-neutral">Confirmado</span>
              <?php else: ?>
                <span class="chip chip-success">Pagado</span>
              <?php endif; ?>
              <?php if (!empty($g['es_material_receta'])): ?><div class="cell-muted" style="font-size:.72rem;margin-top:2px;">Material de receta</div><?php endif; ?>
            </td>
            <td class="cell-muted">
              <?= fmtDate($g['fecha']) ?>
              <?php if ($g['estado'] === 'pagado' && $g['fecha_pago']): ?><div style="font-size:.72rem;">Pagado: <?= fmtDate($g['fecha_pago']) ?></div><?php endif; ?>
            </td>
            <td><span class="chip chip-neutral"><?= e($g['categoria']) ?></span></td>
            <td><?= e($g['descripcion']) ?></td>
            <td class="cell-muted"><?= e($g['proveedor']) ?></td>
            <td class="mono">
              <?= money(montoEfectivoGasto($g)) ?>
              <?php if ($g['estado'] === 'pagado' && !empty($g['factura'])): ?>
                <div><a href="<?= e($base . '/' . $g['factura']) ?>" target="_blank" rel="noopener" class="cell-muted" style="font-size:.78rem;"><?= icon('receipt') ?> Ver factura</a></div>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if ($g['estado'] === 'proyectado' && $puedeEditarGasto): ?>
              <form method="post" data-confirm="¿Confirmar esta partida por el monto indicado?" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="confirmar_gasto">
                <input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                <input type="number" name="monto_confirmado" min="0.01" step="0.01" value="<?= e((string) $g['monto']) ?>" style="width:100px;" title="Monto confirmado">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Confirmar</button>
              </form>
              <?php endif; ?>
              <?php if ($g['estado'] === 'confirmado' && $puedeEditarGasto): ?>
                <a class="btn btn-primary btn-sm" href="gasto_pagar.php?id=<?= (int) $g['id'] ?>"><?= icon('receipt') ?> Marcar pagado</a>
              <?php endif; ?>
              <?php if ($puedeEliminarGasto && ($g['estado'] !== 'pagado' || $esAdmin)): ?>
              <form method="post" data-confirm="<?= $g['estado'] === 'pagado' ? '¿Eliminar este gasto ya pagado? Es una acción de auditoría, solo un Administrador puede hacerla.' : '¿Eliminar esta partida?' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="accion" value="quitar_gasto">
                <input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                <button class="icon-btn" type="submit"><?= icon('trash') ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
