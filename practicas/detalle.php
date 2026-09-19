<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'practicas', 'ver', $base);
$puedeEditar = can($usuarioActual, 'practicas', 'editar');

// Permisos independientes por pestaña (igual que en Eventos, ver
// eventos/detalle.php) — reemplaza la regla vieja de "solo ver+editar
// practicas ve Recetas/Lista de Compra/Gastos, solo ver entra a
// Estudiantes y pagos nada más", que estaba escrita directo aquí. Ahora
// cada pestaña se muestra u oculta según su propio módulo de permiso.
$puedeVerEstudiantesTab = can($usuarioActual, 'practicas_estudiantes', 'ver');
$puedeCrearEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'crear');
$puedeEditarEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'editar');
$puedeEliminarEstudianteTab = can($usuarioActual, 'practicas_estudiantes', 'eliminar');
$puedeVerRecetasTab = can($usuarioActual, 'practicas_recetas', 'ver');
$puedeCrearRecetaTab = can($usuarioActual, 'practicas_recetas', 'crear');
$puedeEditarRecetaTab = can($usuarioActual, 'practicas_recetas', 'editar');
$puedeEliminarRecetaTab = can($usuarioActual, 'practicas_recetas', 'eliminar');
$puedeVerCompras = can($usuarioActual, 'practicas_lista_compra', 'ver');
$puedeEditarCompras = can($usuarioActual, 'practicas_lista_compra', 'editar');
$puedeVerGastos = can($usuarioActual, 'practicas_gastos', 'ver');
$puedeCrearGasto = can($usuarioActual, 'practicas_gastos', 'crear');
$puedeEditarGasto = can($usuarioActual, 'practicas_gastos', 'editar');
$puedeEliminarGasto = can($usuarioActual, 'practicas_gastos', 'eliminar');

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

// A diferencia de Eventos, una Práctica no tiene una pestaña "Resumen"
// siempre visible — si el rol no tiene "ver" en ninguna de las cuatro, no
// hay ninguna pestaña que mostrar (ver el aviso más abajo, junto a "tabs").
$tabsValidos = [];
if ($puedeVerEstudiantesTab) {
    $tabsValidos[] = 'estudiantes';
}
if ($puedeVerRecetasTab) {
    $tabsValidos[] = 'recetas';
}
if ($puedeVerCompras) {
    $tabsValidos[] = 'compras';
}
if ($puedeVerGastos) {
    $tabsValidos[] = 'gastos';
}
$tabPorDefecto = $tabsValidos[0] ?? '';
$tab = in_array($_GET['tab'] ?? '', $tabsValidos, true) ? $_GET['tab'] : $tabPorDefecto;

$stmt = db()->prepare('SELECT * FROM practicas WHERE id = ?');
$stmt->execute([$id]);
$practica = $stmt->fetch();
if (!$practica) {
    flash('Esa práctica ya no existe.', 'error');
    redirect('index.php');
}

