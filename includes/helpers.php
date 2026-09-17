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
 * Costo total en vivo de las recetas asignadas a un evento: la suma de
 * costoTotalReceta() de cada receta, escalada a las porciones que hace
 * falta preparar para ese evento (no a las porciones base de la receta).
 */
function costoTotalRecetasEvento(PDO $pdo, int $eventoId): float
{
    $stmt = $pdo->prepare(
        'SELECT er.porciones_necesarias, r.id FROM evento_receta er
         JOIN recetas r ON r.id = er.receta_id
         WHERE er.evento_id = ?'
    );
    $stmt->execute([$eventoId]);
    $recetas = $stmt->fetchAll();

    $total = 0.0;
    foreach ($recetas as $rc) {
        $total += costoTotalReceta($pdo, (int) $rc['id'], (int) $rc['porciones_necesarias']);
    }
    return $total;
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
 * Convierte un costo por unidad (ej. RD$/Onza) a su equivalente en otra
 * unidad (ej. RD$/Gramo), cuando ambas miden lo mismo. Se usa al cambiar la
 * unidad de una línea de receta: si el ingrediente venía con el costo
 * calculado para una unidad y se cambia a otra, hay que recalcular el
 * costo para que "cantidad × costo" siga siendo correcto — de lo
 * contrario se termina multiplicando gramos por un precio que en realidad
 * es por onza (el bug real que motivó esta función).
 *
 * $unidadOrigen / $unidadDestino son filas de unidades_medida (o null).
 * Devuelve null cuando no son convertibles automáticamente: unidades de
 * conteo (Unidad, Lata, Diente...) sin tipo_medida/factor_base, o cuando
 * una es de masa y la otra de volumen. En ese caso el costo se debe
 * ajustar a mano — no hay forma de saber, por ejemplo, cuántos gramos
 * "es" media lata.
 */
function convertirCostoPorUnidad(float $costoPorUnidadOrigen, ?array $unidadOrigen, ?array $unidadDestino): ?float
{
    if (!$unidadOrigen || !$unidadDestino) {
        return null;
    }
    if ((int) $unidadOrigen['id'] === (int) $unidadDestino['id']) {
        return $costoPorUnidadOrigen;
    }
    $tipoOrigen = $unidadOrigen['tipo_medida'] ?? null;
    $tipoDestino = $unidadDestino['tipo_medida'] ?? null;
    if (!$tipoOrigen || !$tipoDestino || $tipoOrigen !== $tipoDestino) {
        return null;
    }
    $factorOrigen = (float) ($unidadOrigen['factor_base'] ?? 0);
    $factorDestino = (float) ($unidadDestino['factor_base'] ?? 0);
    if ($factorOrigen <= 0 || $factorDestino <= 0) {
        return null;
    }
    return $costoPorUnidadOrigen * ($factorDestino / $factorOrigen);
}

/**
 * Convierte una CANTIDAD (no un costo) de una unidad a otra compatible —
 * hermana de convertirCostoPorUnidad() pero para el sentido contrario: si
 * costo-por-unidad escala por (factorDestino/factorOrigen), una cantidad
 * escala por (factorOrigen/factorDestino) — ej. 300 g a Onza: 300 × (1/28.35)
 * ≈ 10.58 oz. Se usa en listaCompraConsolidada() para saber, en la unidad de
 * uso propia del catálogo de un ingrediente, cuánto se necesita en total y
 * así calcular cuántas unidades de compra (Libra, Paquete, Cartón...) hacen
 * falta. Devuelve null en los mismos casos que convertirCostoPorUnidad():
 * unidades no convertibles entre sí (de conteo, o tipos de medida distintos).
 */
function convertirCantidadEntreUnidades(float $cantidadOrigen, ?array $unidadOrigen, ?array $unidadDestino): ?float
{
    if (!$unidadOrigen || !$unidadDestino) {
        return null;
    }
    if ((int) $unidadOrigen['id'] === (int) $unidadDestino['id']) {
        return $cantidadOrigen;
    }
    $tipoOrigen = $unidadOrigen['tipo_medida'] ?? null;
    $tipoDestino = $unidadDestino['tipo_medida'] ?? null;
    if (!$tipoOrigen || !$tipoDestino || $tipoOrigen !== $tipoDestino) {
        return null;
    }
    $factorOrigen = (float) ($unidadOrigen['factor_base'] ?? 0);
    $factorDestino = (float) ($unidadDestino['factor_base'] ?? 0);
    if ($factorOrigen <= 0 || $factorDestino <= 0) {
        return null;
    }
    return $cantidadOrigen * ($factorOrigen / $factorDestino);
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
 * compatibles (mismo tipo_medida, vía convertirCostoPorUnidad()) — si no
 * son convertibles (unidades de conteo distintas, o masa vs. volumen),
 * quedan como líneas separadas para no inventar una conversión que no
 * existe. Las líneas "Al gusto" no tienen cantidad medible: se listan
 * aparte, solo para recordar que hace falta tenerlas a mano.
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
 * $recetasConPorciones: array de ['receta_id' => int, 'porciones_necesarias' => int]
 * Devuelve ['lineas' => [...], 'al_gusto' => [...], 'total' => float].
 */
function listaCompraConsolidada(PDO $pdo, array $recetasConPorciones): array
{
    $unidadesPorId = [];
    foreach ($pdo->query('SELECT * FROM unidades_medida')->fetchAll() as $u) {
        $unidadesPorId[(int) $u['id']] = $u;
    }

    $catalogoPorId = [];
    foreach ($pdo->query('SELECT id, unidad_id, unidad_compra_id, contenido_por_compra FROM ingredientes_catalogo')->fetchAll() as $c) {
        $catalogoPorId[(int) $c['id']] = $c;
    }

    $grupos = [];
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

            $unidadIng = $unidadesPorId[(int) $ing['unidad_id']] ?? null;
            $tipoMedida = $unidadIng['tipo_medida'] ?? null;
            $clave = $claveBase . '|' . ($tipoMedida ?: ('u' . $ing['unidad_id']));

            $cantidad = calcularCantidad((float) $ing['cantidad'], $porcionesBase, $porcionesNecesarias);
            $esEntera = (bool) ($unidadIng['es_entera'] ?? false);
            $costoUnit = (float) $ing['costo_unitario'];

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
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
            $unidadAncla = $grupos[$clave]['unidad_ancla'];
            if ($unidadIng && $unidadAncla && (int) $unidadIng['id'] !== (int) $unidadAncla['id']) {
                $factorOrigen = (float) ($unidadIng['factor_base'] ?? 0);
                $factorDestino = (float) ($unidadAncla['factor_base'] ?? 0);
                if ($factorOrigen > 0 && $factorDestino > 0) {
                    $cantidadEnAncla = $cantidad * ($factorOrigen / $factorDestino);
                }
            }

            $grupos[$clave]['cantidad'] += $cantidadEnAncla;
            $grupos[$clave]['monto_sin_redondear'] += $cantidad * $costoUnit;
            $grupos[$clave]['recetas'][$nombreReceta] = true;
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
        $total += $monto;

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
            if ($unidadCompra && $contenidoPorCompra > 0 && $unidadCompraId !== (int) ($g['unidad_ancla']['id'] ?? 0)) {
                $cantidadEnUsoCatalogo = convertirCantidadEntreUnidades($g['cantidad'], $g['unidad_ancla'], $unidadUsoCatalogo);
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

        $lineas[] = [
            'nombre' => $g['nombre'],
            'cantidad' => $g['cantidad'],
            'unidad' => $g['unidad_ancla']['abreviatura'] ?? '',
            'monto' => $monto,
            'compra' => $compra,
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
            $compraTxt = sprintf(' [comprar ≈ %s %s]', numFmt($l['compra']['cantidad']), $l['compra']['unidad']);
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
