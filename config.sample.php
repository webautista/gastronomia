<?php
/**
 * Plantilla de configuración.
 *
 * Copia este archivo como "config.php" (que NO se sube a git, ver .gitignore)
 * y completa los datos reales de tu base de datos.
 *
 * - En Hostinger (producción): el sitio y la base de datos viven en el mismo
 *   servidor, así que DB_HOST normalmente es "localhost".
 * - En XAMPP (desarrollo local) conectando a la base remota de Hostinger:
 *   necesitas el host remoto de MySQL. Se encuentra en hPanel -> Bases de
 *   datos -> Acceso remoto a MySQL, y ahí mismo debes autorizar tu IP.
 */

$esLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

if ($esLocal) {
    define('DB_HOST', 'CAMBIAR_POR_HOST_REMOTO_DE_HOSTINGER');
} else {
    define('DB_HOST', 'localhost');
}

define('DB_NAME', 'nombre_de_tu_base');
define('DB_USER', 'usuario_de_tu_base');
define('DB_PASS', 'contraseña_de_tu_base');
define('DB_CHARSET', 'utf8mb4');

// Zona horaria usada para fechas mostradas en la aplicación.
date_default_timezone_set('America/Santo_Domingo');