/* ---------------- Acciones POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $pdo = db();

    if ($accion === 'quitar_receta') {
        requirePermission($usuarioActual, 'practicas_recetas', 'eliminar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        if ($recetaId) {
            $pdo->prepare('DELETE FROM practica_receta WHERE practica_id=? AND receta_id=?')->execute([$id, $recetaId]);
        }
    } elseif ($accion === 'asignar_recetas') {
        requirePermission($usuarioActual, 'practicas_recetas', 'crear', $base);
        $ids = array_map('intval', $_POST['receta_ids'] ?? []);
        $stmtPorciones = $pdo->prepare('SELECT porciones_base FROM recetas WHERE id = ?');
        $stmt = $pdo->prepare('INSERT IGNORE INTO practica_receta (practica_id, receta_id, porciones_necesarias) VALUES (?,?,?)');
        foreach ($ids as $rid) {
            if ($rid > 0) {
                $stmtPorciones->execute([$rid]);
                $porcionesBase = max(1, (int) $stmtPorciones->fetchColumn());
                $stmt->execute([$id, $rid, $porcionesBase]);
            }
        }
    } elseif ($accion === 'actualizar_porciones') {
        requirePermission($usuarioActual, 'practicas_recetas', 'editar', $base);
        $recetaId = intOrNull($_POST['receta_id'] ?? null);
        $porciones = intOrNull($_POST['porciones_necesarias'] ?? null);
        if ($recetaId && $porciones && $porciones > 0) {
            $pdo->prepare('UPDATE practica_receta SET porciones_necesarias=? WHERE practica_id=? AND receta_id=?')
                ->execute([$porciones, $id, $recetaId]);
        }
    } elseif ($accion === 'registrar_pago') {
        requirePermission($usuarioActual, 'practicas_estudiantes', 'editar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        $monto = isset($_POST['monto_pagado']) ? (float) $_POST['monto_pagado'] : null;
        if ($estudianteId && $monto !== null && $monto >= 0) {
            $fechaPago = $monto > 0 ? date('Y-m-d') : null;
            $pdo->prepare('UPDATE practica_estudiante SET monto_pagado=?, fecha_pago=? WHERE practica_id=? AND estudiante_id=?')
                ->execute([$monto, $fechaPago, $id, $estudianteId]);
        }
    } elseif ($accion === 'quitar_estudiante') {
        requirePermission($usuarioActual, 'practicas_estudiantes', 'eliminar', $base);
        $estudianteId = intOrNull($_POST['estudiante_id'] ?? null);
        if ($estudianteId) {
            $pdo->prepare('DELETE FROM practica_estudiante WHERE practica_id=? AND estudiante_id=?')->execute([$id, $estudianteId]);
        }
    } elseif ($accion === 'asignar_estudiantes') {
        requirePermission($usuarioActual, 'practicas_estudiantes', 'crear', $base);
        $ids = array_map('intval', $_POST['estudiante_ids'] ?? []);
        $stmt = $pdo->prepare('INSERT IGNORE INTO practica_estudiante (practica_id, estudiante_id) VALUES (?,?)');
        foreach ($ids as $eid) {
            if ($eid > 0) {
                $stmt->execute([$id, $eid]);
            }
        }
    } elseif ($accion === 'quitar_gasto') {
        requirePermission($usuarioActual, 'practicas_gastos', 'eliminar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        if ($gastoId) {
            $stmtG = $pdo->prepare("SELECT estado FROM gastos WHERE id = ? AND practica_id = ? AND eliminado_en IS NULL");
            $stmtG->execute([$gastoId, $id]);
            $estadoGasto = $stmtG->fetchColumn();
            if ($estadoGasto === 'pagado' && ($usuarioActual['rol_nombre'] ?? '') !== 'Administrador') {
                flash('Un gasto ya pagado solo puede eliminarlo un Administrador.', 'error');
            } elseif ($estadoGasto) {
                $pdo->prepare('UPDATE gastos SET eliminado_en = NOW() WHERE id = ?')->execute([$gastoId]);
                flash('Gasto eliminado.');
            }
        }
    } elseif ($accion === 'confirmar_gasto') {
        requirePermission($usuarioActual, 'practicas_gastos', 'editar', $base);
        $gastoId = intOrNull($_POST['gasto_id'] ?? null);
        $montoConfirmado = isset($_POST['monto_confirmado']) ? (float) $_POST['monto_confirmado'] : 0;
        if ($gastoId && $montoConfirmado > 0) {
            $pdo->prepare("UPDATE gastos SET estado = 'confirmado', monto_confirmado = ? WHERE id = ? AND practica_id = ? AND estado = 'proyectado'")
                ->execute([$montoConfirmado, $gastoId, $id]);
            flash('Gasto confirmado.');
        }
    } elseif ($accion === 'guardar_decision_compra') {
        requirePermission($usuarioActual, 'practicas_lista_compra', 'editar', $base);
        $catalogoId = intOrNull($_POST['catalogo_id'] ?? null);
        $comprarPaquete = !empty($_POST['comprar_paquete']) ? 1 : 0;
        $precioPaquete = isset($_POST['precio_paquete']) && $_POST['precio_paquete'] !== '' ? (float) $_POST['precio_paquete'] : null;
        if ($catalogoId && $precioPaquete !== null && $precioPaquete >= 0) {
            $pdo->prepare(
                'INSERT INTO compra_decisiones (entidad_tipo, entidad_id, ingrediente_catalogo_id, comprar_paquete, precio_paquete)
                 VALUES (\'practica\', ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE comprar_paquete = VALUES(comprar_paquete), precio_paquete = VALUES(precio_paquete)'
            )->execute([$id, $catalogoId, $comprarPaquete, $precioPaquete]);
        }
    }

    redirect('detalle.php?id=' . $id . '&tab=' . $tab);
}

/* ---------------- Datos para mostrar ---------------- */
$stmt = db()->prepare(
    'SELECT pr.porciones_necesarias, r.*, cr.nombre AS categoria FROM practica_receta pr
     JOIN recetas r ON r.id = pr.receta_id
     JOIN categorias_receta cr ON cr.id = r.categoria_id
     WHERE pr.practica_id = ? ORDER BY r.nombre ASC'
);
$stmt->execute([$id]);
$recetasPractica = $stmt->fetchAll();

