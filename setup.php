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

    // Descripción corta de la receta (debajo del nombre) — distinta de
    // "preparacion" (los pasos a seguir): es una presentación breve de la
    // receta, útil en el listado y en la vista de la receta.
    if (columnaExiste($pdo, 'recetas', 'id') && !columnaExiste($pdo, 'recetas', 'descripcion')) {
        $pdo->exec('ALTER TABLE recetas ADD COLUMN descripcion TEXT NULL AFTER nombre');
        $mensajes[] = 'Columna "descripcion" agregada a la tabla recetas.';
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
        // "Se compra completa" (ej. no se puede comprar medio huevo o media
        // lata): se usa para redondear hacia arriba la cantidad de compra al
        // calcular el monto por línea de una receta. Se siembra una sola vez,
        // justo aquí, con las unidades "de conteo" típicas — después se
        // puede ajustar libremente desde Configuración sin que una futura
        // corrida de setup.php lo vuelva a pisar.
        if (!columnaExiste($pdo, 'unidades_medida', 'es_entera')) {
            $pdo->exec('ALTER TABLE unidades_medida ADD COLUMN es_entera TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec("UPDATE unidades_medida SET es_entera = 1 WHERE nombre IN ('Unidad','Diente','Rama','Rebanada','Manojo','Lata','Paquete')");
            $mensajes[] = 'Columna "es_entera" agregada a unidades_medida (se marcaron por defecto Unidad, Diente, Rama, Rebanada, Manojo, Lata y Paquete como "se compra completa").';
        }
        // Conversión automática entre unidades compatibles (ej. Onza <-> Gramo)
        // al cambiar la unidad de una línea de receta, para que el costo por
        // unidad se recalcule solo y no quede multiplicando una cantidad en
        // una unidad por un costo que en realidad es de otra unidad distinta
        // (el bug real que motivó esto: un ingrediente con precio de catálogo
        // por Onza, usado en una receta en Gramo, arrastraba el costo por
        // Onza sin convertir). Se siembra una sola vez, igual que es_entera.
        if (!columnaExiste($pdo, 'unidades_medida', 'tipo_medida')) {
            $pdo->exec('ALTER TABLE unidades_medida ADD COLUMN tipo_medida VARCHAR(10) NULL');
            $pdo->exec('ALTER TABLE unidades_medida ADD COLUMN factor_base DECIMAL(12,6) NULL');
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='masa', factor_base=1 WHERE nombre='Gramo'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='masa', factor_base=1000 WHERE nombre='Kilogramo'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='masa', factor_base=0.001 WHERE nombre='Miligramo'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='masa', factor_base=453.592 WHERE nombre='Libra'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='masa', factor_base=28.349523 WHERE nombre='Onza'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='volumen', factor_base=1 WHERE nombre='Mililitro'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='volumen', factor_base=1000 WHERE nombre='Litro'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='volumen', factor_base=240 WHERE nombre='Taza'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='volumen', factor_base=15 WHERE nombre='Cucharada'");
            $pdo->exec("UPDATE unidades_medida SET tipo_medida='volumen', factor_base=5 WHERE nombre='Cucharadita'");
            $mensajes[] = 'Columnas "tipo_medida" y "factor_base" agregadas a unidades_medida (permiten convertir el costo automáticamente entre unidades de masa entre sí —Gramo, Kilogramo, Miligramo, Libra, Onza— y entre unidades de volumen entre sí —Mililitro, Litro, Taza, Cucharada, Cucharadita— al cambiar la unidad de una línea de receta).';
        }
    }

    // Catálogo maestro de ingredientes (nuevo): la tabla "ingredientes" (el
    // detalle de ingredientes de cada receta) ya existía antes de que
    // existiera ingredientes_catalogo, así que la columna que la enlaza con
    // el catálogo se agrega aquí a mano. Es NULL a propósito: los
    // ingredientes ya escritos como texto libre siguen funcionando igual,
    // y solo se vinculan al catálogo cuando alguien los vuelve a guardar
    // eligiéndolos del selector.
    if (columnaExiste($pdo, 'ingredientes', 'id') && !columnaExiste($pdo, 'ingredientes', 'ingrediente_id')) {
        $pdo->exec('ALTER TABLE ingredientes ADD COLUMN ingrediente_id INT UNSIGNED NULL AFTER receta_id');
        if (columnaExiste($pdo, 'ingredientes_catalogo', 'id')) {
            $pdo->exec('ALTER TABLE ingredientes ADD CONSTRAINT fk_ingredientes_catalogo FOREIGN KEY (ingrediente_id) REFERENCES ingredientes_catalogo(id)');
        }
        $mensajes[] = 'Columna "ingrediente_id" agregada a la tabla ingredientes (enlace al catálogo maestro).';
    }

    // Foto de referencia de la receta terminada.
    if (columnaExiste($pdo, 'recetas', 'id') && !columnaExiste($pdo, 'recetas', 'foto')) {
        $pdo->exec('ALTER TABLE recetas ADD COLUMN foto VARCHAR(255) NULL AFTER preparacion');
        $mensajes[] = 'Columna "foto" agregada a la tabla recetas.';
    }

    // Banner del evento: se muestra al entrar al detalle del evento y en la
    // página pública, igual que la foto de referencia de una receta.
    if (columnaExiste($pdo, 'eventos', 'id') && !columnaExiste($pdo, 'eventos', 'banner')) {
        $pdo->exec('ALTER TABLE eventos ADD COLUMN banner VARCHAR(255) NULL AFTER lugar');
        $mensajes[] = 'Columna "banner" agregada a la tabla eventos.';
    }

    // Presupuesto proyectado vs. gasto confirmado: un gasto nace "proyectado"
    // (todavía no se ha pagado, es solo una previsión) y con un clic pasa a
    // "confirmado" (ya se pagó, cuenta como gasto real contra el
    // presupuesto). Los gastos que ya existían antes de esta columna se
    // marcan "confirmado" por defecto porque ya representaban dinero
    // efectivamente gastado, no una proyección.
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'estado')) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN estado VARCHAR(12) NOT NULL DEFAULT 'confirmado' AFTER monto");
        $mensajes[] = 'Columna "estado" (proyectado/confirmado) agregada a la tabla gastos; los gastos existentes quedaron marcados "confirmado".';
    }

    // Monto pagado por estudiante: reemplaza el "pagado" (sí/no) fijo por un
    // monto real. Ahora la cuota ya no se escribe a mano — se calcula sola
    // (cuota confirmada = recetas + gastos confirmados ÷ estudiantes
    // asignados) y puede subir si se confirman más gastos después de que
    // alguien ya pagó. Guardando cuánto pagó cada quien, el sistema siempre
    // puede mostrar el "pendiente" (el complemento que falta) comparando
    // contra la cuota confirmada actual, sin tener que re-marcar a nadie
    // como pendiente a mano.
    if (columnaExiste($pdo, 'evento_estudiante', 'evento_id') && !columnaExiste($pdo, 'evento_estudiante', 'monto_pagado')) {
        $pdo->exec('ALTER TABLE evento_estudiante ADD COLUMN monto_pagado DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER pagado');
        // A quienes ya estaban marcados "pagado" bajo el sistema viejo (una
        // cuota fija por evento) se les asigna como monto pagado la cuota
        // que tenía el evento en ese momento, para no perder ese historial.
        $pdo->exec(
            'UPDATE evento_estudiante ee
             JOIN eventos ev ON ev.id = ee.evento_id
             SET ee.monto_pagado = ev.cuota
             WHERE ee.pagado = 1'
        );
        $mensajes[] = 'Columna "monto_pagado" agregada a la tabla evento_estudiante; los estudiantes ya marcados como pagados quedaron con el monto de la cuota que tenía el evento en ese momento.';
    }

    // Reemplazo (alternativa anotada, ej. "o mantequilla de maní", para
    // recetas donde es una cosa o la otra), "al gusto" (cantidad no medida,
    // se excluye del costo total) y opcional/requerido por línea de
    // ingrediente de una receta.
    if (columnaExiste($pdo, 'ingredientes', 'id') && !columnaExiste($pdo, 'ingredientes', 'reemplazo')) {
        $pdo->exec('ALTER TABLE ingredientes ADD COLUMN reemplazo VARCHAR(150) NULL AFTER nombre');
        $mensajes[] = 'Columna "reemplazo" agregada a la tabla ingredientes (alternativa anotada para esa línea de la receta).';
    }
    if (columnaExiste($pdo, 'ingredientes', 'id') && !columnaExiste($pdo, 'ingredientes', 'al_gusto')) {
        $pdo->exec('ALTER TABLE ingredientes ADD COLUMN al_gusto TINYINT(1) NOT NULL DEFAULT 0 AFTER costo_unitario');
        $mensajes[] = 'Columna "al_gusto" agregada a la tabla ingredientes (cantidad "Al gusto", sin costo estimado).';
    }
    if (columnaExiste($pdo, 'ingredientes', 'id') && !columnaExiste($pdo, 'ingredientes', 'opcional')) {
        $pdo->exec('ALTER TABLE ingredientes ADD COLUMN opcional TINYINT(1) NOT NULL DEFAULT 0 AFTER al_gusto');
        $mensajes[] = 'Columna "opcional" agregada a la tabla ingredientes (por defecto, requerido); las líneas que ya existían quedaron marcadas como requeridas.';
    }

    // Rediseño del ciclo de vida de un gasto: antes solo tenía dos estados
    // (proyectado/confirmado) con un único monto. Ahora tiene tres etapas
    // (proyectado → confirmado → pagado), cada una con su propio monto
    // editable por separado (para no perder el historial de cuánto se
    // estimó, cuánto se confirmó y cuánto se pagó al final), y el pago
    // exige fecha + una foto de la factura. "es_material_receta" marca un
    // gasto como parte del cálculo de materiales de las recetas (en vez de
    // sumarse aparte como un gasto adicional), y "eliminado_en" es un soft
    // delete (un gasto ya pagado nunca se borra de verdad, solo se marca
    // eliminado, para auditoría) — ver includes/helpers.php,
    // resumenGastosVinculo() y calcularCuotas().
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'monto_confirmado')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN monto_confirmado DECIMAL(10,2) NULL AFTER monto');
        $mensajes[] = 'Columna "monto_confirmado" agregada a la tabla gastos.';
    }
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'monto_pagado')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN monto_pagado DECIMAL(10,2) NULL AFTER monto_confirmado');
        $mensajes[] = 'Columna "monto_pagado" agregada a la tabla gastos.';
    }
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'fecha_pago')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN fecha_pago DATE NULL AFTER fecha');
        $mensajes[] = 'Columna "fecha_pago" agregada a la tabla gastos.';
    }
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'factura')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN factura VARCHAR(255) NULL AFTER fecha_pago');
        $mensajes[] = 'Columna "factura" agregada a la tabla gastos (foto de la factura del gasto ya pagado).';
    }
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'es_material_receta')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN es_material_receta TINYINT(1) NOT NULL DEFAULT 0 AFTER factura');
        $mensajes[] = 'Columna "es_material_receta" agregada a la tabla gastos (marca los gastos que cuentan contra el cálculo de materiales de las recetas).';
    }
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'eliminado_en')) {
        $pdo->exec('ALTER TABLE gastos ADD COLUMN eliminado_en DATETIME NULL AFTER es_material_receta');
        $mensajes[] = 'Columna "eliminado_en" agregada a la tabla gastos (soft delete: un gasto ya pagado se marca eliminado, nunca se borra, para auditoría).';
    }
    // Un gasto ahora puede pertenecer a una práctica en vez de a un evento
    // (evento_id se vuelve opcional). Las dos cosas nacieron juntas, así
    // que comparten un solo guardado: si practica_id ya existe, esta parte
    // ya corrió antes y no hay nada más que hacer.
    if (columnaExiste($pdo, 'gastos', 'id') && !columnaExiste($pdo, 'gastos', 'practica_id')) {
        $pdo->exec('ALTER TABLE gastos MODIFY COLUMN evento_id INT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE gastos ADD COLUMN practica_id INT UNSIGNED NULL AFTER evento_id');
        if (columnaExiste($pdo, 'practicas', 'id')) {
            $pdo->exec('ALTER TABLE gastos ADD CONSTRAINT fk_gastos_practica FOREIGN KEY (practica_id) REFERENCES practicas(id) ON DELETE CASCADE');
            $pdo->exec('ALTER TABLE gastos ADD KEY idx_gastos_practica (practica_id)');
        }
        $mensajes[] = 'Columna "practica_id" agregada a la tabla gastos (un gasto ahora puede pertenecer a una práctica en vez de a un evento); "evento_id" se volvió opcional.';
    }

    // Densidad del ingrediente (gramos por mililitro): el puente que hace
    // falta para convertir su costo entre una unidad de masa (Gramo,
    // Libra...) y una de volumen (Cucharada, Taza...) — algo que
    // tipo_medida/factor_base NO pueden resolver por sí solos porque no hay
    // una equivalencia universal entre masa y volumen (una cucharada de
    // mantequilla no pesa lo mismo que una de harina). Opcional: se deja
    // NULL para la enorme mayoría de ingredientes, que solo se usan en un
    // tipo de medida. Ver convertirCantidadEntreUnidades() en
    // includes/helpers.php y establecerDensidadIngredientes() más abajo,
    // que siembra los primeros valores (mantequilla, harina).
    if (columnaExiste($pdo, 'ingredientes_catalogo', 'id') && !columnaExiste($pdo, 'ingredientes_catalogo', 'densidad_g_ml')) {
        $pdo->exec('ALTER TABLE ingredientes_catalogo ADD COLUMN densidad_g_ml DECIMAL(8,4) NULL AFTER contenido_por_compra');
        $mensajes[] = 'Columna "densidad_g_ml" agregada a ingredientes_catalogo (permite convertir el costo de un ingrediente entre unidades de masa y de volumen, ej. mantequilla en cucharadas o en gramos).';
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

/**
 * Corrección de modelado (no de columnas): varias especias de la primera
 * tanda del catálogo quedaron con "unidad de uso" = Paquete (Canela en
 * polvo, Comino, Orégano, Pimienta negra molida, Sal, Polvo de hornear,
 * Bicarbonato de sodio), cuando en una receta real se piden por
 * Cucharadita — nadie escribe "0.05 Paquete de canela" en una receta. El
 * síntoma real: al elegir uno de estos ingredientes y cambiar la unidad de
 * esa línea a Cucharadita (lo natural), el costo se quedaba sin convertir
 * — Paquete no tiene un tamaño universal (un paquete de canela y uno de
 * pimienta no pesan lo mismo), así que no hay conversión automática
 * posible entre Paquete y Cucharadita — y el monto salía disparatado (ej.
 * 2 cucharaditas de canela calculadas como si costaran lo mismo que un
 * paquete entero).
 *
 * La corrección real es de datos, no de código: cambiar la unidad de uso
 * de estos ingredientes a Cucharadita directamente (así el autocompletado
 * ya empieza en la unidad correcta y no hace falta cambiarla), con el
 * tamaño real de paquete/frasco y el costo por cucharadita recalculados a
 * partir de precios de supermercados dominicanos investigados en
 * septiembre de 2026 (ver el nota_compra de cada uno). Cada UPDATE solo
 * corre si esa fila SIGUE con unidad_id = Paquete, así que no pisa un
 * ajuste manual que se haya hecho después desde Ingredientes (incluida
 * esta misma corrección: la segunda vez que corra setup.php ya no
 * encuentra nada que cambiar).
 */
function corregirUnidadUsoEspecias(PDO $pdo): array
{
    $mensajes = [];
    $idPaquete = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Paquete'")->fetchColumn();
    $idCucharadita = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Cucharadita'")->fetchColumn();
    if (!$idPaquete || !$idCucharadita) {
        return $mensajes;
    }

    // nombre => [contenido_por_compra en cucharaditas, precio_compra, nota_compra]
    $correcciones = [
        'Canela en polvo'       => [25, 95.00, 'Paquete de 65 g (marca Wala) ≈ 25 cucharaditas, a 2.6 g por cucharadita'],
        'Comino'                => [45, 139.00, 'Frasco de 95 g (marca Líder) ≈ 45 cucharaditas, a 2.1 g por cucharadita'],
        'Orégano'               => [47, 39.00, 'Paquete de 70 g (marca Bravo) ≈ 47 cucharaditas, a 1.5 g por cucharadita'],
        'Pimienta negra molida' => [62, 149.00, 'Frasco de 141.7 g / 5 oz (marca Goya) ≈ 62 cucharaditas, a 2.3 g por cucharadita'],
        'Sal'                   => [71, 14.00, 'Paquete de 425 g ≈ 71 cucharaditas, a 6 g por cucharadita (sal fina de mesa)'],
        'Polvo de hornear'      => [14, 35.00, 'Caja de 6 sobres (66 g en total) ≈ 14 cucharaditas, a 4.6 g por cucharadita'],
        'Bicarbonato de sodio'  => [99, 103.00, 'Caja de 453.6 g / 1 lb (marca Arm & Hammer) ≈ 99 cucharaditas, a 4.6 g por cucharadita'],
    ];

    $stmt = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, contenido_por_compra = ?, precio_compra = ?, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    foreach ($correcciones as $nombre => [$contenido, $precio, $nota]) {
        $stmt->execute([$idCucharadita, $contenido, $precio, $nota, $nombre, $idPaquete]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": unidad de uso corregida de Paquete a Cucharadita (antes el costo no se podía convertir al cambiar la unidad en una receta).";
        }
    }
    return $mensajes;
}

/**
 * "Vainilla líquida" era demasiado genérico: en la cocina dominicana hay
 * tres productos de vainilla realmente distintos (Extracto de vainilla,
 * Vainilla negra y Vainilla blanca — ver el catálogo de ingredientes), así
 * que ese único ingrediente se separó en tres. Este paso solo renombra el
 * que ya existía a su nombre correcto ("Extracto de vainilla", que es el
 * que de verdad correspondía por el precio con que se había cargado); los
 * otros dos (Vainilla negra, Vainilla blanca) son ingredientes nuevos que
 * entran solos por el INSERT IGNORE del catálogo, sin necesitar migración.
 * Guardado por nombre: si ya se renombró antes, no hace nada.
 */
function renombrarVainillaLiquidaAExtracto(PDO $pdo): array
{
    $mensajes = [];
    $idViejo = $pdo->query("SELECT id FROM ingredientes_catalogo WHERE nombre = 'Vainilla líquida'")->fetchColumn();
    if (!$idViejo) {
        // No existe (instalación nueva, o ya se renombró antes): nada que hacer.
        return $mensajes;
    }
    // El esquema (schema.sql) ya sembró "Extracto de vainilla" como fila
    // nueva por su cuenta (INSERT IGNORE) antes de que este paso corra, así
    // que en una base que todavía tenía "Vainilla líquida" ahora hay dos
    // filas para el mismo ingrediente. Hay que quitar la duplicada recién
    // sembrada (todavía no la referencia ninguna receta, se acaba de
    // crear) y renombrar la vieja a su lugar, para no perder su id ni
    // cualquier ajuste manual que ya tuviera (precio, ícono, etc.) — así
    // las recetas que ya la usaban no pierden el vínculo con el catálogo.
    $idDuplicado = $pdo->query("SELECT id FROM ingredientes_catalogo WHERE nombre = 'Extracto de vainilla'")->fetchColumn();
    if ($idDuplicado) {
        $pdo->prepare('DELETE FROM ingredientes_catalogo WHERE id = ?')->execute([$idDuplicado]);
    }
    $pdo->prepare("UPDATE ingredientes_catalogo SET nombre = 'Extracto de vainilla' WHERE id = ?")->execute([$idViejo]);
    $mensajes[] = 'Ingrediente "Vainilla líquida" renombrado a "Extracto de vainilla" (para poder distinguirlo de Vainilla negra y Vainilla blanca, que son productos distintos).';
    return $mensajes;
}

/**
 * Segunda ronda del mismo problema: Vainilla líquida/Extracto de vainilla
 * (estaba en Paquete), Limón verde (estaba en Libra, por peso) y Miel de
 * abeja (estaba en Paquete) también se escriben en una receta por
 * cucharada/cucharadita, no por el envase completo ni por libra — el mismo
 * error de modelado que las 7 especias de corregirUnidadUsoEspecias(), solo
 * que cada una viene de una unidad vieja distinta, así que va en su propia
 * función. Guardado igual: solo toca la fila si sigue en su unidad vieja
 * original, para no pisar un ajuste manual que ya se haya hecho desde
 * Ingredientes.
 */
function corregirUnidadUsoLimonMielVainilla(PDO $pdo): array
{
    $mensajes = [];
    $idPaquete = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Paquete'")->fetchColumn();
    $idLibra = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Libra'")->fetchColumn();
    $idCucharada = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Cucharada'")->fetchColumn();
    $idCucharadita = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Cucharadita'")->fetchColumn();
    if (!$idPaquete || !$idLibra || !$idCucharada || !$idCucharadita) {
        return $mensajes;
    }

    // nombre => [unidad_id vieja, unidad_id nueva, contenido_por_compra, precio_compra, nota_compra]
    // Nota: "Vainilla líquida" ya se renombró a "Extracto de vainilla" en
    // renombrarVainillaLiquidaAExtracto() (que corre antes que esta
    // función), así que aquí se busca por el nombre nuevo — funciona igual
    // si la fila ya venía en su unidad vieja (Paquete) o si ya se había
    // corregido a Cucharadita en una ronda anterior bajo el nombre viejo.
    $correcciones = [
        'Extracto de vainilla' => [$idPaquete, $idCucharadita, 6, 269.95, 'Botella de 1 oz / 29.6 ml (marca Food Club) ≈ 6 cucharaditas, a 5 ml por cucharadita'],
        'Limón verde'          => [$idLibra, $idCucharada, 13, 68.00, 'Libra de limón verde/criollo ≈ 13 limones ≈ 13 cucharadas de jugo (ref.: 6-8 limones rinden 8 cucharadas en una limonada típica)'],
        'Miel de abeja'        => [$idPaquete, $idCucharada, 22, 259.95, 'Envase de 16 oz / 453 g (marca Miel De Abeja Del Campo) ≈ 22 cucharadas, a 21 g por cucharada'],
    ];

    $stmt = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, contenido_por_compra = ?, precio_compra = ?, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    foreach ($correcciones as $nombre => [$idViejo, $idNuevo, $contenido, $precio, $nota]) {
        $stmt->execute([$idNuevo, $contenido, $precio, $nota, $nombre, $idViejo]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": unidad de uso corregida para poder usarse por cucharada/cucharadita en una receta (antes el costo no se podía convertir al cambiar la unidad).";
        }
    }
    return $mensajes;
}

/**
 * Tercera ronda del mismo problema de "unidad de uso" mal modelada (ver
 * corregirUnidadUsoEspecias y corregirUnidadUsoLimonMielVainilla): tres
 * ingredientes más que ya existían en el catálogo desde antes se escriben
 * en una receta real por Cucharadita/Taza/Unidad, no por Libra entera —
 * Mantequilla (para repostería, casi siempre por cucharadita), Guineo
 * (por unidad, no por peso) y Avena (por taza, no por libra). Igual que
 * las rondas anteriores: cada UPDATE solo corre si esa fila SIGUE en su
 * unidad vieja, así que no pisa un ajuste manual hecho después desde
 * Ingredientes, y una segunda corrida de setup.php ya no encuentra nada
 * que cambiar.
 */
function corregirUnidadUsoMantequillaGuineoAvena(PDO $pdo): array
{
    $mensajes = [];
    $idLibra = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Libra'")->fetchColumn();
    $idUnidad = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Unidad'")->fetchColumn();
    $idTaza = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Taza'")->fetchColumn();
    $idCucharadita = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Cucharadita'")->fetchColumn();
    if (!$idLibra || !$idUnidad || !$idTaza || !$idCucharadita) {
        return $mensajes;
    }

    // nombre => [unidad_id vieja, unidad_id nueva, contenido_por_compra, precio_compra (por libra, sin cambiar), nota_compra]
    $correcciones = [
        'Mantequilla' => [$idLibra, $idCucharadita, 96, 140.00, '1 libra de mantequilla ≈ 2 tazas ≈ 96 cucharaditas (equivalencia estándar de repostería)'],
        'Guineo'      => [$idLibra, $idUnidad, 5, 19.00, 'Libra de guineo ≈ 5 unidades (rango real observado: 3 a 7 según tamaño, refs. Supermercados Nacional y Superxtra)'],
        'Avena'       => [$idLibra, $idTaza, 5.3, 45.00, '1 libra de avena en hojuelas ≈ 5.3 tazas (a ~85 g por taza)'],
    ];

    $stmt = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, contenido_por_compra = ?, precio_compra = ?, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    foreach ($correcciones as $nombre => [$idViejo, $idNuevo, $contenido, $precio, $nota]) {
        $stmt->execute([$idNuevo, $contenido, $precio, $nota, $nombre, $idViejo]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": unidad de uso corregida para poder usarse en la unidad en que de verdad se escribe en una receta (antes el costo no se podía convertir al cambiar la unidad).";
        }
    }
    return $mensajes;
}

/**
 * Mismo problema de modelado que corregirUnidadUsoEspecias() y las otras
 * rondas: "Pasta (espagueti)" quedó con unidad de uso = Paquete (de la
 * primera tanda del catálogo), cuando en una receta real la pasta se pesa
 * en gramos o libras — nadie escribe "0.4 Paquete de espagueti". Paquete no
 * tiene un tamaño universal, así que no había forma de convertir el costo
 * al escribir una receta en Gramo o Libra. Se corrige a Gramo (ya
 * convertible con Libra/Kilogramo/Onza vía tipo_medida='masa'), igual que
 * ya se hizo para las especias. Guardado igual: solo toca la fila si sigue
 * en Paquete, para no pisar un ajuste manual posterior.
 */
function corregirUnidadUsoPasta(PDO $pdo): array
{
    $mensajes = [];
    $idPaquete = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Paquete'")->fetchColumn();
    $idGramo = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Gramo'")->fetchColumn();
    if (!$idPaquete || !$idGramo) {
        return $mensajes;
    }
    $stmt = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, contenido_por_compra = 454, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    $stmt->execute([$idGramo, 'Paquete de 454 g (marca Zerca/genérica)', 'Pasta (espagueti)', $idPaquete]);
    if ($stmt->rowCount() > 0) {
        $mensajes[] = 'Ingrediente "Pasta (espagueti)": unidad de uso corregida de Paquete a Gramo (antes el costo no se podía convertir al escribir una receta en gramos, libras o kilogramos de pasta).';
    }
    return $mensajes;
}

/**
 * Siembra la densidad (gramos por mililitro) de los primeros ingredientes
 * que la necesitan: mantequilla y harina de trigo, que en una receta real
 * a veces se escriben por peso (gramos, libras) y otras por volumen
 * (cucharadas, cucharaditas, tazas) — sin su densidad no hay forma de
 * convertir el costo entre esos dos mundos (ver comentario de
 * densidad_g_ml en db/schema.sql y convertirCantidadEntreUnidades() en
 * includes/helpers.php). Valores de referencia: King Arthur Baking,
 * "Ingredient Weight Chart" (mantequilla 226 g/taza, harina de trigo todo
 * uso 120 g/taza; 1 taza = 236.588 ml). Guardado igual que las demás
 * correcciones: solo toca la fila si su densidad sigue en NULL, para no
 * pisar un ajuste manual que ya se haya hecho desde Ingredientes.
 */
function establecerDensidadIngredientes(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'ingredientes_catalogo', 'densidad_g_ml')) {
        return $mensajes;
    }
    // nombre => [densidad g/ml, nota para el mensaje]
    $valores = [
        'Mantequilla'     => [0.9553, '226 g por taza'],
        'Harina de trigo' => [0.5072, '120 g por taza'],
    ];
    $stmt = $pdo->prepare('UPDATE ingredientes_catalogo SET densidad_g_ml = ? WHERE nombre = ? AND densidad_g_ml IS NULL');
    foreach ($valores as $nombre => [$densidad, $nota]) {
        $stmt->execute([$densidad, $nombre]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": densidad agregada ($densidad g/ml, ref. $nota) para poder usarse tanto en gramo/libra/kilogramo como en cucharada/cucharadita/taza dentro de una receta.";
        }
    }
    return $mensajes;
}

