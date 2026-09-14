<?php
/**
 * Autenticación y permisos por rol.
 *
 * Modelo: usuarios -> rol_id -> roles; permisos_rol (rol_id, modulo_id,
 * ver/crear/editar/eliminar) define, por rol, qué puede hacer en cada
 * módulo (ver includes/../db/schema.sql, tabla "modulos"). El Administrador
 * tiene todo activado; otros roles (como "Padres") se configuran desde
 * Usuarios y roles → Roles.
 *
 * Cada página protegida debe llamar, en este orden, apenas empieza:
 *   $usuarioActual = requireLogin($base);
 *   requirePermission($usuarioActual, 'modulo_clave', 'ver', $base);
 * ($base es el mismo '.'/'..' que ya se usa para layout_top.php — defínelo
 * antes de estas llamadas, no después.)
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/** Usuario autenticado actual (con su rol), o null si no hay sesión activa. */
function currentUser(): ?array
{
    static $cache = null;
    static $resuelto = false;
    if ($resuelto) {
        return $cache;
    }
    $resuelto = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.nombre, u.usuario, u.email, u.rol_id, u.activo, r.nombre AS rol_nombre
         FROM usuarios u JOIN roles r ON r.id = u.rol_id
         WHERE u.id = ? AND u.activo = 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        // El usuario fue desactivado o eliminado después de iniciar sesión.
        $_SESSION = [];
        $cache = null;
        return null;
    }
    $cache = $usuario;
    return $usuario;
}

/** Intenta iniciar sesión. Devuelve true/false. */
function intentarLogin(string $usuario, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1');
    $stmt->execute([$usuario]);
    $fila = $stmt->fetch();
    if (!$fila || !password_verify($password, $fila['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $fila['id'];
    return true;
}

function logoutUsuario(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Exige sesión iniciada; si no hay, redirige a login.php y termina. */
function requireLogin(string $base = '.'): array
{
    $usuario = currentUser();
    if (!$usuario) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect($base . '/login.php');
    }
    return $usuario;
}

/** Permisos del rol del usuario, en caché por el resto del request. */
function permisosDeRol(int $rolId): array
{
    static $cache = [];
    if (!isset($cache[$rolId])) {
        $stmt = db()->prepare(
            'SELECT m.clave, p.ver, p.crear, p.editar, p.eliminar
             FROM permisos_rol p JOIN modulos m ON m.id = p.modulo_id
             WHERE p.rol_id = ?'
        );
        $stmt->execute([$rolId]);
        $mapa = [];
        foreach ($stmt->fetchAll() as $fila) {
            $mapa[$fila['clave']] = $fila;
        }
        $cache[$rolId] = $mapa;
    }
    return $cache[$rolId];
}

/** ¿Puede este usuario hacer $accion ('ver'|'crear'|'editar'|'eliminar') en $moduloClave? */
function can(?array $usuario, string $moduloClave, string $accion = 'ver'): bool
{
    if (!$usuario) {
        return false;
    }
    $permisos = permisosDeRol((int) $usuario['rol_id']);
    $fila = $permisos[$moduloClave] ?? null;
    return $fila ? (bool) $fila[$accion] : false;
}

/** Exige un permiso puntual; si no lo tiene, muestra "no autorizado" y termina. */
function requirePermission(array $usuario, string $moduloClave, string $accion = 'ver', string $base = '.'): void
{
    if (can($usuario, $moduloClave, $accion)) {
        return;
    }
    http_response_code(403);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Sin permiso · Fogón Eventos</title>'
        . '<link rel="stylesheet" href="' . e($base) . '/assets/css/app.css"></head><body>'
        . '<div style="max-width:480px;margin:80px auto;text-align:center;padding:0 20px;">'
        . '<div style="font-size:48px;margin-bottom:8px;">🔒</div>'
        . '<h1 style="font-family:\'Fraunces\',serif;">No tienes permiso para ver esto</h1>'
        . '<p style="color:var(--text-secondary);">Tu rol (' . e($usuario['rol_nombre']) . ') no tiene acceso a esta sección. Si crees que es un error, contacta al administrador.</p>'
        . '<a class="btn btn-primary" href="' . e($base) . '/panel.php" style="display:inline-block;margin-top:12px;">Volver al panel</a>'
        . '</div></body></html>';
    exit;
}
