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

/**
 * Busca la fracción de cocina más cercana (medios, tercios, cuartos, octavos
 * — las que se leen en tazas y cucharas medidoras) a la parte decimal de un
 * valor, y la devuelve como texto (ej. "1/4", "1 1/2"). Devuelve null si el
 * decimal no se acerca a ninguna de esas fracciones comunes, para no
 * mostrarle a los estudiantes una fracción fea/inexacta (ej. "0.37" se deja
 * solo, no se fuerza a un octavo cercano).
 */
function fraccionCantidad(float $valor): ?string
{
    if ($valor <= 0) {
        return null;
    }

    $entero = (int) floor($valor + 0.0001);
    $resto = $valor - $entero;

    // [numerador, denominador, valor decimal exacto]. La tolerancia (0.008)
    // cubre el redondeo a 2 decimales con el que se guarda `cantidad` en la
    // base de datos (ej. 1/8 = 0.125 se guarda como 0.13) sin confundir una
    // fracción con otra: entre fracciones vecinas siempre hay más de 0.06
    // de diferencia.
    $fraccionesComunes = [
        [1, 8, 0.125], [1, 4, 0.25], [1, 3, 1 / 3], [3, 8, 0.375],
        [1, 2, 0.5], [5, 8, 0.625], [2, 3, 2 / 3], [3, 4, 0.75], [7, 8, 0.875],
    ];
    $tolerancia = 0.008;

    foreach ($fraccionesComunes as [$num, $den, $exacto]) {
        if (abs($resto - $exacto) <= $tolerancia) {
            $texto = $num . '/' . $den;
            return $entero > 0 ? $entero . ' ' . $texto : $texto;
        }
    }

    return null;
}

/**
 * Sufijo listo para concatenar después de una cantidad+unidad ya formateada
 * (ej. "0.25 taza" . fraccionSufijo(0.25) = "0.25 taza (1/4)"), o cadena
 * vacía si el valor no tiene una fracción común equivalente.
 */
function fraccionSufijo(float $valor): string
{
    $fraccion = fraccionCantidad($valor);
    return $fraccion !== null ? ' (' . $fraccion . ')' : '';
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

/**
 * Fecha de un evento para mostrar. Si la fecha es tentativa
 * (eventos.fecha_tentativa = 1) solo se muestra el mes (y el año, para no
 * confundir un mes de este año con el del próximo): "octubre de 2026" en
 * vez de "31 de octubre de 2026". Prácticas y demás fechas siguen usando
 * fmtDate().
 */
function fmtFechaEvento(?string $iso, $tentativa = false): string
{
    if (!$iso || !$tentativa) {
        return fmtDate($iso);
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return fmtDate($iso);
    }
    $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    return $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts);
}

/** Chip "Tentativa" para junto a la fecha de un evento con fecha tentativa ('' si no lo es). */
function chipFechaTentativa($tentativa): string
{
    return $tentativa
        ? '<span class="chip chip-muted" style="font-size:.7rem;" title="La fecha exacta aún no está definida: solo se sabe el mes">Tentativa</span>'
        : '';
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

/**
 * Cantidad que realmente hay que comprar para cubrir la cantidad que pide
 * la receta. Si la unidad de uso es de las que se compran completas
 * (es_entera, ej. "Unidad", "Lata") y la receta pide una fracción (ej.
 * media manzana, medio huevo), no se puede comprar esa fracción: hay que
 * redondear hacia arriba al entero siguiente. Para unidades continuas
 * (Gramo, Libra, Litro, Cucharada, etc.) la cantidad se usa tal cual.
 */
function cantidadDeCompra(float $cantidad, bool $esEntera): float
{
    if ($cantidad <= 0) {
        return 0.0;
    }
    return $esEntera ? ceil($cantidad - 0.0000001) : $cantidad;
}

/**
 * Monto (RD$) que hay que invertir en un ingrediente para cubrir la
 * cantidad que pide la receta, aplicando la regla de compra completa
 * cuando corresponde (ver cantidadDeCompra()).
 */
function montoLineaReceta(float $cantidad, float $costoUnitario, bool $esEntera): float
{
    return cantidadDeCompra($cantidad, $esEntera) * $costoUnitario;
}

/**
 * Normaliza un nombre de ingrediente para poder compararlo sin importar
 * mayúsculas/minúsculas, acentos, ni espacios de más — usado por
 * listaCompraConsolidada() para reconocer que una línea de receta escrita
 * a mano (sin quedar enlazada al catálogo por ingrediente_id — un typo, un
 * acento distinto, "uva" en vez de "Uvas"...) en realidad se refiere a un
 * ingrediente que sí existe en el catálogo, y así no perder ni la
 * consolidación ni la sugerencia de compra solo porque el enlace no se
 * hizo (sección 28 de la especificación).
 */
function normalizarNombreIngrediente(string $nombre): string
{
    $nombre = trim(mb_strtolower($nombre));
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
    if ($transliterado !== false) {
        $nombre = $transliterado;
    }
    $nombre = (string) preg_replace('/[^a-z0-9]+/', ' ', $nombre);
    return trim((string) preg_replace('/\s+/', ' ', $nombre));
}

/**
 * Costo total en vivo de UNA receta, escalado a $porcionesDeseadas (o a sus
 * propias porciones base si no se indica) — la misma suma que ya se
 * calculaba a mano dentro de la vista de receta y del detalle de un evento,
 * expuesta aquí como función compartida para que el listado de recetas, la
 * vista de solo lectura, el detalle de evento y la página pública usen
 * siempre el mismo número. Nunca se guarda en ninguna tabla — siempre se
 * recalcula a partir de los precios actuales del catálogo. Las líneas
 * marcadas "Al gusto" no tienen una cantidad medible, así que se excluyen
 * del costo (no se puede estimar cuánto cuesta "sal al gusto").
 */
function costoTotalReceta(PDO $pdo, int $recetaId, ?int $porcionesDeseadas = null): float
{
    $stmt = $pdo->prepare('SELECT porciones_base FROM recetas WHERE id = ?');
    $stmt->execute([$recetaId]);
    $porcionesBase = max(1, (int) $stmt->fetchColumn());
    if ($porcionesDeseadas === null || $porcionesDeseadas <= 0) {
        $porcionesDeseadas = $porcionesBase;
    }

    $stmtIng = $pdo->prepare(
        'SELECT i.cantidad, i.costo_unitario, i.al_gusto, um.es_entera AS unidad_entera FROM ingredientes i
         JOIN unidades_medida um ON um.id = i.unidad_id
         WHERE i.receta_id = ?'
    );
    $stmtIng->execute([$recetaId]);

    $total = 0.0;
    foreach ($stmtIng->fetchAll() as $ing) {
        if (!empty($ing['al_gusto'])) {
            continue;
        }
        $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, $porcionesDeseadas);
        $esEntera = (bool) ($ing['unidad_entera'] ?? false);
        $total += montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
    }
    return $total;
}

/**
 * Recetas asignadas a un evento o práctica, con las porciones que hace
 * falta preparar de cada una — la misma forma que espera
 * listaCompraConsolidada(). $entidadTipo es 'evento' o 'practica'.
 */
function recetasAsignadas(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    if ($entidadTipo === 'practica') {
        $stmt = $pdo->prepare('SELECT receta_id, porciones_necesarias FROM practica_receta WHERE practica_id = ?');
    } else {
        $stmt = $pdo->prepare('SELECT receta_id, porciones_necesarias FROM evento_receta WHERE evento_id = ?');
    }
    $stmt->execute([$entidadId]);
    return $stmt->fetchAll();
}

/**
 * Costo real de materiales de un evento o práctica, para la tarjeta
 * "Inversión" y para calcularCuotas() — a diferencia de sumar
 * costoTotalReceta() de cada receta por separado (la suma ingenua del
 * costo por porción de cada receta, calculada receta por receta sin verlas
 * juntas), este usa el mismo total consolidado que ya se muestra en la
 * pestaña Lista de Compra
 * (listaCompraConsolidada(), sección 12/16 de la especificación): suma los
 * ingredientes de TODAS las recetas juntas (así dos recetas que comparten
 * un ingrediente no pagan cada una su propio redondeo por separado) y
 * respeta las decisiones de compra guardadas (comprar el paquete completo
 * a su precio real, o "ya lo tiene" — compra_decisiones, sección 16). Es
 * intencional que este número pueda ser MAYOR que la suma de costos "en el
 * papel" de cada receta: si hay que comprar el paquete completo de queso
 * parmesano para usar solo 60 g, el gasto real es el del paquete, no el de
 * la porción — y la tarjeta de Inversión y la cuota deben reflejar ese
 * gasto real, no uno más bajo que nunca se va a poder cumplir en la
 * práctica. Devuelve 0 si no hay ninguna receta asignada todavía.
 */
function costoRecetasConsolidado(PDO $pdo, string $entidadTipo, int $entidadId): float
{
    return consolidadoRecetasEntidad($pdo, $entidadTipo, $entidadId)['total'];
}

/**
 * Igual que costoRecetasConsolidado() (mismas recetas asignadas + mismas
 * decisiones de compra guardadas), pero además del array completo de
 * listaCompraConsolidada() (con las decisiones de compra ya aplicadas, en
 * 'total') agrega la clave 'total_estimado': el mismo consolidado pero
 * calculado SIN ninguna decisión de compra guardada, es decir, usando solo
 * el modo de compra por defecto de cada ingrediente en el catálogo — el
 * costo "de línea base" antes de marcar nada como "ya lo tienes" o comprar
 * el paquete completo. Se usa en cierreFinanciero() para mostrar por
 * separado "Costo estimado" (antes de esas decisiones) y "Monto lista de
 * compra" (después, el número que de verdad hay que salir a comprar). Se
 * reutiliza la misma lista de recetas asignadas para ambos cálculos, así
 * los dos números siempre parten exactamente del mismo conjunto de
 * recetas. Con 0 recetas asignadas devuelve los mismos campos en 0/vacío,
 * sin tocar la base de datos de más.
 */
function consolidadoRecetasEntidad(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    $recetas = recetasAsignadas($pdo, $entidadTipo, $entidadId);
    if (!$recetas) {
        return ['lineas' => [], 'al_gusto' => [], 'total' => 0.0, 'total_estimado' => 0.0];
    }
    $decisiones = cargarDecisionesCompra($pdo, $entidadTipo, $entidadId);
    $consolidado = listaCompraConsolidada($pdo, $recetas, $decisiones);
    $consolidado['total_estimado'] = listaCompraConsolidada($pdo, $recetas, [])['total'];
    return $consolidado;
}

/**
 * Monto que "cuenta" de un gasto según en qué etapa de su ciclo de vida
 * está (proyectado → confirmado → pagado), cada una con su propio monto
 * editable por separado (ver db/schema.sql, tabla gastos): un gasto pagado
 * usa monto_pagado, uno confirmado usa monto_confirmado, y uno todavía
 * proyectado usa el monto original estimado. Los montos de una etapa
 * posterior pueden venir NULL si esa etapa fue sembrada por una versión
 * anterior del sistema (antes de que existiera esta columna) — en ese caso
 * se cae hacia el monto de la etapa anterior, nunca hacia 0.
 */
function montoEfectivoGasto(array $gasto): float
{
    if (($gasto['estado'] ?? '') === 'pagado') {
        return (float) ($gasto['monto_pagado'] ?? $gasto['monto_confirmado'] ?? $gasto['monto']);
    }
    if (($gasto['estado'] ?? '') === 'confirmado') {
        return (float) ($gasto['monto_confirmado'] ?? $gasto['monto']);
    }
    return (float) $gasto['monto'];
}

/**
 * Suma los gastos activos (no eliminados) vinculados a un evento o una
 * práctica en cuatro baldes: material vs. "otros" (según
 * es_material_receta) × proyectado vs. usado (confirmado o pagado — un
 * gasto solo proyectado NUNCA cuenta como "usado", es la corrección al bug
 * reportado de "presupuesto usado" mostrando dinero que todavía no se ha
 * confirmado). $columna es 'evento_id' o 'practica_id' — siempre uno de
 * estos dos literales fijos, nunca entrada del usuario, así que es seguro
 * interpolarla directo en el SQL.
 */
function resumenGastosVinculo(PDO $pdo, string $columna, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT estado, es_material_receta, monto, monto_confirmado, monto_pagado
         FROM gastos WHERE $columna = ? AND eliminado_en IS NULL"
    );
    $stmt->execute([$id]);

    $out = ['material_proyectado' => 0.0, 'material_usado' => 0.0, 'otros_proyectado' => 0.0, 'otros_usado' => 0.0];
    foreach ($stmt->fetchAll() as $g) {
        $material = !empty($g['es_material_receta']);
        if ($g['estado'] === 'proyectado') {
            $out[$material ? 'material_proyectado' : 'otros_proyectado'] += (float) $g['monto'];
        } else {
            $out[$material ? 'material_usado' : 'otros_usado'] += montoEfectivoGasto($g);
        }
    }
    return $out;
}

/**
 * Anota en gastos_historial un cambio a UN campo de un gasto (pensado para
 * un gasto ya "pagado": ver eventos/gasto_editar.php y
 * practicas/gasto_editar.php). Un gasto pagado es un registro de
 * auditoría, así que cualquier corrección posterior queda anotada aquí
 * —quién, cuándo, de qué valor a cuál— en vez de sobrescribirse en
 * silencio. No hace nada si el valor no cambió (comparación como texto,
 * para no anotar "cambios" que en realidad son el mismo valor con distinto
 * formato, ej. "500" vs "500.00").
 */
function registrarCambioGasto(PDO $pdo, int $gastoId, string $campo, ?string $anterior, ?string $nuevo, int $usuarioId, string $usuarioNombre): void
{
    if ((string) $anterior === (string) $nuevo) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO gastos_historial (gasto_id, campo, valor_anterior, valor_nuevo, registrado_por, registrado_por_nombre)
         VALUES (?,?,?,?,?,?)'
    )->execute([$gastoId, $campo, $anterior, $nuevo, $usuarioId, $usuarioNombre]);
}

/** Historial de cambios de un gasto (ver registrarCambioGasto()), más reciente primero. */
function historialGasto(PDO $pdo, int $gastoId): array
{
    $stmt = $pdo->prepare('SELECT * FROM gastos_historial WHERE gasto_id = ? ORDER BY creado_en DESC, id DESC');
    $stmt->execute([$gastoId]);
    return $stmt->fetchAll();
}

/** Nombre visible de cada campo que se puede rastrear en el historial de un gasto. */
function etiquetaCampoGastoHistorial(string $campo): string
{
    return match ($campo) {
        'categoria'         => 'Categoría',
        'descripcion'       => 'Descripción',
        'proveedor'         => 'Proveedor',
        'monto'             => 'Monto proyectado',
        'monto_confirmado'  => 'Monto confirmado',
        'monto_pagado'      => 'Monto pagado',
        'fecha'             => 'Fecha del gasto',
        'fecha_pago'        => 'Fecha de pago',
        'factura'           => 'Factura',
        default             => ucfirst($campo),
    };
}

