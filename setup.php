<?php
/**
 * Instalador de un solo uso: crea las tablas en la base de datos
 * configurada en config.php (y, si lo pides, carga datos de ejemplo).
 *
 * Bórralo cuando termines de usarlo — dejarlo publicado permite que
 * cualquiera que conozca la URL vuelva a ejecutarlo.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

function ejecutarArchivoSql(PDO $pdo, string $ruta): int
{
    $sql = file_get_contents($ruta);
    $sql = preg_replace('/^--.*$/m', '', $sql); // fuera comentarios de línea
    $sentencias = array_filter(array_map('trim', explode(";\n", $sql)));
    $ejecutadas = 0;
    foreach ($sentencias as $sentencia) {
        $sentencia = rtrim(trim($sentencia), ';');
        if ($sentencia === '') {
            continue;
        }
        $pdo->exec($sentencia);
        $ejecutadas++;
    }
    return $ejecutadas;
}

/**
 * Migraciones puntuales para bases de datos que ya existían antes de que
 * se agregara determinada columna/tabla. CREATE TABLE IF NOT EXISTS no
 * modifica una tabla que ya existe, así que estas columnas nuevas se
 * agregan aquí a mano, comprobando primero si ya están (seguro de
 * ejecutar varias veces).
 */
function migrarColumnasNuevas(PDO $pdo): array
{
    $mensajes = [];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recetas' AND COLUMN_NAME = 'preparacion'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE recetas ADD COLUMN preparacion TEXT NULL AFTER porciones_base");
        $mensajes[] = 'Columna "preparacion" agregada a la tabla recetas.';
    }

    return $mensajes;
}

$mensajes = [];
$error = null;
$hecho = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        $pdo = db();
        $n1 = ejecutarArchivoSql($pdo, __DIR__ . '/db/schema.sql');
        $mensajes[] = "Esquema creado/verificado correctamente ($n1 sentencias ejecutadas).";

        $mensajes = array_merge($mensajes, migrarColumnasNuevas($pdo));

        if (!empty($_POST['con_datos_ejemplo'])) {
            $n2 = ejecutarArchivoSql($pdo, __DIR__ . '/db/seed_demo.sql');
            $mensajes[] = "Datos de ejemplo cargados ($n2 sentencias). Esto reemplazó cualquier dato que hubiera antes en estas tablas.";
        }
        $hecho = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Instalación';
$activeNav = 'panel';
$base = '.';
$breadcrumb = '<b>Instalación de la base de datos</b>';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="page-head"><div><h1>Instalación de la base de datos</h1><p>Crea las tablas que la aplicación necesita en <span class="mono"><?= e(DB_NAME) ?></span> (host: <span class="mono"><?= e(DB_HOST) ?></span>).</p></div></div>

<?php if ($error): ?>
  <div class="alert alert-error"><b>No se pudo completar la instalación.</b><br><?= e($error) ?></div>
<?php endif; ?>

<?php if ($mensajes): ?>
  <div class="alert alert-success">
    <?php foreach ($mensajes as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($hecho): ?>
  <div class="card card-pad form-card" style="border-color:var(--danger);">
    <h2 class="section-title" style="color:var(--danger);">Ahora borra este archivo</h2>
    <p class="cell-muted">La instalación terminó. Por seguridad, elimina <span class="mono">setup.php</span> del servidor (o de esta carpeta en XAMPP) — si lo dejas, cualquiera que conozca la URL podría volver a ejecutarlo.</p>
    <div class="form-actions" style="border-top:none;padding-top:0;">
      <a class="btn btn-primary" href="index.php">Ir al panel</a>
    </div>
  </div>
<?php else: ?>
  <div class="card card-pad form-card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <p>Esto crea las tablas <span class="mono">estudiantes, recetas, ingredientes, eventos, evento_estudiante, evento_receta, gastos</span> si todavía no existen. No borra tablas que ya tengan datos.</p>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
          <input type="checkbox" name="con_datos_ejemplo" value="1" style="width:16px;height:16px;">
          También cargar datos de ejemplo (3 eventos, 10 estudiantes, 4 recetas) — <b>borra y reemplaza</b> cualquier dato que ya exista en esas tablas. Solo recomendado para probar en local.
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Crear base de datos</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
