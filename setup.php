<?php
/**
 * Instalador de un solo uso: crea/actualiza el esquema en la base de datos
 * configurada en config.php, migra datos existentes al nuevo modelo
 * normalizado (catálogos + roles/permisos) sin perder información, y crea
 * el primer usuario administrador si todavía no existe ninguno.
 *
 * Página independiente a propósito (no usa includes/layout_top.php ni
 * requiere sesión iniciada): tiene que poder correr ANTES de que exista
 * ningún usuario en el sistema.
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

function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabla, $columna]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Migraciones puntuales para bases de datos que ya existían antes de que
 * se agregara determinada columna. CREATE TABLE IF NOT EXISTS no modifica
 * una tabla que ya existe, así que estas columnas nuevas se agregan aquí
 * a mano, comprobando primero si ya están (seguro de ejecutar varias veces).
 */
function migrarColumnasNuevas(PDO $pdo): array
{
    $mensajes = [];

    if (!columnaExiste($pdo, 'recetas', 'preparacion')) {
        $pdo->exec('ALTER TABLE recetas ADD COLUMN preparacion TEXT NULL AFTER porciones_base');
        $mensajes[] = 'Columna "preparacion" agregada a la tabla recetas.';
    }

    // unidades_medida pudo haber existido desde antes (de una versión previa
    // del catálogo de unidades) sin las columnas "activo"/"orden" que el
    // esquema actual espera. CREATE TABLE IF NOT EXISTS no las agrega porque
    // la tabla ya existe, así que se agregan aquí a mano.
    if (columnaExiste($pdo, 'unidades_medida', 'id')) {
        if (!columnaExiste($pdo, 'unidades_medida', 'activo')) {
            $pdo->exec('ALTER TABLE unidades_medida ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1');
            $mensajes[] = 'Columna "activo" agregada a la tabla unidades_medida (catálogo ya existente).';
        }
        if (!columnaExiste($pdo, 'unidades_medida', 'orden')) {
            $pdo->exec('ALTER TABLE unidades_medida ADD COLUMN orden INT UNSIGNED NOT NULL DEFAULT 0');
            $mensajes[] = 'Columna "orden" agregada a la tabla unidades_medida (catálogo ya existente).';
        }
    }

    return $mensajes;
}

/**
 * Convierte una columna de texto libre (o ENUM) en una llave foránea hacia
 * su catálogo, sin perder datos: agrega la columna nueva, puebla el
 * catálogo con los valores que ya existían (si $poblarDesdeExistente),
 * empareja por nombre/abreviatura, crea entradas de catálogo para
 * cualquier valor huérfano que no haya hecho match, y (si es obligatoria)
 * cierra con NOT NULL + FOREIGN KEY antes de borrar la columna vieja.
 * No hace nada si la columna nueva ya existe (ya migrado o instalación
 * nueva que ya nació con el esquema normalizado).
 */
