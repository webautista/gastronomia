<?php
/**
 * Editar un gasto ya marcado como "pagado" — antes quedaba completamente
 * cerrado (solo "Ver factura" y, únicamente para un Administrador,
 * borrarlo), así que si se cargaba la foto de la factura equivocada, o
 * cualquier otro dato quedaba mal, no había forma de corregirlo (a pedido
 * de Eyaelkys). Cualquier rol con permiso de "editar" en eventos_gastos
 * puede entrar aquí (el mismo permiso que ya deja confirmar una partida o
 * marcarla como pagada — Administrador lo tiene siempre, y se le puede dar
 * a un rol propio como "Tesorero" desde Usuarios y roles → Roles), pero
 * cada cambio queda anotado en gastos_historial (ver
 * registrarCambioGasto() en includes/helpers.php): quién, cuándo, de qué
 * valor a cuál. La factura es reemplazable pero nunca obligatoria aquí
 * (ya existe una) — dejar el campo vacío conserva la actual, y la anterior
 * NO se borra del servidor al reemplazarla, por si hiciera falta rastrear
 * el archivo que quedó registrado en el historial.
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
if ($gastoActual['estado'] !== 'pagado') {
    flash('Solo un gasto ya pagado se edita desde aquí — uno proyectado o confirmado se ajusta directo desde la pestaña de Gastos.', 'error');
    redirect('detalle.php?id=' . $gastoActual['evento_id'] . '&tab=gastos');
}

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

$gasto = [
    'categoria_id'      => (int) $gastoActual['categoria_id'],
    'descripcion'       => $gastoActual['descripcion'],
    'proveedor'         => $gastoActual['proveedor'] ?? '',
    'fecha'             => $gastoActual['fecha'],
    'monto'             => $gastoActual['monto'],
    'monto_confirmado'  => $gastoActual['monto_confirmado'] ?? $gastoActual['monto'],
    'monto_pagado'      => $gastoActual['monto_pagado'] ?? ($gastoActual['monto_confirmado'] ?? $gastoActual['monto']),
    'fecha_pago'        => $gastoActual['fecha_pago'] ?? date('Y-m-d'),
];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Ver el mismo bloque en gasto_pagar.php: la foto de reemplazo superó
    // el límite total de subida del servidor y PHP descartó todo el POST.
    $errores[] = 'La imagen es demasiado pesada para este servidor. Tu navegador ya intenta reducirla automáticamente; si el problema sigue, prueba con otra foto o con una de menor resolución.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $gasto['categoria_id']     = intOrNull($_POST['categoria_id'] ?? null);
    $gasto['descripcion']      = trim($_POST['descripcion'] ?? '');
    $gasto['proveedor']        = trim($_POST['proveedor'] ?? '');
    $gasto['fecha']            = $_POST['fecha'] ?? '';
    $gasto['monto']            = (float) ($_POST['monto'] ?? 0);
    $gasto['monto_confirmado'] = (float) ($_POST['monto_confirmado'] ?? 0);
    $gasto['monto_pagado']     = (float) ($_POST['monto_pagado'] ?? 0);
    $gasto['fecha_pago']       = $_POST['fecha_pago'] ?? '';

    if (!in_array($gasto['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }
    if ($gasto['descripcion'] === '') {
        $errores[] = 'La descripción es obligatoria.';
    }
    if ($gasto['monto'] <= 0 || $gasto['monto_confirmado'] <= 0 || $gasto['monto_pagado'] <= 0) {
        $errores[] = 'Los montos deben ser mayores a 0.';
    }
    if (!$gasto['fecha'] || !strtotime($gasto['fecha'])) {
        $errores[] = 'La fecha del gasto no es válida.';
    }
    if (!$gasto['fecha_pago'] || !strtotime($gasto['fecha_pago'])) {
        $errores[] = 'La fecha de pago no es válida.';
    }

    // La factura es opcional aquí: ya existe una, así que solo se procesa
    // si se elige un archivo nuevo (mismo criterio que la foto de receta
    // en recetas/form.php, pero sin poder dejarla vacía — siempre hace
    // falta alguna).
    $facturaFinal = $gastoActual['factura'];
    if (!$errores && isset($_FILES['factura']) && $_FILES['factura']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['factura']['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir la factura nueva. Intenta de nuevo.';
        } else {
            $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = @mime_content_type($_FILES['factura']['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                $errores[] = 'La factura debe ser una imagen JPG, PNG o WEBP.';
            } elseif ($_FILES['factura']['size'] > 5 * 1024 * 1024) {
                $errores[] = 'La imagen de la factura no puede pesar más de 5 MB.';
            } else {
                $directorioDestino = __DIR__ . '/../assets/uploads/facturas';
                if (!is_dir($directorioDestino)) {
                    mkdir($directorioDestino, 0775, true);
                }
                $nombreArchivo = 'factura_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $tiposPermitidos[$mime];
                if (move_uploaded_file($_FILES['factura']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                    // La factura anterior NO se borra del servidor: queda su
                    // ruta anotada como "valor_anterior" en el historial, por
                    // si hiciera falta rastrearla más adelante.
                    $facturaFinal = 'assets/uploads/facturas/' . $nombreArchivo;
                } else {
                    $errores[] = 'No se pudo guardar la factura nueva en el servidor. Vuelve a intentarlo.';
                }
            }
        }
    }

    if (!$errores) {
        $categoriaAnteriorNombre = $gastoActual['categoria'];
        $stmtCatNueva = db()->prepare('SELECT nombre FROM categorias_gasto WHERE id = ?');
        $stmtCatNueva->execute([$gasto['categoria_id']]);
        $categoriaNuevaNombre = $stmtCatNueva->fetchColumn() ?: (string) $gasto['categoria_id'];

        db()->prepare(
            'UPDATE gastos SET categoria_id=?, descripcion=?, proveedor=?, fecha=?, monto=?, monto_confirmado=?, monto_pagado=?, fecha_pago=?, factura=? WHERE id=?'
        )->execute([
            $gasto['categoria_id'], $gasto['descripcion'], $gasto['proveedor'] ?: '—', $gasto['fecha'],
            $gasto['monto'], $gasto['monto_confirmado'], $gasto['monto_pagado'], $gasto['fecha_pago'], $facturaFinal, $id,
        ]);

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
        registrarCambioGasto(db(), $id, 'monto_confirmado', sprintf('%.2f', $gastoActual['monto_confirmado']), sprintf('%.2f', $gasto['monto_confirmado']), $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'monto_pagado', sprintf('%.2f', $gastoActual['monto_pagado']), sprintf('%.2f', $gasto['monto_pagado']), $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'fecha_pago', $gastoActual['fecha_pago'], $gasto['fecha_pago'], $usuarioId, $usuarioNombre);
        registrarCambioGasto(db(), $id, 'factura', $gastoActual['factura'], $facturaFinal, $usuarioId, $usuarioNombre);

        flash('Gasto actualizado.');
        redirect('detalle.php?id=' . $eventoId . '&tab=gastos');
    }
}

$historial = historialGasto(db(), $id);

$pageTitle = 'Editar gasto pagado';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <a href="detalle.php?id=' . $eventoId . '&tab=gastos">' . e($evento['nombre'] ?? '') . '</a> &nbsp;/&nbsp; <b>Editar gasto</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1>Editar gasto pagado</h1><p>Cualquier cambio queda anotado abajo, con quién lo hizo y cuándo.</p></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post" enctype="multipart/form-data">
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

    <div class="field-row">
      <div class="field">
        <label for="monto">Monto proyectado (RD$)</label>
        <input type="number" id="monto" name="monto" min="0.01" step="0.01" required value="<?= e((string) $gasto['monto']) ?>">
      </div>
      <div class="field">
        <label for="monto_confirmado">Monto confirmado (RD$)</label>
        <input type="number" id="monto_confirmado" name="monto_confirmado" min="0.01" step="0.01" required value="<?= e((string) $gasto['monto_confirmado']) ?>">
      </div>
    </div>

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

    <div class="field">
      <label>Factura actual</label>
      <?php if (!empty($gastoActual['factura'])): ?>
        <div style="margin-bottom:8px;">
          <a href="<?= e($base . '/' . $gastoActual['factura']) ?>" target="_blank" rel="noopener"><img src="<?= e($base . '/' . $gastoActual['factura']) ?>" alt="Factura actual" style="max-width:220px;max-height:220px;border-radius:8px;border:1px solid var(--border);display:block;"></a>
        </div>
      <?php else: ?>
        <p class="cell-muted" style="margin-top:0;">Este gasto no tiene ninguna factura guardada todavía.</p>
      <?php endif; ?>
      <label for="factura">Reemplazar factura</label>
      <input type="file" id="factura" name="factura" accept="image/jpeg,image/png,image/webp">
      <div class="hint">Opcional — deja este campo vacío para mantener la factura actual. JPG, PNG o WEBP, hasta 5 MB.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="detalle.php?id=<?= $eventoId ?>&tab=gastos">Cancelar</a>
      <button class="btn btn-primary" type="submit">Guardar cambios</button>
    </div>
  </form>
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