/**
 * Cuota proyectada y confirmada por estudiante de un evento o práctica. El
 * total a repartir ya no se escribe a mano: siempre se calcula a partir del
 * costo de recetas y los gastos reales, dividido entre los estudiantes
 * asignados. Comparte la misma lógica entre Eventos y Prácticas (ver
 * eventos/detalle.php y practicas/detalle.php).
 *
 * "Materiales": una receta tiene un costo proyectado (el cálculo de sus
 * ingredientes, $costoRecetas — siempre en vivo). Los gastos marcados
 * "es_material_receta" no se suman aparte de ese cálculo: se comparan
 * contra él, y se usa el MAYOR de los dos ($materialUsado si ya superó lo
 * proyectado) — así, si de verdad se gastó más de lo previsto en
 * materiales, la cuota lo refleja automáticamente en vez de quedarse corta.
 * "Otros" gastos (es_material_receta = 0, ej. alquiler de salón, logística)
 * sí se suman aparte, como siempre.
 *
 * "Proyectada" es la estimación más completa (incluye lo aún no
 * confirmado, de cualquier balde); "confirmada" es solo lo que ya es gasto
 * real (materiales-final + otros ya confirmados/pagados) — es la que se
 * usa para cobrarle a cada estudiante. Con 0 estudiantes asignados ambas
 * cuotas quedan en 0 (no se puede repartir entre nadie todavía).
 *
 * $cuotaManual (eventos.cuota_confirmada_manual / practicas.
 * cuota_confirmada_manual, ver setup.php) es el ajuste que el taller puede
 * fijar cuando decide cobrar MENOS de lo que costó de verdad (ej. por
 * logística) — a pedido explícito de Eyaelkys, para que un estudiante que
 * ya pagó lo acordado no siga apareciendo con un "pendiente" fantasma
 * calculado contra el costo real. Cuando viene NULL (el caso normal),
 * 'confirmada' es igual que antes: el cálculo automático. Cuando viene un
 * número, 'confirmada' pasa a ser ESE número en vez del cálculo — pero el
 * cálculo automático se sigue devolviendo aparte, en 'confirmada_sugerida',
 * para poder mostrarlo al lado como referencia. 'meta_recaudo' (cuota
 * final × estudiantes) es lo que de verdad se espera recaudar en total —
 * antes las barras de progreso usaban 'total_confirmado' (el costo real),
 * que con un ajuste manual activo nunca llegaría a 100% aunque todos ya
 * hubieran pagado lo acordado.
 */
function calcularCuotas(float $costoRecetas, array $resumenGastos, int $cantidadEstudiantes, ?float $cuotaManual = null): array
{
    $materialUsado = $resumenGastos['material_usado'];
    $materialProyectado = $resumenGastos['material_proyectado'];
    $otrosProyectado = $resumenGastos['otros_proyectado'];
    $otrosUsado = $resumenGastos['otros_usado'];

    $materialesFinal = max($costoRecetas, $materialUsado);
    $totalProyeccion = $materialesFinal + $materialProyectado + $otrosProyectado + $otrosUsado;
    $totalConfirmado = $materialesFinal + $otrosUsado;

    $confirmadaSugerida = $cantidadEstudiantes > 0 ? $totalConfirmado / $cantidadEstudiantes : 0.0;
    $confirmadaFinal = $cuotaManual ?? $confirmadaSugerida;

    return [
        'materiales_final' => $materialesFinal,
        'material_excedido' => $materialUsado > $costoRecetas + 0.005,
        'material_exceso' => max(0.0, $materialUsado - $costoRecetas),
        'total_proyeccion' => $totalProyeccion,
        'total_confirmado' => $totalConfirmado,
        'proyectada' => $cantidadEstudiantes > 0 ? $totalProyeccion / $cantidadEstudiantes : 0.0,
        'confirmada_sugerida' => $confirmadaSugerida,
        'confirmada' => $confirmadaFinal,
        'cuota_ajustada' => $cuotaManual !== null,
        'meta_recaudo' => $confirmadaFinal * $cantidadEstudiantes,
    ];
}

/**
 * Anota en cuota_historial un cambio al ajuste manual de la cuota
 * confirmada de un evento o práctica (ver calcularCuotas() arriba y
 * eventos/cuota_editar.php, practicas/cuota_editar.php). No hace nada si
 * el valor no cambió (ninguno de los dos, o el mismo número).
 * $entidadTipo es 'evento' o 'practica' — siempre uno de estos dos
 * literales fijos, nunca entrada del usuario.
 */
function registrarCambioCuota(PDO $pdo, string $entidadTipo, int $entidadId, ?float $anterior, ?float $nuevo, ?string $nota, int $usuarioId, string $usuarioNombre): void
{
    $antStr = $anterior !== null ? sprintf('%.2f', $anterior) : null;
    $nuevStr = $nuevo !== null ? sprintf('%.2f', $nuevo) : null;
    if ($antStr === $nuevStr) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO cuota_historial (entidad_tipo, entidad_id, valor_anterior, valor_nuevo, nota, registrado_por, registrado_por_nombre)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$entidadTipo, $entidadId, $anterior, $nuevo, ($nota !== null && $nota !== '') ? $nota : null, $usuarioId, $usuarioNombre]);
}

/** Historial de ajustes de cuota de un evento o práctica (ver registrarCambioCuota()), más reciente primero. */
function historialCuota(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    $stmt = $pdo->prepare('SELECT * FROM cuota_historial WHERE entidad_tipo = ? AND entidad_id = ? ORDER BY creado_en DESC, id DESC');
    $stmt->execute([$entidadTipo, $entidadId]);
    return $stmt->fetchAll();
}

/**
 * ---------------------------------------------------------------------
 * Reportes generales (reportes/index.php) — a pedido explícito de
 * Eyaelkys: "estudiantes con saldo a favor, padres con deuda pendiente".
 * ---------------------------------------------------------------------
 */

/**
 * Reporte de cartera: una fila por cada estudiante con un pendiente real
 * (mayor a RD$0.01) en algún evento o práctica, usando la cuota VIGENTE
 * (calcularCuotas() — el ajuste manual si existe, si no el cálculo
 * automático) para no mostrar una deuda que en realidad ya no se le va a
 * cobrar. No filtra por estado del evento ni por fecha: un evento ya
 * Finalizado con un pendiente real sigue siendo dinero que falta por
 * cobrar, y es justamente el caso que más interesa dar seguimiento.
 */
function reporteCartera(PDO $pdo): array
{
    $filas = [];

    $eventos = $pdo->query(
        "SELECT ev.*, es.nombre AS estado_nombre
         FROM eventos ev JOIN estados_evento es ON es.id = ev.estado_id"
    )->fetchAll();
    foreach ($eventos as $ev) {
        $stmtEst = $pdo->prepare(
            'SELECT ee.monto_pagado, est.id AS estudiante_id, est.nombre AS estudiante_nombre
             FROM evento_estudiante ee JOIN estudiantes est ON est.id = ee.estudiante_id
             WHERE ee.evento_id = ?'
        );
        $stmtEst->execute([$ev['id']]);
        $estudiantesFilas = $stmtEst->fetchAll();
        if (!$estudiantesFilas) {
            continue;
        }
        $costoRecetas = costoRecetasConsolidado($pdo, 'evento', (int) $ev['id']);
        $resumenGastos = resumenGastosVinculo($pdo, 'evento_id', (int) $ev['id']);
        $cuotaManual = $ev['cuota_confirmada_manual'] !== null ? (float) $ev['cuota_confirmada_manual'] : null;
        $cuotaConfirmada = calcularCuotas($costoRecetas, $resumenGastos, count($estudiantesFilas), $cuotaManual)['confirmada'];
        foreach ($estudiantesFilas as $fila) {
            $pendiente = $cuotaConfirmada - (float) $fila['monto_pagado'];
            if ($pendiente > 0.005) {
                $filas[] = [
                    'entidad_tipo' => 'evento', 'entidad_id' => (int) $ev['id'],
                    'entidad_nombre' => $ev['nombre'], 'entidad_fecha' => $ev['fecha'], 'entidad_fecha_tentativa' => !empty($ev['fecha_tentativa']), 'entidad_estado' => $ev['estado_nombre'],
                    'estudiante_id' => (int) $fila['estudiante_id'], 'estudiante_nombre' => $fila['estudiante_nombre'],
                    'cuota' => $cuotaConfirmada, 'pagado' => (float) $fila['monto_pagado'], 'pendiente' => $pendiente,
                ];
            }
        }
    }

    $practicas = $pdo->query('SELECT * FROM practicas')->fetchAll();
    foreach ($practicas as $p) {
        $stmtEst = $pdo->prepare(
            'SELECT pe.monto_pagado, est.id AS estudiante_id, est.nombre AS estudiante_nombre
             FROM practica_estudiante pe JOIN estudiantes est ON est.id = pe.estudiante_id
             WHERE pe.practica_id = ?'
        );
        $stmtEst->execute([$p['id']]);
        $estudiantesFilas = $stmtEst->fetchAll();
        if (!$estudiantesFilas) {
            continue;
        }
        $costoMateriales = costoRecetasConsolidado($pdo, 'practica', (int) $p['id']);
        $resumenGastos = resumenGastosVinculo($pdo, 'practica_id', (int) $p['id']);
        $cuotaManual = $p['cuota_confirmada_manual'] !== null ? (float) $p['cuota_confirmada_manual'] : null;
        $cuotaConfirmada = calcularCuotas($costoMateriales, $resumenGastos, count($estudiantesFilas), $cuotaManual)['confirmada'];
        foreach ($estudiantesFilas as $fila) {
            $pendiente = $cuotaConfirmada - (float) $fila['monto_pagado'];
            if ($pendiente > 0.005) {
                $filas[] = [
                    'entidad_tipo' => 'practica', 'entidad_id' => (int) $p['id'],
                    'entidad_nombre' => $p['nombre'], 'entidad_fecha' => $p['fecha'], 'entidad_estado' => null,
                    'estudiante_id' => (int) $fila['estudiante_id'], 'estudiante_nombre' => $fila['estudiante_nombre'],
                    'cuota' => $cuotaConfirmada, 'pagado' => (float) $fila['monto_pagado'], 'pendiente' => $pendiente,
                ];
            }
        }
    }

    usort($filas, fn ($a, $b) => $a['estudiante_nombre'] <=> $b['estudiante_nombre'] ?: $a['entidad_fecha'] <=> $b['entidad_fecha']);

    return $filas;
}

/**
 * Reporte de fondo: saldo a favor de cada estudiante que tenga saldo
 * disponible ahora mismo (mismo cálculo en vivo que saldoFondoEstudiante(),
 * pero de una vez para todos, para no hacer una consulta por estudiante).
 */
function reporteFondoSaldos(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT e.id AS estudiante_id, e.nombre AS estudiante_nombre,
                COALESCE(SUM(CASE WHEN fm.tipo = 'deposito' THEN fm.monto ELSE -fm.monto END), 0) AS saldo,
                MAX(fm.fecha) AS ultimo_movimiento
         FROM estudiantes e
         JOIN fondo_movimientos fm ON fm.estudiante_id = e.id
         GROUP BY e.id, e.nombre
         HAVING saldo > 0.005
         ORDER BY saldo DESC"
    );
    return $stmt->fetchAll();
}

/**
 * Mapa estudiante_id => ["Padre (teléfono)", ...] — para mostrar a quién
 * contactar junto a cada fila de los reportes de cartera/fondo, sin hacer
 * una consulta de padres por cada estudiante.
 */
function mapaPadresPorEstudiante(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT pe.estudiante_id, p.nombre, p.telefono
         FROM padre_estudiante pe JOIN padres p ON p.id = pe.padre_id
         ORDER BY p.nombre ASC'
    );
    $mapa = [];
    foreach ($stmt->fetchAll() as $fila) {
        $etiqueta = $fila['nombre'] . ($fila['telefono'] ? ' (' . $fila['telefono'] . ')' : '');
        $mapa[(int) $fila['estudiante_id']][] = $etiqueta;
    }
    return $mapa;
}

/**
 * Estado de cuenta consolidado de un estudiante: todas sus participaciones
 * (eventos y prácticas, con su cuota vigente/pagado/pendiente — reutiliza
 * participacionesEstudiante()) más el fondo (saldo actual e historial
 * completo) — para reportes/estado_cuenta.php. Null si ya no existe.
 */
function estadoCuentaEstudiante(PDO $pdo, int $estudianteId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, g.nombre AS grupo FROM estudiantes e
         LEFT JOIN grupos_estudiante g ON g.id = e.grupo_id
         WHERE e.id = ?'
    );
    $stmt->execute([$estudianteId]);
    $estudiante = $stmt->fetch();
    if (!$estudiante) {
        return null;
    }

    $participaciones = participacionesEstudiante($pdo, $estudianteId);

    return [
        'estudiante' => $estudiante,
        'participaciones' => $participaciones,
        'total_cuota' => array_sum(array_column($participaciones, 'cuota_confirmada')),
        'total_pagado' => array_sum(array_column($participaciones, 'monto_pagado')),
        'total_pendiente' => array_sum(array_column($participaciones, 'pendiente')),
        'saldo_fondo' => saldoFondoEstudiante($pdo, $estudianteId),
        'historial_fondo' => historialFondoEstudiante($pdo, $estudianteId),
    ];
}

/**
 * Snapshot congelado de un cierre financiero ya cerrado (sección 42): si
 * existe una fila en cierres_financieros para esta entidad, el cierre está
 * "cerrado" y estos son los datos de costo que se compartieron en ese
 * momento — no se recalculan aunque después cambien las recetas o el
 * catálogo de ingredientes. Null si la entidad nunca se ha cerrado (o fue
 * reabierta), en cuyo caso cierreFinanciero() calcula todo en vivo.
 */
