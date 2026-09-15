<?php
/**
 * Funciones de apoyo: formato, sesión/flash, CSRF y el cálculo de
 * ingredientes según las porciones a preparar.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Escapa texto para salida segura en HTML. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/** Formatea un monto en pesos dominicanos, ej. RD$ 1,234. */
function money($valor): string
{
    return 'RD$ ' . number_format((float) $valor, 0, '.', ',');
}

/** Formatea un número con separador de miles y hasta N decimales (sin ceros de más). */
function numFmt($valor, int $decimales = 2): string
{
    $valor = (float) $valor;
    $formateado = number_format($valor, $decimales, '.', ',');
    if ($decimales > 0) {
        $formateado = rtrim(rtrim($formateado, '0'), '.');
    }
    return $formateado === '' ? '0' : $formateado;
}

/** Convierte una fecha ISO (YYYY-MM-DD) a "18 de octubre de 2026". */
function fmtDate(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    $ts = strtotime($iso);
    if ($ts === false) {
        return e($iso);
    }
    return (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
}

/** Cantidad de un ingrediente escalada según las porciones que se necesitan preparar. */
function calcularCantidad(float $cantidadBase, int $porcionesBase, int $porcionesNecesarias): float
{
    if ($porcionesBase <= 0) {
        return 0.0;
    }
    return $cantidadBase * ($porcionesNecesarias / $porcionesBase);
}

/** Redirige y termina la ejecución. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Guarda un mensaje flash para mostrarlo después de una redirección. */
function flash(string $mensaje, string $tipo = 'success'): void
{
    $_SESSION['flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
}

/** Obtiene (y limpia) el mensaje flash pendiente, si existe. */
function flashGet(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** Token CSRF para formularios POST. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verifica el token CSRF recibido por POST; corta la ejecución si no coincide. */
function csrfCheck(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Token de seguridad inválido. Vuelve atrás y actualiza la página antes de intentar de nuevo.');
    }
}

/** Etiqueta visual (clase CSS) según el estado de un evento. */
function chipEstadoClase(string $estado): string
{
    return match ($estado) {
        'En curso'   => 'chip-success',
        'Finalizado' => 'chip-muted',
        default      => 'chip-neutral',
    };
}

/** Clase del medidor de progreso según el porcentaje usado. */
function meterClase(float $pct): string
{
    if ($pct >= 100) {
        return 'danger';
    }
    if ($pct >= 80) {
        return 'warn';
    }
    return '';
}

/** Entero positivo desde $_GET/$_POST, o null si no es válido. */
function intOrNull($valor): ?int
{
    if ($valor === null || $valor === '') {
        return null;
    }
    $filtrado = filter_var($valor, FILTER_VALIDATE_INT);
    return $filtrado === false ? null : $filtrado;
}

/**
 * Costo de un ingrediente del catálogo por su "unidad de uso" (la unidad en
 * que se escribe la cantidad dentro de una receta), a partir de cómo se
 * compra realmente: precio_compra / contenido_por_compra. Ejemplo: un
 * cartón de huevos (unidad de compra) cuesta RD$194.95 y trae 30 huevos
 * (contenido_por_compra), así que cada huevo (unidad de uso) sale a
 * RD$6.50. Cuando se compra igual que se usa, contenido_por_compra es 1 y
 * el costo es el mismo precio_compra.
 */
function costoPorUnidadUso(array $ingredienteCatalogo): float
{
    $contenido = (float) ($ingredienteCatalogo['contenido_por_compra'] ?? 0);
    if ($contenido <= 0) {
        return 0.0;
    }
    return ((float) ($ingredienteCatalogo['precio_compra'] ?? 0)) / $contenido;
}
