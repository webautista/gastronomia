<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
requirePermission($usuarioActual, 'estudiantes', 'ver', $base);
$puedeEditar = can($usuarioActual, 'estudiantes', 'editar');
$puedeCrear = can($usuarioActual, 'estudiantes', 'crear');
$puedeEliminar = can($usuarioActual, 'estudiantes', 'eliminar');
$puedeVerFondo = can($usuarioActual, 'estudiantes_fondo', 'ver');

// Eliminar estudiante (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    requirePermission($usuarioActual, 'estudiantes', 'eliminar', $base);
    csrfCheck();
    $id = intOrNull($_POST['id'] ?? null);
    if ($id) {
        db()->prepare('DELETE FROM estudiantes WHERE id = ?')->execute([$id]);
        flash('Estudiante eliminado.');
    }
    redirect('index.php');
}

$busqueda = trim($_GET['q'] ?? '');

// El padre/tutor de un estudiante ahora vive en la tabla "padres" (con su
// propio CRUD, ver módulo Padres), vinculado vía padre_estudiante — ya no en
// los campos de texto libre padre_tutor/telefono_padre_tutor (que se dejan
// en la base de datos sin usar, ver db/schema.sql). GROUP_CONCAT junta los
// nombres si un estudiante llegara a tener más de un padre/tutor vinculado.
$sql = "SELECT e.*, ge.nombre AS grupo,
               (SELECT COUNT(*) FROM evento_estudiante ee WHERE ee.estudiante_id = e.id) AS num_eventos,
               (SELECT GROUP_CONCAT(p.nombre SEPARATOR ', ')
                  FROM padre_estudiante pe JOIN padres p ON p.id = pe.padre_id
                  WHERE pe.estudiante_id = e.id) AS padres_nombres
        FROM estudiantes e
        LEFT JOIN grupos_estudiante ge ON ge.id = e.grupo_id";
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE e.nombre LIKE ? OR ge.nombre LIKE ?
              OR EXISTS (SELECT 1 FROM padre_estudiante pe JOIN padres p ON p.id = pe.padre_id
                         WHERE pe.estudiante_id = e.id AND p.nombre LIKE ?)';
    $like = '%' . $busqueda . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY e.nombre ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();

$pageTitle = 'Estudiantes';
$activeNav = 'estudiantes';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head">
  <div>
    <h1>Estudiantes</h1>
    <p>Lista maestra reutilizable en todos los eventos.</p>
  </div>
  <?php if ($puedeCrear): ?>
    <a class="btn btn-primary" href="form.php"><?= icon('plus') ?> Nuevo estudiante</a>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <?= icon('search') ?>
    <input type="text" name="q" placeholder="Buscar por nombre, grupo o padre/tutor vinculado..." value="<?= e($busqueda) ?>">
  </form>
</div>

<?php if (!$estudiantes): ?>
  <div class="card"><div class="empty"><?= icon('boxEmpty') ?>
    <div style="font-weight:600;color:var(--text);margin-bottom:2px;">Sin resultados</div>
    <div>No hay estudiantes que coincidan con tu búsqueda.</div>
  </div></div>
<?php else: ?>
  <div class="event-grid">
    <?php foreach ($estudiantes as $st): ?>
      <div class="dash-card">
        <div class="dash-card-head is-sage">
          <div class="dash-head-top">
            <div class="dash-head-id">
              <div class="child-avatar" style="width:40px;height:40px;font-size:.95rem;"><?= e(iniciales($st['nombre'])) ?></div>
              <div>
                <div class="dash-title"><?= e($st['nombre']) ?></div>
                <div class="dash-meta">
                  <span><?= icon('users') ?> <?= e($st['grupo'] ?? 'Sin grupo asignado') ?></span>
                </div>
              </div>
            </div>
            <div class="dash-chips">
              <span class="chip chip-neutral"><?= (int) $st['num_eventos'] ?> evento<?= $st['num_eventos'] == 1 ? '' : 's' ?></span>
            </div>
          </div>
        </div>
        <div class="dash-body">
          <div class="person-meta">
            <span><?= icon('phone') ?> <?= $st['telefono'] ? e($st['telefono']) : 'Sin teléfono' ?></span>
            <span><?= icon('mail') ?> <?= $st['email'] ? e($st['email']) : 'Sin email' ?></span>
          </div>
          <div class="person-meta">
            <span><?= icon('heart') ?>
              <?php if (trim((string) ($st['padres_nombres'] ?? '')) !== ''): ?>
                <?= e($st['padres_nombres']) ?>
              <?php else: ?>
                Sin padre/madre o tutor vinculado
              <?php endif; ?>
            </span>
          </div>
          <div class="dash-footer">
            <?php if ($puedeVerFondo): ?>
              <a class="btn btn-secondary btn-sm" href="detalle.php?id=<?= (int) $st['id'] ?>">Ver ficha</a>
            <?php endif; ?>
            <div class="row-actions">
              <?php if ($puedeEditar): ?>
                <a class="icon-btn" href="form.php?id=<?= (int) $st['id'] ?>" title="Editar"><?= icon('edit') ?></a>
              <?php endif; ?>
              <?php if ($puedeEliminar): ?>
                <form method="post" action="index.php" data-confirm="¿Eliminar a &quot;<?= e($st['nombre']) ?>&quot; de la lista maestra? También se quitará de los eventos donde esté asignado.">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                  <button class="icon-btn" type="submit" title="Eliminar"><?= icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