function obtenerCierreFinancieroGuardado(PDO $pdo, string $entidadTipo, int $entidadId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT datos_json, cerrado_en, cerrado_por_nombre FROM cierres_financieros
         WHERE entidad_tipo = ? AND entidad_id = ?'
    );
    $stmt->execute([$entidadTipo, $entidadId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $datos = json_decode($fila['datos_json'], true);
    if (!is_array($datos)) {
        return null;
    }
    $datos['cerrado_en'] = $fila['cerrado_en'];
    $datos['cerrado_por_nombre'] = $fila['cerrado_por_nombre'];
    return $datos;
}

/**
 * Congela (o re-congela, si ya estaba cerrado) el lado de costos del cierre
 * financiero de una entidad: costo de recetas, gastos y cuotas, tal como se
 * ven en este momento. A partir de aquí reportes/cierre.php deja de
 * recalcular ese lado aunque se editen recetas, ingredientes o gastos
 * después. El pago de los estudiantes (recaudado/pendiente) NUNCA se
 * congela — sigue en vivo siempre, para permitir que un estudiante se ponga
 * al día aunque la entidad ya esté cerrada.
 */
function cerrarCierreFinanciero(PDO $pdo, string $entidadTipo, int $entidadId, int $usuarioId, string $usuarioNombre): void
{
    $consolidadoRecetas = consolidadoRecetasEntidad($pdo, $entidadTipo, $entidadId);
    $costo = $consolidadoRecetas['total'];
    $costoEstimado = $consolidadoRecetas['total_estimado'];

    if ($entidadTipo === 'evento') {
        $campoEntidad = 'evento_id';
        $stmt = $pdo->prepare('SELECT cuota_confirmada_manual FROM eventos WHERE id = ?');
        $stmt->execute([$entidadId]);
        $cuotaManualValor = $stmt->fetchColumn();
        $stmtCant = $pdo->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ?');
    } else {
        $campoEntidad = 'practica_id';
        $stmt = $pdo->prepare('SELECT cuota_confirmada_manual FROM practicas WHERE id = ?');
        $stmt->execute([$entidadId]);
        $cuotaManualValor = $stmt->fetchColumn();
        $stmtCant = $pdo->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ?');
    }
    $cuotaManual = ($cuotaManualValor !== false && $cuotaManualValor !== null) ? (float) $cuotaManualValor : null;
    $stmtCant->execute([$entidadId]);
    $cantidadEstudiantes = (int) $stmtCant->fetchColumn();

    $resumenGastos = resumenGastosVinculo($pdo, $campoEntidad, $entidadId);
    $stmtGastos = $pdo->prepare(
        "SELECT g.*, cg.nombre AS categoria FROM gastos g
         JOIN categorias_gasto cg ON cg.id = g.categoria_id
         WHERE g.$campoEntidad = ? AND g.eliminado_en IS NULL
         ORDER BY FIELD(g.estado,'proyectado','confirmado','pagado'), g.fecha DESC"
    );
    $stmtGastos->execute([$entidadId]);
    $gastos = $stmtGastos->fetchAll();

    $catTotales = [];
    foreach ($gastos as $g) {
        $catTotales[$g['categoria']] = ($catTotales[$g['categoria']] ?? 0) + montoEfectivoGasto($g);
    }

    $cuotas = calcularCuotas($costo, $resumenGastos, $cantidadEstudiantes, $cuotaManual);

    $datos = [
        'costo_recetas' => $costo,
        'costo_estimado' => $costoEstimado,
        'gastos' => $gastos,
        'gastos_por_categoria' => $catTotales,
        'cuotas' => $cuotas,
        'cantidad_estudiantes' => $cantidadEstudiantes,
    ];

    $pdo->prepare(
        'INSERT INTO cierres_financieros (entidad_tipo, entidad_id, datos_json, cerrado_por, cerrado_por_nombre)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             datos_json = VALUES(datos_json),
             cerrado_en = CURRENT_TIMESTAMP,
             cerrado_por = VALUES(cerrado_por),
             cerrado_por_nombre = VALUES(cerrado_por_nombre)'
    )->execute([$entidadTipo, $entidadId, json_encode($datos), $usuarioId, $usuarioNombre]);
}

/**
 * Reabre un cierre financiero ya cerrado: borra el snapshot congelado, así
 * que cierreFinanciero() vuelve a calcular todo en vivo desde este momento
 * (hasta que se cierre de nuevo).
 */
function reabrirCierreFinanciero(PDO $pdo, string $entidadTipo, int $entidadId): void
{
    $pdo->prepare('DELETE FROM cierres_financieros WHERE entidad_tipo = ? AND entidad_id = ?')
        ->execute([$entidadTipo, $entidadId]);
}

/**
 * Cierre financiero de un evento o práctica: costo real vs. proyectado,
 * cuota sugerida vs. confirmada (con su nota si hay un ajuste manual, ver
 * sección 33), gastos por categoría, y estado de pago de cada estudiante —
 * para reportes/cierre.php. $entidadTipo es 'evento' o 'practica', siempre
 * uno de estos dos literales fijos. Null si la entidad ya no existe.
 *
 * Si la entidad ya fue cerrada manualmente (sección 42, ver
 * cerrarCierreFinanciero()), el lado de costos (costo_recetas,
 * costo_estimado, gastos, gastos_por_categoria, cuotas) viene del snapshot
 * congelado en vez de recalcularse — así ediciones posteriores a recetas o
 * ingredientes no alteran lo que ya se compartió. El pago de los
 * estudiantes (estudiantes/recaudado) siempre se calcula en vivo, cerrado o
 * no.
 */
function cierreFinanciero(PDO $pdo, string $entidadTipo, int $entidadId): ?array
{
    if ($entidadTipo === 'evento') {
        $stmt = $pdo->prepare(
            'SELECT ev.*, es.nombre AS estado_nombre FROM eventos ev
             JOIN estados_evento es ON es.id = ev.estado_id WHERE ev.id = ?'
        );
        $stmt->execute([$entidadId]);
        $entidad = $stmt->fetch();
        if (!$entidad) {
            return null;
        }
        $stmtEst = $pdo->prepare(
            'SELECT ee.monto_pagado, est.id AS estudiante_id, est.nombre AS estudiante_nombre
             FROM evento_estudiante ee JOIN estudiantes est ON est.id = ee.estudiante_id
             WHERE ee.evento_id = ? ORDER BY est.nombre ASC'
        );
        $stmtEst->execute([$entidadId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM practicas WHERE id = ?');
        $stmt->execute([$entidadId]);
        $entidad = $stmt->fetch();
        if (!$entidad) {
            return null;
        }
        $entidad['estado_nombre'] = null;
        $stmtEst = $pdo->prepare(
            'SELECT pe.monto_pagado, est.id AS estudiante_id, est.nombre AS estudiante_nombre
             FROM practica_estudiante pe JOIN estudiantes est ON est.id = pe.estudiante_id
             WHERE pe.practica_id = ? ORDER BY est.nombre ASC'
        );
        $stmtEst->execute([$entidadId]);
    }

    $estudiantes = $stmtEst->fetchAll();

    $guardado = obtenerCierreFinancieroGuardado($pdo, $entidadTipo, $entidadId);
    if ($guardado !== null) {
        $costo = (float) $guardado['costo_recetas'];
        $costoEstimado = (float) $guardado['costo_estimado'];
        $gastos = $guardado['gastos'];
        $catTotales = $guardado['gastos_por_categoria'];
        $cuotas = $guardado['cuotas'];
        $cerrado = true;
        $cerradoEn = $guardado['cerrado_en'];
        $cerradoPorNombre = $guardado['cerrado_por_nombre'];
    } else {
        $consolidadoRecetas = consolidadoRecetasEntidad($pdo, $entidadTipo, $entidadId);
        $costo = $consolidadoRecetas['total'];
        $costoEstimado = $consolidadoRecetas['total_estimado'];
        $campoEntidad = $entidadTipo === 'evento' ? 'evento_id' : 'practica_id';
        $resumenGastos = resumenGastosVinculo($pdo, $campoEntidad, $entidadId);
        $stmtGastos = $pdo->prepare(
            "SELECT g.*, cg.nombre AS categoria FROM gastos g
             JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE g.$campoEntidad = ? AND g.eliminado_en IS NULL
             ORDER BY FIELD(g.estado,'proyectado','confirmado','pagado'), g.fecha DESC"
        );
        $stmtGastos->execute([$entidadId]);
        $gastos = $stmtGastos->fetchAll();

        $cuotaManual = $entidad['cuota_confirmada_manual'] !== null ? (float) $entidad['cuota_confirmada_manual'] : null;
        $cuotas = calcularCuotas($costo, $resumenGastos, count($estudiantes), $cuotaManual);

        $catTotales = [];
        foreach ($gastos as $g) {
            $catTotales[$g['categoria']] = ($catTotales[$g['categoria']] ?? 0) + montoEfectivoGasto($g);
        }
        $cerrado = false;
        $cerradoEn = null;
        $cerradoPorNombre = null;
    }

    $cuotaConfirmada = $cuotas['confirmada'];

    $metodosPorEstudiante = recaudadoPorMetodoYEstudiante($pdo, $entidadTipo, $entidadId);

    $filasEstudiantes = [];
    foreach ($estudiantes as $e) {
        $pagado = (float) $e['monto_pagado'];
        $porMetodoEst = $metodosPorEstudiante[(int) $e['estudiante_id']] ?? [];
        // Igual que en recaudadoPorMetodo(): lo que el historial no explica
        // (datos antiguos) se muestra como "sin especificar".
        $diferencia = $pagado - array_sum($porMetodoEst);
        if (abs($diferencia) > 0.005 && $pagado > 0.005) {
            $porMetodoEst['sin_especificar'] = ($porMetodoEst['sin_especificar'] ?? 0.0) + $diferencia;
        }
        $filasEstudiantes[] = [
            'estudiante_id' => (int) $e['estudiante_id'],
            'estudiante_nombre' => $e['estudiante_nombre'],
            'pagado' => $pagado,
            'pendiente' => max(0.0, $cuotaConfirmada - $pagado),
            'por_metodo' => $porMetodoEst,
        ];
    }

    $recaudado = array_sum(array_column($filasEstudiantes, 'pagado'));

    return [
        'tipo' => $entidadTipo,
        'entidad' => $entidad,
        'costo_recetas' => $costo,
        'costo_estimado' => $costoEstimado,
        'gastos' => $gastos,
        'gastos_por_categoria' => $catTotales,
        'cuotas' => $cuotas,
        'estudiantes' => $filasEstudiantes,
        'recaudado' => $recaudado,
        'recaudado_por_metodo' => recaudadoPorMetodo($pdo, $entidadTipo, $entidadId, $recaudado),
        'cantidad_estudiantes' => count($estudiantes),
        'cerrado' => $cerrado,
        'cerrado_en' => $cerradoEn,
        'cerrado_por_nombre' => $cerradoPorNombre,
    ];
}

/**
 * Lo recaudado de un evento/práctica separado por cómo llegó (cierre
 * financiero): 'fondo' (aplicado desde el fondo del estudiante), 'efectivo',
 * 'transferencia' y 'sin_especificar' (pagos antiguos sin método). Cada
 * método trae 'monto' y 'pagos' (cantidad de pagos). Se suma pagos_estudiante
 * en vivo (los pagos no se congelan con el cierre, igual que "Recaudado");
 * si por datos antiguos el total cacheado ($recaudadoTotal, la suma de
 * monto_pagado) no coincide con el historial, la diferencia se muestra como
 * 'sin_especificar' para que las líneas siempre cuadren con el total.
 */
function recaudadoPorMetodo(PDO $pdo, string $entidadTipo, int $entidadId, float $recaudadoTotal): array
{
    $res = [];
    foreach (['fondo', 'efectivo', 'transferencia', 'sin_especificar'] as $m) {
        $res[$m] = ['monto' => 0.0, 'pagos' => 0];
    }
    $stmt = $pdo->prepare(
        'SELECT metodo, COALESCE(SUM(monto), 0) AS total, COUNT(*) AS pagos
         FROM pagos_estudiante WHERE entidad_tipo = ? AND entidad_id = ? GROUP BY metodo'
    );
    $stmt->execute([$entidadTipo, $entidadId]);
    foreach ($stmt->fetchAll() as $f) {
        $clave = isset($res[$f['metodo']]) ? $f['metodo'] : 'sin_especificar';
        $res[$clave]['monto'] += (float) $f['total'];
        $res[$clave]['pagos'] += (int) $f['pagos'];
    }
    $diferencia = $recaudadoTotal - array_sum(array_column($res, 'monto'));
    if (abs($diferencia) > 0.005) {
        $res['sin_especificar']['monto'] += $diferencia;
    }
    return $res;
}

/**
 * Lo pagado por cada estudiante de un evento/práctica, separado por método
 * (vista interna del cierre financiero): [estudiante_id => [metodo => monto]]
 * con solo los métodos que tienen monto. Los métodos desconocidos cuentan
 * como 'sin_especificar'.
 */
function recaudadoPorMetodoYEstudiante(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    $stmt = $pdo->prepare(
        'SELECT estudiante_id, metodo, COALESCE(SUM(monto), 0) AS total
         FROM pagos_estudiante WHERE entidad_tipo = ? AND entidad_id = ? GROUP BY estudiante_id, metodo'
    );
    $stmt->execute([$entidadTipo, $entidadId]);
    $res = [];
    foreach ($stmt->fetchAll() as $f) {
        $clave = in_array($f['metodo'], ['fondo', 'efectivo', 'transferencia'], true) ? $f['metodo'] : 'sin_especificar';
        $eid = (int) $f['estudiante_id'];
        $res[$eid][$clave] = ($res[$eid][$clave] ?? 0.0) + (float) $f['total'];
    }
    return $res;
}

/**
 * Historial de pagos (tabla pagos_estudiante) de un estudiante en un evento
 * o práctica, del más reciente al más antiguo — para la pantalla
 * eventos/pago_estudiante.php y practicas/pago_estudiante.php.
 */
function historialPagosEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM pagos_estudiante
         WHERE entidad_tipo = ? AND entidad_id = ? AND estudiante_id = ?
         ORDER BY fecha_pago DESC, id DESC'
    );
    $stmt->execute([$entidadTipo, $entidadId, $estudianteId]);
    return $stmt->fetchAll();
}

/**
 * Recalcula evento_estudiante.monto_pagado/pagado/fecha_pago (o el
 * equivalente en practica_estudiante) sumando pagos_estudiante — estas
 * columnas quedan como un valor DERIVADO, nunca editado directo desde
 * ninguna pantalla, para que panel.php, index.php y el resto de pantallas
 * que ya leen "cuánto ha pagado este estudiante" sigan funcionando igual
 * que antes de existir el historial de pagos. Se llama siempre después de
 * registrarPagoEstudiante() o eliminarPagoEstudiante(), nunca por separado.
 */
function recomputarMontoPagadoEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId): void
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(monto), 0) AS total, MAX(fecha_pago) AS ultima
         FROM pagos_estudiante WHERE entidad_tipo = ? AND entidad_id = ? AND estudiante_id = ?'
    );
    $stmt->execute([$entidadTipo, $entidadId, $estudianteId]);
    $fila = $stmt->fetch();
    $total = (float) $fila['total'];
    $ultima = $total > 0.005 ? $fila['ultima'] : null;

    if ($entidadTipo === 'evento') {
        $pdo->prepare('UPDATE evento_estudiante SET monto_pagado = ?, pagado = ?, fecha_pago = ? WHERE evento_id = ? AND estudiante_id = ?')
            ->execute([$total, $total > 0.005 ? 1 : 0, $ultima, $entidadId, $estudianteId]);
    } else {
        $pdo->prepare('UPDATE practica_estudiante SET monto_pagado = ?, fecha_pago = ? WHERE practica_id = ? AND estudiante_id = ?')
            ->execute([$total, $ultima, $entidadId, $estudianteId]);
    }
}