/**
 * Padres antes no tenía ningún acceso a Prácticas (era planificación
 * interna del taller). Ahora sí puede VER la pestaña "Estudiantes y pagos"
 * de una práctica (mismo criterio que ya tiene en Eventos), pero sigue sin
 * ver Recetas/Lista de Compra/Gastos de una práctica — eso lo controla la
 * propia pantalla de practicas/detalle.php mostrando esa pestaña nada más,
 * no un módulo de permisos aparte. Guardado: solo toca la fila si sigue en
 * su estado sembrado original (0,0,0,0), para no pisar un ajuste manual
 * que ya se haya hecho desde Usuarios y roles → Roles.
 */
function otorgarAccesoPadresAPracticas(PDO $pdo): array
{
    $mensajes = [];
    $stmt = $pdo->prepare(
        "UPDATE permisos_rol pr
         JOIN roles r ON r.id = pr.rol_id
         JOIN modulos m ON m.id = pr.modulo_id
         SET pr.ver = 1
         WHERE r.nombre = 'Padres' AND m.clave = 'practicas'
           AND pr.ver = 0 AND pr.crear = 0 AND pr.editar = 0 AND pr.eliminar = 0"
    );
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $mensajes[] = 'Rol "Padres": acceso de solo lectura otorgado a Prácticas (pestaña "Estudiantes y pagos"), igual que ya tenía en Eventos.';
    }
    return $mensajes;
}

