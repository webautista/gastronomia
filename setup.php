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

    // Control de publicación de la cuota en la Home pública: mientras un
    // evento todavía se está presupuestando, la cuota puede ir variando, y
    // mostrarla en la página pública daría una cifra errática. Por defecto
    // OCULTA (0) — tanto para eventos ya existentes como para uno nuevo —
    // así ningún evento se publica de golpe al correr esta migración; se
    // activa a mano por evento desde eventos/form.php cuando la cuota ya
    // esté estable.
    if (columnaExiste($pdo, 'eventos', 'id') && !columnaExiste($pdo, 'eventos', 'cuota_publica')) {
        $pdo->exec('ALTER TABLE eventos ADD COLUMN cuota_publica TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_id');
        $mensajes[] = 'Columna "cuota_publica" agregada a eventos (controla si la cuota de ese evento se muestra en la página pública; por defecto oculta).';
    }

    // Padre/madre o tutor y su teléfono de contacto, separado del teléfono
    // del propio estudiante (que puede no tener uno todavía, sobre todo si
    // es menor de edad) — pedido para poder localizar al responsable de
    // cada estudiante sin depender de un solo número de contacto.
    if (columnaExiste($pdo, 'estudiantes', 'id') && !columnaExiste($pdo, 'estudiantes', 'padre_tutor')) {
        $pdo->exec('ALTER TABLE estudiantes ADD COLUMN padre_tutor VARCHAR(150) NULL AFTER nombre');
        $mensajes[] = 'Columna "padre_tutor" agregada a la tabla estudiantes.';
    }
    if (columnaExiste($pdo, 'estudiantes', 'id') && !columnaExiste($pdo, 'estudiantes', 'telefono_padre_tutor')) {
        $pdo->exec('ALTER TABLE estudiantes ADD COLUMN telefono_padre_tutor VARCHAR(30) NULL AFTER padre_tutor');
        $mensajes[] = 'Columna "telefono_padre_tutor" agregada a la tabla estudiantes.';
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
 * Cuarta ronda del mismo problema de "unidad de uso" mal modelada (ver
 * corregirUnidadUsoEspecias, corregirUnidadUsoLimonMielVainilla,
 * corregirUnidadUsoMantequillaGuineoAvena y corregirUnidadUsoPasta) —
 * encontrada al preparar las 4 recetas nuevas de esta ronda (Mousse de
 * chinola, Muffins de avena y guineo, Yogurt con frutas y granola,
 * Brochetas de frutas):
 * - "Fresa" quedó con unidad de uso = Paquete (450 g) desde la primera
 *   tanda del catálogo — el mismo error ya corregido para Mantequilla,
 *   Guineo y Avena en su momento (sección 6), pero que a Fresa no le había
 *   tocado todavía porque hasta ahora ninguna receta real la necesitaba.
 *   Las recetas nuevas la piden en dos formas distintas: "½ taza de
 *   fresas" (Yogurt con frutas y granola) y "6 fresas grandes" (Brochetas
 *   de frutas). Igual que con Limón verde/corteza de limón, se corrige la
 *   unidad de uso a la más general de las dos (Taza — se mide en volumen
 *   más seguido que se cuenta por unidad) y la necesidad puntual por
 *   Unidad se resuelve con un costo calculado a mano en esa línea de la
 *   receta (ver comentario junto a esa línea en sembrarRecetasReposteria3).
 * - "Gelatina sin sabor" quedó con unidad de uso = Paquete (el sobre
 *   completo) desde que se agregó al catálogo — otra receta real
 *   (Mousse de chinola) la pide por cucharadita ("1½ cucharaditas"), no
 *   por sobre entero.
 * Guardado igual que las rondas anteriores: cada UPDATE solo corre si esa
 * fila SIGUE en su unidad vieja, así que no pisa un ajuste manual hecho
 * después desde Ingredientes, y una segunda corrida de setup.php ya no
 * encuentra nada que cambiar.
 */
