<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'eventos', $id ? 'editar' : 'crear', $base);

$estados = db()->query('SELECT * FROM estados_evento WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$estadoPorDefecto = null;
foreach ($estados as $es) {
    if ($es['nombre'] === 'Planificado') {
        $estadoPorDefecto = (int) $es['id'];
        break;
    }
}
$estadoPorDefecto = $estadoPorDefecto ?? ($estados[0]['id'] ?? null);

$evento = [
    'nombre' => '', 'fecha' => date('Y-m-d'), 'lugar' => '', 'banner' => null,
    'estado_id' => $estadoPorDefecto, 'cuota_publica' => 0,
];
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM eventos WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese evento ya no existe.', 'error');
        redirect('index.php');
    }
    $evento = $encontrado;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $evento['nombre']      = trim($_POST['nombre'] ?? '');
    $evento['fecha']       = $_POST['fecha'] ?? '';
    $evento['lugar']       = trim($_POST['lugar'] ?? '');
    $evento['estado_id']   = intOrNull($_POST['estado_id'] ?? null);
    $evento['cuota_publica'] = !empty($_POST['cuota_publica']) ? 1 : 0;

    if ($evento['nombre'] === '') {
        $errores[] = 'El nombre del evento es obligatorio.';
    }
    if (!$evento['fecha'] || !strtotime($evento['fecha'])) {
        $errores[] = 'La fecha no es válida.';
    }
    if (!in_array($evento['estado_id'], array_column($estados, 'id'), true)) {
        $errores[] = 'Estado no válido.';
    }

    // Banner: solo se valida el tipo/tamaño aquí. El archivo no se mueve ni
    // se borra el banner anterior todavía — eso pasa más abajo, y solo si
    // el resto del formulario también es válido, para no perder el banner
    // viejo si el guardado termina fallando por otro motivo.
    $eliminarBanner = !empty($_POST['eliminar_banner']);
    $subioBannerValido = false;
    $extensionBanner = null;
    if (isset($_FILES['banner']) && $_FILES['banner']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir el banner. Intenta de nuevo.';
        } else {
            $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = @mime_content_type($_FILES['banner']['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                $errores[] = 'El banner debe ser una imagen JPG, PNG o WEBP.';
            } elseif ($_FILES['banner']['size'] > 5 * 1024 * 1024) {
                $errores[] = 'El banner no puede pesar más de 5 MB.';
            } else {
                $subioBannerValido = true;
                $extensionBanner = $tiposPermitidos[$mime];
            }
        }
    }

    if (!$errores) {
        // Ahora sí: mover el archivo nuevo (o borrar el banner actual si se
        // pidió quitarlo) justo antes de guardar en la base de datos.
        $bannerFinal = $evento['banner'] ?? null;
        if ($subioBannerValido) {
            // El banner se ve en la página pública ("Próximos eventos"), así
            // que se guarda en el almacenamiento compartido fuera del
            // repositorio (enlace `public/` en la raíz del proyecto ->
            // ../shared/public), para que sobreviva a los despliegues y no
            // se suba a git.
            $directorioDestino = __DIR__ . '/../public/eventos';
            if (!is_dir($directorioDestino)) {
                mkdir($directorioDestino, 0775, true);
            }
            $nombreArchivo = 'evento_' . ($id ?: 'nuevo') . '_' . bin2hex(random_bytes(6)) . '.' . $extensionBanner;
            if (move_uploaded_file($_FILES['banner']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                if ($bannerFinal) {
                    @unlink(__DIR__ . '/../' . $bannerFinal);
                }
                $bannerFinal = 'public/eventos/' . $nombreArchivo;
            } else {
                $errores[] = 'No se pudo guardar el banner en el servidor. Vuelve a intentarlo.';
            }
        } elseif ($eliminarBanner && $bannerFinal) {
            @unlink(__DIR__ . '/../' . $bannerFinal);
            $bannerFinal = null;
        }
    }

    if (!$errores) {
        if ($id) {
            $stmt = db()->prepare('UPDATE eventos SET nombre=?, fecha=?, lugar=?, banner=?, estado_id=?, cuota_publica=? WHERE id=?');
            $stmt->execute([$evento['nombre'], $evento['fecha'], $evento['lugar'], $bannerFinal, $evento['estado_id'], $evento['cuota_publica'], $id]);
            flash('Evento actualizado.');
            redirect('detalle.php?id=' . $id);
        } else {
            $stmt = db()->prepare('INSERT INTO eventos (nombre, fecha, lugar, banner, estado_id, cuota_publica) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$evento['nombre'], $evento['fecha'], $evento['lugar'], $bannerFinal, $evento['estado_id'], $evento['cuota_publica']]);
            $nuevoId = (int) db()->lastInsertId();
            flash('Evento creado.');
            redirect('detalle.php?id=' . $nuevoId);
        }
    }
}

$pageTitle = $id ? 'Editar evento' : 'Nuevo evento';
$activeNav = 'eventos';
$breadcrumb = '<a href="index.php">Eventos</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre del evento</label>
      <input type="text" id="nombre" name="nombre" required placeholder="Ej. Buffet de fin de curso" value="<?= e($evento['nombre']) ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" required value="<?= e($evento['fecha']) ?>">
      </div>
      <div class="field">
        <label for="lugar">Lugar</label>
        <input type="text" id="lugar" name="lugar" placeholder="Ej. Salón principal" value="<?= e($evento['lugar']) ?>">
      </div>
    </div>

    <div class="field">
      <label>Banner del evento</label>
      <?php if (!empty($evento['banner'])): ?>
        <div data-banner-actual>
          <img class="event-banner-preview" src="<?= e($base . '/' . $evento['banner']) ?>" alt="Banner de <?= e($evento['nombre']) ?: 'evento' ?>">
          <div style="margin:8px 0;">
            <button type="button" class="btn btn-danger btn-sm" data-eliminar-banner><?= icon('trash') ?> Eliminar banner</button>
          </div>
        </div>
        <div class="hint" data-banner-marcado style="display:none;color:var(--danger);margin-bottom:8px;">Este banner se eliminará al guardar los cambios.</div>
        <input type="hidden" name="eliminar_banner" value="0" data-input-eliminar-banner>
      <?php else: ?>
        <div class="recipe-photo-box" style="margin-bottom:8px;">Sin banner todavía</div>
      <?php endif; ?>
      <input type="file" id="banner" name="banner" accept="image/jpeg,image/png,image/webp">
      <div class="hint">Opcional. Se muestra como imagen de portada al entrar al detalle del evento y en la página pública, en "Próximos eventos". JPG, PNG o WEBP, hasta 5 MB (ideal: una foto ancha, tipo panorámica).</div>
    </div>

    <div class="field">
      <div class="hint" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:10px 12px;">
        <?= icon('sparkle') ?> La inversión y la cuota por estudiante ya no se escriben a mano: se calculan solas a partir del costo de las recetas y los gastos del evento, divididas entre los estudiantes asignados. Las vas a ver en el Resumen del evento una vez lo guardes.
      </div>
    </div>

    <div class="field">
      <div class="toggle-card <?= !empty($evento['cuota_publica']) ? 'is-public' : '' ?>" data-role="cuota-publica-card">
        <div class="toggle-card-text">
          <span class="toggle-card-icon" data-role="cuota-publica-icon"><?= icon(!empty($evento['cuota_publica']) ? 'eye' : 'lock') ?></span>
          <div>
            <div class="toggle-card-title">Mostrar la cuota en la página pública</div>
            <div class="toggle-card-desc">Mientras el presupuesto todavía se está armando, la cuota puede variar. Con esto apagado, "Próximos eventos" en la Home sigue mostrando el evento, pero con la cuota como "Por confirmar". Actívalo cuando la cifra ya esté estable.</div>
            <span class="toggle-card-state" data-role="cuota-publica-estado"><?= !empty($evento['cuota_publica']) ? 'Cuota visible al público' : 'Cuota oculta al público' ?></span>
          </div>
        </div>
        <label class="switch">
          <input type="checkbox" id="cuota_publica" name="cuota_publica" value="1" data-role="cuota-publica-input" <?= !empty($evento['cuota_publica']) ? 'checked' : '' ?>>
          <span class="switch-track"></span>
          <span class="switch-thumb"></span>
        </label>
      </div>
    </div>

    <div class="field">
      <label for="estado_id">Estado</label>
      <select id="estado_id" name="estado_id">
        <?php foreach ($estados as $es): ?>
          <option value="<?= (int) $es['id'] ?>" <?= (int) $es['id'] === (int) $evento['estado_id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="<?= $id ? 'detalle.php?id=' . (int) $id : 'index.php' ?>">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear evento' ?></button>
    </div>
  </form>
</div>

<script>
(function () {
  var btnEliminarBanner = document.querySelector('[data-eliminar-banner]');
  if (btnEliminarBanner) {
    btnEliminarBanner.addEventListener('click', function () {
      if (!window.confirm('¿Eliminar este banner? Se quitará al guardar los cambios del evento.')) {
        return;
      }
      document.querySelector('[data-banner-actual]').style.display = 'none';
      document.querySelector('[data-banner-marcado]').style.display = 'block';
      document.querySelector('[data-input-eliminar-banner]').value = '1';
    });
  }

  var cuotaInput = document.querySelector('[data-role="cuota-publica-input"]');
  if (cuotaInput) {
    var cuotaCard = document.querySelector('[data-role="cuota-publica-card"]');
    var cuotaIcon = document.querySelector('[data-role="cuota-publica-icon"]');
    var cuotaEstado = document.querySelector('[data-role="cuota-publica-estado"]');
    var iconoOjo = '<?= addslashes(icon('eye')) ?>';
    var iconoCandado = '<?= addslashes(icon('lock')) ?>';
    cuotaInput.addEventListener('change', function () {
      cuotaCard.classList.toggle('is-public', cuotaInput.checked);
      cuotaIcon.innerHTML = cuotaInput.checked ? iconoOjo : iconoCandado;
      cuotaEstado.textContent = cuotaInput.checked ? 'Cuota visible al público' : 'Cuota oculta al público';
    });
  }
})();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