/**
 * Agrega un pago al historial y recalcula el total cacheado (ver arriba).
 * Devuelve el id del pago insertado (lo usa aplicarFondoEstudiante() para
 * enlazar el movimiento del fondo con este pago) — se captura ANTES de
 * llamar a recomputarMontoPagadoEstudiante(), porque ese UPDATE deja
 * lastInsertId() en 0 en esta conexión (probado: un UPDATE posterior borra
 * el valor que dejó el INSERT anterior).
 */
function registrarPagoEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId, float $monto, string $metodo, string $fechaPago, ?string $nota): int
{
    $pdo->prepare(
        'INSERT INTO pagos_estudiante (entidad_tipo, entidad_id, estudiante_id, monto, metodo, fecha_pago, nota)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$entidadTipo, $entidadId, $estudianteId, $monto, $metodo, $fechaPago, ($nota !== null && $nota !== '') ? $nota : null]);
    $pagoId = (int) $pdo->lastInsertId();
    recomputarMontoPagadoEstudiante($pdo, $entidadTipo, $entidadId, $estudianteId);
    return $pagoId;
}

/** Corrige un pago ya registrado (monto/método/fecha/nota) y recalcula el total cacheado. */
function editarPagoEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId, int $pagoId, float $monto, string $metodo, string $fechaPago, ?string $nota): void
{
    $pdo->prepare(
        'UPDATE pagos_estudiante SET monto = ?, metodo = ?, fecha_pago = ?, nota = ?
         WHERE id = ? AND entidad_tipo = ? AND entidad_id = ? AND estudiante_id = ?'
    )->execute([$monto, $metodo, $fechaPago, ($nota !== null && $nota !== '') ? $nota : null, $pagoId, $entidadTipo, $entidadId, $estudianteId]);
    recomputarMontoPagadoEstudiante($pdo, $entidadTipo, $entidadId, $estudianteId);
}

/** Quita un pago del historial (para corregir uno registrado por error) y recalcula el total cacheado. */
function eliminarPagoEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId, int $pagoId): void
{
    $pdo->prepare('DELETE FROM pagos_estudiante WHERE id = ? AND entidad_tipo = ? AND entidad_id = ? AND estudiante_id = ?')
        ->execute([$pagoId, $entidadTipo, $entidadId, $estudianteId]);
    recomputarMontoPagadoEstudiante($pdo, $entidadTipo, $entidadId, $estudianteId);
}

/** Etiqueta legible del método de pago (pagos_estudiante.metodo) para mostrar en pantalla. */
function etiquetaMetodoPago(string $metodo): string
{
    switch ($metodo) {
        case 'efectivo':
            return 'Efectivo';
        case 'transferencia':
            return 'Transferencia bancaria';
        case 'fondo':
            return 'Fondo del estudiante';
        default:
            return 'Sin especificar';
    }
}

/**
 * ---------------------------------------------------------------------
 * Fondo del estudiante (fondo_movimientos, ver db/schema.sql): un padre
 * deposita por adelantado y ese saldo se va aplicando a cuotas de eventos y
 * prácticas. El saldo NUNCA se guarda — siempre se calcula en vivo sumando
 * depósitos y restando aplicaciones, para no arrastrar un total
 * desincronizado si algo se corrige después.
 * ---------------------------------------------------------------------
 */

