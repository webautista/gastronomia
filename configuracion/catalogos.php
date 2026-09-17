<?php
/**
 * Administración de catálogos (campos "de opciones" parametrizados):
 * unidades de medida, categorías de receta/gasto, estados de evento y
 * grupos de estudiante. Cada uno vive en su propia tabla normalizada;
 * esta pantalla es el único lugar donde se agregan/editan/desactivan
 * esas opciones, para que el resto del sistema nunca use texto libre.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'configuracion', 'ver', $base);
$puedeEditar = can($usuarioActual, 'configuracion', 'editar');
$puedeCrear = can($usuarioActual, 'configuracion', 'crear');
$puedeEliminar = can($usuarioActual, 'configuracion', 'eliminar');

// Definición de los catálogos administrables: tabla, etiquetas y (si aplica)
// la tabla/columna que lo referencia, para poder avisar antes de borrar.
$catalogos = [
    'unidades_medida' => [
        'tabla' => 'unidades_medida', 'label' => 'Unidades de medida', 'abrev' => true, 'entero' => true,
        'ref_tabla' => 'ingredientes', 'ref_col' => 'unidad_id',
    ],
    'categorias_receta' => [
        'tabla' => 'categorias_receta', 'label' => 'Categorías de receta', 'abrev' => false,
        'ref_tabla' => 'recetas', 'ref_col' => 'categoria_id',
    ],
    'categorias_gasto' => [
        'tabla' => 'categorias_gasto', 'label' => 'Categorías de gasto', 'abrev' => false,
        'ref_tabla' => 'gastos', 'ref_col' => 'categoria_id',
    ],
    'estados_evento' => [
        'tabla' => 'estados_evento', 'label' => 'Estados de evento', 'abrev' => false,
        'ref_tabla' => 'eventos', 'ref_col' => 'estado_id',
    ],
    'grupos_estudiante' => [
        'tabla' => 'grupos_estudiante', 'label' => 'Grupos de estudiantes', 'abrev' => false,
        'ref_tabla' => 'estudiantes', 'ref_col' => 'grupo_id',
    ],
    'categorias_ingrediente' => [
        'tabla' => 'categorias_ingrediente', 'label' => 'Categorías de ingrediente', 'abrev' => false,
        'ref_tabla' => 'ingredientes_catalogo', 'ref_col' => 'categoria_id',
    ],
    'acciones_ingrediente' => [
        'tabla' => 'acciones_ingrediente', 'label' => 'Acciones de preparación (cortes, etc.)', 'abrev' => false,
        'ref_tabla' => 'ingrediente_accion', 'ref_col' => 'accion_id',
    ],
];

$tipo = $_GET['tipo'] ?? 'categorias_receta';
if (!isset($catalogos[$tipo])) {
    $tipo = 'categorias_receta';
}
$cat = $catalogos[$tipo];
$tabla = $cat['tabla'];

function contarReferencias(PDO $pdo, string $refTabla, string $refCol, int $id): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$refTabla` WHERE `$refCol` = ?");
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $accion = $_POST['accion'] ?? '';
    $tipoPost = $_POST['tipo'] ?? '';
    if (!isset($catalogos[$tipoPost])) {
        flash('Catálogo no válido.', 'error');
        redirect('catalogos.php?tipo=' . urlencode($tipo));
    }
    $catPost = $catalogos[$tipoPost];
    $tablaPost = $catPost['tabla'];

    if ($accion === 'crear') {
        requirePermission($usuarioActual, 'configuracion', 'crear', $base);
        $nombre = trim($_POST['nombre'] ?? '');
        $abreviatura = trim($_POST['abreviatura'] ?? '');
        $esEntera = !empty($_POST['es_entera']) ? 1 : 0;
        if ($nombre === '') {
            flash('El nombre no puede estar vacío.', 'error');
        } elseif ($catPost['abrev'] && $abreviatura === '') {
            flash('La abreviatura no puede estar vacía.', 'error');
        } else {
            $maxOrden = (int) db()->query("SELECT COALESCE(MAX(orden),0) FROM `$tablaPost`")->fetchColumn();
            if (!empty($catPost['entero'])) {
                $stmt = db()->prepare("INSERT INTO `$tablaPost` (nombre, abreviatura, orden, es_entera) VALUES (?,?,?,?)");
                $stmt->execute([$nombre, $abreviatura, $maxOrden + 10, $esEntera]);
            } elseif ($catPost['abrev']) {
                $stmt = db()->prepare("INSERT INTO `$tablaPost` (nombre, abreviatura, orden) VALUES (?,?,?)");
                $stmt->execute([$nombre, $abreviatura, $maxOrden + 10]);
            } else {
                $stmt = db()->prepare("INSERT INTO `$tablaPost` (nombre, orden) VALUES (?,?)");
                $stmt->execute([$nombre, $maxOrden + 10]);
            }
            flash('Elemento agregado.');
        }
    } elseif ($accion === 'editar') {
        requirePermission($usuarioActual, 'configuracion', 'editar', $base);
        $id = intOrNull($_POST['id'] ?? null);
        $nombre = trim($_POST['nombre'] ?? '');
        $abreviatura = trim($_POST['abreviatura'] ?? '');
        $esEntera = !empty($_POST['es_entera']) ? 1 : 0;
        if ($id && $nombre !== '' && (!$catPost['abrev'] || $abreviatura !== '')) {
            if (!empty($catPost['entero'])) {
                $stmt = db()->prepare("UPDATE `$tablaPost` SET nombre=?, abreviatura=?, es_entera=? WHERE id=?");
                $stmt->execute([$nombre, $abreviatura, $esEntera, $id]);
            } elseif ($catPost['abrev']) {
                $stmt = db()->prepare("UPDATE `$tablaPost` SET nombre=?, abreviatura=? WHERE id=?");
                $stmt->execute([$nombre, $abreviatura, $id]);
            } else {
                $stmt = db()->prepare("UPDATE `$tablaPost` SET nombre=? WHERE id=?");
                $stmt->execute([$nombre, $id]);
            }
            flash('Cambios guardados.');
        } else {
            flash('Datos no válidos.', 'error');
        }
    } elseif ($accion === 'toggle') {
        requirePermission($usuarioActual, 'configuracion', 'editar', $base);
        $id = intOrNull($_POST['id'] ?? null);
        if ($id) {
            db()->prepare("UPDATE `$tablaPost` SET activo = 1 - activo WHERE id = ?")->execute([$id]);
            flash('Estado actualizado.');
        }
    } elseif ($accion === 'eliminar') {
        requirePermission($usuarioActual, 'configuracion', 'eliminar', $base);
        $id = intOrNull($_POST['id'] ?? null);
        if ($id) {
            $usos = contarReferencias(db(), $catPost['ref_tabla'], $catPost['ref_col'], $id);
            if ($usos > 0) {
                flash("No se puede eliminar: $usos registro(s) lo están usando. Puedes desactivarlo en su lugar.", 'error');
            } else {
                db()->prepare("DELETE FROM `$tablaPost` WHERE id = ?")->execute([$id]);
                flash('Elemento eliminado.');
            }
        }
    } elseif ($accion === 'mover') {
        requirePermission($usuarioActual, 'configuracion', 'editar', $base);
        $id = intOrNull($_POST['id'] ?? null);
        $direccion = $_POST['direccion'] ?? '';
        $filas = db()->query("SELECT id, orden FROM `$tablaPost` ORDER BY orden ASC, nombre ASC")->fetchAll();
        $idx = null;
        foreach ($filas as $i => $f) {
            if ((int) $f['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        $vecino = null;
        if ($idx !== null) {
            if ($direccion === 'arriba' && $idx > 0) {
                $vecino = $filas[$idx - 1];
            } elseif ($direccion === 'abajo' && $idx < count($filas) - 1) {
                $vecino = $filas[$idx + 1];
            }
        }
        if ($vecino) {
            $pdo = db();
            $pdo->prepare("UPDATE `$tablaPost` SET orden = ? WHERE id = ?")->execute([(int) $vecino['orden'], $id]);
            $pdo->prepare("UPDATE `$tablaPost` SET orden = ? WHERE id = ?")->execute([(int) $filas[$idx]['orden'], $vecino['id']]);
        }
    }
    redirect('catalogos.php?tipo=' . urlencode($tipoPost));
}

$items = db()->query("SELECT * FROM `$tabla` ORDER BY orden ASC, nombre ASC")->fetchAll();

// Cantidad de usos de cada elemento (para mostrar y para saber si se puede borrar sin avisar).
$usosPorId = [];
if ($items) {
    $ids = array_column($items, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtUso = db()->prepare("SELECT `{$cat['ref_col']}` AS id, COUNT(*) AS n FROM `{$cat['ref_tabla']}` WHERE `{$cat['ref_col']}` IN ($in) GROUP BY `{$cat['ref_col']}`");
    $stmtUso->execute($ids);
    foreach ($stmtUso->fetchAll() as $fila) {
        $usosPorId[$fila['id']] = (int) $fila['n'];
    }
}

$pageTitle = 'Configuración';
$activeNav = 'configuracion';
$breadcrumb = '<b>Configuración</b> &nbsp;/&nbsp; ' . e($cat['label']);
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Configuración de catálogos</h1>
    <p>Estas son las opciones que aparecen en los formularios de toda la aplicación. Agrega o ajusta aquí — nunca escribiendo texto libre en otro lado.</p>
  </div>
</div>

<div class="toolbar" style="flex-wrap:wrap;gap:8px;">
  <?php foreach ($catalogos as $key => $c): ?>
    <a class="btn <?= $key === $tipo ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="catalogos.php?tipo=<?= e($key) ?>"><?= e($c['label']) ?></a>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:16px;">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th style="width:70px;">Orden</th>
        <th>Nombre</th>
        <?php if ($cat['abrev']): ?><th>Abreviatura</th><?php endif; ?>
        <?php if (!empty($cat['entero'])): ?><th>Se compra completa</th><?php endif; ?>
        <th>En uso</th>
        <th>Estado</th>
        <th style="min-width:220px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="<?= 5 + ($cat['abrev'] ? 1 : 0) + (!empty($cat['entero']) ? 1 : 0) ?>" class="cell-muted" style="text-align:center;padding:24px;">Sin elementos todavía.</td></tr>
      <?php endif; ?>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td class="row-actions">
            <?php if ($puedeEditar): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                <input type="hidden" name="accion" value="mover">
                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <input type="hidden" name="direccion" value="arriba">
                <button class="icon-btn" type="submit" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                <input type="hidden" name="accion" value="mover">
                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <input type="hidden" name="direccion" value="abajo">
                <button class="icon-btn" type="submit" title="Bajar" <?= $i === count($items) - 1 ? 'disabled' : '' ?>>↓</button>
              </form>
            <?php endif; ?>
          </td>
          <?php if ($puedeEditar): ?>
            <form method="post" action="catalogos.php?tipo=<?= e($tipo) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
              <input type="hidden" name="accion" value="editar">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <td><input type="text" name="nombre" value="<?= e($it['nombre']) ?>" style="max-width:220px;"></td>
              <?php if ($cat['abrev']): ?>
                <td><input type="text" name="abreviatura" value="<?= e($it['abreviatura']) ?>" style="max-width:110px;"></td>
              <?php endif; ?>
              <?php if (!empty($cat['entero'])): ?>
                <td><label style="display:flex;align-items:center;gap:6px;font-weight:400;margin:0;"><input type="checkbox" name="es_entera" value="1" style="width:16px;height:16px;" <?= !empty($it['es_entera']) ? 'checked' : '' ?>> completa</label></td>
              <?php endif; ?>
              <td class="cell-muted"><?= (int) ($usosPorId[$it['id']] ?? 0) ?></td>
              <td><span class="chip <?= $it['activo'] ? 'chip-success' : 'chip-muted' ?>"><?= $it['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
              <td class="row-actions">
                <button class="btn btn-secondary btn-sm" type="submit">Guardar</button>
          <?php else: ?>
              <td><?= e($it['nombre']) ?></td>
              <?php if ($cat['abrev']): ?><td><?= e($it['abreviatura']) ?></td><?php endif; ?>
              <?php if (!empty($cat['entero'])): ?>
                <td><span class="chip <?= !empty($it['es_entera']) ? 'chip-neutral' : 'chip-muted' ?>"><?= !empty($it['es_entera']) ? 'Sí' : 'No' ?></span></td>
              <?php endif; ?>
              <td class="cell-muted"><?= (int) ($usosPorId[$it['id']] ?? 0) ?></td>
              <td><span class="chip <?= $it['activo'] ? 'chip-success' : 'chip-muted' ?>"><?= $it['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
              <td class="row-actions">
          <?php endif; ?>
              <?php if ($puedeEditar): ?>
                </form>
                <form method="post" action="catalogos.php?tipo=<?= e($tipo) ?>" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                  <input type="hidden" name="accion" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                  <button class="btn btn-secondary btn-sm" type="submit"><?= $it['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="catalogos.php?tipo=<?= e($tipo) ?>" style="display:inline;" data-confirm="¿Eliminar &quot;<?= e($it['nombre']) ?>&quot;? Solo se puede si no está en uso.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                  <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
              </td>
            </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($puedeCrear): ?>
<div class="card card-pad" style="margin-top:16px;max-width:520px;">
  <h2 class="section-title">Agregar a «<?= e($cat['label']) ?>»</h2>
  <form method="post" action="catalogos.php?tipo=<?= e($tipo) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <input type="hidden" name="accion" value="crear">
    <div class="field-row">
      <div class="field">
        <label for="nombre_nuevo">Nombre</label>
        <input type="text" id="nombre_nuevo" name="nombre" required placeholder="Ej. Vegano">
      </div>
      <?php if ($cat['abrev']): ?>
        <div class="field">
          <label for="abreviatura_nueva">Abreviatura</label>
          <input type="text" id="abreviatura_nueva" name="abreviatura" required placeholder="Ej. veg" style="max-width:140px;">
        </div>
      <?php endif; ?>
    </div>
    <?php if (!empty($cat['entero'])): ?>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
        <input type="checkbox" name="es_entera" value="1" style="width:16px;height:16px;"> Se compra completa (ej. no se puede comprar medio huevo o media lata)
      </label>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Agregar</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
