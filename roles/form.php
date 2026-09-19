<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'usuarios', $id ? 'editar' : 'crear', $base);

// "gastos" queda excluido a propósito: es el módulo compartido viejo,
// reemplazado por "eventos_gastos"/"practicas_gastos" (y los otros seis
// permisos por pestaña) — ver migrarPermisosGastosPorContexto() en
// setup.php. La fila sigue en la base de datos, pero ya no se muestra ni
// se guarda desde esta pantalla.
$modulos = db()->query("SELECT * FROM modulos WHERE clave <> 'gastos' ORDER BY orden ASC")->fetchAll();
$acciones = ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'];

$rol = ['nombre' => '', 'descripcion' => '', 'es_sistema' => 0];
$permisos = []; // [modulo_id => ['ver'=>0,'crear'=>0,'editar'=>0,'eliminar'=>0]]
foreach ($modulos as $m) {
    $permisos[$m['id']] = ['ver' => 0, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
}
$errores = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $stmt->execute([$id]);
    $encontrado = $stmt->fetch();
    if (!$encontrado) {
        flash('Ese rol ya no existe.', 'error');
        redirect('index.php');
    }
    $rol = $encontrado;
    $stmtP = db()->prepare('SELECT * FROM permisos_rol WHERE rol_id = ?');
    $stmtP->execute([$id]);
    foreach ($stmtP->fetchAll() as $p) {
        $permisos[$p['modulo_id']] = [
            'ver' => (int) $p['ver'], 'crear' => (int) $p['crear'],
            'editar' => (int) $p['editar'], 'eliminar' => (int) $p['eliminar'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    // El nombre de un rol de sistema (Administrador) no se puede cambiar desde
    // el formulario: otras partes del código dependen de que siga llamándose
    // así, y el campo en pantalla es de solo lectura — esto lo refuerza en
    // el servidor por si alguien arma la petición a mano.
    $rol['nombre'] = $rol['es_sistema'] ? $rol['nombre'] : trim($_POST['nombre'] ?? '');
    $rol['descripcion'] = trim($_POST['descripcion'] ?? '');
    $permisosPost = $_POST['permisos'] ?? [];
    foreach ($modulos as $m) {
        if ($rol['es_sistema']) {
            // El rol de sistema (Administrador) es por definición "acceso
            // completo a todos los módulos" — no se deja editar su matriz de
            // permisos desde aquí, para que nadie (por error) se quite a sí
            // mismo, o a todo el equipo, el acceso a Usuarios y roles.
            $permisos[$m['id']] = ['ver' => 1, 'crear' => 1, 'editar' => 1, 'eliminar' => 1];
            continue;
        }
        $p = $permisosPost[$m['id']] ?? [];
        $permisos[$m['id']] = [
            'ver' => !empty($p['ver']) ? 1 : 0,
            'crear' => !empty($p['crear']) ? 1 : 0,
            'editar' => !empty($p['editar']) ? 1 : 0,
            'eliminar' => !empty($p['eliminar']) ? 1 : 0,
        ];
        // Crear, editar o eliminar sin poder ver no tiene sentido: si se marca
        // cualquiera de esos, forzamos "ver" también.
        if ($permisos[$m['id']]['crear'] || $permisos[$m['id']]['editar'] || $permisos[$m['id']]['eliminar']) {
            $permisos[$m['id']]['ver'] = 1;
        }
    }

    if ($rol['nombre'] === '') {
        $errores[] = 'El nombre del rol es obligatorio.';
    } else {
        $stmtDup = db()->prepare('SELECT id FROM roles WHERE nombre = ? AND id <> ?');
        $stmtDup->execute([$rol['nombre'], $id ?? 0]);
        if ($stmtDup->fetch()) {
            $errores[] = 'Ya existe otro rol con ese nombre.';
        }
    }

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE roles SET nombre=?, descripcion=? WHERE id=?')
                    ->execute([$rol['nombre'], $rol['descripcion'] ?: null, $id]);
                $rolId = $id;
            } else {
                $pdo->prepare('INSERT INTO roles (nombre, descripcion) VALUES (?,?)')
                    ->execute([$rol['nombre'], $rol['descripcion'] ?: null]);
                $rolId = (int) $pdo->lastInsertId();
            }
            $stmtUp = $pdo->prepare(
                'INSERT INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE ver=VALUES(ver), crear=VALUES(crear), editar=VALUES(editar), eliminar=VALUES(eliminar)'
            );
            foreach ($permisos as $moduloId => $p) {
                $stmtUp->execute([$rolId, $moduloId, $p['ver'], $p['crear'], $p['editar'], $p['eliminar']]);
            }
            $pdo->commit();
            flash($id ? 'Rol actualizado.' : 'Rol creado.');
            redirect('index.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errores[] = 'No se pudo guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = $id ? 'Editar rol' : 'Nuevo rol';
$activeNav = 'usuarios';
$breadcrumb = '<a href="../usuarios/index.php">Usuarios y roles</a> &nbsp;/&nbsp; <a href="index.php">Roles</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card" style="max-width:760px;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field-row">
      <div class="field">
        <label for="nombre">Nombre del rol</label>
        <input type="text" id="nombre" name="nombre" required value="<?= e($rol['nombre']) ?>" <?= $rol['es_sistema'] ? 'readonly' : '' ?>>
      </div>
      <div class="field">
        <label for="descripcion">Descripción</label>
        <input type="text" id="descripcion" name="descripcion" placeholder="Ej. Acceso de solo lectura" value="<?= e($rol['descripcion'] ?? '') ?>">
      </div>
    </div>

    <h2 class="section-title" style="margin-top:22px;">Permisos por pantalla</h2>
    <?php if ($rol['es_sistema']): ?>
      <p class="cell-muted" style="font-size:.85rem;">Administrador es el rol de sistema: siempre tiene acceso completo a todas las pantallas, para que nunca se quede el equipo sin nadie que pueda administrar usuarios y roles.</p>
    <?php endif; ?>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Pantalla</th>
          <?php foreach ($acciones as $clave => $label): ?><th style="text-align:center;"><?= e($label) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($modulos as $m): $esSubPestana = str_contains($m['clave'], '_'); ?>
          <tr>
            <td class="cell-name" <?= $esSubPestana ? 'style="padding-left:30px;font-weight:400;color:var(--text-secondary);"' : '' ?>>
              <?= $esSubPestana ? '↳ ' : '' ?><?= e($m['nombre']) ?>
            </td>
            <?php foreach ($acciones as $clave => $label): ?>
              <td style="text-align:center;">
                <input type="checkbox" name="permisos[<?= (int) $m['id'] ?>][<?= $clave ?>]" value="1"
                  <?= $permisos[$m['id']][$clave] ? 'checked' : '' ?> <?= $rol['es_sistema'] ? 'disabled' : '' ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (!$rol['es_sistema']): ?>
      <p class="cell-muted" style="font-size:.82rem;margin-top:8px;">Marcar Crear, Editar o Eliminar activa "Ver" automáticamente en esa pantalla. Las filas con "↳" son pestañas específicas dentro del detalle de un Evento o Práctica (independientes entre sí y entre Eventos/Prácticas) — en "Lista de Compra" solo Ver y Editar tienen efecto (Editar controla aceptar/rechazar la sugerencia de compra); Crear/Eliminar no aplican ahí.</p>
    <?php endif; ?>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear rol' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