/** Saldo disponible ahora mismo en el fondo de un estudiante. */
function saldoFondoEstudiante(PDO $pdo, int $estudianteId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN tipo = 'deposito' THEN monto ELSE -monto END), 0)
         FROM fondo_movimientos WHERE estudiante_id = ?"
    );
    $stmt->execute([$estudianteId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Historial completo de movimientos del fondo de un estudiante (depósitos y
 * aplicaciones), del más reciente al más antiguo, con el nombre del
 * evento/práctica cuando el movimiento es una aplicación.
 */
function historialFondoEstudiante(PDO $pdo, int $estudianteId): array
{
    $stmt = $pdo->prepare(
        "SELECT fm.*,
                CASE fm.entidad_tipo
                    WHEN 'evento' THEN (SELECT nombre FROM eventos WHERE id = fm.entidad_id)
                    WHEN 'practica' THEN (SELECT nombre FROM practicas WHERE id = fm.entidad_id)
                    ELSE NULL
                END AS entidad_nombre
         FROM fondo_movimientos fm
         WHERE fm.estudiante_id = ?
         ORDER BY fm.fecha DESC, fm.id DESC"
    );
    $stmt->execute([$estudianteId]);
    return $stmt->fetchAll();
}

/** Registra un depósito al fondo de un estudiante ($metodo: 'efectivo' o 'transferencia'). */
function depositarFondoEstudiante(PDO $pdo, int $estudianteId, float $monto, string $metodo, string $fecha, ?string $nota, int $usuarioId, string $usuarioNombre): void
{
    $pdo->prepare(
        'INSERT INTO fondo_movimientos (estudiante_id, tipo, monto, metodo, fecha, nota, registrado_por, registrado_por_nombre)
         VALUES (?, \'deposito\', ?, ?, ?, ?, ?, ?)'
    )->execute([$estudianteId, $monto, $metodo, $fecha, ($nota !== null && $nota !== '') ? $nota : null, $usuarioId, $usuarioNombre]);
}

/**
 * Aplica un monto del fondo del estudiante a la cuota de un evento o
 * práctica: registra el pago reutilizando registrarPagoEstudiante() (con
 * metodo='fondo', para que el historial de pagos de ese evento/práctica y
 * el monto_pagado ya existentes se mantengan en sincronía automáticamente,
 * ver el comentario en pagos_estudiante en db/schema.sql) y, en la misma
 * transacción, registra el movimiento en fondo_movimientos enlazado a ese
 * pago. Devuelve un arreglo de errores (vacío si todo salió bien) — nunca
 * deja aplicar más de lo que el estudiante tiene disponible en el fondo.
 */
function aplicarFondoEstudiante(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId, float $monto, string $fecha, ?string $nota, int $usuarioId, string $usuarioNombre): array
{
    if ($monto <= 0) {
        return ['El monto debe ser mayor a 0.'];
    }
    $saldo = saldoFondoEstudiante($pdo, $estudianteId);
    if ($monto > $saldo + 0.005) {
        return ['El estudiante solo tiene ' . number_format($saldo, 2) . ' disponible en su fondo.'];
    }

    $pdo->beginTransaction();
    try {
        $pagoId = registrarPagoEstudiante($pdo, $entidadTipo, $entidadId, $estudianteId, $monto, 'fondo', $fecha, $nota);

        $pdo->prepare(
            'INSERT INTO fondo_movimientos (estudiante_id, tipo, monto, fecha, entidad_tipo, entidad_id, pago_estudiante_id, nota, registrado_por, registrado_por_nombre)
             VALUES (?, \'aplicacion\', ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$estudianteId, $monto, $fecha, $entidadTipo, $entidadId, $pagoId, ($nota !== null && $nota !== '') ? $nota : null, $usuarioId, $usuarioNombre]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [];
}

/**
 * Elimina un movimiento del fondo (para corregir uno registrado por error).
 * Un depósito solo se puede eliminar si el saldo restante, después de
 * quitarlo, no queda negativo (es decir, si ya se aplicó más de lo que
 * quedaría). Una aplicación siempre revierte también el pago vinculado en
 * pagos_estudiante (eliminarPagoEstudiante(), que recalcula monto_pagado),
 * para que el evento/práctica correspondiente no se quede con un pago
 * "fantasma" que ya nadie respalda desde el fondo. Devuelve un arreglo de
 * errores (vacío si se eliminó correctamente).
 */
function eliminarMovimientoFondo(PDO $pdo, int $movimientoId, int $estudianteId): array
{
    $stmt = $pdo->prepare('SELECT * FROM fondo_movimientos WHERE id = ? AND estudiante_id = ?');
    $stmt->execute([$movimientoId, $estudianteId]);
    $movimiento = $stmt->fetch();
    if (!$movimiento) {
        return ['Ese movimiento ya no existe.'];
    }

    if ($movimiento['tipo'] === 'deposito') {
        $saldo = saldoFondoEstudiante($pdo, $estudianteId);
        if ($saldo - (float) $movimiento['monto'] < -0.005) {
            return ['No se puede eliminar: ya se aplicó del fondo más de lo que quedaría disponible sin este depósito.'];
        }
        $pdo->prepare('DELETE FROM fondo_movimientos WHERE id = ?')->execute([$movimientoId]);
        return [];
    }

    // tipo === 'aplicacion': revertir también el pago vinculado, si todavía existe.
    $pdo->beginTransaction();
    try {
        if ($movimiento['pago_estudiante_id'] && $movimiento['entidad_tipo'] && $movimiento['entidad_id']) {
            eliminarPagoEstudiante($pdo, $movimiento['entidad_tipo'], (int) $movimiento['entidad_id'], $estudianteId, (int) $movimiento['pago_estudiante_id']);
        }
        $pdo->prepare('DELETE FROM fondo_movimientos WHERE id = ?')->execute([$movimientoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [];
}

/**
 * Tipo de medida y factor_base EFECTIVOS de una unidad, para
 * convertirCantidadEntreUnidades()/convertirCostoPorUnidad(). Para casi
 * cualquier unidad es simplemente lo que ya trae su fila de
 * unidades_medida (tipo_medida/factor_base). La única excepción es la
 * unidad de conteo "Unidad": por sí sola no tiene un tamaño universal (una
 * unidad de guineo no pesa lo mismo que una de mango), pero cuando el
 * ingrediente en cuestión sí tiene su propio "peso por unidad" cargado
 * (ingredientes_catalogo.peso_unidad_g, $pesoUnidadG aquí), se puede tratar
 * como si fuera una unidad de masa cuyo factor_base es ESE peso — el mismo
 * truco que densidad_g_ml ya usa para puentear masa↔volumen, aplicado aquí
 * a Unidad↔masa. Devuelve [tipo_medida ('masa'/'volumen'/null), factor_base].
 */
function tipoYFactorDeUnidad(array $unidad, ?float $pesoUnidadG): array
{
    $tipo = $unidad['tipo_medida'] ?? null;
    $factor = (float) ($unidad['factor_base'] ?? 0);
    if ($tipo && $factor > 0) {
        return [$tipo, $factor];
    }
    if ($pesoUnidadG !== null && $pesoUnidadG > 0 && ($unidad['nombre'] ?? '') === 'Unidad') {
        return ['masa', $pesoUnidadG];
    }
    return [null, 0.0];
}

/**
 * Convierte una CANTIDAD (no un costo) de una unidad a otra — hace posible
 * que una línea de receta escrita en Cucharadita se pueda comparar/sumar con
 * otra escrita en Gramo del mismo ingrediente. Tres casos:
 *
 * 1) Mismo tipo_medida (masa con masa, o volumen con volumen): conversión
 *    universal vía factor_base — 1 Onza siempre son 28.35 g, para
 *    cualquier ingrediente. Ej. 300 g a Onza: 300 × (1/28.35) ≈ 10.58 oz.
 *
 * 2) Distinto tipo_medida (una de masa, otra de volumen): NO hay una
 *    equivalencia universal — una cucharada de mantequilla no pesa lo
 *    mismo que una de harina — así que hace falta la densidad (gramos por
 *    mililitro) de ESE ingrediente en particular ($densidadGml, columna
 *    ingredientes_catalogo.densidad_g_ml). Sin ella, devuelve null (el
 *    caso más común: la enorme mayoría de ingredientes no la tienen
 *    cargada porque solo se usan en un tipo de medida).
 *
 * 3) La unidad de conteo "Unidad" puente hacia masa/volumen: tampoco hay
 *    una equivalencia universal (una unidad de fresa no pesa lo mismo que
 *    una de guineo), así que hace falta el "peso por unidad" de ESE
 *    ingrediente en particular ($pesoUnidadG, columna
 *    ingredientes_catalogo.peso_unidad_g) — ver tipoYFactorDeUnidad() más
 *    arriba. Sin él, "Unidad" sigue sin ser convertible, como siempre.
 *
 * Cualquier otra unidad "de conteo" (Lata, Diente, Rebanada...) sigue sin
 * tipo_medida/factor_base y por lo tanto sin convertir — ahí no hay forma
 * de saber, por ejemplo, cuántos gramos "es" media lata, y el valor se debe
 * ajustar a mano. $unidadOrigen / $unidadDestino son filas de
 * unidades_medida (o null). Se usa en listaCompraConsolidada() para
 * consolidar cantidades del mismo ingrediente escritas en unidades
 * distintas, y como base de convertirCostoPorUnidad() (ver más abajo).
 *
 * 4) Unidad de uso ↔ unidad de compra del propio catálogo (ej. Gelatina sin
 *    sabor: se USA por Cucharadita pero se COMPRA por Paquete, y
 *    contenido_por_compra dice cuántas cucharaditas trae un paquete). A
 *    diferencia de la densidad o el peso por unidad, este dato SIEMPRE
 *    existe para cualquier ingrediente del catálogo (unidad_id,
 *    unidad_compra_id y contenido_por_compra son obligatorios), así que
 *    esta conversión aplica de una vez, sin necesitar carga extra — es lo
 *    que permite, por ejemplo, que una receta que escribe "1 paquete de
 *    gelatina" y otra que escribe "1.5 cucharaditas" se consoliden en una
 *    sola línea en la Lista de Compra. Para lograrlo se pasa $catalogo (la
 *    fila de ingredientes_catalogo de ESTE ingrediente: unidad_id,
 *    unidad_compra_id, contenido_por_compra) y $unidadesPorId (mapa id →
 *    fila de unidades_medida, para poder ubicar la unidad de uso del
 *    catálogo como "escalón" intermedio al encadenar, ej. Paquete → uso →
 *    Gramo). Si el destino/origen final no es ni la unidad de uso ni la de
 *    compra, se encadena UNA vez a través de la unidad de uso y desde ahí
 *    se sigue con los casos 1-3 normalmente.
 */
function convertirCantidadEntreUnidades(float $cantidadOrigen, ?array $unidadOrigen, ?array $unidadDestino, ?float $densidadGml = null, ?float $pesoUnidadG = null, ?array $catalogo = null, ?array $unidadesPorId = null): ?float
{
    if (!$unidadOrigen || !$unidadDestino) {
        return null;
    }
    if ((int) $unidadOrigen['id'] === (int) $unidadDestino['id']) {
        return $cantidadOrigen;
    }

    if ($catalogo && $unidadesPorId) {
        $idUso = (int) ($catalogo['unidad_id'] ?? 0);
        $idCompra = (int) ($catalogo['unidad_compra_id'] ?? 0);
        $contenido = (float) ($catalogo['contenido_por_compra'] ?? 0);
        $idOrigen = (int) $unidadOrigen['id'];
        $idDestino = (int) $unidadDestino['id'];
        if ($idUso > 0 && $idCompra > 0 && $idUso !== $idCompra && $contenido > 0) {
            if ($idOrigen === $idCompra && $idDestino === $idUso) {
                return $cantidadOrigen * $contenido;
            }
            if ($idOrigen === $idUso && $idDestino === $idCompra) {
                return $cantidadOrigen / $contenido;
            }
            $unidadUso = $unidadesPorId[$idUso] ?? null;
            if ($unidadUso && $idOrigen === $idCompra) {
                // Compra -> uso (vía contenido_por_compra) -> lo que pida el destino.
                return convertirCantidadEntreUnidades($cantidadOrigen * $contenido, $unidadUso, $unidadDestino, $densidadGml, $pesoUnidadG, null, $unidadesPorId);
            }
            if ($unidadUso && $idDestino === $idCompra) {
                // Origen -> uso -> compra (vía contenido_por_compra).
                $enUso = convertirCantidadEntreUnidades($cantidadOrigen, $unidadOrigen, $unidadUso, $densidadGml, $pesoUnidadG, null, $unidadesPorId);
                return $enUso !== null ? $enUso / $contenido : null;
            }
        }
    }

    [$tipoOrigen, $factorOrigen] = tipoYFactorDeUnidad($unidadOrigen, $pesoUnidadG);
    [$tipoDestino, $factorDestino] = tipoYFactorDeUnidad($unidadDestino, $pesoUnidadG);
    if (!$tipoOrigen || !$tipoDestino || $factorOrigen <= 0 || $factorDestino <= 0) {
        return null;
    }
    if ($tipoOrigen === $tipoDestino) {
        return $cantidadOrigen * ($factorOrigen / $factorDestino);
    }
    if ($densidadGml === null || $densidadGml <= 0) {
        return null;
    }
    // $baseOrigen queda en gramos (si $tipoOrigen es 'masa') o en
    // mililitros (si es 'volumen') — factor_base siempre está expresado en
    // la unidad base de su propio tipo (ver comentario en db/schema.sql).
    $baseOrigen = $cantidadOrigen * $factorOrigen;
    if ($tipoOrigen === 'masa' && $tipoDestino === 'volumen') {
        $baseDestino = $baseOrigen / $densidadGml; // g ÷ (g/ml) = ml
    } elseif ($tipoOrigen === 'volumen' && $tipoDestino === 'masa') {
        $baseDestino = $baseOrigen * $densidadGml; // ml × (g/ml) = g
    } else {
        return null; // por si en el futuro aparece un tercer tipo_medida
    }
    return $baseDestino / $factorDestino;
}

/**
 * Convierte un costo por unidad (ej. RD$/Onza) a su equivalente en otra
 * unidad (ej. RD$/Gramo). Se usa al cambiar la unidad de una línea de
 * receta: si el ingrediente venía con el costo calculado para una unidad y
 * se cambia a otra, hay que recalcular el costo para que "cantidad × costo"
 * siga siendo correcto — de lo contrario se termina multiplicando gramos
 * por un precio que en realidad es por onza (el bug real que motivó esta
 * función). Se apoya en convertirCantidadEntreUnidades(): si 1 unidadOrigen
 * equivale a X unidadDestino, 1 unidadOrigen cuesta lo mismo que X
 * unidadDestino, así que el costo por unidadDestino es el costo por
 * unidadOrigen ÷ X. $densidadGml y $pesoUnidadG se pasan igual que en esa
 * función, para poder convertir también entre masa y volumen cuando el
 * ingrediente tiene densidad cargada (ej. mantequilla de Cucharadita a
 * Gramo), o desde/hacia la unidad de conteo "Unidad" cuando tiene su peso
 * por unidad cargado (ej. fresas de Taza a Unidad).
 *
 * Devuelve null cuando no son convertibles automáticamente (ver
 * convertirCantidadEntreUnidades) — en ese caso el costo se debe ajustar a
 * mano. $catalogo / $unidadesPorId: igual que en convertirCantidadEntreUnidades(),
 * para poder usar también el puente unidad de uso ↔ unidad de compra.
 */
function convertirCostoPorUnidad(float $costoPorUnidadOrigen, ?array $unidadOrigen, ?array $unidadDestino, ?float $densidadGml = null, ?float $pesoUnidadG = null, ?array $catalogo = null, ?array $unidadesPorId = null): ?float
{
    if (!$unidadOrigen || !$unidadDestino) {
        return null;
    }
    if ((int) $unidadOrigen['id'] === (int) $unidadDestino['id']) {
        return $costoPorUnidadOrigen;
    }
    $equivalencia = convertirCantidadEntreUnidades(1.0, $unidadOrigen, $unidadDestino, $densidadGml, $pesoUnidadG, $catalogo, $unidadesPorId);
    if ($equivalencia === null || $equivalencia <= 0) {
        return null;
    }
    return $costoPorUnidadOrigen / $equivalencia;
}

/**
 * Consolida en una sola lista de compra los ingredientes de un conjunto de
 * recetas, cada una con las porciones que hace falta preparar (de un evento
 * o de una práctica — cualquier lugar donde se asignen recetas con
 * porciones_necesarias). La gracia de consolidar en vez de sumar el costo
 * "ya redondeado" de cada receta por separado: si tres recetas usan 0.3,
 * 0.4 y 0.5 huevos cada una, comprar por separado redondearía a un huevo
 * completo TRES veces (3 huevos); consolidado, se suman las cantidades
 * crudas primero (1.2 huevos) y la regla de "se compra completa" se aplica
 * una sola vez sobre el total (2 huevos) — se ahorra comprar de más.
 *
 * Agrupa por ingrediente del catálogo (ingrediente_id) cuando existe, o por
 * nombre de texto libre en minúsculas cuando no; dentro de un mismo
 * ingrediente, convierte a una unidad común cuando las unidades son
 * compatibles (mismo tipo_medida, o distinto tipo_medida si el ingrediente
 * tiene densidad_g_ml cargada — ver convertirCantidadEntreUnidades()) — si
 * no son convertibles (unidades de conteo distintas, o masa vs. volumen sin
 * densidad), quedan como líneas separadas para no inventar una conversión
 * que no existe. Las líneas "Al gusto" no tienen cantidad medible: se
 * listan aparte, solo para recordar que hace falta tenerlas a mano.
 *
 * Cada línea trae, además de la cantidad en la unidad de uso (la que se
 * escribe en la receta, ej. "7.5 taza"), un posible 'compra' con cuánto hay
 * que llevar al súper en la unidad en que ese ingrediente realmente se
 * vende (ej. "2 lb") — a partir de `unidad_compra_id`/`contenido_por_compra`
 * del catálogo (sección 4 de la especificación). Solo se calcula cuando el
 * ingrediente viene del catálogo, tiene una unidad de compra distinta a la
 * de uso, y ambas son convertibles; si no, 'compra' queda en null y la
 * pantalla solo muestra la cantidad de uso, como antes. Si la unidad de
 * compra es de las que se compran completas (es_entera, ej. Paquete,
 * Cartón), la cantidad de compra se redondea hacia arriba UNA vez sobre el
 * total ya consolidado — mismo principio de "sumar primero, redondear
 * después" que ya se aplica al costo.
 *
 * Cuando una línea trae 'compra' (hay que llevar el ingrediente en su
 * presentación de compra, ej. el paquete completo de pasta o de queso), el
 * monto de esa línea para el total de ESTA lista de compra ya no es el
 * costo prorrateado de la porción usada en la receta, sino el precio del
 * paquete (editable, ver `compra_decisiones`) multiplicado por cuántos
 * paquetes hacen falta — porque en la práctica hay que comprar el paquete
 * completo, no una fracción de él. Cada línea con 'compra' trae también
 * 'compra_decision' => ['comprar_paquete' => bool, 'precio_paquete' =>
 * float, 'precio_sugerido' => float, 'monto_porcion' => float]:
 * 'comprar_paquete' true (el valor por defecto si no hay una decisión
 * guardada) suma el precio del paquete al total; false significa "ya lo
 * tengo" y esa línea no suma nada al total (pero 'monto_porcion' conserva
 * el costo por porción original, solo para referencia en pantalla).
 *
 * $recetasConPorciones: array de ['receta_id' => int, 'porciones_necesarias' => int]
 * — se reordena internamente por receta_id antes de procesar nada (ver el
 * primer 'usort' dentro de la función), así que no importa en qué orden lo
 * arme quien llama: cuando dos recetas comparten un ingrediente escrito en
 * unidades distintas (ej. una en Gramos, otra en Unidad), cuál de las dos
 * "gana" y decide si ese ingrediente redondea a unidades completas
 * (es_entera, ver más abajo) queda siempre fijo, en vez de depender del
 * orden en que se haya hecho la consulta a la base de datos — antes de este
 * reordenamiento, esa misma práctica/evento podía costar distinto en el
 * detalle (ORDER BY nombre) que en el Cierre financiero (sin ORDER BY,
 * vía recetasAsignadas()) sin que nada hubiera cambiado en los datos.
 * $decisiones: array [catalogo_id => ['comprar_paquete' => bool, 'precio_paquete' => ?float]],
 * tal como lo devuelve cargarDecisionesCompra() — las decisiones que la
 * usuaria ya guardó para este evento/práctica sobre qué comprar completo.
 * Devuelve ['lineas' => [...], 'al_gusto' => [...], 'total' => float].
 */
function listaCompraConsolidada(PDO $pdo, array $recetasConPorciones, array $decisiones = []): array
{
    $unidadesPorId = [];
    foreach ($pdo->query('SELECT * FROM unidades_medida')->fetchAll() as $u) {
        $unidadesPorId[(int) $u['id']] = $u;
    }

    $catalogoPorId = [];
    // nombre normalizado => [id, id, ...] — para reconocer una línea de
    // receta escrita a mano (sin enlazar al catálogo por ingrediente_id,
    // ej. un typo, un acento distinto, o mayúsculas/minúsculas) que en
    // realidad se refiere a un ingrediente del catálogo. Solo se usa
    // cuando el nombre es inequívoco (un solo ingrediente del catálogo
    // coincide) — ver más abajo.
    $catalogoPorNombreNormalizado = [];
    foreach ($pdo->query('SELECT id, nombre, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, densidad_g_ml, peso_unidad_g, modo_compra_defecto FROM ingredientes_catalogo')->fetchAll() as $c) {
        $catalogoPorId[(int) $c['id']] = $c;
        $normalizado = normalizarNombreIngrediente((string) $c['nombre']);
        if ($normalizado !== '') {
            $catalogoPorNombreNormalizado[$normalizado][] = (int) $c['id'];
        }
    }

    // Cuando dos o más recetas comparten un ingrediente pero lo escribieron
    // en unidades distintas (ej. una en Gramos, otra en Unidad), la línea
    // que se procesa PRIMERO es la que decide en qué unidad queda anclado
    // el grupo — y de eso depende si el total redondea hacia arriba a
    // unidades completas o no (ver más abajo, 'es_entera'). Si
    // $recetasConPorciones llega en un orden distinto según quién llama a
    // esta función (practicas/detalle.php pide las recetas por nombre;
    // cierreFinanciero(), a través de recetasAsignadas(), las pide sin
    // ORDER BY — el orden que MySQL decida darles), la MISMA práctica podía
    // mostrar dos costos distintos en dos pantallas distintas sin que nada
    // hubiera cambiado en los datos (bug reportado por Eyaelkys: "Practica
    // #2" mostraba un monto en el detalle y otro en el Cierre financiero
    // para la misma práctica y las mismas decisiones de compra guardadas).
    // Se ordena aquí por receta_id — un criterio arbitrario pero siempre el
    // mismo sin importar en qué orden haya llegado el array — así esta
    // función procesa las recetas en el mismo orden pase lo que pase quién
    // la llame, y el resultado deja de depender del orden de entrada.
    usort($recetasConPorciones, fn($a, $b) => ($a['receta_id'] ?? 0) <=> ($b['receta_id'] ?? 0));

    $grupos = [];
    // Para cada ingrediente (claveBase), lista de las claves de grupo ya
    // creadas para él — normalmente solo una, pero puede haber más de una
    // cuando de verdad no hay ningún puente de conversión conocido entre
    // las unidades usadas (ver más abajo).
    $gruposPorIngrediente = [];
    $alGusto = [];

    foreach ($recetasConPorciones as $rp) {
        $recetaId = (int) ($rp['receta_id'] ?? 0);
        $porcionesNecesarias = (int) ($rp['porciones_necesarias'] ?? 0);
        if ($recetaId <= 0) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT nombre, porciones_base FROM recetas WHERE id = ?');
        $stmt->execute([$recetaId]);
        $receta = $stmt->fetch();
        if (!$receta) {
            continue;
        }
        $porcionesBase = max(1, (int) $receta['porciones_base']);
        $nombreReceta = $receta['nombre'];

        $stmtIng = $pdo->prepare(
            'SELECT i.* FROM ingredientes i WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
        );
        $stmtIng->execute([$recetaId]);

        foreach ($stmtIng->fetchAll() as $ing) {
            $nombre = $ing['nombre'];

            // La línea puede no estar enlazada al catálogo (ingrediente_id
            // NULL) por haberse escrito a mano en el formulario de receta
            // sin que coincidiera exactamente con un nombre del catálogo
            // (acento, mayúsculas, un espacio de más...). Sin este enlace,
            // la línea nunca se beneficia de la unidad de compra ni
            // consolida con las demás líneas del mismo ingrediente — antes
            // de rendirse, se intenta reconocerla por nombre normalizado,
            // y solo se usa si un único ingrediente del catálogo coincide
            // (nunca se adivina si hay más de uno, para no mezclar
            // ingredientes distintos por error).
            $catalogoIdEfectivo = !empty($ing['ingrediente_id']) ? (int) $ing['ingrediente_id'] : null;
            if ($catalogoIdEfectivo === null) {
                $normalizado = normalizarNombreIngrediente((string) $nombre);
                $candidatos = $catalogoPorNombreNormalizado[$normalizado] ?? [];
                if (count($candidatos) === 1) {
                    $catalogoIdEfectivo = $candidatos[0];
                }
            }

            $claveBase = $catalogoIdEfectivo !== null
                ? 'cat:' . $catalogoIdEfectivo
                : 'txt:' . mb_strtolower(trim($nombre));

            if (!empty($ing['al_gusto'])) {
                if (!isset($alGusto[$claveBase])) {
                    $alGusto[$claveBase] = ['nombre' => $nombre, 'recetas' => []];
                }
                $alGusto[$claveBase]['recetas'][$nombreReceta] = true;
                continue;
            }

            $catalogoDeEstaLinea = $catalogoIdEfectivo !== null ? ($catalogoPorId[$catalogoIdEfectivo] ?? null) : null;
            $densidadIngrediente = $catalogoDeEstaLinea && $catalogoDeEstaLinea['densidad_g_ml'] !== null
                ? (float) $catalogoDeEstaLinea['densidad_g_ml']
                : null;
            $pesoUnidadIngrediente = $catalogoDeEstaLinea && ($catalogoDeEstaLinea['peso_unidad_g'] ?? null) !== null
                ? (float) $catalogoDeEstaLinea['peso_unidad_g']
                : null;

            $unidadIng = $unidadesPorId[(int) $ing['unidad_id']] ?? null;
            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, $porcionesNecesarias);
            $esEntera = (bool) ($unidadIng['es_entera'] ?? false);
            $costoUnit = (float) $ing['costo_unitario'];

            // Un solo renglón por ingrediente en la Lista de Compra: en vez
            // de separar por tipo_medida (como antes), se busca entre los
            // grupos YA creados para este mismo ingrediente uno cuya unidad
            // ancla sea convertible con la de ESTA línea — misma unidad,
            // mismo tipo_medida, o vía cualquiera de los puentes de
            // convertirCantidadEntreUnidades() (densidad, peso por unidad, o
            // la unidad de compra del catálogo, que siempre existe). Solo
            // cuando de verdad no hay NINGÚN puente conocido entre las
            // unidades usadas (ej. un ingrediente sin catálogo, en unidades
            // no relacionadas) se crea un renglón aparte — que es lo único
            // que se puede hacer sin inventar una equivalencia.
            $claveGrupo = null;
            foreach ($gruposPorIngrediente[$claveBase] ?? [] as $candidata) {
                $unidadAncla = $grupos[$candidata]['unidad_ancla'];
                if (!$unidadIng || !$unidadAncla) {
                    continue;
                }
                if ((int) $unidadIng['id'] === (int) $unidadAncla['id']) {
                    $claveGrupo = $candidata;
                    break;
                }
                $convertible = convertirCantidadEntreUnidades(1.0, $unidadIng, $unidadAncla, $densidadIngrediente, $pesoUnidadIngrediente, $catalogoDeEstaLinea, $unidadesPorId);
                if ($convertible !== null) {
                    $claveGrupo = $candidata;
                    break;
                }
            }

            if ($claveGrupo === null) {
                $claveGrupo = $claveBase . '#' . (count($gruposPorIngrediente[$claveBase] ?? []) + 1);
                $gruposPorIngrediente[$claveBase][] = $claveGrupo;
                $grupos[$claveGrupo] = [
                    'nombre' => $nombre,
                    'catalogo_id' => $catalogoIdEfectivo,
                    'unidad_ancla' => $unidadIng,
                    'cantidad' => 0.0,
                    // Suma del costo de cada línea EN SU PROPIA UNIDAD
                    // (cantidad × costo, sin convertir nada): el monto de una
                    // línea no depende de en qué unidad esté escrita, así que
                    // sumarlo así es exacto y no arrastra el error de redondeo
                    // que sí introduciría convertir un costo por unidad de
                    // Gramo a Onza (o viceversa) y multiplicar después.
                    'monto_sin_redondear' => 0.0,
                    'es_entera' => $esEntera,
                    'recetas' => [],
                ];
            }

            // La cantidad SÍ se convierte a una unidad común (la del primer
            // ingrediente del grupo, "unidad_ancla") porque esto es solo para
            // mostrar "cuánto comprar" en una sola unidad legible — no para
            // calcular el costo, que ya se sumó arriba sin necesitar
            // conversión.
            $cantidadEnAncla = $cantidad;
            $unidadAncla = $grupos[$claveGrupo]['unidad_ancla'];
            if ($unidadIng && $unidadAncla && (int) $unidadIng['id'] !== (int) $unidadAncla['id']) {
                $convertida = convertirCantidadEntreUnidades($cantidad, $unidadIng, $unidadAncla, $densidadIngrediente, $pesoUnidadIngrediente, $catalogoDeEstaLinea, $unidadesPorId);
                if ($convertida !== null) {
                    $cantidadEnAncla = $convertida;
                }
            }

            $grupos[$claveGrupo]['cantidad'] += $cantidadEnAncla;
            $grupos[$claveGrupo]['monto_sin_redondear'] += $cantidad * $costoUnit;
            $grupos[$claveGrupo]['recetas'][$nombreReceta] = true;
        }
    }

    $lineas = [];
    $total = 0.0;
    foreach ($grupos as $g) {
        // Unidades continuas (Gramo, Onza, Cucharada...) nunca se compran
        // "completas": el monto exacto ya sumado sin convertir es el
        // correcto, sin más que hacer. Solo las unidades de conteo
        // (es_entera, ej. Unidad, Lata) necesitan redondear la cantidad
        // total hacia arriba UNA vez — para eso hace falta un precio
        // promedio por unidad, que se obtiene del propio monto ya sumado
        // (monto_sin_redondear / cantidad), en vez de arrastrar el costo de
        // una sola línea, así que sigue siendo correcto aunque dos recetas
        // hayan guardado el costo con centavos ligeramente distintos.
        // Cuántas unidades COMPLETAS hay que comprar en la propia unidad de
        // uso (ej. 2 piñas enteras para cubrir 1.06 necesarias) — ya se usa
        // arriba para calcular el monto de una unidad "entera" sin unidad de
        // compra propia (Piña, Gelatina sin sabor: se compran igual que se
        // usan), así que se guarda aquí para poder mostrarla también como
        // nota informativa más abajo (bug reportado por Eyaelkys: el monto
        // ya reflejaba comprar 2 piñas completas, pero la cantidad mostrada
        // se quedaba en "1.06 unid" sin avisar que hay que llevar 2 al
        // súper — la misma info que ya se usa para el precio, ahora también
        // visible).
        $cantidadEnteraRedondeada = null;
        if ($g['es_entera'] && $g['cantidad'] > 0) {
            $cantidadEnteraRedondeada = cantidadDeCompra($g['cantidad'], true);
            $costoPromedioPorUnidad = $g['monto_sin_redondear'] / $g['cantidad'];
            $monto = $cantidadEnteraRedondeada * $costoPromedioPorUnidad;
        } else {
            $monto = $g['monto_sin_redondear'];
        }
        $montoPorcion = $monto;

        // Además de la cantidad en la unidad de uso, calcular cuánto hay que
        // llevar al súper en la unidad en que ese ingrediente realmente se
        // vende (ver docblock de la función) — solo cuando el ingrediente
        // viene del catálogo y esa unidad de compra es distinta a la de uso
        // y convertible con ella.
        $compra = null;
        $catalogo = $g['catalogo_id'] ? ($catalogoPorId[$g['catalogo_id']] ?? null) : null;
        if ($catalogo && !empty($catalogo['unidad_compra_id'])) {
            $unidadCompraId = (int) $catalogo['unidad_compra_id'];
            $contenidoPorCompra = (float) ($catalogo['contenido_por_compra'] ?? 0);
            $unidadCompra = $unidadesPorId[$unidadCompraId] ?? null;
            $unidadUsoCatalogo = isset($catalogo['unidad_id']) ? ($unidadesPorId[(int) $catalogo['unidad_id']] ?? null) : null;
            $densidadCatalogo = $catalogo['densidad_g_ml'] !== null ? (float) $catalogo['densidad_g_ml'] : null;
            $pesoUnidadCatalogo = ($catalogo['peso_unidad_g'] ?? null) !== null ? (float) $catalogo['peso_unidad_g'] : null;
            if ($unidadCompra && $contenidoPorCompra > 0 && $unidadCompraId !== (int) ($g['unidad_ancla']['id'] ?? 0)) {
                $cantidadEnUsoCatalogo = convertirCantidadEntreUnidades($g['cantidad'], $g['unidad_ancla'], $unidadUsoCatalogo, $densidadCatalogo, $pesoUnidadCatalogo, $catalogo, $unidadesPorId);
                if ($cantidadEnUsoCatalogo !== null && $cantidadEnUsoCatalogo > 0) {
                    $unidadesNecesarias = $cantidadEnUsoCatalogo / $contenidoPorCompra;
                    if (!empty($unidadCompra['es_entera'])) {
                        $unidadesNecesarias = cantidadDeCompra($unidadesNecesarias, true);
                    }
                    // 'cantidad_completa' SIEMPRE redondea hacia arriba al
                    // siguiente entero de la unidad de compra, sin importar
                    // si esa unidad es "entera" (Paquete, Lata) o continua
                    // (Litro, Libra) — porque en el súper no se puede
                    // comprar, por ejemplo, 0.36 litros sueltos: si se elige
                    // "comprar el paquete/envase completo" hay que llevar
                    // como mínimo 1 litro entero. 'cantidad' (sin redondear
                    // para unidades continuas) se conserva tal cual para el
                    // modo 'exacto' y para el texto informativo cuando aún
                    // no se ha decidido nada (ver más abajo y sección 12).
                    $compra = [
                        'cantidad' => $unidadesNecesarias,
                        'cantidad_completa' => cantidadDeCompra($unidadesNecesarias, true),
                        'unidad' => $unidadCompra['abreviatura'] ?: $unidadCompra['nombre'],
                    ];
                }
            }
        }

        // Cuando hay una sugerencia de compra (hay que llevar el paquete, no
        // la porción exacta), el monto de la línea depende del MODO de
        // compra, que puede venir de tres lugares (en este orden de
        // prioridad):
        //   1) una decisión guardada para este evento/práctica en concreto
        //      (compra_decisiones.modo — la usuaria la cambió ahí mismo);
        //   2) si esa entidad guardó una decisión ANTES de que existiera
        //      este tercer modo (solo tiene el viejo comprar_paquete
        //      booleano), se respeta tal cual para no alterar nada ya
        //      decidido;
        //   3) si no hay nada guardado para esta entidad, el modo por
        //      defecto de ESE ingrediente en el catálogo
        //      (ingredientes_catalogo.modo_compra_defecto) — 'paquete_completo'
        //      para casi todos (comportamiento histórico, sin cambios), o
        //      'cantidad_exacta' para ingredientes como el Huevo, que ella
        //      no quiere comprar por cartón completo cuando solo hace falta
        //      una parte.
        // Los tres modos posibles:
        //   'paquete'   → comprar el paquete/caja completo, a su precio
        //                 (editable): monto = precio_paquete × cantidad de compra.
        //   'exacto'    → comprar solo lo necesario: el monto se queda en
        //                 el costo exacto por unidad de uso ya sumado
        //                 (monto_porcion), sin redondear a un paquete completo.
        //   'ya_tiene'  → ya lo tiene, no hay que comprarlo: no suma al total.
        $decisionCompra = null;
        if ($compra !== null && $g['catalogo_id']) {
            $precioSugerido = isset($catalogo['precio_compra']) ? (float) $catalogo['precio_compra'] : 0.0;
            $guardada = $decisiones[$g['catalogo_id']] ?? null;
            if ($guardada && !empty($guardada['modo'])) {
                $modo = $guardada['modo'];
            } elseif ($guardada && array_key_exists('comprar_paquete', $guardada)) {
                // Decisión guardada antes de que existiera el modo 'exacto':
                // se respeta tal cual (true = paquete completo, false = ya lo tiene).
                $modo = $guardada['comprar_paquete'] ? 'paquete' : 'ya_tiene';
            } else {
                $modo = ($catalogo['modo_compra_defecto'] ?? null) === 'cantidad_exacta' ? 'exacto' : 'paquete';
            }
            $precioPaquete = ($guardada['precio_paquete'] ?? null) !== null ? (float) $guardada['precio_paquete'] : $precioSugerido;
            $decisionCompra = [
                'modo' => $modo,
                // Se conserva por compatibilidad (renderListaCompraTexto() y
                // cualquier vista que aún no se haya actualizado a 'modo').
                'comprar_paquete' => $modo === 'paquete',
                'precio_paquete' => $precioPaquete,
                'precio_sugerido' => $precioSugerido,
                'monto_porcion' => $montoPorcion,
            ];
            $monto = match ($modo) {
                // 'cantidad_completa' (siempre redondeada hacia arriba, ver
                // arriba) — no 'cantidad' — porque "comprar el paquete
                // completo" de un ingrediente cuya unidad de compra es
                // continua (Litro, Libra) tiene que costar el precio de al
                // menos una unidad de compra entera, no el precio
                // proporcional a la cantidad exacta que pide la receta.
                'paquete' => $precioPaquete * $compra['cantidad_completa'],
                'exacto' => $montoPorcion,
                default => 0.0, // 'ya_tiene'
            };
        }
        $total += $monto;

        // Nota "hay que comprar N [unidad]" para una unidad entera que NO
        // tiene una unidad de compra distinta (Piña, Gelatina sin sabor: se
        // compran igual que se usan) — cuando SÍ hay una unidad de compra
        // distinta, 'compra' de arriba ya cubre este aviso, así que aquí
        // solo aplica cuando 'compra' quedó vacío. Solo se muestra si de
        // verdad hay algo que redondear (si ya se necesitan piñas enteras
        // exactas, no hace falta aclarar nada).
        $cantidadEnteraAComprar = null;
        if ($compra === null && $cantidadEnteraRedondeada !== null && $cantidadEnteraRedondeada > $g['cantidad'] + 0.0000001) {
            $cantidadEnteraAComprar = $cantidadEnteraRedondeada;
        }

        $lineas[] = [
            'nombre' => $g['nombre'],
            'catalogo_id' => $g['catalogo_id'],
            'cantidad' => $g['cantidad'],
            'unidad' => $g['unidad_ancla']['abreviatura'] ?? '',
            'monto' => $monto,
            'compra' => $compra,
            'compra_decision' => $decisionCompra,
            'cantidad_entera_a_comprar' => $cantidadEnteraAComprar,
            'recetas' => array_keys($g['recetas']),
        ];
    }
    usort($lineas, fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

    $alGustoLista = array_values($alGusto);
    foreach ($alGustoLista as &$ag) {
        $ag['recetas'] = array_keys($ag['recetas']);
    }
    unset($ag);
    usort($alGustoLista, fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

    return ['lineas' => $lineas, 'al_gusto' => $alGustoLista, 'total' => $total];
}

/**
 * Decisiones de compra guardadas para un evento o práctica (ver
 * compra_decisiones y el docblock de listaCompraConsolidada()): por cada
 * ingrediente del catálogo con una sugerencia de "comprar el paquete
 * completo", si la usuaria ya la aceptó/rechazó y/o editó el precio del
 * paquete. $entidadTipo es 'evento' o 'practica'. Devuelve un array listo
 * para pasarle a listaCompraConsolidada() como $decisiones.
 */
function cargarDecisionesCompra(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    $stmt = $pdo->prepare(
        'SELECT ingrediente_catalogo_id, comprar_paquete, precio_paquete, modo
         FROM compra_decisiones WHERE entidad_tipo = ? AND entidad_id = ?'
    );
    $stmt->execute([$entidadTipo, $entidadId]);
    $decisiones = [];
    foreach ($stmt->fetchAll() as $d) {
        $decisiones[(int) $d['ingrediente_catalogo_id']] = [
            'comprar_paquete' => (bool) $d['comprar_paquete'],
            'precio_paquete' => $d['precio_paquete'] !== null ? (float) $d['precio_paquete'] : null,
            'modo' => $d['modo'] ?? null,
        ];
    }
    return $decisiones;
}

/**
 * Tablas involucradas en el "responsable de la compra" (sección 45) según
 * sea un evento o una práctica: [tabla principal, tabla de estudiantes
 * asignados, columna que apunta a la principal en esa tabla].
 */
function tablasResponsableCompra(string $entidadTipo): array
{
    return $entidadTipo === 'practica'
        ? ['practicas', 'practica_estudiante', 'practica_id']
        : ['eventos', 'evento_estudiante', 'evento_id'];
}

/**
 * Estudiante que quedó como responsable de ir a hacer la compra de un
 * evento o práctica (id, nombre y grupo), o null si no hay ninguno. Si la
 * columna responsable_compra_id todavía no existe (se subió el código antes
 * de correr setup.php), devuelve null en vez de romper la página.
 */
function obtenerResponsableCompra(PDO $pdo, string $entidadTipo, int $entidadId): ?array
{
    [$tabla] = tablasResponsableCompra($entidadTipo);
    try {
        $stmt = $pdo->prepare(
            "SELECT est.id, est.nombre, ge.nombre AS grupo
             FROM $tabla t
             JOIN estudiantes est ON est.id = t.responsable_compra_id
             LEFT JOIN grupos_estudiante ge ON ge.id = est.grupo_id
             WHERE t.id = ?"
        );
        $stmt->execute([$entidadId]);
        $fila = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    return $fila ?: null;
}

/**
 * Asigna (o quita, con null) al responsable de la compra. Solo acepta a un
 * estudiante que ya esté asignado a ese evento/práctica — devuelve false si
 * no lo está o si la columna aún no existe.
 */
function asignarResponsableCompra(PDO $pdo, string $entidadTipo, int $entidadId, ?int $estudianteId): bool
{
    [$tabla, $tablaEst, $col] = tablasResponsableCompra($entidadTipo);
    try {
        if ($estudianteId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tablaEst WHERE $col = ? AND estudiante_id = ?");
            $stmt->execute([$entidadId, $estudianteId]);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
        }
        $pdo->prepare("UPDATE $tabla SET responsable_compra_id = ?, actualizado_en = actualizado_en WHERE id = ?")
            ->execute([$estudianteId, $entidadId]);
    } catch (PDOException $e) {
        return false;
    }
    return true;
}

/**
 * Si el estudiante que se está quitando del evento/práctica era el
 * responsable de la compra, lo deja sin asignar (el responsable siempre
 * tiene que ser alguien de los asignados). Devuelve true si lo liberó.
 */
function liberarResponsableCompraSiEs(PDO $pdo, string $entidadTipo, int $entidadId, int $estudianteId): bool
{
    [$tabla] = tablasResponsableCompra($entidadTipo);
    try {
        $stmt = $pdo->prepare("UPDATE $tabla SET responsable_compra_id = NULL, actualizado_en = actualizado_en WHERE id = ? AND responsable_compra_id = ?");
        $stmt->execute([$entidadId, $estudianteId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Versión en texto plano de una lista de compra consolidada (ver
 * listaCompraConsolidada()), lista para descargar como archivo .txt — para
 * que los estudiantes puedan llevarla al súper sin necesitar abrir el
 * sistema desde el navegador. $responsable (opcional): nombre del
 * estudiante encargado de hacer la compra, se imprime bajo el título.
 */
function renderListaCompraTexto(string $titulo, array $consolidado, ?string $responsable = null): string
{
    $lineas = [];
    $lineas[] = $titulo;
    $lineas[] = str_repeat('=', mb_strlen($titulo));
    if ($responsable !== null && $responsable !== '') {
        $lineas[] = 'Responsable de la compra: ' . $responsable;
    }
    $lineas[] = '';

    if (!$consolidado['lineas'] && !$consolidado['al_gusto']) {
        $lineas[] = '(Sin ingredientes todavía — asigna recetas primero.)';
        return implode("\n", $lineas) . "\n";
    }

    foreach ($consolidado['lineas'] as $l) {
        $compraTxt = '';
        if (!empty($l['compra'])) {
            $modo = $l['compra_decision']['modo'] ?? 'paquete';
            $compraTxt = match ($modo) {
                'ya_tiene' => ' [ya lo tienes, no se compra]',
                'exacto' => ' [comprar solo lo necesario, costo exacto]',
                // 'paquete' (o sin decidir, que por defecto es 'paquete'):
                // se muestra 'cantidad_completa' — la cantidad YA redondeada
                // hacia arriba a la unidad de compra — porque es lo que de
                // verdad hay que llevar al súper y lo que se está cobrando
                // (ver el cálculo de $monto más arriba).
                default => sprintf(' [comprar ≈ %s %s]', numFmt($l['compra']['cantidad_completa'] ?? $l['compra']['cantidad']), $l['compra']['unidad']),
            };
        } elseif (!empty($l['cantidad_entera_a_comprar'])) {
            $compraTxt = sprintf(' [comprar %s %s]', numFmt($l['cantidad_entera_a_comprar']), $l['unidad']);
        }
        $lineas[] = sprintf('[ ] %s — %s %s (%s)%s', $l['nombre'], numFmt($l['cantidad']), $l['unidad'], money($l['monto']), $compraTxt);
    }

    if ($consolidado['al_gusto']) {
        $lineas[] = '';
        $lineas[] = 'Al gusto (sin cantidad fija):';
        foreach ($consolidado['al_gusto'] as $ag) {
            $lineas[] = '[ ] ' . $ag['nombre'];
        }
    }

    $lineas[] = '';
    $lineas[] = 'Costo estimado total: ' . money($consolidado['total']);

    return implode("\n", $lineas) . "\n";
}

/**
 * Invitaciones de auto-registro (ver db/schema.sql, tabla "invitaciones"):
 * un enlace de un solo uso que un padre/tutor usa para crearse su propia
 * cuenta de acceso, sin que el administrador tenga que inventarle una
 * contraseña. Por ahora solo se generan de tipo 'padre' — el diseño queda
 * listo para invitar estudiantes en el futuro (cuando exista
 * estudiantes.usuario_id y el rol "Estudiante"), pero esa parte no está
 * conectada todavía.
 */

/**
 * Crea una invitación nueva para $entidadTipo/$entidadId. Borra primero
 * cualquier invitación previa sin usar de esa misma entidad, para que nunca
 * queden dos enlaces activos a la vez (generar uno nuevo "reemplaza" al
 * anterior, que deja de servir). Devuelve el token generado.
 */
function crearInvitacion(PDO $pdo, string $entidadTipo, int $entidadId, int $creadoPor, int $horasValidez = 72): string
{
    $pdo->prepare('DELETE FROM invitaciones WHERE entidad_tipo = ? AND entidad_id = ? AND usado_en IS NULL')
        ->execute([$entidadTipo, $entidadId]);

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', time() + $horasValidez * 3600);
    $stmt = $pdo->prepare(
        'INSERT INTO invitaciones (entidad_tipo, entidad_id, token, expira_en, creado_por) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$entidadTipo, $entidadId, $token, $expira, $creadoPor]);
    return $token;
}

/**
 * URL absoluta y compartible de una invitación. Las páginas que la generan
 * viven una carpeta adentro del sitio (ej. /padres/detalle.php), mientras
 * que invitacion.php está en la raíz — de ahí el dirname() doble.
 */
function urlInvitacion(string $token): string
{
    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $raiz = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    return $protocolo . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $raiz . '/invitacion.php?token=' . urlencode($token);
}

/**
 * Invitación activa (existe, no se usó, no venció) para $token, con el
 * nombre de la entidad ya resuelto para mostrarlo en la pantalla pública.
 * Devuelve null si el token no sirve por cualquier motivo, incluyendo que
 * el padre/estudiante ya haya obtenido una cuenta por otra vía mientras el
 * enlace seguía sin usarse. Vale tanto para invitaciones de padre como de
 * estudiante (Paso 5): mismo token, misma validación, solo cambia la tabla
 * de la que se lee el nombre.
 */
function obtenerInvitacionValida(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM invitaciones WHERE token = ? AND usado_en IS NULL AND expira_en > NOW()'
    );
    $stmt->execute([$token]);
    $inv = $stmt->fetch();
    if (!$inv) {
        return null;
    }

    $tabla = $inv['entidad_tipo'] === 'padre' ? 'padres' : 'estudiantes';
    $stmtE = $pdo->prepare("SELECT * FROM $tabla WHERE id = ?");
    $stmtE->execute([$inv['entidad_id']]);
    $entidad = $stmtE->fetch();
    if (!$entidad || $entidad['usuario_id']) {
        return null;
    }
    $inv['entidad_nombre'] = $entidad['nombre'];

    return $inv;
}

/**
 * Consume una invitación (de padre o de estudiante) ya validada con
 * obtenerInvitacionValida(): crea la cuenta de acceso (usuarios, rol
 * "Padres" o "Estudiante" según corresponda), la vincula en
 * padres.usuario_id o estudiantes.usuario_id, y marca la invitación como
 * usada — todo en una transacción para no dejar una cuenta huérfana si algo
 * falla a mitad de camino. Devuelve ['ok' => true, 'usuario_id' => N] o
 * ['ok' => false, 'errores' => [...]].
 */
function consumirInvitacionRegistro(PDO $pdo, array $invitacion, string $usuarioLogin, string $password): array
{
    $errores = [];
    if ($usuarioLogin === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuarioLogin)) {
        $errores[] = 'El usuario debe tener 3-50 caracteres (letras, números, punto, guion).';
    }
    if (strlen($password) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    if ($errores) {
        return ['ok' => false, 'errores' => $errores];
    }

    $stmtDup = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
    $stmtDup->execute([$usuarioLogin]);
    if ($stmtDup->fetch()) {
        return ['ok' => false, 'errores' => ['Ese nombre de usuario ya está en uso. Elige otro.']];
    }

    $esPadre = $invitacion['entidad_tipo'] === 'padre';
    $tabla = $esPadre ? 'padres' : 'estudiantes';
    $rolNombre = $esPadre ? 'Padres' : 'Estudiante';

    $stmtRol = $pdo->prepare('SELECT id FROM roles WHERE nombre = ?');
    $stmtRol->execute([$rolNombre]);
    $rolId = $stmtRol->fetchColumn();
    if (!$rolId) {
        return ['ok' => false, 'errores' => ["No se encontró el rol de $rolNombre. Contacta al administrador."]];
    }

    $pdo->beginTransaction();
    try {
        $stmtChk = $pdo->prepare("SELECT usuario_id FROM $tabla WHERE id = ? FOR UPDATE");
        $stmtChk->execute([$invitacion['entidad_id']]);
        $yaVinculado = $stmtChk->fetchColumn();
        if ($yaVinculado) {
            $pdo->rollBack();
            $mensajeEntidad = $esPadre ? 'Este padre/tutor' : 'Este estudiante';
            return ['ok' => false, 'errores' => ["$mensajeEntidad ya tiene una cuenta de acceso. Si es tuya, inicia sesión normalmente."]];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO usuarios (nombre, usuario, password_hash, rol_id, activo) VALUES (?,?,?,?,1)')
            ->execute([$invitacion['entidad_nombre'], $usuarioLogin, $hash, $rolId]);
        // Se captura aquí mismo, antes de cualquier otra sentencia: con
        // PDO::ATTR_EMULATE_PREPARES=false, lastInsertId() después de un
        // UPDATE en la misma conexión devuelve 0 (el mismo comportamiento
        // documentado en registrarPagoEstudiante(), más arriba en este
        // archivo).
        $usuarioId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE $tabla SET usuario_id = ? WHERE id = ?")->execute([$usuarioId, $invitacion['entidad_id']]);
        $pdo->prepare('UPDATE invitaciones SET usado_en = NOW() WHERE id = ?')->execute([$invitacion['id']]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return ['ok' => true, 'usuario_id' => $usuarioId];
}

/* ------------------------------------------------------------------
 * Contraseñas (sección 47): cambiar la propia y restablecer con enlace.
 *
 * Restablecer: un administrador/encargado genera un enlace de UN solo uso
 * (tabla restablecer_password, mismo estilo que las invitaciones) y se lo
 * pasa a la persona por WhatsApp; ella elige su contraseña nueva en
 * restablecer.php sin que nadie la vea. Generar uno nuevo reemplaza al
 * anterior sin usar. Como las invitaciones, las fechas se escriben y se
 * comparan con la hora de PHP (Santo Domingo), no con NOW() de MySQL.
 * ---------------------------------------------------------------- */

/** Crea el enlace de restablecimiento para un usuario (borra el anterior sin usar). Devuelve el token. */
function crearRestablecimiento(PDO $pdo, int $usuarioId, int $creadoPor, int $horasValidez = 48): string
{
    $pdo->prepare('DELETE FROM restablecer_password WHERE usuario_id = ? AND usado_en IS NULL')->execute([$usuarioId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO restablecer_password (usuario_id, token, creado_en, expira_en, creado_por) VALUES (?,?,?,?,?)')
        ->execute([$usuarioId, $token, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + $horasValidez * 3600), $creadoPor]);
    return $token;
}

/** URL absoluta y compartible del enlace de restablecimiento (restablecer.php vive en la raíz; quien lo genera, una carpeta adentro). */
function urlRestablecimiento(string $token): string
{
    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $raiz = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    return $protocolo . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $raiz . '/restablecer.php?token=' . urlencode($token);
}

/**
 * Último enlace de restablecimiento SIN USAR de un usuario (vigente o ya
 * vencido), con 'vigente' => bool calculado con la hora de PHP; null si no
 * hay ninguno. Tolera que la tabla todavía no exista (antes de setup.php).
 */
function restablecimientoPendiente(PDO $pdo, int $usuarioId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM restablecer_password WHERE usuario_id = ? AND usado_en IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$usuarioId]);
        $fila = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$fila) {
        return null;
    }
    $fila['vigente'] = strtotime($fila['expira_en']) > time();
    return $fila;
}

/** Enlace de restablecimiento válido (sin usar, no vencido, usuario activo) para $token, con el usuario resuelto; null si no sirve. */
function obtenerRestablecimientoValido(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT r.*, u.nombre AS usuario_nombre, u.usuario AS usuario_login
             FROM restablecer_password r JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.token = ? AND r.usado_en IS NULL AND u.activo = 1'
        );
        $stmt->execute([$token]);
        $fila = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$fila || strtotime($fila['expira_en']) <= time()) {
        return null;
    }
    return $fila;
}

/** Valida una contraseña nueva; devuelve la lista de errores (vacía si está bien). */
function validarPasswordNueva(string $password, string $repetida): array
{
    $errores = [];
    if (strlen($password) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    if ($password !== $repetida) {
        $errores[] = 'Las contraseñas no coinciden.';
    }
    return $errores;
}

/** Usa el enlace: guarda la contraseña nueva y lo marca como usado (transacción). ['ok'=>bool,'errores'=>[...]]. */
function consumirRestablecimiento(PDO $pdo, array $restablecimiento, string $password, string $repetida): array
{
    $errores = validarPasswordNueva($password, $repetida);
    if ($errores) {
        return ['ok' => false, 'errores' => $errores];
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT usado_en FROM restablecer_password WHERE id = ? FOR UPDATE');
        $stmt->execute([$restablecimiento['id']]);
        if ($stmt->fetchColumn() !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'errores' => ['Este enlace ya se usó. Pide uno nuevo.']];
        }
        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $restablecimiento['usuario_id']]);
        $pdo->prepare('UPDATE restablecer_password SET usado_en = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $restablecimiento['id']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['ok' => true, 'errores' => []];
}

/** Cambio de contraseña propio: exige la actual. ['ok'=>bool,'errores'=>[...]]; invalida enlaces de restablecimiento pendientes. */
function cambiarPasswordPropia(PDO $pdo, int $usuarioId, string $actual, string $nueva, string $repetida): array
{
    $stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($actual, $hash)) {
        return ['ok' => false, 'errores' => ['La contraseña actual no es correcta.']];
    }
    $errores = validarPasswordNueva($nueva, $repetida);
    if ($nueva === $actual && !$errores) {
        $errores[] = 'La contraseña nueva debe ser distinta de la actual.';
    }
    if ($errores) {
        return ['ok' => false, 'errores' => $errores];
    }
    $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
    try {
        $pdo->prepare('DELETE FROM restablecer_password WHERE usuario_id = ? AND usado_en IS NULL')->execute([$usuarioId]);
    } catch (PDOException $e) {
        // tabla aún no creada: nada que invalidar
    }
    return ['ok' => true, 'errores' => []];
}

/** "en 2 días" / "en 5 h" / "en 20 min" hasta $dt (hora de PHP); "ya venció" si pasó. */
function tiempoHasta(string $dt): string
{
    $seg = strtotime($dt) - time();
    if ($seg <= 0) {
        return 'ya venció';
    }
    if ($seg < 3600) {
        return 'en ' . max(1, (int) floor($seg / 60)) . ' min';
    }
    if ($seg < 86400) {
        return 'en ' . (int) floor($seg / 3600) . ' h';
    }
    $d = (int) floor($seg / 86400);
    return 'en ' . $d . ($d === 1 ? ' día' : ' días');
}

/**
 * Estado de acceso de una lista de padres o estudiantes (sección 47), para
 * ver de un vistazo si ya se les contactó y están a la espera. $filas: filas
 * con al menos 'id' y 'usuario_id'. Por id devuelve:
 *   estado          'con_acceso' | 'vigente' | 'vencida' | 'sin_invitacion'
 *   invitacion      última invitación sin usar (o null)
 *   restablecimiento último enlace de restablecimiento sin usar de su
 *                    usuario, con 'vigente' (o null; solo si ya tiene cuenta)
 * "Vigente/vencida" se decide con la hora de PHP. Ojo: una invitación
 * vigente significa "enlace generado y sin usar" — el sistema no sabe si ya
 * se lo enviaron por WhatsApp.
 */
function estadoAccesoPersonas(PDO $pdo, string $entidadTipo, array $filas): array
{
    $res = [];
    $sinCuenta = [];
    $conCuenta = [];
    foreach ($filas as $f) {
        $id = (int) $f['id'];
        $res[$id] = ['estado' => !empty($f['usuario_id']) ? 'con_acceso' : 'sin_invitacion', 'invitacion' => null, 'restablecimiento' => null];
        if (empty($f['usuario_id'])) {
            $sinCuenta[] = $id;
        } else {
            $conCuenta[$id] = (int) $f['usuario_id'];
        }
    }
    if ($sinCuenta) {
        $in = implode(',', array_fill(0, count($sinCuenta), '?'));
        $stmt = $pdo->prepare("SELECT * FROM invitaciones WHERE entidad_tipo = ? AND entidad_id IN ($in) AND usado_en IS NULL ORDER BY id ASC");
        $stmt->execute(array_merge([$entidadTipo], $sinCuenta));
        foreach ($stmt->fetchAll() as $inv) {
            $eid = (int) $inv['entidad_id'];
            $res[$eid]['invitacion'] = $inv;
            $res[$eid]['estado'] = strtotime($inv['expira_en']) > time() ? 'vigente' : 'vencida';
        }
    }
    if ($conCuenta) {
        foreach ($conCuenta as $eid => $uid) {
            $res[$eid]['restablecimiento'] = restablecimientoPendiente($pdo, $uid);
        }
    }
    return $res;
}

/** Chips (HTML) del estado de acceso devuelto por estadoAccesoPersonas(). */
function chipsEstadoAcceso(array $est): string
{
    switch ($est['estado']) {
        case 'con_acceso':
            $html = '<span class="chip chip-success">' . icon('check') . ' Con acceso</span>';
            $r = $est['restablecimiento'] ?? null;
            if ($r && $r['vigente']) {
                $html .= '<span class="chip chip-warning" title="Se generó un enlace para restablecer su contraseña y todavía no lo usa (vence ' . e(tiempoHasta($r['expira_en'])) . ')">' . icon('clock') . ' Restablecimiento pendiente</span>';
            } elseif ($r) {
                $html .= '<span class="chip chip-muted" title="El enlace para restablecer su contraseña venció sin usarse">Enlace de restablecimiento vencido</span>';
            }
            return $html;
        case 'vigente':
            return '<span class="chip chip-warning" title="Enlace de invitación generado y sin usar (vence ' . e(tiempoHasta($est['invitacion']['expira_en'])) . ')">' . icon('clock') . ' Invitación vigente</span>';
        case 'vencida':
            return '<span class="chip chip-danger" title="El enlace de invitación venció sin usarse: genera uno nuevo">Invitación vencida</span>';
        default:
            return '<span class="chip chip-muted">Sin acceso</span>';
    }
}

/** Línea de texto con el detalle del estado de acceso ('' si no hay nada que aclarar). */
function detalleEstadoAcceso(array $est): string
{
    if (in_array($est['estado'], ['vigente', 'vencida'], true) && $est['invitacion']) {
        $inv = $est['invitacion'];
        return $est['estado'] === 'vigente'
            ? 'Invitación generada el ' . fmtDate($inv['creado_en']) . ' · vence el ' . fmtDate($inv['expira_en']) . ' (' . tiempoHasta($inv['expira_en']) . ')'
            : 'Invitación generada el ' . fmtDate($inv['creado_en']) . ' · venció el ' . fmtDate($inv['expira_en']);
    }
    $r = $est['restablecimiento'] ?? null;
    if ($est['estado'] === 'con_acceso' && $r) {
        return $r['vigente']
            ? 'Enlace de restablecimiento generado el ' . fmtDate($r['creado_en']) . ' · vence ' . tiempoHasta($r['expira_en'])
            : 'Enlace de restablecimiento generado el ' . fmtDate($r['creado_en']) . ' · venció el ' . fmtDate($r['expira_en']);
    }
    return '';
}

/** Iniciales (hasta 2 letras) de un nombre completo, para el avatar circular de panel_padre.php/panel_estudiante.php. */
function iniciales(string $nombre): string
{
    // Solo palabras que empiecen con una letra (evita usar "(" cuando el
    // nombre trae una aclaración entre paréntesis, ej. "Juan (hermano)").
    $partes = array_values(array_filter(
        preg_split('/\s+/', trim($nombre)),
        fn($p) => $p !== '' && preg_match('/^\p{L}/u', $p)
    ));
    if (!$partes) {
        return '?';
    }
    $primera = mb_substr($partes[0], 0, 1);
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';
    return mb_strtoupper($primera . $ultima);
}

/** Clase CSS (chip) para el método de un pago — igual criterio que ya usan eventos/pago_estudiante.php y practicas/pago_estudiante.php. */
function claseChipMetodoPago(string $metodo): string
{
    if ($metodo === 'efectivo') {
        return 'chip-success';
    }
    return in_array($metodo, ['transferencia', 'fondo'], true) ? 'chip-neutral' : 'chip-muted';
}

/**
 * Todo en lo que participa un estudiante (eventos y prácticas donde está
 * asignado), con su cuota, lo pagado, lo pendiente y el historial completo
 * de pagos de cada uno (con método) — usado tanto por el panel del padre
 * (panel_padre.php, ver los pagos de su hijo) como por el panel del propio
 * estudiante (panel_estudiante.php). El más reciente primero.
 */
function participacionesEstudiante(PDO $pdo, int $estudianteId): array
{
    $filas = [];

    $stmt = $pdo->prepare(
        'SELECT ev.*, ee.monto_pagado
         FROM evento_estudiante ee JOIN eventos ev ON ev.id = ee.evento_id
         WHERE ee.estudiante_id = ? ORDER BY ev.fecha ASC'
    );
    $stmt->execute([$estudianteId]);
    foreach ($stmt->fetchAll() as $ev) {
        $stmtN = $pdo->prepare('SELECT COUNT(*) FROM evento_estudiante WHERE evento_id = ?');
        $stmtN->execute([$ev['id']]);
        $numEst = (int) $stmtN->fetchColumn();

        $costoRecetas = costoRecetasConsolidado($pdo, 'evento', (int) $ev['id']);
        $resumenGastos = resumenGastosVinculo($pdo, 'evento_id', (int) $ev['id']);
        $cuotaManual = $ev['cuota_confirmada_manual'] !== null ? (float) $ev['cuota_confirmada_manual'] : null;
        $cuotas = calcularCuotas($costoRecetas, $resumenGastos, $numEst, $cuotaManual);
        $montoPagado = (float) $ev['monto_pagado'];
        $filas[] = [
            'tipo' => 'evento',
            'id' => (int) $ev['id'],
            'nombre' => $ev['nombre'],
            'fecha' => $ev['fecha'],
            'fecha_tentativa' => !empty($ev['fecha_tentativa']),
            'cuota_confirmada' => $cuotas['confirmada'],
            'monto_pagado' => $montoPagado,
            'pendiente' => max(0.0, $cuotas['confirmada'] - $montoPagado),
            'historial_pagos' => historialPagosEstudiante($pdo, 'evento', (int) $ev['id'], $estudianteId),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.nombre, p.fecha, p.cuota_confirmada_manual, pe.monto_pagado
         FROM practica_estudiante pe JOIN practicas p ON p.id = pe.practica_id
         WHERE pe.estudiante_id = ? ORDER BY p.fecha ASC'
    );
    $stmt->execute([$estudianteId]);
    foreach ($stmt->fetchAll() as $p) {
        $stmtN = $pdo->prepare('SELECT COUNT(*) FROM practica_estudiante WHERE practica_id = ?');
        $stmtN->execute([$p['id']]);
        $numEst = (int) $stmtN->fetchColumn();

        $costoMateriales = costoRecetasConsolidado($pdo, 'practica', (int) $p['id']);
        $resumenGastos = resumenGastosVinculo($pdo, 'practica_id', (int) $p['id']);
        $cuotaManual = $p['cuota_confirmada_manual'] !== null ? (float) $p['cuota_confirmada_manual'] : null;
        $cuotas = calcularCuotas($costoMateriales, $resumenGastos, $numEst, $cuotaManual);
        $montoPagado = (float) $p['monto_pagado'];
        $filas[] = [
            'tipo' => 'practica',
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'fecha' => $p['fecha'],
            'cuota_confirmada' => $cuotas['confirmada'],
            'monto_pagado' => $montoPagado,
            'pendiente' => max(0.0, $cuotas['confirmada'] - $montoPagado),
            'historial_pagos' => historialPagosEstudiante($pdo, 'practica', (int) $p['id'], $estudianteId),
        ];
    }

    usort($filas, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    return $filas;
}

/**
 * Recetas asignadas a un evento o práctica con su detalle completo
 * (ingredientes con cantidad/costo ya escalados a las porciones a preparar,
 * costo total, y las acciones/cortes marcados por línea) — la misma
 * información que ya arma eventos/detalle.php y practicas/detalle.php para
 * su pestaña "Recetas e ingredientes", en una función aparte de solo
 * lectura para el panel del estudiante (panel_estudiante.php), que no
 * necesita ni el formulario de "porciones a preparar" ni el de "quitar
 * receta". $entidadTipo es 'evento' o 'practica'.
 */
function recetasConDetalleParaConsulta(PDO $pdo, string $entidadTipo, int $entidadId): array
{
    if ($entidadTipo === 'practica') {
        $stmt = $pdo->prepare(
            'SELECT pr.porciones_necesarias, r.*, cr.nombre AS categoria FROM practica_receta pr
             JOIN recetas r ON r.id = pr.receta_id
             JOIN categorias_receta cr ON cr.id = r.categoria_id
             WHERE pr.practica_id = ? ORDER BY r.nombre ASC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT er.porciones_necesarias, r.*, cr.nombre AS categoria FROM evento_receta er
             JOIN recetas r ON r.id = er.receta_id
             JOIN categorias_receta cr ON cr.id = r.categoria_id
             WHERE er.evento_id = ? ORDER BY r.nombre ASC'
        );
    }
    $stmt->execute([$entidadId]);
    $recetas = $stmt->fetchAll();

    $idsFilasTodas = [];
    foreach ($recetas as &$rc) {
        $porcionesBase = max(1, (int) $rc['porciones_base']);
        $stmtIng = $pdo->prepare(
            'SELECT i.*, um.abreviatura AS unidad, um.es_entera AS unidad_entera FROM ingredientes i
             JOIN unidades_medida um ON um.id = i.unidad_id
             WHERE i.receta_id = ? ORDER BY i.orden ASC, i.id ASC'
        );
        $stmtIng->execute([$rc['id']]);
        $rc['ingredientes'] = $stmtIng->fetchAll();
        $rc['costo_total'] = 0.0;
        foreach ($rc['ingredientes'] as $ing) {
            $idsFilasTodas[] = (int) $ing['id'];
            if (!empty($ing['al_gusto'])) {
                continue;
            }
            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, (int) $rc['porciones_necesarias']);
            $esEntera = (bool) ($ing['unidad_entera'] ?? false);
            $rc['costo_total'] += montoLineaReceta($cantidad, (float) $ing['costo_unitario'], $esEntera);
        }
    }
    unset($rc);

    $accionesPorFila = [];
    if ($idsFilasTodas) {
        $in = implode(',', array_fill(0, count($idsFilasTodas), '?'));
        $stmtAcc = $pdo->prepare(
            "SELECT ia.receta_ingrediente_id, ac.nombre FROM ingrediente_accion ia
             JOIN acciones_ingrediente ac ON ac.id = ia.accion_id
             WHERE ia.receta_ingrediente_id IN ($in) ORDER BY ac.orden ASC, ac.nombre ASC"
        );
        $stmtAcc->execute($idsFilasTodas);
        foreach ($stmtAcc->fetchAll() as $fa) {
            $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = $fa['nombre'];
        }
    }

    return ['recetas' => $recetas, 'acciones_por_fila' => $accionesPorFila];
}