function migrarColumnaCatalogo(
    PDO $pdo,
    array &$mensajes,
    string $tabla,
    string $colVieja,
    string $colNueva,
    string $tablaCatalogo,
    string $colCatalogoMatch,
    bool $obligatorio,
    bool $poblarDesdeExistente
): void {
    if (columnaExiste($pdo, $tabla, $colNueva)) {
        return; // ya migrado, o la tabla nació con el esquema nuevo
    }
    if (!columnaExiste($pdo, $tabla, $colVieja)) {
        return; // no hay nada que migrar
    }

    $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$colNueva` INT UNSIGNED NULL");

    if ($poblarDesdeExistente) {
        $pdo->exec(
            "INSERT IGNORE INTO `$tablaCatalogo` (nombre)
             SELECT DISTINCT `$colVieja` FROM `$tabla` WHERE `$colVieja` IS NOT NULL AND `$colVieja` <> ''"
        );
    }

    $pdo->exec(
        "UPDATE `$tabla` t JOIN `$tablaCatalogo` c ON c.`$colCatalogoMatch` = t.`$colVieja`
         SET t.`$colNueva` = c.id WHERE t.`$colNueva` IS NULL AND t.`$colVieja` IS NOT NULL AND t.`$colVieja` <> ''"
    );

    if ($obligatorio) {
        // Valores que no hicieron match con ningún catálogo: se crean sobre
        // la marcha (con el mismo texto) para no perder ni un solo registro.
        $huerfanos = $pdo->query(
            "SELECT DISTINCT `$colVieja` FROM `$tabla` WHERE `$colNueva` IS NULL AND `$colVieja` IS NOT NULL AND `$colVieja` <> ''"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($huerfanos as $valor) {
            if ($colCatalogoMatch === 'nombre') {
                $ins = $pdo->prepare("INSERT IGNORE INTO `$tablaCatalogo` (nombre) VALUES (?)");
                $ins->execute([$valor]);
            } else {
                $ins = $pdo->prepare("INSERT IGNORE INTO `$tablaCatalogo` (nombre, `$colCatalogoMatch`) VALUES (?, ?)");
                $ins->execute([$valor, $valor]);
            }
        }
        if ($huerfanos) {
            $pdo->exec(
                "UPDATE `$tabla` t JOIN `$tablaCatalogo` c ON c.`$colCatalogoMatch` = t.`$colVieja`
                 SET t.`$colNueva` = c.id WHERE t.`$colNueva` IS NULL AND t.`$colVieja` IS NOT NULL AND t.`$colVieja` <> ''"
            );
        }
        // Filas sin ningún valor (columna vacía o NULL): van a un catálogo "Sin definir".
        $faltantes = (int) $pdo->query("SELECT COUNT(*) FROM `$tabla` WHERE `$colNueva` IS NULL")->fetchColumn();
        if ($faltantes > 0) {
            if ($colCatalogoMatch === 'nombre') {
                $pdo->exec("INSERT IGNORE INTO `$tablaCatalogo` (nombre) VALUES ('Sin definir')");
            } else {
                $pdo->exec("INSERT IGNORE INTO `$tablaCatalogo` (nombre, `$colCatalogoMatch`) VALUES ('Sin definir', 'Sin definir')");
            }
            $idSinDefinir = (int) $pdo->query("SELECT id FROM `$tablaCatalogo` WHERE `$colCatalogoMatch` = 'Sin definir'")->fetchColumn();
            $pdo->exec("UPDATE `$tabla` SET `$colNueva` = $idSinDefinir WHERE `$colNueva` IS NULL");
        }
        $pdo->exec("ALTER TABLE `$tabla` MODIFY COLUMN `$colNueva` INT UNSIGNED NOT NULL");
    }

    $fk = 'fk_' . $tabla . '_' . $colNueva;
    $pdo->exec("ALTER TABLE `$tabla` ADD CONSTRAINT `$fk` FOREIGN KEY (`$colNueva`) REFERENCES `$tablaCatalogo`(id)");
    $pdo->exec("ALTER TABLE `$tabla` DROP COLUMN `$colVieja`");

    $mensajes[] = "Tabla \"$tabla\": \"$colVieja\" (texto) migrada a \"$colNueva\" enlazada al catálogo $tablaCatalogo.";
}

function migrarCatalogosYRoles(PDO $pdo): array
{
    $mensajes = [];
    migrarColumnaCatalogo($pdo, $mensajes, 'recetas', 'categoria', 'categoria_id', 'categorias_receta', 'nombre', true, true);
    migrarColumnaCatalogo($pdo, $mensajes, 'gastos', 'categoria', 'categoria_id', 'categorias_gasto', 'nombre', true, true);
    migrarColumnaCatalogo($pdo, $mensajes, 'eventos', 'estado', 'estado_id', 'estados_evento', 'nombre', true, true);
    migrarColumnaCatalogo($pdo, $mensajes, 'ingredientes', 'unidad', 'unidad_id', 'unidades_medida', 'abreviatura', true, true);
    migrarColumnaCatalogo($pdo, $mensajes, 'estudiantes', 'grupo', 'grupo_id', 'grupos_estudiante', 'nombre', false, true);
    return $mensajes;
}

/** Crea el primer usuario administrador si la tabla usuarios está vacía. */
function bootstrapAdmin(PDO $pdo): ?array
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    if ($total > 0) {
        return null;
    }
    $rolId = $pdo->query("SELECT id FROM roles WHERE nombre = 'Administrador'")->fetchColumn();
    if (!$rolId) {
        return null;
    }
    $usuario = 'admin';
    $passwordTemporal = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 10);
    $hash = password_hash($passwordTemporal, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, usuario, password_hash, rol_id) VALUES (?,?,?,?)');
    $stmt->execute(['Administrador', $usuario, $hash, $rolId]);
    return ['usuario' => $usuario, 'password' => $passwordTemporal];
}

$mensajes = [];
$error = null;
$hecho = false;
$adminNuevo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        $pdo = db();
        $n1 = ejecutarArchivoSql($pdo, __DIR__ . '/db/schema.sql');
        $mensajes[] = "Esquema creado/verificado correctamente ($n1 sentencias ejecutadas).";

        $mensajes = array_merge($mensajes, migrarColumnasNuevas($pdo));
        $mensajes = array_merge($mensajes, migrarCatalogosYRoles($pdo));

        $adminNuevo = bootstrapAdmin($pdo);
        if ($adminNuevo) {
            $mensajes[] = 'Usuario administrador creado.';
        }

        if (!empty($_POST['con_datos_ejemplo'])) {
            $n2 = ejecutarArchivoSql($pdo, __DIR__ . '/db/seed_demo.sql');
            $mensajes[] = "Datos de ejemplo cargados ($n2 sentencias). Esto reemplazó cualquier dato que hubiera antes en esas tablas (no toca usuarios ni catálogos).";
        }
        $hecho = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · Fogón Eventos</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:680px;margin:50px auto;padding:0 20px;color:#2A1B17;line-height:1.55;}
  h1{font-size:1.4rem;}
  .card{background:#fff;border:1px solid #E6D2C3;border-radius:14px;padding:24px;margin-top:18px;}
  .ok{background:#E3F0E4;border:1px solid #bcdec0;color:#245231;padding:14px 16px;border-radius:10px;margin-bottom:10px;}
  .err{background:#F7E1DA;border:1px solid #e3b3a2;color:#7a2d1d;padding:14px 16px;border-radius:10px;}
  .mono{font-family:ui-monospace,SFMono-Regular,monospace;background:#F4E6DD;padding:1px 6px;border-radius:5px;}
  .btn{display:inline-block;background:#7A1F2E;color:#fff;padding:10px 18px;border-radius:9px;text-decoration:none;font-weight:600;border:none;cursor:pointer;font-size:.95rem;}
  .creds{background:#2A1B17;color:#F3E7DE;padding:18px 20px;border-radius:10px;margin:14px 0;}
  .creds .mono{background:#4C382F;color:#fff;}
  label{display:flex;align-items:center;gap:8px;font-weight:400;margin:12px 0;}
</style>
</head>
<body>
<h1>🔥 Instalación de Fogón Eventos</h1>
<p>Base de datos <span class="mono"><?= e(DB_NAME) ?></span> en host <span class="mono"><?= e(DB_HOST) ?></span>.</p>

<?php if ($error): ?>
  <div class="err"><b>No se pudo completar la instalación.</b><br><?= e($error) ?></div>
<?php endif; ?>

<?php if ($mensajes): ?>
  <div class="ok"><?php foreach ($mensajes as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($adminNuevo): ?>
  <div class="creds">
    <b>Usuario administrador creado — anota esto ahora, no se vuelve a mostrar:</b><br><br>
    Usuario: <span class="mono"><?= e($adminNuevo['usuario']) ?></span><br>
    Contraseña temporal: <span class="mono"><?= e($adminNuevo['password']) ?></span><br><br>
    Inicia sesión y cámbiala de inmediato desde "Usuarios y roles" → editar tu usuario.
  </div>
<?php endif; ?>

<?php if ($hecho): ?>
  <div class="card" style="border-color:#A63A2E;">
    <h2 style="color:#A63A2E;margin-top:0;">Ahora borra este archivo</h2>
    <p>La instalación terminó. Por seguridad, elimina <span class="mono">setup.php</span> del servidor (o de esta carpeta en XAMPP) — si lo dejas, cualquiera que conozca la URL podría volver a ejecutarlo.</p>
    <a class="btn" href="login.php">Ir a iniciar sesión</a>
  </div>
<?php else: ?>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <p>Esto crea o actualiza todas las tablas necesarias (catálogos, roles, usuarios, eventos, recetas, estudiantes, gastos) y migra cualquier dato existente al nuevo modelo, sin perderlo. Si es la primera vez, también crea el usuario administrador.</p>
      <label>
        <input type="checkbox" name="con_datos_ejemplo" value="1" style="width:16px;height:16px;">
        También cargar datos de ejemplo (3 eventos, 10 estudiantes, 4 recetas) — <b>borra y reemplaza</b> cualquier dato que ya exista en esas tablas de negocio. Solo recomendado para probar en local.
      </label>
      <button class="btn" type="submit">Crear / actualizar base de datos</button>
    </form>
  </div>
<?php endif; ?>

</body>
</html>
