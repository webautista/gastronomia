<?php
/**
 * Editar un gasto de un evento en cualquier etapa de su ciclo de vida
 * (proyectado, confirmado o pagado). Antes solo se podía editar uno ya
 * pagado (y hasta entonces la única manera de corregir un gasto
 * proyectado o confirmado era borrarlo y volver a crearlo — a pedido de
 * Eyaelkys se abrió para las tres etapas). Los campos que se muestran
 * dependen de la etapa: un gasto proyectado solo tiene su monto proyectado;
 * uno confirmado además su monto confirmado; uno pagado además el monto
 * pagado y la fecha de pago. Cualquier rol con permiso de "editar" en
 * eventos_gastos puede entrar aquí (el mismo permiso que ya deja confirmar una
 * partida o marcarla como pagada), y cada cambio queda anotado en
 * gastos_historial (ver registrarCambioGasto() en includes/helpers.php):
 * quién, cuándo, de qué valor a cuál. Las facturas ya no se reemplazan
 * aquí: se agregan o quitan (varias, y en cualquier etapa) desde la pantalla
 * de Facturas del gasto (gasto_facturas.php).
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'eventos_gastos', 'editar', $base);

$id = intOrNull($_GET['id'] ?? null);
if (!$id) {
    redirect('index.php');
}

$stmt = db()->prepare('SELECT g.*, cg.nombre AS categoria FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id WHERE g.id = ? AND g.evento_id IS NOT NULL AND g.eliminado_en IS NULL');
$stmt->execute([$id]);
$gastoActual = $stmt->fetch();
if (!$gastoActual) {
    flash('Ese gasto ya no existe.', 'error');
    redirect('index.php');
}

$estadoGasto = $gastoActual['estado'];
$esConfirmadoOPagado = in_array($estadoGasto, ['confirmado', 'pagado'], true);
$esPagado = $estadoGasto === 'pagado';

$eventoId = (int) $gastoActual['evento_id'];
$stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
$stmt->execute([$eventoId]);
$evento = $stmt->fetch();

$categorias = db()->query('SELECT * FROM categorias_gasto WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
// La categoría actual del gasto puede haber quedado inactiva desde
// entonces — se incluye igual en el selector para no perderla de vista al
// abrir el formulario (mismo criterio que categorias_receta en otras
// pantallas), aunque ya no aparezca para un gasto nuevo.
if (!in_array((int) $gastoActual['categoria_id'], array_column($categorias, 'id'), true)) {
    $stmt = db()->prepare('SELECT * FROM categorias_gasto WHERE id = ?');
    $stmt->execute([$gastoActual['categoria_id']]);
    $catActual = $stmt->fetch();
    if ($catActual) {
        $categorias[] = $catActual;
    }
}

// Estudiante que reportó el gasto (opcional): los asignados a este evento, más el
// actual aunque ya no esté asignado (para no perderlo de vista al guardar).
$tieneReportadoPor = gastosTieneReportadoPor(db());
$estudiantesReporte = $tieneReportadoPor ? estudiantesAsignadosEntidad(db(), 'evento', $eventoId) : [];
$reportadoActualId = $tieneReportadoPor ? intOrNull($gastoActual['reportado_por_estudiante_id'] ?? null) : null;
$reportadoActualNombre = null;
if ($reportadoActualId !== null) {
    $reportadoActualNombre = nombresEstudiantesPorId(db(), [$reportadoActualId])[$reportadoActualId] ?? null;
    if (!in_array($reportadoActualId, array_map('intval', array_column($estudiantesReporte, 'id')), true) && $reportadoActualNombre !== null) {
        $estudiantesReporte[] = ['id' => $reportadoActualId, 'nombre' => $reportadoActualNombre . ' (ya no está asignado)'];
    }
}

$gasto = [
    'reportado_por'     => $reportadoActualId,
    'categoria_id'      => (int) $gastoActual['categoria_id'],
    'descripcion'       => $gastoActual['descripcion'],
    'proveedor'         => $gastoActual['proveedor'] ?? '',
    'fecha'             => $gastoActual['fecha'],
    'monto'             => $gastoActual['monto'],
    'es_material_receta' => !empty($gastoActual['es_material_receta']) ? 1 : 0,
    'monto_confirmado'  => $gastoActual['monto_confirmado'] ?? $gastoActual['monto'],
    'monto_pagado'      => $gastoActual['monto_pagado'] ?? ($gastoActual['monto_confirmado'] ?? $gastoActual['monto']),
    'fecha_pago'        => $gastoActual['fecha_pago'] ?? date('Y-m-d'),
];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $gasto['categoria_id']     = intOrNull($_POST['categoria_id'] ?? null);
    $gasto['descripcion']      = trim($_POST['descripcion'] ?? '');
    $gasto['proveedor']        = trim($_POST['proveedor'] ?? '');
    $gasto['fecha']            = $_POST['fecha'] ?? '';
    $gasto['monto']            = (float) ($_POST['monto'] ?? 0);
    $gasto['es_material_receta'] = !empty($_POST['es_material_receta']) ? 1 : 0;
    if ($tieneReportadoPor) {
        $gasto['reportado_por'] = intOrNull($_POST['reportado_por'] ?? null);
        if (!in_array($gasto['reportado_por'], array_map('intval', array_column($estudiantesReporte, 'id')), true)) {
            $gasto['reportado_por'] = null;
        }
    }
    if ($esConfirmadoOPagado) {
        $gasto['monto_confirmado'] = (float) ($_POST['monto_confirmado'] ?? 0);
    }
    if ($esPagado) {
        $gasto['monto_pagado'] = (float) ($_POST['monto_pagado'] ?? 0);
        $gasto['fecha_pago']   = $_POST['fecha_pago'] ?? '';
    }

    if (!in_array($gasto['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }
    if ($gasto['descripcion'] === '') {
        $errores[] = 'La descripción es obligatoria.';
    }
    if ($gasto['monto'] <= 0 || ($esConfirmadoOPagado && $gasto['monto_confirmado'] <= 0) || ($esPagado && $gasto['monto_pagado'] <= 0)) {
        $errores[] = 'Los montos deben ser mayores a 0.';
    }
    if (!$gasto['fecha'] || !strtotime($gasto['fecha'])) {
        $errores[] = 'La fecha del gasto no es válida.';
    }
    if ($esPagado && (!$gasto['fecha_pago'] || !strtotime($gasto['fecha_pago']))) {
        $errores[] = 'La fecha de pago no es válida.';
    }

    if (!$errores) {
        $categoriaAnteriorNombre = $gastoActual['categoria'];
        $stmtCatNueva = db()->prepare('SELECT nombre FROM categorias_gasto WHERE id = ?');
        $stmtCatNueva->execute([$gasto['categoria_id']]);
        $categoriaNuevaNombre = $stmtCatNueva->fetchColumn() ?: (string) $gasto['categoria_id'];

        $sets = ['categoria_id=?', 'descripcion=?', 'proveedor=?', 'fecha=?', 'monto=?', 'es_material_receta=?'];
        $params = [$gasto['categoria_id'], $gasto['descripcion'], $gasto['proveedor'] ?: '—', $gasto['fecha'], $gasto['monto'], $gasto['es_material_receta']];
        if ($esConfirmadoOPagado) {
            $sets[] = 'monto_confirmado=?';
            $params[] = $gasto['monto_confirmado'];
        }
        if ($esPagado) {
            $sets[] = 'monto_pagado=?';
            $sets[] = 'fecha_pago=?';
            $params[] = $gasto['monto_pagado'];
            $params[] = $gasto['fecha_pago'];
        }
        if ($tieneReportadoPor) {
            $sets[] = 'reportado_por_estudiante_id=?';
            $params[] = $gasto['reportado_por'];
        }
        $params[] = $id;
        db()->prepare('UPDATE gastos SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);

        $usuarioId = (int) $usuarioActual['id'];
        $usuarioNombre = $usuarioActual['nombre'];
        registrarCambioGasto(db(), $id, 'categoria', $categoriaAnteriorNombre, $categoriaNuevaNombre, $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'descripcion', $gastoActual['descripcion'], $gasto['descripcion'], $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'proveedor', $gastoActual['proveedor'] ?? '', $gasto['proveedor'] ?: '—', $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'fecha', $gastoActual['fecha'], $gasto['fecha'], $usuarioId, $usuarioNombre);
        // sprintf en los dos lados (no un simple (string)) para que "300" y
        // "300.00" —el mismo monto, uno recién salido de un DECIMAL de MySQL
        // y el otro de un (float) de PHP— comparen iguales y no se anoten
        // como si hubieran cambiado.
        registrarCambioGasto(db(), $id, 'monto', sprintf('%.2f', $gastoActual['monto']), sprintf('%.2f', $gasto['monto']), $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'es_material_receta', !empty($gastoActual['es_material_receta']) ? 'Sí' : 'No', $gasto['es_material_receta'] ? 'Sí' : 'No', $usuarioId, $usuarioNombre);
        if ($tieneReportadoPor) {
            $reportadoNuevoNombre = $gasto['reportado_por'] !== null ? (nombresEstudiantesPorId(db(), [$gasto['reportado_por']])[$gasto['reportado_por']] ?? (string) $gasto['reportado_por']) : '—';
            registrarCambioGasto(db(), $id, 'reportado_por', $reportadoActualId !== null ? ($reportadoActualNombre ?? (string) $reportadoActualId) : '—', $reportadoNuevoNombre, $usuarioId, $usuarioNombre);
        }
        if ($esConfirmadoOPagado) {
            registrarCambioGasto(db(), $id, 'monto_confirmado', sprintf('%.2f', $gastoActual['monto_confirmado'] ?? $gastoActual['monto']), sprintf('%.2f', $gasto['monto_confirmado']), $usuarioId, $usuarioNombre);
        }
        if ($esPagado) {
            registrarCambioGasto(db(), $id, 'monto_pagado', sprintf('%.2f', $gastoActual['monto_pagado'] ?? $gastoActual['monto_confirmado'] ?? $gastoActual['monto']), sprintf('%.2f', $gasto['monto_pagado']), $usuarioId, $usuarioNombre);
            registrarCambioGasto(db(), $id, 'fecha_pago', $gastoActual['fecha_pago'], $gasto['fecha_pago'], $usuarioId, $usuarioNombre);
        }

        flash('Gasto actualizado.');
        redirect('detalle.php?id=' . $eventoId . '&tab=gastos');
    }
}

$historial = historialGasto(db(), $id);
$facturas = facturasDeGasto(db(), $gastoActual);

$tituloEtapa = $esPagado ? 'pagado' : ($estadoGasto === 'confirmado' ? 'confirmado (pendiente de pago)' : 'proyectado');
$pageTitle = 'Editar gasto ' . $tituloEtapa;
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $eventoId . '&tab=gastos">' . e($evento['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Editar gasto</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Editar gasto <?= e($tituloEtapa) ?></h1><p>Cualquier cambio queda anotado abajo, con quién lo hizo y cuándo.</p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field-row">
      <div class="field">
        <label for="categoria_id">Categoría</label>
        <select id="categoria_id" name="categoria_id">
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === (int) $gasto['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="fecha">Fecha del gasto</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($gasto['fecha']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="descripcion">Descripción</label>
      <input type="text" id="descripcion" name="descripcion" required value="<?= e($gasto['descripcion']) ?>">
    </div>

    <div class="field">
      <label for="proveedor">Proveedor</label>
      <input type="text" id="proveedor" name="proveedor" value="<?= e($gasto['proveedor']) ?>">
    </div>

    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
        <input type="checkbox" id="es_material_receta" name="es_material_receta" value="1" style="width:16px;height:16px;" <?= $gasto['es_material_receta'] ? 'checked' : '' ?>>
        Es para materiales de recetas
      </label>
      <div class="hint">Si está marcado, este gasto se compara contra el costo de materiales de las recetas en vez de sumarse aparte.</div>
    </div>

    <?php if ($tieneReportadoPor): ?>
    <div class="field">
      <label for="reportado_por">¿Qué estudiante reportó este gasto? (opcional)</label>
      <select id="reportado_por" name="reportado_por">
        <option value="">— Ninguno en particular —</option>
        <?php foreach ($estudiantesReporte as $est): ?>
          <option value="<?= (int) $est['id'] ?>" <?= (int) $gasto['reportado_por'] === (int) $est['id'] ? 'selected' : '' ?>><?= e($est['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">El estudiante que hizo la compra y entregó la factura (para saber a quién reembolsar si no se le devolvió el efectivo). Solo informativo.</div>
    </div>
    <?php endif; ?>

    <div class="field-row">
      <div class="field">
        <label for="monto">Monto proyectado (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" step="0.01" required value="<?= e((string) $gasto['monto']) ?>">
      </div>
      <?php if ($esConfirmadoOPagado): ?>
      <div class="field">
        <label for="monto_confirmado">Monto confirmado (RD$)</label>
        <input type="number" id="monto_confirmado" name="monto_confirmado" min="0.01" step="0.01" required value="<?= e((string) $gasto['monto_confirmado']) ?>">
      </div>
      <?php endif; ?>
    </div>

    <?php if ($esPagado): ?>
    <div class="field-row">
      <div class="field">
        <label for="monto_pagado">Monto pagado (RD$)</label>
        <input type="number" id="monto_pagado" name="monto_pagado" min="0.01" step="0.01" required value="<?= e((string) $gasto['monto_pagado']) ?>">
      </div>
      <div class="field">
        <label for="fecha_pago">Fecha de pago</label>
        <input type="date" id="fecha_pago" name="fecha_pago" required value="<?= e($gasto['fecha_pago']) ?>">
      </div>
    </div>
    <?php endif; ?>

    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $eventoId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Guardar cambios</button>
    </div>
  </form>
</div>

<div class="card card-pad" style="margin-top:16px;">
  <div class="section-title-row" style="margin-bottom:10px;">
    <div class="section-icon is-gold"><?= icon('receipt') ?></div>
    <h2 class="section-title" style="margin:0;">Facturas (<?= count($facturas) ?>)</h2>
  </div>
  <?php if ($facturas): ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <?php foreach ($facturas as $f): ?>
        <a href="<?= e($base . '/' . $f['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e($base . '/' . $f['ruta']) ?>" alt="Factura" style="width:110px;height:110px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="cell-muted" style="margin-top:0;">Este gasto todavía no tiene facturas<?= $estadoGasto === 'confirmado' ? ' (hace falta al menos una para marcarlo como pagado)' : '' ?>.</p>
  <?php endif; ?>
  <a class="btn btn-secondary btn-sm" href="gasto_facturas.php?id=<?= $id ?>"><?= icon('receipt') ?> Agregar o quitar facturas</a>
</div>

<?php if ($historial): ?>
<div class="card card-pad" style="margin-top:16px;">
  <div class="section-title-row" style="margin-bottom:10px;">
    <div class="section-icon is-gold"><?= icon('clock') ?></div>
    <h2 class="section-title" style="margin:0;">Historial de cambios</h2>
  </div>
  <div class="pago-row-list">
    <?php foreach ($historial as $h): ?>
      <div class="pago-row" style="flex-wrap:wrap;">
        <span class="pago-fecha"><?= fmtDate(substr($h['creado_en'], 0, 10)) ?> <span class="cell-muted" style="font-size:.78rem;"><?= date('g:i a', strtotime($h['creado_en'])) ?></span></span>
        <span><b><?= e(etiquetaCampoGastoHistorial($h['campo'])) ?></b></span>
        <span class="cell-muted" style="font-size:.85rem;">
          <?php if ($h['campo'] === 'factura'): ?>
            Se reemplazó la imagen de la factura.
          <?php elseif ($h['campo'] === 'factura_agregada'): ?>
            Se agregó una factura.
          <?php elseif ($h['campo'] === 'factura_quitada'): ?>
            Se quitó una factura.
          <?php else: ?>
            <?= e($h['valor_anterior'] ?? '—') ?> → <?= e($h['valor_nuevo'] ?? '—') ?>
          <?php endif; ?>
        </span>
        <span class="chip chip-muted"><?= e($h['registrado_por_nombre']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
