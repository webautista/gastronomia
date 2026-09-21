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
    $recetas = recetasAsignadas($pdo, $entidadTipo, $entidadId);
    if (!$recetas) {
        return 0.0;
    }
    $decisiones = cargarDecisionesCompra($pdo, $entidadTipo, $entidadId);
    return listaCompraConsolidada($pdo, $recetas, $decisiones)['total'];
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
 */
function calcularCuotas(float $costoRecetas, array $resumenGastos, int $cantidadEstudiantes): array
{
    $materialUsado = $resumenGastos['material_usado'];
    $materialProyectado = $resumenGastos['material_proyectado'];
    $otrosProyectado = $resumenGastos['otros_proyectado'];
    $otrosUsado = $resumenGastos['otros_usado'];

    $materialesFinal = max($costoRecetas, $materialUsado);
    $totalProyeccion = $materialesFinal + $materialProyectado + $otrosProyectado + $otrosUsado;
    $totalConfirmado = $materialesFinal + $otrosUsado;

    return [
        'materiales_final' => $materialesFinal,
        'material_excedido' => $materialUsado > $costoRecetas + 0.005,
        'material_exceso' => max(0.0, $materialUsado - $costoRecetas),
        'total_proyeccion' => $totalProyeccion,
        'total_confirmado' => $totalConfirmado,
        'proyectada' => $cantidadEstudiantes > 0 ? $totalProyeccion / $cantidadEstudiantes : 0.0,
        'confirmada' => $cantidadEstudiantes > 0 ? $totalConfirmado / $cantidadEstudiantes : 0.0,
    ];
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
    foreach ($pdo->query('SELECT id, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, densidad_g_ml, peso_unidad_g, modo_compra_defecto FROM ingredientes_catalogo')->fetchAll() as $c) {
        $catalogoPorId[(int) $c['id']] = $c;
    }

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
            $claveBase = !empty($ing['ingrediente_id'])
                ? 'cat:' . $ing['ingrediente_id']
                : 'txt:' . mb_strtolower(trim($nombre));

            if (!empty($ing['al_gusto'])) {
                if (!isset($alGusto[$claveBase])) {
                    $alGusto[$claveBase] = ['nombre' => $nombre, 'recetas' => []];
                }
                $alGusto[$claveBase]['recetas'][$nombreReceta] = true;
                continue;
            }

            $catalogoDeEstaLinea = !empty($ing['ingrediente_id']) ? ($catalogoPorId[(int) $ing['ingrediente_id']] ?? null) : null;
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
                    'catalogo_id' => !empty($ing['ingrediente_id']) ? (int) $ing['ingrediente_id'] : null,
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
        if ($g['es_entera'] && $g['cantidad'] > 0) {
            $costoPromedioPorUnidad = $g['monto_sin_redondear'] / $g['cantidad'];
            $monto = cantidadDeCompra($g['cantidad'], true) * $costoPromedioPorUnidad;
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
                    $compra = [
                        'cantidad' => $unidadesNecesarias,
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
                'paquete' => $precioPaquete * $compra['cantidad'],
                'exacto' => $montoPorcion,
                default => 0.0, // 'ya_tiene'
            };
        }
        $total += $monto;

        $lineas[] = [
            'nombre' => $g['nombre'],
            'catalogo_id' => $g['catalogo_id'],
            'cantidad' => $g['cantidad'],
            'unidad' => $g['unidad_ancla']['abreviatura'] ?? '',
            'monto' => $monto,
            'compra' => $compra,
            'compra_decision' => $decisionCompra,
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
 * Versión en texto plano de una lista de compra consolidada (ver
 * listaCompraConsolidada()), lista para descargar como archivo .txt — para
 * que los estudiantes puedan llevarla al súper sin necesitar abrir el
 * sistema desde el navegador.
 */
function renderListaCompraTexto(string $titulo, array $consolidado): string
{
    $lineas = [];
    $lineas[] = $titulo;
    $lineas[] = str_repeat('=', mb_strlen($titulo));
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
                default => sprintf(' [comprar ≈ %s %s]', numFmt($l['compra']['cantidad']), $l['compra']['unidad']),
            };
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