function corregirUnidadUsoFresaYGelatina(PDO $pdo): array
{
    $mensajes = [];
    $idPaquete = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Paquete'")->fetchColumn();
    $idTaza = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Taza'")->fetchColumn();
    $idLibra = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Libra'")->fetchColumn();
    $idCucharadita = $pdo->query("SELECT id FROM unidades_medida WHERE nombre='Cucharadita'")->fetchColumn();
    if (!$idPaquete || !$idTaza || !$idLibra || !$idCucharadita) {
        return $mensajes;
    }

    // "Fresa" también cambia su unidad de COMPRA (de Paquete de 450 g a
    // Libra), porque el mejor precio de referencia encontrado (Fresas
    // Selectas Criollas, Grupo CCN) se vende por libra, no por paquete.
    $stmt = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, unidad_compra_id = ?, contenido_por_compra = 2.667, precio_compra = 149.25, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    $stmt->execute([
        $idTaza, $idLibra,
        'Fresas Selectas Criollas, mejor precio, RD$149.25/lb (supermercadosrd.com) ≈2.667 tazas/lb (170 g/taza, medidasrecetascocina.com)',
        'Fresa', $idPaquete,
    ]);
    if ($stmt->rowCount() > 0) {
        $mensajes[] = 'Ingrediente "Fresa": unidad de uso corregida de Paquete a Taza (antes el costo no se podía convertir al escribir una receta en tazas de fresa).';
    }

    $stmt2 = $pdo->prepare(
        'UPDATE ingredientes_catalogo
         SET unidad_id = ?, contenido_por_compra = 3, nota_compra = ?
         WHERE nombre = ? AND unidad_id = ?'
    );
    $stmt2->execute([
        $idCucharadita,
        '1 sobre de gelatina sin sabor ≈ 1 cucharada ≈ 3 cucharaditas (equivalencia estándar de repostería)',
        'Gelatina sin sabor', $idPaquete,
    ]);
    if ($stmt2->rowCount() > 0) {
        $mensajes[] = 'Ingrediente "Gelatina sin sabor": unidad de uso corregida de Paquete a Cucharadita (antes el costo no se podía convertir al escribir una receta en cucharaditas de gelatina).';
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
        'Mantequilla'      => [0.9553, '226 g por taza'],
        'Harina de trigo'  => [0.5072, '120 g por taza'],
        'Azúcar blanca'    => [0.8369, '198 g por taza, azúcar granulada (King Arthur Baking)'],
        'Nueces'           => [0.4776, '113 g por taza, nueces picadas (King Arthur Baking)'],
        // Agregada al preparar "Mousse de chinola" (½ taza de crema para
        // batir), que en el catálogo está por Libra.
        'Crema para batir' => [1.0102, '239 g por taza, heavy cream/crema para batir (ref. cuporgram.com)'],
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
 * Pedido de Eyaelkys: el catálogo de "acciones/cortes de preparación"
 * (Cortado en cuadritos, Rallado, Cocido, etc. — sección 1) solo cubría
 * formas de cortar o procesar un ingrediente, no en qué ESTADO de
 * temperatura debe estar o quedar ("agua Hirviendo", "mantequilla a
 * temperatura ambiente", "servir Frío"). Se agregan 5 estados de
 * temperatura nuevos a ese mismo catálogo (misma tabla, mismo mecanismo de
 * selección múltiple por línea de ingrediente) — "Congelado" no se repite
 * porque ya existía desde la sección 1. INSERT IGNORE: seguro de correr
 * de nuevo, no pisa ningún ajuste manual hecho desde Configuración.
 */
function agregarAccionesTemperatura(PDO $pdo): array
{
    $mensajes = [];
    $antes = (int) $pdo->query('SELECT COUNT(*) FROM acciones_ingrediente')->fetchColumn();
    $pdo->exec(
        "INSERT IGNORE INTO acciones_ingrediente (nombre, orden) VALUES
        ('Frío',200),('Caliente',210),('Hirviendo',220),('Templado',230),
        ('A temperatura ambiente',240)"
    );
    $despues = (int) $pdo->query('SELECT COUNT(*) FROM acciones_ingrediente')->fetchColumn();
    if ($despues > $antes) {
        $mensajes[] = 'Catálogo de acciones de preparación: agregados ' . ($despues - $antes) . ' estados de temperatura (Frío, Caliente, Hirviendo, Templado, A temperatura ambiente).';
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

/**
 * El módulo "gastos" (un solo permiso compartido entre Eventos y
 * Prácticas, ver renombrarModuloGastos() arriba) no dejaba, por ejemplo,
 * armar un rol de tesorero que registre gastos en Prácticas pero no en
 * Eventos — a pedido explícito de Eyaelkys, se reemplaza por dos módulos
 * separados: "eventos_gastos" y "practicas_gastos" (schema.sql), y junto
 * con ellos otros seis para poder mostrar/ocultar por rol, también por
 * separado, las pestañas Recetas, Lista de Compra y Estudiantes y pagos de
 * cada uno.
 *
 * A cada rol que ya tenía algún permiso en el módulo viejo "gastos" se le
 * copian esos mismos ver/crear/editar/eliminar a "eventos_gastos" Y a
 * "practicas_gastos" — así nadie pierde de golpe el acceso que ya tenía al
 * actualizar; Eyaelkys ajusta después, rol por rol, quién gestiona gastos
 * en cuál de los dos desde Usuarios y roles → Roles. El módulo "gastos"
 * viejo se deja tal cual en la base de datos (por si acaso) pero ya no lo
 * usa ninguna pantalla, y roles/form.php ya no lo muestra en la matriz.
 * Guardado: solo crea una fila nueva si el rol todavía no tiene ninguna
 * para ese módulo nuevo, así una corrida posterior nunca pisa un ajuste
 * manual que ya se haya hecho desde la pantalla de Roles.
 */
function migrarPermisosGastosPorContexto(PDO $pdo): array
{
    $mensajes = [];
    $idGastos = idPorNombre($pdo, 'modulos', 'clave', 'gastos');
    $idEventosGastos = idPorNombre($pdo, 'modulos', 'clave', 'eventos_gastos');
    $idPracticasGastos = idPorNombre($pdo, 'modulos', 'clave', 'practicas_gastos');
    if (!$idGastos || !$idEventosGastos || !$idPracticasGastos) {
        return $mensajes;
    }
    $stmt = $pdo->prepare('SELECT rol_id, ver, crear, editar, eliminar FROM permisos_rol WHERE modulo_id = ?');
    $stmt->execute([$idGastos]);
    $filas = $stmt->fetchAll();
    $existeStmt = $pdo->prepare('SELECT 1 FROM permisos_rol WHERE rol_id = ? AND modulo_id = ?');
    $insStmt = $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (?,?,?,?,?,?)');
    $copiadas = 0;
    foreach ($filas as $fila) {
        foreach ([$idEventosGastos, $idPracticasGastos] as $idDestino) {
            $existeStmt->execute([$fila['rol_id'], $idDestino]);
            if (!$existeStmt->fetchColumn()) {
                $insStmt->execute([$fila['rol_id'], $idDestino, $fila['ver'], $fila['crear'], $fila['editar'], $fila['eliminar']]);
                $copiadas++;
            }
        }
    }
    if ($copiadas > 0) {
        $mensajes[] = "Permisos del módulo \"Gastos\" copiados a los módulos separados \"Eventos → Gastos\" y \"Prácticas → Gastos\" ($copiadas asignaciones de rol) — revisa Usuarios y roles → Roles para separar quién gestiona gastos en Eventos y quién en Prácticas.";
    }
    return $mensajes;
}

/**
 * Antes de esta ronda, qué pestañas veía Padres dentro de un Evento o
 * Práctica no era un permiso de verdad — estaba escrito directo en
 * eventos/detalle.php y practicas/detalle.php (Padres veía Resumen,
 * Estudiantes y pagos, Recetas y Lista de Compra en Eventos, nunca Gastos;
 * y solo Estudiantes y pagos en Prácticas). Ahora que cada pestaña tiene su
 * propio módulo de permiso, hace falta sembrar aquí los valores nuevos que
 * Eyaelkys pidió expresamente: Padres deja de ver la pestaña Recetas de un
 * evento (para no revelar el menú sorpresa), y a cambio sí ve Gastos (solo
 * para consultar, no para crear/editar/eliminar) — Estudiantes y pagos y
 * Lista de Compra se mantienen. "eventos_recetas" y todo lo de Prácticas
 * fuera de "Estudiantes y pagos" se dejan sin ninguna fila para Padres: al
 * no existir, cuentan como "no" en can(), que es exactamente lo que se
 * quiere. Guardado: solo crea la fila si todavía no existe ninguna para
 * ese rol y módulo, así una corrida posterior nunca pisa un ajuste manual
 * que Eyaelkys ya haya hecho desde Usuarios y roles → Roles.
 */
function establecerPermisosPadresPorPestana(PDO $pdo): array
{
    $mensajes = [];
    $idRolPadres = idPorNombre($pdo, 'roles', 'nombre', 'Padres');
    if (!$idRolPadres) {
        return $mensajes;
    }
    $otorgar = [
        'eventos_estudiantes'   => [1, 0, 0, 0],
        'eventos_lista_compra'  => [1, 0, 0, 0],
        'eventos_gastos'        => [1, 0, 0, 0],
        'practicas_estudiantes' => [1, 0, 0, 0],
    ];
    $existeStmt = $pdo->prepare('SELECT 1 FROM permisos_rol WHERE rol_id = ? AND modulo_id = ?');
    $insStmt = $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (?,?,?,?,?,?)');
    $creadas = 0;
    foreach ($otorgar as $clave => [$ver, $crear, $editar, $eliminar]) {
        $idModulo = idPorNombre($pdo, 'modulos', 'clave', $clave);
        if (!$idModulo) {
            continue;
        }
        $existeStmt->execute([$idRolPadres, $idModulo]);
        if (!$existeStmt->fetchColumn()) {
            $insStmt->execute([$idRolPadres, $idModulo, $ver, $crear, $editar, $eliminar]);
            $creadas++;
        }
    }
    if ($creadas > 0) {
        $mensajes[] = 'Rol "Padres": permisos sembrados por pestaña — ve Estudiantes y pagos, Lista de Compra y Gastos (solo consulta) en Eventos, y Estudiantes y pagos en Prácticas; ya NO ve la pestaña Recetas de un evento (antes sí la veía).';
    }
    return $mensajes;
}

/** Busca el id de una fila por su columna de nombre; null si no existe. */
function idPorNombre(PDO $pdo, string $tabla, string $columna, string $nombre): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM `$tabla` WHERE `$columna` = ?");
    $stmt->execute([$nombre]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Primera tanda de recetas nuevas pedidas por Eyaelkys por chat (panadería
 * dulce): Panqué de Plátano con Nuez, Pastel de Plátano, Manzana y Nueces,
 * y Mini Muffins de Manzana, Pasas y Avena. Cada receta se crea solo si su
 * nombre no existe todavía en la tabla, así una receta que ella ya haya
 * editado a mano no se pisa, y una segunda corrida de setup.php no la
 * duplica.
 *
 * Un ingrediente nuevo tuvo que entrar al catálogo para poder montar estas
 * recetas: "Pasas" (no existía). Precio de referencia investigado en
 * supermercadosnacional.com en septiembre de 2026 (ver su nota_compra más
 * abajo) — se puede editar libremente desde Ingredientes si el precio real
 * es otro.
 *
 * Dos ingredientes que ya existían se piden en estas recetas por "taza",
 * pero el catálogo solo los tenía en peso (Azúcar blanca y Nueces, ambos
 * en Libra) — sin la densidad g/ml del ingrediente no hay forma de
 * convertir entre peso y volumen (ver convertirCantidadEntreUnidades() en
 * includes/helpers.php), así que a los dos se les agregó su densidad en
 * establecerDensidadIngredientes() (misma función que ya traía la de
 * Mantequilla y Harina de trigo), con referencia de King Arthur Baking.
 * Con esa densidad puesta, el costo de cada línea se calculó con la misma
 * fórmula que usa el formulario de recetas (costoPorUnidadUso() +
 * convertirCostoPorUnidad()), no a mano, para que salga igual que si ella
 * las hubiera escrito una por una desde la pantalla de Recetas.
 */
function sembrarRecetasReposteria1(PDO $pdo): array
{
    $mensajes = [];

    // Único ingrediente nuevo que hizo falta para esta tanda de recetas.
    $pdo->exec("INSERT IGNORE INTO ingredientes_catalogo
        (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
        VALUES (
            'Pasas',
            (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'),
            '🍇',
            (SELECT id FROM unidades_medida WHERE nombre='Taza'),
            (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
            1.71, 122.95,
            'Líder sin semilla, bolsa 9 oz/255 g, RD\$122.95 (supermercadosnacional.com) ≈ 1.71 tazas/bolsa (1 taza sueltas ≈ 149 g, King Arthur)'
        )");

    $idCategoriaPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    if (!$idCategoriaPostre) {
        return $mensajes;
    }

    $u = fn (string $n) => idPorNombre($pdo, 'unidades_medida', 'nombre', $n);
    $ing = fn (string $n) => idPorNombre($pdo, 'ingredientes_catalogo', 'nombre', $n);
    $accion = fn (string $n) => idPorNombre($pdo, 'acciones_ingrediente', 'nombre', $n);

    $insLinea = $pdo->prepare(
        'INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, al_gusto, opcional, orden)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $insAccion = $pdo->prepare('INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES (?,?)');

    $crearReceta = function (string $nombre, int $porcionesBase, string $preparacion, array $lineas) use (
        $pdo, $idCategoriaPostre, $insLinea, $insAccion, $u, $ing, $accion, &$mensajes
    ) {
        $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
        $yaExiste->execute([$nombre]);
        if ((int) $yaExiste->fetchColumn() > 0) {
            return;
        }
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?)');
        $stmtR->execute([$nombre, $idCategoriaPostre, $porcionesBase, $preparacion]);
        $recetaId = (int) $pdo->lastInsertId();

        $orden = 1;
        foreach ($lineas as [$catNombre, $nombreLinea, $cantidad, $unidadNombre, $costo, $acciones, $alGusto, $opcional]) {
            $insLinea->execute([
                $recetaId,
                $catNombre ? $ing($catNombre) : null,
                $nombreLinea,
                $cantidad,
                $u($unidadNombre),
                $costo,
                $alGusto ? 1 : 0,
                $opcional ? 1 : 0,
                $orden,
            ]);
            $lineaId = (int) $pdo->lastInsertId();
            foreach ($acciones as $accNombre) {
                $accId = $accion($accNombre);
                if ($accId) {
                    $insAccion->execute([$lineaId, $accId]);
                }
            }
            $orden++;
        }
        $mensajes[] = "Receta \"$nombre\" creada ($porcionesBase porciones base, " . count($lineas) . ' ingredientes).';
    };

    $crearReceta(
        'Panqué de Plátano con Nuez',
        10,
        "Precalienta el horno a 180 °C. Engrasa y enharina el molde (aprox. 22 × 12 cm) o cúbrelo con papel para hornear.\n\n" .
        "Coloca los plátanos maduros en un recipiente y aplástalos con un tenedor hasta obtener un puré.\n\n" .
        "Añade los huevos, el azúcar, la mantequilla derretida y la vainilla. Mezcla hasta integrar.\n\n" .
        "En otro recipiente, combina la harina, el bicarbonato, el polvo para hornear, la canela y la sal.\n\n" .
        "Incorpora los ingredientes secos a la mezcla de plátano. Remueve suavemente hasta que no queden rastros de harina; evita batir demasiado.\n\n" .
        "Agrega las nueces picadas y mézclalas con movimientos envolventes.\n\n" .
        "Vierte la preparación en el molde. Decora con el plátano adicional cortado longitudinalmente y algunas nueces.\n\n" .
        "Hornea entre 50 y 60 minutos, o hasta que al insertar un palillo en el centro salga limpio.\n\n" .
        'Deja reposar durante 15 minutos antes de desmoldar. Colócalo sobre una rejilla y espera a que se enfríe.',
        [
            ['Plátano maduro', 'Plátano maduro', 3, 'Unidad', 20.00, [], false, false],
            ['Huevo', 'Huevo', 2, 'Unidad', 6.50, [], false, false],
            ['Mantequilla', 'Mantequilla derretida', 0.5, 'Taza', 70.00, ['Derretido'], false, false],
            ['Azúcar blanca', 'Azúcar', 0.75, 'Taza', 15.50, [], false, false],
            ['Extracto de vainilla', 'Esencia de vainilla', 1, 'Cucharadita', 44.99, [], false, false],
            ['Harina de trigo', 'Harina de trigo', 1.5, 'Taza', 7.51, [], false, false],
            ['Bicarbonato de sodio', 'Bicarbonato de sodio', 1, 'Cucharadita', 1.04, [], false, false],
            ['Polvo de hornear', 'Polvo para hornear', 0.5, 'Cucharadita', 2.50, [], false, false],
            ['Canela en polvo', 'Canela molida', 0.5, 'Cucharadita', 3.80, [], false, false],
            ['Sal', 'Sal', 0.25, 'Cucharadita', 0.20, [], false, false],
            ['Nueces', 'Nueces picadas', 0.75, 'Taza', 96.03, ['Picado'], false, false],
            ['Plátano maduro', 'Plátano adicional para decorar', 1, 'Unidad', 20.00, [], false, true],
            ['Nueces', 'Nueces enteras para decorar', 0, 'Taza', 96.03, [], true, true],
        ]
    );

    $crearReceta(
        'Pastel de Plátano, Manzana y Nueces',
        10,
        "Precalienta el horno a 180 °C.\n\n" .
        "Corta los plátanos y las manzanas en cubitos y colócalos en un bol.\n\n" .
        "Añade la avena, el azúcar, la canela, las nueces troceadas y el polvo de hornear.\n\n" .
        "En otro bol, bate los huevos junto con el aceite de coco hasta que estén bien integrados.\n\n" .
        "Incorpora los ingredientes secos a la mezcla líquida y mezcla con una espátula hasta obtener una masa homogénea.\n\n" .
        "Engrasa un molde con mantequilla y espolvorea un poco de harina.\n\n" .
        "Vierte la mezcla en el molde y hornea durante aproximadamente 45 minutos.\n\n" .
        'Haz la prueba del palillo: si sale limpio, el pastel está listo.',
        [
            ['Huevo', 'Huevo', 3, 'Unidad', 6.50, [], false, false],
            ['Avena integral', 'Avena en hojuelas finas', 1.5, 'Taza', 6.16, [], false, false],
            ['Aceite de coco', 'Aceite de coco', 0.5, 'Taza', 162.37, [], false, false],
            ['Azúcar blanca', 'Azúcar', 1, 'Taza', 15.50, [], false, false],
            ['Plátano maduro', 'Plátano maduro', 3, 'Unidad', 20.00, [], false, false],
            ['Manzana', 'Manzana roja', 3, 'Unidad', 40.00, ['Cortado en cuadritos'], false, false],
            ['Pasas', 'Pasas', 0.5, 'Taza', 71.90, [], false, false],
            ['Nueces', 'Nueces troceadas', 0.5, 'Taza', 96.03, ['Cortado en trozos'], false, false],
            ['Polvo de hornear', 'Polvo de hornear', 1, 'Cucharada', 7.50, [], false, false],
            ['Canela en polvo', 'Canela en polvo', 1, 'Cucharada', 11.40, [], false, false],
        ]
    );

    $crearReceta(
        'Mini Muffins de Manzana, Pasas y Avena',
        20,
        "Precalienta el horno a 180 °C. Engrasa un molde para mini muffins o coloca capacillos de papel o silicona.\n\n" .
        "En un bol grande, tritura bien el plátano con un tenedor. Agrega los huevos y la esencia de vainilla si decides utilizarla. Mezcla hasta integrar todos los ingredientes.\n\n" .
        "Ralla la manzana (con piel) directamente sobre la mezcla anterior.\n\n" .
        "Añade la avena, la canela, el polvo de hornear y las pasas. Mezcla con una cuchara hasta que todos los ingredientes queden bien distribuidos.\n\n" .
        "Con ayuda de una cuchara, llena cada cavidad del molde hasta ¾ de su capacidad, ya que subirán ligeramente al hornearse.\n\n" .
        "Lleva al horno durante 18 a 20 minutos. Comprueba la cocción insertando un palillo; si sale limpio, estarán listos.\n\n" .
        'Déjalos enfriar durante unos minutos antes de desmoldarlos para que conserven su forma.',
        [
            ['Manzana', 'Manzana mediana rallada (con piel)', 1, 'Unidad', 40.00, ['Rallado'], false, false],
            ['Plátano maduro', 'Plátano maduro triturado', 1, 'Unidad', 20.00, ['Triturado'], false, false],
            ['Huevo', 'Huevo', 2, 'Unidad', 6.50, [], false, false],
            ['Avena integral', 'Hojuelas de avena', 1, 'Taza', 6.16, [], false, false],
            ['Pasas', 'Pasas', 0.33, 'Taza', 71.90, [], false, false],
            ['Canela en polvo', 'Canela en polvo', 1, 'Cucharadita', 3.80, [], false, false],
            ['Polvo de hornear', 'Polvo de hornear', 1, 'Cucharadita', 2.50, [], false, false],
            ['Extracto de vainilla', 'Esencia de vainilla', 0.5, 'Cucharadita', 44.99, [], false, true],
        ]
    );

    return $mensajes;
}

/**
 * Segunda tanda de recetas nuevas pedidas por Eyaelkys por chat: Quinoa con
 * Leche estilo Arroz con Leche (Postre) y Pan de Zanahoria y Avena en 10
 * Minutos (Panadería). A diferencia de sembrarRecetasReposteria1(), cada
 * receta puede ir en una categoría distinta, así que la categoría se pasa
 * por receta en vez de fijarla una sola vez para toda la tanda.
 *
 * Un tercer texto que llegó en el mismo mensaje ("Pastel de Naranja") no se
 * pudo sembrar: el texto que ella pegó nunca incluye una lista de
 * ingredientes con cantidades (solo pasos de preparación, y repetidos/
 * desordenados) — sin cantidades no hay forma de calcular costos ni de
 * armar las líneas de ingredientes, así que se dejó pendiente hasta que
 * ella la reenvíe completa.
 *
 * Tres ingredientes nuevos entraron al catálogo para esta tanda —
 * precios de referencia investigados por chat en septiembre de 2026 (ver
 * cada nota_compra), se pueden editar libremente desde Ingredientes si el
 * precio real es otro:
 * - "Quinoa" (Quinoa Blanca Líder, supermercadosrd.com).
 * - "Leche descremada" (Rica 0% grasa, supermercadosnacional.com).
 * - "Canela en rama" (Canela Entera Líder, supermercadosrd.com) — el peso
 *   por palito (≈4 g) es un promedio de referencia (canela cassia, 3
 *   pulgadas), no un dato exacto del paquete, porque el tamaño de cada
 *   palito varía bastante.
 *
 * Tres líneas de ingrediente no se pudieron convertir con
 * convertirCostoPorUnidad() porque la unidad que pide la receta no es del
 * mismo tipo (masa/volumen) que la unidad del catálogo, y no hay una
 * "densidad" que sirva de puente para una unidad discreta como "Unidad" o
 * "Pizca" (eso solo aplica entre masa y volumen — ver
 * convertirCantidadEntreUnidades() en includes/helpers.php). Para esas se
 * calculó el costo a mano, con una referencia citada en el comentario de
 * cada línea más abajo:
 * - "Corteza de limón" (1 Unidad) a partir del precio por libra de Limón
 *   verde (≈13 limones/libra).
 * - "Zanahoria" (Unidad, no por libra) a partir de su precio por libra y
 *   el peso de una zanahoria mediana (USDA: 50-72 g, se usó 61 g).
 * - "Sal" (Pizca) a partir de su costo por cucharadita, usando la
 *   equivalencia estándar de cocina 1 pizca = 1/16 cucharadita.
 *
 * Ninguna de las dos recetas indicó explícitamente cuántas porciones
 * rinde, salvo el pan de zanahoria y avena ("rinde aproximadamente 8
 * porciones", tomado tal cual). Para la quinoa con leche se estimaron 6
 * porciones (vasitos individuales) a partir de las cantidades de la
 * receta — a revisar por Eyaelkys.
 */
function sembrarRecetasReposteria2(PDO $pdo): array
{
    $mensajes = [];

    $pdo->exec("INSERT IGNORE INTO ingredientes_catalogo
        (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
        VALUES
        ('Quinoa',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'),
         '🌾',
         (SELECT id FROM unidades_medida WHERE nombre='Taza'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         2.00, 109.00,
         'Quinoa Blanca Líder, paquete de 340.2 g, RD\$109 ≈ 2 tazas/paquete (1 taza de quinoa cruda ≈ 170 g) (supermercadosrd.com)'),
        ('Leche descremada',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'),
         '🥛',
         (SELECT id FROM unidades_medida WHERE nombre='Litro'),
         (SELECT id FROM unidades_medida WHERE nombre='Litro'),
         1.00, 79.95,
         'Leche Descremada 0% Grasa Rica, botella de 1 L, RD\$79.95 (supermercadosnacional.com)'),
        ('Canela en rama',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'),
         '🟤',
         (SELECT id FROM unidades_medida WHERE nombre='Rama'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         22.50, 109.00,
         'Canela Entera Líder, paquete de 90 g, RD\$109 ≈ 22.5 ramas/paquete (1 rama ≈ 4 g, canela cassia) (supermercadosrd.com)')");

    $idPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    $idPanaderia = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Panadería');
    if (!$idPostre || !$idPanaderia) {
        return $mensajes;
    }

    $u = fn (string $n) => idPorNombre($pdo, 'unidades_medida', 'nombre', $n);
    $ing = fn (string $n) => idPorNombre($pdo, 'ingredientes_catalogo', 'nombre', $n);
    $accion = fn (string $n) => idPorNombre($pdo, 'acciones_ingrediente', 'nombre', $n);

    $insLinea = $pdo->prepare(
        'INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, reemplazo, al_gusto, opcional, orden)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $insAccion = $pdo->prepare('INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES (?,?)');

    $crearReceta = function (int $categoriaId, string $nombre, int $porcionesBase, string $preparacion, array $lineas) use (
        $pdo, $insLinea, $insAccion, $u, $ing, $accion, &$mensajes
    ) {
        $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
        $yaExiste->execute([$nombre]);
        if ((int) $yaExiste->fetchColumn() > 0) {
            return;
        }
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?)');
        $stmtR->execute([$nombre, $categoriaId, $porcionesBase, $preparacion]);
        $recetaId = (int) $pdo->lastInsertId();

        $orden = 1;
        foreach ($lineas as [$catNombre, $nombreLinea, $cantidad, $unidadNombre, $costo, $acciones, $alGusto, $opcional, $reemplazo]) {
            $insLinea->execute([
                $recetaId,
                $catNombre ? $ing($catNombre) : null,
                $nombreLinea,
                $cantidad,
                $u($unidadNombre),
                $costo,
                $reemplazo,
                $alGusto ? 1 : 0,
                $opcional ? 1 : 0,
                $orden,
            ]);
            $lineaId = (int) $pdo->lastInsertId();
            foreach ($acciones as $accNombre) {
                $accId = $accion($accNombre);
                if ($accId) {
                    $insAccion->execute([$lineaId, $accId]);
                }
            }
            $orden++;
        }
        $mensajes[] = "Receta \"$nombre\" creada ($porcionesBase porciones base, " . count($lineas) . ' ingredientes).';
    };

    $crearReceta(
        $idPostre,
        'Quinoa con Leche estilo Arroz con Leche',
        6,
        "Coloca la leche en un cazo junto con el palo de canela y la corteza de limón, para aromatizar y crear el fondo de sabor del postre.\n\n" .
        "Mientras se calienta la leche, lava la quinoa con agua fría para eliminar impurezas y ponla a hervir con el agua durante unos 15 minutos, hasta que esté lista (puedes usar quinoa ya preparada para ahorrarte este paso).\n\n" .
        "Añade el azúcar a la leche e incorpora la quinoa cocida. Retira el palo de canela y la corteza de limón, y agrega la esencia de vainilla al gusto.\n\n" .
        "Remueve todos los ingredientes hasta obtener una mezcla consistente, similar a un arroz con leche pero más suave y ligera por efecto de la quinoa.\n\n" .
        "Baja el fuego y deja que la quinoa suelte su gelatina natural para que la leche espese. Cuando empiece a espesar, retira del fuego.\n\n" .
        "Vierte la mezcla en vasitos individuales y refrigera.\n\n" .
        'Sirve fría, espolvoreada con un poco de canela por encima.',
        [
            ['Quinoa', 'Quinoa', 1, 'Taza', 54.50, [], false, false, null],
            ['Agua', 'Agua', 1.5, 'Taza', 0, [], false, false, null],
            ['Leche descremada', 'Leche desnatada', 3, 'Taza', 19.19, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 200, 'Gramo', 0.08, [], false, false, null],
            // Costo a mano: precio por libra de Limón verde ÷ 13 limones/libra (ver nota_compra del catálogo).
            ['Limón verde', 'Corteza de limón (ralladura de 1 limón)', 1, 'Unidad', 5.23, [], false, false, null],
            ['Canela en rama', 'Palo de canela', 1, 'Rama', 4.84, [], false, false, null],
            ['Extracto de vainilla', 'Esencia de vainilla', 0, 'Cucharadita', 44.99, [], true, false, null],
        ]
    );

    $crearReceta(
        $idPanaderia,
        'Pan de Zanahoria y Avena en 10 Minutos',
        8,
        "Ralla las zanahorias y mide todos los ingredientes en un bol grande.\n\n" .
        "Combina la avena, la zanahoria rallada, los huevos, la miel, el aceite, el polvo de hornear, la canela y la sal. Mezcla bien hasta obtener una masa homogénea.\n\n" .
        "Unta ligeramente 8 moldes para muffins o ramequines y reparte la masa de forma pareja entre ellos.\n\n" .
        "Cocina en el microondas a máxima potencia por 2-3 minutos por tanda (o todos juntos si caben), hasta que estén firmes al tacto. Usa un microondas de referencia de 1000 W para estos tiempos; ajústalos si el tuyo es diferente.\n\n" .
        'Sirve tibio, con yogur o untado con mantequilla de maní. Se conserva en el refrigerador hasta 3 días.',
        [
            ['Avena integral', 'Avena en hojuelas', 1, 'Taza', 6.16, [], false, false, null],
            // Costo a mano: precio por libra de Zanahoria × peso de una zanahoria mediana (USDA: 50-72 g, se usó 61 g).
            ['Zanahoria', 'Zanahoria rallada', 2, 'Unidad', 4.17, ['Rallado'], false, false, null],
            ['Huevo', 'Huevo', 2, 'Unidad', 6.50, [], false, false, null],
            ['Miel de abeja', 'Miel', 0.25, 'Taza', 189.05, [], false, false, 'Azúcar'],
            ['Aceite de coco', 'Aceite de coco', 0.25, 'Taza', 162.37, [], false, false, 'Aceite vegetal'],
            ['Polvo de hornear', 'Polvo de hornear', 1, 'Cucharadita', 2.50, [], false, false, null],
            ['Canela en polvo', 'Canela en polvo', 1, 'Cucharadita', 3.80, [], false, false, null],
            // Costo a mano: costo por cucharadita de Sal ÷ 16 (1 pizca = 1/16 cucharadita).
            ['Sal', 'Sal', 1, 'Pizca', 0.01, [], false, false, null],
        ]
    );

    return $mensajes;
}

/**
 * Tercera tanda de recetas de repostería/postres, a pedido textual de
 * Eyaelkys: Mousse de chinola, Muffins de avena y guineo, Yogurt con
 * frutas y granola y Brochetas de frutas — igual que en
 * sembrarRecetasReposteria2(), primero se agregan al catálogo los
 * ingredientes nuevos que ninguna receta anterior necesitaba, y luego se
 * crean las 4 recetas (cada una solo si su nombre no existe ya).
 *
 * Ingredientes nuevos en el catálogo, con su referencia de precio:
 * - "Chinola" (fruta de la pasión): Sirena, RD$78.00/lb, mejor precio
 *   comparado (Carrefour: RD$83.95/lb) (supermercadosrd.com). Se queda en
 *   Libra (como se compra) porque la receta la pide en dos formas
 *   distintas dentro de la MISMA receta (taza y unidad) — ver el costo a
 *   mano de cada línea más abajo, igual que ya se hizo con Limón
 *   verde/corteza y Zanahoria en sembrarRecetasReposteria2().
 * - "Uvas": Jumbo Market, RD$138.00/lb, mejor precio comparado (Bravo:
 *   RD$179.00/lb) (supermercadosrd.com). Unidad de uso: Taza directamente
 *   (las dos recetas que la usan la piden siempre por taza).
 * - "Granola": Granola Líder 350 g, RD$129.00, mejor precio comparado
 *   (Merca Jumbo) (supermercadosrd.com). Unidad de uso: Taza.
 * - "Yogurt natural (envase grande)": aparte del "Yogurt natural" ya en el
 *   catálogo (un potecito individual, sección 1) porque esta receta lo
 *   pide por peso (750 g) — Yogurt Litro Deliciel, natural o vainilla al
 *   mismo precio, RD$150.00 (deliciel.com.do). Unidad de uso: Gramo.
 * - "Capacillos para muffins", "Palitos de brocheta" y "Vasos
 *   transparentes": no son comida — van en la categoría "Otro" (sección
 *   22 ya la usó para casos así) — Simply Done, Simply Done y Sunny Pack
 *   respectivamente (supermercadosrd.com).
 *
 * Líneas con costo calculado a mano (mismo criterio que en
 * sembrarRecetasReposteria2, con la referencia citada junto a cada una):
 * - "Chinola" en Taza (pulpa colada) y en Unidad (pulpa para decorar) —
 *   peso de una chinola mediana ≈ 35-45 g, se usó 40 g (variedad morada,
 *   ref. agritech.tnau.ac.in); 8 chinolas ≈ 1 taza de pulpa (ref.
 *   missvickie.com).
 * - "Fresa" en Unidad ("6 fresas grandes", Brochetas de frutas) — la
 *   unidad de uso del catálogo se corrigió a Taza en
 *   corregirUnidadUsoFresaYGelatina() porque esa es la forma más general
 *   en que se usa una fresa en una receta, así que la necesidad puntual
 *   por unidad (igual que Zanahoria) se resuelve a mano: peso de una
 *   fresa grande ≈ 25-30 g (se usó 28 g, ref.
 *   pasteleriamarianohernandez.es).
 * - "Limón verde" en Unidad ("1 limón" entero para rociar sobre la fruta
 *   cortada, Brochetas de frutas) — mismo costo ya calculado para
 *   "Corteza de limón" en sembrarRecetasReposteria2 (RD$68/lb ÷ 13
 *   limones/libra), porque es la misma equivalencia (1 limón = 1
 *   cucharada de jugo = 1/13 de libra).
 *
 * Ninguna de las 4 recetas dio pie a duda sobre las porciones: las 3
 * primeras dicen "6 porciones"/"6 unidades" tal cual, y "Brochetas de
 * frutas" aclara "6 porciones" pero "12 brochetas" (2 por persona) — se
 * usó 6 como porciones base, igual que las otras, con la nota de las 12
 * brochetas en la preparación.
 */
function sembrarRecetasReposteria3(PDO $pdo): array
{
    $mensajes = [];

    $pdo->exec("INSERT IGNORE INTO ingredientes_catalogo
        (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
        VALUES
        ('Mango',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'),
         '🥭',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         1, 34.00,
         'RD\$34.00/unidad (supermercadosrd.com)'),
        ('Chinola',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'),
         '🟣',
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         1, 78.00,
         'Mejor precio Sirena RD\$78/lb (Carrefour RD\$83.95), supermercadosrd.com. ≈43 g/chinola (agritech.tnau.ac.in); 8 chinolas≈1 taza pulpa (missvickie.com)'),
        ('Uvas',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'),
         '🍇',
         (SELECT id FROM unidades_medida WHERE nombre='Taza'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         2.75, 138.00,
         'Uvas Globe California, mejor precio Jumbo Market RD\$138.00/lb (Bravo RD\$179/lb), supermercadosrd.com ≈2.75 tazas/lb (165 g/taza, cookingconverter.com)'),
        ('Granola',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'),
         '🥣',
         (SELECT id FROM unidades_medida WHERE nombre='Taza'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         2.92, 129.00,
         'Granola Líder 350 g, mejor precio Merca Jumbo, RD\$129.00 (supermercadosrd.com) ≈2.92 tazas/paquete (120 g/taza, gramcups.com)'),
        ('Yogurt natural (envase grande)',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'),
         '🥛',
         (SELECT id FROM unidades_medida WHERE nombre='Gramo'),
         (SELECT id FROM unidades_medida WHERE nombre='Litro'),
         1030, 150.00,
         'Aparte del potecito individual: se usa por peso. Yogurt Litro Deliciel, natural o vainilla, mismo precio, RD\$150.00/L (deliciel.com.do) ≈1030 g/L'),
        ('Capacillos para muffins',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Otro'),
         '🧁',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         90, 84.95,
         'Simply Done Paper Baking Liners 6.35 cm, paquete de 90 unidades, RD\$84.95 (comparador supermercadosrd.com)'),
        ('Palitos de brocheta',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Otro'),
         '🍢',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         250, 149.00,
         'Palillos Grandes Para Picadera Simply Done, paquete de 250 unidades, mejor precio en Merca Jumbo, RD\$149.00 (supermercadosrd.com)'),
        ('Vasos transparentes',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Otro'),
         '🥤',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         25, 230.00,
         'Envase Transparente Sunny Pack 8 Oz, paquete de 25 unidades, RD\$230.00 (comparador supermercadosrd.com)')");

    $idPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    $idPanaderia = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Panadería');
    if (!$idPostre || !$idPanaderia) {
        return $mensajes;
    }

    $u = fn (string $n) => idPorNombre($pdo, 'unidades_medida', 'nombre', $n);
    $ing = fn (string $n) => idPorNombre($pdo, 'ingredientes_catalogo', 'nombre', $n);
    $accion = fn (string $n) => idPorNombre($pdo, 'acciones_ingrediente', 'nombre', $n);

    $insLinea = $pdo->prepare(
        'INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, reemplazo, al_gusto, opcional, orden)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $insAccion = $pdo->prepare('INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES (?,?)');

    $crearReceta = function (int $categoriaId, string $nombre, int $porcionesBase, string $preparacion, array $lineas) use (
        $pdo, $insLinea, $insAccion, $u, $ing, $accion, &$mensajes
    ) {
        $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
        $yaExiste->execute([$nombre]);
        if ((int) $yaExiste->fetchColumn() > 0) {
            return;
        }
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?)');
        $stmtR->execute([$nombre, $categoriaId, $porcionesBase, $preparacion]);
        $recetaId = (int) $pdo->lastInsertId();

        $orden = 1;
        foreach ($lineas as [$catNombre, $nombreLinea, $cantidad, $unidadNombre, $costo, $acciones, $alGusto, $opcional, $reemplazo]) {
            $insLinea->execute([
                $recetaId,
                $catNombre ? $ing($catNombre) : null,
                $nombreLinea,
                $cantidad,
                $u($unidadNombre),
                $costo,
                $reemplazo,
                $alGusto ? 1 : 0,
                $opcional ? 1 : 0,
                $orden,
            ]);
            $lineaId = (int) $pdo->lastInsertId();
            foreach ($acciones as $accNombre) {
                $accId = $accion($accNombre);
                if ($accId) {
                    $insAccion->execute([$lineaId, $accId]);
                }
            }
            $orden++;
        }
        $mensajes[] = "Receta \"$nombre\" creada ($porcionesBase porciones base, " . count($lineas) . ' ingredientes).';
    };

    $crearReceta(
        $idPostre,
        'Mousse de chinola',
        6,
        "Sacar la pulpa de las chinolas y colarla para retirar las semillas.\n\n" .
        "Colocar la gelatina sin sabor en las 3 cucharadas de agua. Dejar hidratar durante unos 5 minutos.\n\n" .
        "Calentar suavemente la gelatina hidratada hasta que se disuelva. No dejar hervir.\n\n" .
        "Licuar la leche condensada, la leche evaporada y ½ taza de pulpa de chinola.\n\n" .
        "Incorporar la gelatina disuelta.\n\n" .
        "Batir ligeramente la crema de leche y agregarla a la mezcla con movimientos envolventes.\n\n" .
        "Distribuir en 6 vasitos.\n\n" .
        "Refrigerar durante 3 horas como mínimo.\n\n" .
        'Decorar con un poco de pulpa de chinola antes de servir. Si está muy ácida, pueden cocinarla brevemente con una cucharada de azúcar y dejarla enfriar.',
        [
            // Costo a mano: precio por libra de Chinola ÷ 453.6 g/libra × 40 g/chinola (promedio) × 8 chinolas/taza (ver nota_compra del catálogo).
            ['Chinola', 'Pulpa de chinola colada', 0.5, 'Taza', 55.04, [], false, false, null],
            ['Leche condensada', 'Leche condensada', 0.5, 'Lata', 110.00, [], false, false, null],
            ['Leche evaporada', 'Leche evaporada', 0.5, 'Lata', 70.00, [], false, false, null],
            ['Crema para batir', 'Crema de leche', 0.5, 'Taza', 62.80, ['Batido'], false, false, 'Crema de leche'],
            ['Gelatina sin sabor', 'Gelatina sin sabor', 1.5, 'Cucharadita', 16.67, [], false, false, null],
            ['Agua', 'Agua', 3, 'Cucharada', 0, [], false, false, null],
            // Costo a mano: precio por libra de Chinola ÷ 453.6 g/libra × 40 g/chinola (promedio) (ver nota_compra del catálogo).
            ['Chinola', 'Pulpa de 1 chinola para decorar', 1, 'Unidad', 6.88, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 1, 'Cucharada', 0.97, [], false, true, null],
        ]
    );

    $crearReceta(
        $idPanaderia,
        'Muffins de avena y guineo',
        6,
        "Precalentar el horno a 180 °C / 350 °F.\n\n" .
        "Pelar los guineos y majarlos hasta formar un puré.\n\n" .
        "Agregar el huevo, azúcar, leche, aceite y vainilla. Mezclar.\n\n" .
        "En otro recipiente combinar la avena, harina, canela, polvo de hornear, bicarbonato y sal.\n\n" .
        "Incorporar los ingredientes secos a los húmedos.\n\n" .
        "Mezclar solamente hasta integrar; no batir demasiado.\n\n" .
        "Colocar los capacillos en el molde para muffins.\n\n" .
        "Llenar cada uno hasta aproximadamente ¾ de su capacidad.\n\n" .
        "Hornear durante 18-22 minutos.\n\n" .
        "Comprobar la cocción introduciendo un palillo en el centro. Si sale limpio, están listos.\n\n" .
        'Dejar reposar unos 5 minutos antes de desmoldar.',
        [
            ['Guineo', 'Guineo maduro', 2, 'Unidad', 3.80, ['Machacado'], false, false, null],
            ['Avena', 'Avena en hojuelas', 0.75, 'Taza', 8.49, [], false, false, null],
            ['Harina de trigo', 'Harina de trigo', 0.5, 'Taza', 7.51, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Leche entera', 'Leche', 0.25, 'Taza', 17.76, [], false, false, null],
            ['Aceite vegetal', 'Aceite vegetal', 3, 'Cucharada', 2.21, [], false, false, null],
            ['Azúcar blanca', 'Azúcar crema o blanca', 0.25, 'Taza', 15.50, [], false, false, 'Azúcar crema'],
            ['Vainilla negra', 'Vainilla', 0.5, 'Cucharadita', 0.60, [], false, false, null],
            ['Canela en polvo', 'Canela en polvo', 0.5, 'Cucharadita', 3.80, [], false, false, null],
            ['Polvo de hornear', 'Polvo de hornear', 1, 'Cucharadita', 2.50, [], false, false, null],
            ['Bicarbonato de sodio', 'Bicarbonato de sodio', 0.25, 'Cucharadita', 1.04, [], false, false, null],
            // Costo a mano: costo por cucharadita de Sal ÷ 16 (1 pizca = 1/16 cucharadita).
            ['Sal', 'Sal', 1, 'Pizca', 0.01, [], false, false, null],
            ['Capacillos para muffins', 'Capacillos para muffins', 6, 'Unidad', 0.94, [], false, false, null],
        ]
    );

    $crearReceta(
        $idPostre,
        'Yogurt con frutas y granola',
        6,
        "Lavar y desinfectar correctamente las frutas.\n\n" .
        "Pelar el mango y el guineo.\n\n" .
        "Cortar todas las frutas en trozos pequeños.\n\n" .
        "Colocar aproximadamente 2 cucharadas de yogurt en el fondo de cada vaso.\n\n" .
        "Agregar una capa de frutas.\n\n" .
        "Añadir otra capa de yogurt.\n\n" .
        "Colocar más frutas encima.\n\n" .
        "Agregar la granola justo antes de servir para evitar que se ablande.\n\n" .
        "Terminar con un pequeño hilo de miel, si desean.\n\n" .
        'Presentación sugerida: Yogurt → frutas → yogurt → frutas → granola → miel.',
        [
            ['Yogurt natural (envase grande)', 'Yogurt natural o de vainilla', 750, 'Gramo', 0.15, [], false, false, 'Yogurt de vainilla'],
            ['Granola', 'Granola', 1, 'Taza', 44.18, [], false, false, null],
            ['Guineo', 'Guineo', 1, 'Unidad', 3.80, ['Cortado en trozos'], false, false, null],
            ['Mango', 'Mango', 0.5, 'Unidad', 34.00, ['Cortado en trozos'], false, false, null],
            ['Fresa', 'Fresas', 0.5, 'Taza', 55.97, ['Cortado en trozos'], false, false, null],
            ['Uvas', 'Uvas', 0.5, 'Taza', 50.18, ['Cortado en mitades'], false, false, null],
            ['Miel de abeja', 'Miel', 2, 'Cucharada', 11.82, [], false, true, null],
            ['Vasos transparentes', 'Vasos transparentes', 6, 'Unidad', 9.20, [], false, false, null],
        ]
    );

    $crearReceta(
        $idPostre,
        'Brochetas de frutas',
        6,
        "Consideración: 2 brochetas pequeñas por persona, para un total de 12 brochetas.\n\n" .
        "Lavar y desinfectar las frutas.\n\n" .
        "Pelar el mango, la piña y el guineo.\n\n" .
        "Cortar mango, piña, manzana y guineo en trozos aproximadamente del mismo tamaño.\n\n" .
        "Cortar las fresas por la mitad si son grandes.\n\n" .
        "Rociar ligeramente la manzana y el guineo con jugo de limón para retrasar la oxidación.\n\n" .
        "Armar las brochetas alternando colores y frutas. Por ejemplo: fresa → mango → uva → piña → guineo → manzana.\n\n" .
        'Colocarlas en una bandeja, cubrirlas y mantenerlas refrigeradas hasta el momento de servir.',
        [
            ['Mango', 'Mango', 0.5, 'Unidad', 34.00, ['Cortado en trozos'], false, false, null],
            ['Piña', 'Piña', 0.25, 'Unidad', 90.00, ['Cortado en trozos'], false, false, null],
            ['Guineo', 'Guineo', 1, 'Unidad', 3.80, ['Cortado en trozos'], false, false, null],
            ['Uvas', 'Uvas', 1, 'Taza', 50.18, [], false, false, null],
            // Costo a mano: precio por libra de Fresa ÷ 453.6 g/libra × 28 g/fresa grande (ver nota_compra del catálogo).
            ['Fresa', 'Fresas grandes', 6, 'Unidad', 9.21, ['Cortado en mitades'], false, false, null],
            ['Manzana', 'Manzana', 1, 'Unidad', 40.00, ['Cortado en trozos'], false, false, null],
            // Costo a mano: mismo cálculo que "Corteza de limón" en sembrarRecetasReposteria2 (RD$68/lb ÷ 13 limones/libra).
            ['Limón verde', 'Limón (para rociar sobre la fruta cortada)', 1, 'Unidad', 5.23, [], false, false, null],
            ['Palitos de brocheta', 'Palitos de brocheta', 12, 'Unidad', 0.60, [], false, false, null],
        ]
    );

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
        $mensajes = array_merge($mensajes, corregirUnidadUsoFresaYGelatina($pdo));
        $mensajes = array_merge($mensajes, establecerDensidadIngredientes($pdo));
        $mensajes = array_merge($mensajes, agregarAccionesTemperatura($pdo));
        $mensajes = array_merge($mensajes, otorgarAccesoPadresAPracticas($pdo));
        $mensajes = array_merge($mensajes, renombrarModuloGastos($pdo));
        // Orden importante: primero se siembran los valores nuevos que
        // Eyaelkys pidió explícitamente para Padres (incluye ver=1 en
        // "eventos_gastos", aunque el viejo módulo compartido "gastos" no le
        // daba acceso). Si migrarPermisosGastosPorContexto() corriera
        // primero, copiaría el ver=0 heredado de "gastos" a "eventos_gastos"
        // para Padres, y el guardado de establecerPermisosPadresPorPestana()
        // (que no pisa una fila que ya existe) se quedaría con ese ver=0 en
        // vez del ver=1 que se pidió.
        $mensajes = array_merge($mensajes, establecerPermisosPadresPorPestana($pdo));
        $mensajes = array_merge($mensajes, migrarPermisosGastosPorContexto($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetasReposteria1($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetasReposteria2($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetasReposteria3($pdo));

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