foreach ($recetasPractica as &$rc) {
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
$costoMateriales = costoRecetasConsolidado(db(), 'practica', $id);

// Acciones/cortes marcados por línea (igual que en eventos/detalle.php).
$accionesPorFila = [];
$idsFilasTodas = [];
foreach ($recetasPractica as $rc) {
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

$consolidado = null;
if ($tab === 'compras') {
    $recetasParaLista = array_map(fn($rc) => ['receta_id' => $rc['id'], 'porciones_necesarias' => $rc['porciones_necesarias']], $recetasPractica);
    $decisionesCompra = cargarDecisionesCompra(db(), 'practica', $id);
    $consolidado = listaCompraConsolidada(db(), $recetasParaLista, $decisionesCompra);
}

// Estudiantes asignados a esta práctica + su cuota (mismo patrón que
// eventos/detalle.php, con practica_estudiante en vez de evento_estudiante).
$stmt = db()->prepare(
    'SELECT pe.monto_pagado, pe.fecha_pago, est.*, ge.nombre AS grupo FROM practica_estudiante pe
     JOIN estudiantes est ON est.id = pe.estudiante_id
     LEFT JOIN grupos_estudiante ge ON ge.id = est.grupo_id
     WHERE pe.practica_id = ? ORDER BY est.nombre ASC'
);
$stmt->execute([$id]);
$estudiantesPractica = $stmt->fetchAll();
$cantidadEstudiantes = count($estudiantesPractica);

// Gastos de la práctica, mismo balde material/otros × proyectado/usado que
// un evento (ver includes/helpers.php, resumenGastosVinculo()).
$resumenGastos = resumenGastosVinculo(db(), 'practica_id', $id);
$materialUsado = $resumenGastos['material_usado'];
$otrosUsado = $resumenGastos['otros_usado'];
$totalProyectado = $resumenGastos['material_proyectado'] + $resumenGastos['otros_proyectado'];

$gastosPractica = [];
if ($puedeVerGastos) {
    $stmt = db()->prepare(
        "SELECT g.*, cg.nombre AS categoria FROM gastos g
         JOIN categorias_gasto cg ON cg.id = g.categoria_id
         WHERE g.practica_id = ? AND g.eliminado_en IS NULL
         ORDER BY FIELD(g.estado,'proyectado','confirmado','pagado'), g.fecha DESC, g.id DESC"
    );
    $stmt->execute([$id]);
    $gastosPractica = $stmt->fetchAll();
}

$cuotas = calcularCuotas($costoMateriales, $resumenGastos, $cantidadEstudiantes);
$cuotaProyectada = $cuotas['proyectada'];
$cuotaConfirmada = $cuotas['confirmada'];
$totalConfirmadoConMateriales = $cuotas['total_confirmado'];
$totalProyeccionInversion = $cuotas['total_proyeccion'];

$recaudado = array_sum(array_column($estudiantesPractica, 'monto_pagado'));
$numPagados = count(array_filter($estudiantesPractica, fn($a) => (float) $a['monto_pagado'] >= $cuotaConfirmada - 0.005));
$pctPago = $totalConfirmadoConMateriales > 0 ? round($recaudado / $totalConfirmadoConMateriales * 100) : 0;

$pageTitle = $practica['nombre'];
$activeNav = 'practicas';
$breadcrumb = '<a href="index.php">Prácticas</a> &nbsp;/&nbsp; <b>' . e($practica['nombre']) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($practica['nombre']) ?></h1>
    <div class="event-meta" style="margin-top:6px;">
      <span><?= icon('calendar') ?> <?= fmtDate($practica['fecha']) ?></span>
      <?php if ($practica['materia']): ?><span><?= icon('book') ?> <?= e($practica['materia']) ?></span><?php endif; ?>
      <?php if ($practica['maestro_responsable']): ?><span><?= icon('users') ?> <?= e($practica['maestro_responsable']) ?></span><?php endif; ?>
    </div>
  </div>
  <?php if ($puedeEditar): ?>
    <a class="btn btn-secondary" href="form.php?id=<?= (int) $practica['id'] ?>"><?= icon('edit') ?> Editar</a>
  <?php endif; ?>
</div>

<?php if (trim((string) ($practica['notas'] ?? '')) !== ''): ?>
  <div class="alert" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-secondary);margin-bottom:18px;"><?= nl2br(e($practica['notas'])) ?></div>
<?php endif; ?>

<div class="summary-grid">
  <div class="card card-pad">
    <div class="stat-label">Inversión de la práctica</div>
    <div class="stat-value"><?= money($totalProyeccionInversion) ?></div>
    <?php if ($puedeVerGastos): ?>
      <div class="stat-hint" style="margin-top:8px;">Proyectado en recetas: <b class="mono"><?= money($costoMateriales) ?></b> · Gastado en materiales: <b class="mono"><?= money($materialUsado) ?></b><?php if ($otrosUsado > 0 || $totalProyectado > 0): ?> · Otros gastos: <b class="mono"><?= money($otrosUsado) ?></b><?php endif; ?></div>
      <?php if ($cuotas['material_excedido']): ?>
        <div class="alert alert-error" style="margin-top:10px;padding:10px 12px;font-size:.85rem;">
          <?= icon('alertTriangle') ?> El gasto en materiales superó lo proyectado en recetas por <b><?= money($cuotas['material_exceso']) ?></b>.
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="stat-hint">Según las recetas y porciones asignadas a esta práctica.</div>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Cuota y recaudo</div>
    <?php if ($cantidadEstudiantes > 0): ?>
      <div class="meter-row" style="margin-top:8px;"><span class="mono"><?= money($recaudado) ?> recaudado</span><span><?= (int) $pctPago ?>%</span></div>
      <div class="meter <?= meterClase($pctPago) ?>"><span style="width:<?= min($pctPago, 100) ?>%"></span></div>
      <div class="stat-hint" style="margin-top:8px;">Cuota confirmada: <b class="mono"><?= money($cuotaConfirmada) ?></b> · Cuota proyectada: <b class="mono"><?= money($cuotaProyectada) ?></b> por estudiante</div>
    <?php else: ?>
      <div class="stat-value" style="font-size:1.05rem;">—</div>
      <div class="stat-hint">Asigna estudiantes a la práctica para calcular la cuota.</div>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="stat-label">Recetas asignadas</div>
    <div class="stat-value"><?= count($recetasPractica) ?></div>
    <div class="stat-hint">Ver la pestaña "Lista de Compra" para el consolidado de ingredientes.</div>
  </div>
</div>

<div class="tabs">
  <?php if ($puedeVerEstudiantesTab): ?>
    <a class="tab <?= $tab === 'estudiantes' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=estudiantes">Estudiantes y pagos</a>
  <?php endif; ?>
  <?php if ($puedeVerRecetasTab): ?>
    <a class="tab <?= $tab === 'recetas' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=recetas">Recetas</a>
  <?php endif; ?>
  <?php if ($puedeVerCompras): ?>
    <a class="tab <?= $tab === 'compras' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=compras"><?= icon('clipboardList') ?> Lista de Compra</a>
  <?php endif; ?>
  <?php if ($puedeVerGastos): ?>
    <a class="tab <?= $tab === 'gastos' ? 'active' : '' ?>" href="detalle.php?id=<?= $id ?>&tab=gastos">Gastos</a>
  <?php endif; ?>
</div>

<?php if (!$tabsValidos): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin acceso</div>
    <div>Tu rol no tiene permiso para ver ninguna sección de esta práctica.</div>
  </div></div>
<?php endif; ?>

<?php if ($tab === 'recetas'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Las cantidades se recalculan según las porciones que necesitas preparar.</div>
    <div class="row-actions">
      <?php if (count($recetasPractica) > 1): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-role="toggle-todas-recetas" data-contenedor="lista-recetas-practica">Colapsar todo</button>
      <?php endif; ?>
      <?php if ($puedeCrearRecetaTab): ?>
        <a class="btn btn-secondary btn-sm" href="asignar_receta.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar receta</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$recetasPractica): ?>
    <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
      <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin recetas</div>
      <div>Agrega recetas del catálogo para calcular los ingredientes.</div>
    </div></div>
  <?php endif; ?>
  <div data-role="lista-recetas-practica">
  <?php foreach ($recetasPractica as $rc):
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
        <?php if ($puedeEditarRecetaTab): ?>
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
        <?php else: ?>
          <div class="stat-hint"><?= (int) $rc['porciones_necesarias'] ?> porciones a preparar</div>
        <?php endif; ?>
        <?php if ($puedeEliminarRecetaTab): ?>
          <form method="post" class="no-print" data-confirm="¿Quitar la receta &quot;<?= e($rc['nombre']) ?>&quot; de esta práctica?">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="accion" value="quitar_receta">
            <input type="hidden" name="receta_id" value="<?= (int) $rc['id'] ?>">
            <button class="icon-btn" type="submit" title="Quitar receta"><?= icon('trash') ?></button>
          </form>
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

<?php elseif ($tab === 'compras'): ?>
  <div class="toolbar no-print">
    <div class="cell-muted">Ingredientes de todas las recetas de esta práctica, sumados y organizados para ir al súper.</div>
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
          <tr><td colspan="4" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay recetas asignadas a esta práctica.</td></tr>
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
                <?php if ($puedeEditarCompras): ?>
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

<?php elseif ($tab === 'estudiantes'): ?>
  <div class="toolbar">
    <div class="cell-muted">
      <?= $numPagados ?> de <?= count($estudiantesPractica) ?> estudiantes al día · <span class="mono"><?= money($recaudado) ?></span> de <span class="mono"><?= money($totalConfirmadoConMateriales) ?></span> recaudado
      <?php if ($cantidadEstudiantes > 0): ?>
        · cuota confirmada: <span class="mono"><?= money($cuotaConfirmada) ?></span> c/u
      <?php endif; ?>
    </div>
    <?php if ($puedeCrearEstudianteTab): ?>
      <a class="btn btn-secondary btn-sm" href="asignar_estudiante.php?id=<?= $id ?>"><?= icon('plus') ?> Agregar estudiante</a>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Grupo</th><th>Teléfono</th><th>Pagado</th><th>Pendiente</th><th></th></tr></thead>
      <tbody>
        <?php if (!$estudiantesPractica): ?>
          <tr><td colspan="6" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay estudiantes asignados a esta práctica.</td></tr>
        <?php endif; ?>
        <?php foreach ($estudiantesPractica as $a):
          $montoPagado = (float) $a['monto_pagado'];
          $pendienteEstudiante = max(0, $cuotaConfirmada - $montoPagado);
          $alDia = $pendienteEstudiante <= 0.005;
        ?>
          <tr>
            <td class="cell-name"><?= e($a['nombre']) ?></td>
            <td class="cell-muted"><?= e($a['grupo']) ?></td>
            <td class="cell-muted mono"><?= e($a['telefono']) ?></td>
            <td>
              <?php if ($puedeEditarEstudianteTab): ?>
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
              <?php if ($puedeEditarEstudianteTab && !$alDia): ?>
                <form method="post" data-confirm="¿Registrar el pago completo de la cuota confirmada (<?= e(money($cuotaConfirmada)) ?>) para &quot;<?= e($a['nombre']) ?>&quot;?">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="registrar_pago">
                  <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                  <input type="hidden" name="monto_pagado" value="<?= e((string) $cuotaConfirmada) ?>">
                  <button class="btn btn-primary btn-sm" type="submit">Pagar cuota completa</button>
                </form>
              <?php endif; ?>
              <?php if ($puedeEliminarEstudianteTab): ?>
                <form method="post" data-confirm="¿Quitar a &quot;<?= e($a['nombre']) ?>&quot; de esta práctica?">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="quitar_estudiante">
                  <input type="hidden" name="estudiante_id" value="<?= (int) $a['id'] ?>">
                  <button class="icon-btn" type="submit" title="Quitar de la práctica"><?= icon('x') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'gastos'): $esAdmin = ($usuarioActual['rol_nombre'] ?? '') === 'Administrador'; ?>
  <div class="card card-pad" style="margin-bottom:16px;">
    <div class="stat-hint">
      Proyectado en recetas: <b class="mono"><?= money($costoMateriales) ?></b>
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
    <div class="cell-muted"><?= count($gastosPractica) ?> partida<?= count($gastosPractica) === 1 ? '' : 's' ?> registrada<?= count($gastosPractica) === 1 ? '' : 's' ?></div>
    <?php if ($puedeCrearGasto): ?>
      <a class="btn btn-secondary btn-sm" href="gasto_form.php?practica_id=<?= $id ?>"><?= icon('plus') ?> Agregar partida</a>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Estado</th><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>Monto</th><th></th></tr></thead>
      <tbody>
        <?php if (!$gastosPractica): ?>
          <tr><td colspan="7" class="cell-muted" style="text-align:center;padding:24px;">Aún no hay gastos ni partidas proyectadas.</td></tr>
        <?php endif; ?>
        <?php foreach ($gastosPractica as $g): ?>
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