/**
 * El módulo "gastos" ahora cubre los gastos de Eventos Y de Prácticas (antes
 * era solo de eventos), así que su nombre visible en la matriz de permisos
 * (Usuarios y roles → Roles) se actualiza para no confundir. Guardado por
 * el nombre viejo exacto: si alguien ya lo personalizó desde la base de
 * datos a mano, esto no lo toca.
 */
function renombrarModuloGastos(PDO $pdo): array
{
    $mensajes = [];
    $stmt = $pdo->prepare("UPDATE modulos SET nombre = 'Gastos' WHERE clave = 'gastos' AND nombre = 'Gastos de eventos'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $mensajes[] = 'Módulo "gastos" renombrado de "Gastos de eventos" a "Gastos" (ahora cubre tanto eventos como prácticas).';
    }
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
        $mensajes = array_merge($mensajes, corregirUnidadUsoEspecias($pdo));
        $mensajes = array_merge($mensajes, renombrarVainillaLiquidaAExtracto($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoLimonMielVainilla($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoMantequillaGuineoAvena($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoPasta($pdo));
        $mensajes = array_merge($mensajes, establecerDensidadIngredientes($pdo));
        $mensajes = array_merge($mensajes, otorgarAccesoPadresAPracticas($pdo));
        $mensajes = array_merge($mensajes, renombrarModuloGastos($pdo));

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
