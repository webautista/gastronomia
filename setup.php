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

    // Peso (en gramos) que pesa 1 "Unidad" de este ingrediente en
    // particular: el mismo tipo de puente que densidad_g_ml, pero para
    // convertir el costo entre la unidad de conteo "Unidad" y una unidad de
    // masa/volumen (Gramo, Libra, Taza...) — una unidad de fresa no pesa lo
    // mismo que una de guineo, así que no hay una equivalencia universal.
    // Opcional: NULL para la enorme mayoría de ingredientes, que o no se
    // cuentan por Unidad o ya tienen su "unidad de uso" fijada en Unidad sin
    // necesitar convertir hacia otra. Ver tipoYFactorDeUnidad() y
    // convertirCantidadEntreUnidades() en includes/helpers.php, y
    // establecerPesoUnidadIngredientes() más abajo.
    if (columnaExiste($pdo, 'ingredientes_catalogo', 'id') && !columnaExiste($pdo, 'ingredientes_catalogo', 'peso_unidad_g')) {
        $pdo->exec('ALTER TABLE ingredientes_catalogo ADD COLUMN peso_unidad_g DECIMAL(8,2) NULL AFTER densidad_g_ml');
        $mensajes[] = 'Columna "peso_unidad_g" agregada a ingredientes_catalogo (permite convertir el costo de un ingrediente entre la unidad "Unidad" (contado) y sus unidades de masa/volumen, ej. fresas en unidad, gramo, libra, kilogramo o taza).';
    }

    // Modo de compra por defecto para la Lista de Compra, cuando la unidad
    // de compra de un ingrediente es distinta de la de uso: por defecto
    // (NULL) se sigue asumiendo que hay que comprar el paquete/caja
    // completa, igual que siempre. 'cantidad_exacta' es para ingredientes
    // como el Huevo, que NO se deben forzar a comprar por cartón completo
    // cuando la receta solo necesita una parte — ahí el monto se queda en
    // el costo exacto ya prorrateado por unidad, sin redondear a un
    // paquete. Ver establecerModoCompraDefecto() más abajo (siembra Huevo)
    // y el docblock de listaCompraConsolidada() en includes/helpers.php.
    if (columnaExiste($pdo, 'ingredientes_catalogo', 'id') && !columnaExiste($pdo, 'ingredientes_catalogo', 'modo_compra_defecto')) {
        $pdo->exec("ALTER TABLE ingredientes_catalogo ADD COLUMN modo_compra_defecto ENUM('paquete_completo','cantidad_exacta') NULL AFTER peso_unidad_g");
        $mensajes[] = 'Columna "modo_compra_defecto" agregada a ingredientes_catalogo (permite que un ingrediente como el Huevo no se compre por cartón/paquete completo en la Lista de Compra cuando solo hace falta una parte).';
    }

    // Tercer modo para una decisión de compra guardada por evento/práctica
    // (ver docblock de la tabla compra_decisiones en db/schema.sql y de
    // listaCompraConsolidada() en includes/helpers.php): además de
    // comprar_paquete (paquete completo / ya lo tiene), ahora se puede
    // guardar 'exacto' (comprar solo lo necesario, sin redondear a
    // paquete). NULL = no hay decisión guardada con este campo todavía; se
    // usa comprar_paquete o el modo por defecto del catálogo.
    if (columnaExiste($pdo, 'compra_decisiones', 'id') && !columnaExiste($pdo, 'compra_decisiones', 'modo')) {
        $pdo->exec("ALTER TABLE compra_decisiones ADD COLUMN modo ENUM('paquete','exacto','ya_tiene') NULL AFTER comprar_paquete");
        $mensajes[] = 'Columna "modo" agregada a compra_decisiones (agrega un tercer estado, "comprar solo lo necesario", además de paquete completo / ya lo tiene).';
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

    // Cuota confirmada manual: por defecto la cuota que se le cobra a cada
    // estudiante sigue calculándose sola (costo real de materiales + otros
    // gastos, dividido entre los estudiantes — ver calcularCuotas() en
    // includes/helpers.php), pero a veces el taller decide cobrar MENOS de
    // lo que costó de verdad (ej. por logística, o para no trasladarle a las
    // familias un sobrecosto puntual) — a pedido explícito de Eyaelkys. Sin
    // esto, cualquier estudiante que ya pagó lo acordado seguía apareciendo
    // con un "pendiente" fantasma, porque el sistema lo comparaba contra el
    // costo real en vez de contra lo que en verdad se decidió cobrar. NULL
    // (el default) = seguir calculando sola, igual que siempre; un valor
    // aquí REEMPLAZA la cuota confirmada calculada en todas las pantallas
    // (Resumen, Estudiantes y pagos, paneles de padres/estudiantes), pero el
    // cálculo automático se sigue mostrando al lado como "cuota sugerida"
    // para referencia. La nota es opcional, para dejar constancia de por qué
    // se ajustó. Cada cambio queda anotado en cuota_historial (mismo
    // criterio que gastos_historial: un registro por cambio, nunca se
    // sobrescribe en silencio) — ver eventos/cuota_editar.php y
    // practicas/cuota_editar.php.
    if (columnaExiste($pdo, 'eventos', 'id') && !columnaExiste($pdo, 'eventos', 'cuota_confirmada_manual')) {
        $pdo->exec('ALTER TABLE eventos ADD COLUMN cuota_confirmada_manual DECIMAL(10,2) NULL AFTER cuota');
        $pdo->exec('ALTER TABLE eventos ADD COLUMN cuota_confirmada_manual_nota VARCHAR(255) NULL AFTER cuota_confirmada_manual');
        $mensajes[] = 'Columnas "cuota_confirmada_manual" y "cuota_confirmada_manual_nota" agregadas a la tabla eventos.';
    }
    if (columnaExiste($pdo, 'practicas', 'id') && !columnaExiste($pdo, 'practicas', 'cuota_confirmada_manual')) {
        $pdo->exec('ALTER TABLE practicas ADD COLUMN cuota_confirmada_manual DECIMAL(10,2) NULL AFTER notas');
        $pdo->exec('ALTER TABLE practicas ADD COLUMN cuota_confirmada_manual_nota VARCHAR(255) NULL AFTER cuota_confirmada_manual');
        $mensajes[] = 'Columnas "cuota_confirmada_manual" y "cuota_confirmada_manual_nota" agregadas a la tabla practicas.';
    }

    return $mensajes;
}

/**
 * Historial de pagos por estudiante (pagos_estudiante, ver db/schema.sql):
 * a las bases de datos que ya tenían estudiantes con algo pagado (un solo
 * monto acumulado en evento_estudiante.monto_pagado / practica_estudiante.
 * monto_pagado, de antes de que existiera este historial) se les crea, una
 * sola vez, un pago de respaldo con ese monto para que no "desaparezca" al
 * pasar a la nueva pantalla — queda marcado como "Sin especificar" porque
 * no hay forma de saber si esos pagos viejos fueron en efectivo o por
 * transferencia. Guardado: solo crea el pago de respaldo si ese estudiante
 * todavía no tiene NINGÚN pago en el historial para ese evento/práctica, así
 * una corrida posterior nunca duplica nada ni pisa pagos ya registrados
 * desde eventos/pago_estudiante.php o practicas/pago_estudiante.php.
 */
function migrarPagosEstudianteExistentes(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'pagos_estudiante', 'id')) {
        return $mensajes;
    }

    $existeStmt = $pdo->prepare(
        'SELECT 1 FROM pagos_estudiante WHERE entidad_tipo = ? AND entidad_id = ? AND estudiante_id = ? LIMIT 1'
    );
    $insStmt = $pdo->prepare(
        "INSERT INTO pagos_estudiante (entidad_tipo, entidad_id, estudiante_id, monto, metodo, fecha_pago)
         VALUES (?, ?, ?, ?, 'sin_especificar', ?)"
    );
    $migrados = 0;

    $stmt = $pdo->query('SELECT evento_id, estudiante_id, monto_pagado, fecha_pago FROM evento_estudiante WHERE monto_pagado > 0');
    foreach ($stmt->fetchAll() as $fila) {
        $existeStmt->execute(['evento', $fila['evento_id'], $fila['estudiante_id']]);
        if (!$existeStmt->fetchColumn()) {
            $insStmt->execute(['evento', $fila['evento_id'], $fila['estudiante_id'], $fila['monto_pagado'], $fila['fecha_pago'] ?: date('Y-m-d')]);
            $migrados++;
        }
    }

    $stmt = $pdo->query('SELECT practica_id, estudiante_id, monto_pagado, fecha_pago FROM practica_estudiante WHERE monto_pagado > 0');
    foreach ($stmt->fetchAll() as $fila) {
        $existeStmt->execute(['practica', $fila['practica_id'], $fila['estudiante_id']]);
        if (!$existeStmt->fetchColumn()) {
            $insStmt->execute(['practica', $fila['practica_id'], $fila['estudiante_id'], $fila['monto_pagado'], $fila['fecha_pago'] ?: date('Y-m-d')]);
            $migrados++;
        }
    }

    if ($migrados > 0) {
        $mensajes[] = "$migrados pago(s) existentes migrados al nuevo historial de pagos por estudiante, marcados como \"Sin especificar\" (no se sabe si fueron en efectivo o por transferencia) — corrígelos desde \"Estudiantes y pagos\" → \"Pagos\" si recuerdas cómo fue cada uno.";
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
        // Agregada a pedido de Eyaelkys de poder manejar la Fresa también en
        // Gramo/Libra/Kilogramo además de en Taza (ver
        // establecerPesoUnidadIngredientes() para el puente hacia Unidad).
        'Fresa' => [0.7083, '170 g por taza, fresas enteras (medidasrecetascocina.com)'],
        // Agregada a pedido de Eyaelkys (sección 28): sus dos recetas de
        // Piña quedaban en unidades sin ningún puente conocido (Unidad y
        // Taza) — el catálogo de Piña compra y usa por Unidad, así que este
        // valor es el que permite llegar hasta Taza (ver
        // establecerPesoUnidadIngredientes() para el puente Unidad↔gramo).
        'Piña' => [0.6875, '165 g por taza de piña picada (USDA FoodData Central)'],
        // Agregadas al preparar "Pastel de zanahoria con harina de
        // almendras" y "Yogur con granola y frutas" (sección 30): estos
        // cuatro ya existían en el catálogo pero solo en peso/gramaje
        // (Zanahoria y Azúcar morena en Libra, Mango en Unidad, Yogurt
        // natural (envase grande) en Gramo) y las recetas los piden en
        // Taza.
        'Zanahoria' => [0.4583, '110 g por taza, zanahoria rallada (ref. USDA/Nutritionix)'],
        'Azúcar morena' => [0.8333, '200 g por taza, compactada (medidasrecetascocina.com)'],
        'Mango' => [0.6875, '165 g por taza de mango en cubos (USDA FoodData Central)'],
        'Yogurt natural (envase grande)' => [1.0300, 'densidad estándar de yogur entero natural (chefsolver.com)'],
        // Único ingrediente nuevo que hizo falta para esta tanda: Harina de
        // almendras no existía en el catálogo (ver INSERT en
        // sembrarRecetasReposteria4()); sin esta densidad quedaría sin
        // convertir de Libra a Taza, que es como la pide la receta.
        'Harina de almendras' => [0.4000, '96 g por taza (gramspercup.com)'],
        // Agregada al preparar "Yogur helado con granola" (sección 31): el
        // Yogur griego natural del catálogo se compra y usa por Gramo, pero
        // la receta lo pide en Taza.
        'Yogur griego natural' => [1.0042, '241 g por taza, colado (chefsolver.com)'],
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
 * Siembra, para cada ingrediente que lo necesita (Fresa, y desde la
 * sección 28 también Piña), cuántos gramos pesa 1 "Unidad" de ese
 * ingrediente en particular — el mismo tipo
 * de puente que establecerDensidadIngredientes() ya usa para masa↔volumen
 * (ver esa función más arriba), pero para convertir el costo entre la
 * unidad de conteo "Unidad" y las unidades de masa/volumen del catálogo.
 * Pedido de Eyaelkys: "necesito manejar la fresa por libra, gramo,
 * kilogramo, taza y unidad" — con esto más la densidad ya agregada arriba,
 * las cinco quedan disponibles: Libra/Gramo/Kilogramo ya eran convertibles
 * entre sí (misma tipo_medida "masa"), Taza lo es gracias a la densidad, y
 * Unidad lo es gracias a este valor. Guardado igual que las demás
 * correcciones: solo toca la fila si peso_unidad_g sigue en NULL, para no
 * pisar un ajuste manual hecho después desde Ingredientes.
 */
function establecerPesoUnidadIngredientes(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'ingredientes_catalogo', 'peso_unidad_g')) {
        return $mensajes;
    }
    // nombre => [gramos por 1 Unidad, nota para el mensaje]
    $valores = [
        // Fresa grande — la misma referencia ya usada a mano en la línea
        // "Fresas grandes" de la receta "Brochetas de frutas" (sección 26):
        // con este valor, esa misma línea ya se podría cargar hoy con el
        // costo calculado solo por la aplicación, en vez de a mano.
        'Fresa' => [28.0, '28 g por fresa grande (ref. pasteleriamarianohernandez.es)'],
        // Piña — 1 Unidad (1 piña) se estima en 4 tazas de pulpa picada una
        // vez pelada y descorazonada (≈660 g a 165 g/taza, ver densidad
        // arriba) — cifra de referencia (howmuchisin.com cita 3 a 4.5 tazas
        // según el tamaño de la fruta); si el rendimiento real de las piñas
        // que compra Eyaelkys es distinto, este valor se puede ajustar
        // desde Ingredientes sin tocar código.
        'Piña' => [660.0, '4 tazas de pulpa por piña a 165 g/taza (USDA FoodData Central; rendimiento por fruta: howmuchisin.com)'],
        // Agregado al preparar "Yogur con granola y frutas" (sección 30):
        // el Mango del catálogo se compra y usa por Unidad, pero la receta
        // lo pide en Taza (ya en cubos) — con esto más la densidad de
        // arriba, queda convertible igual que Fresa y Piña.
        'Mango' => [250.0, 'peso aproximado de 1 mango entero, variedad común (rango de referencia 150-300 g)'],
    ];
    $stmt = $pdo->prepare('UPDATE ingredientes_catalogo SET peso_unidad_g = ? WHERE nombre = ? AND peso_unidad_g IS NULL');
    foreach ($valores as $nombre => [$peso, $nota]) {
        $stmt->execute([$peso, $nombre]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": peso por unidad agregado ($peso g, ref. $nota) para poder usarse también en Unidad (contado) dentro de una receta.";
        }
    }
    return $mensajes;
}

/**
 * Modo de compra por defecto (ver modo_compra_defecto en
 * ingredientes_catalogo, docblock en db/schema.sql) para ingredientes que
 * NO se deben forzar a comprar por paquete/caja completa en la Lista de
 * Compra. Pedido real de Eyaelkys (bug reportado desde una práctica en
 * producción): "SI necesito solo 3 huevos no me puedes mandar a comprar el
 * paquete. El costo del huevo para la lista de compra debe mantenerse a
 * nivel de la unidad." Con 'cantidad_exacta', listaCompraConsolidada() deja
 * el monto en el costo exacto ya prorrateado por unidad de uso (Unidad, en
 * el caso del Huevo) en vez de saltar al precio del cartón/Paquete
 * completo. Guardado igual que las demás correcciones: solo toca la fila
 * si modo_compra_defecto sigue en NULL, para no pisar un ajuste manual
 * hecho después desde Ingredientes (donde también se puede activar para
 * cualquier otro ingrediente con el mismo problema).
 */
function establecerModoCompraDefecto(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'ingredientes_catalogo', 'modo_compra_defecto')) {
        return $mensajes;
    }
    // nombre => nota para el mensaje
    $valores = [
        'Huevo' => 'se compra por cartón/Paquete pero se usa por Unidad; no tiene sentido forzar el cartón completo cuando una receta solo necesita unos pocos',
    ];
    $stmt = $pdo->prepare("UPDATE ingredientes_catalogo SET modo_compra_defecto = 'cantidad_exacta' WHERE nombre = ? AND modo_compra_defecto IS NULL");
    foreach ($valores as $nombre => $nota) {
        $stmt->execute([$nombre]);
        if ($stmt->rowCount() > 0) {
            $mensajes[] = "Ingrediente \"$nombre\": modo de compra por defecto puesto en \"cantidad exacta\" en vez de \"paquete completo\" ($nota).";
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

/**
 * Cuarta tanda de recetas nuevas pedidas por Eyaelkys por chat (sección 30):
 * Pastel de zanahoria con harina de almendras. (También incluía "Yogur con
 * granola y frutas", quitada más adelante por duplicar "Yogurt con frutas y
 * granola" de sembrarRecetasReposteria3() — ver el comentario al final de
 * esta función.)
 * Pidió expresamente que cualquier ingrediente que faltara se agregara "con
 * todas sus equivalencias correspondientes".
 *
 * Único ingrediente nuevo que hizo falta: "Harina de almendras" (no
 * existía). Precio de referencia: Harina de Almendra Carrefour 125 g,
 * RD$144.95 (supermercadosrd.com, septiembre 2026) — equivale a ~RD$525.99
 * por libra, que es como se guarda aquí (igual que las demás harinas del
 * catálogo, todas en Libra). Su densidad (96 g/taza) se agrega en
 * establecerDensidadIngredientes() junto con las de Zanahoria, Azúcar
 * morena, Mango y Yogurt natural (envase grande) — estos cuatro ya existían
 * pero solo en peso/gramaje, y ambas recetas los piden en Taza; ver esa
 * función (y establecerPesoUnidadIngredientes() para el peso por Unidad de
 * Mango) para el detalle y las fuentes de cada valor.
 *
 * Con esas equivalencias puestas, el costo de cada línea se calculó con la
 * misma fórmula que usa el formulario de recetas (costoPorUnidadUso() +
 * convertirCostoPorUnidad()), no a mano — igual que en las tandas
 * anteriores.
 *
 * Precios estimados de fuentes públicas, no del proveedor real de
 * Eyaelkys: revisar y ajustar desde Ingredientes si hace falta, igual que
 * ya ajustó el de la Piña.
 */
function sembrarRecetasReposteria4(PDO $pdo): array
{
    $mensajes = [];

    $pdo->exec("INSERT IGNORE INTO ingredientes_catalogo
        (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
        VALUES
        ('Harina de almendras',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Grano y cereal'),
         '🌰',
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         1, 525.99,
         'Harina de Almendra Carrefour 125 g, RD\$144.95 (supermercadosrd.com) equivale a ~RD\$525.99/lb')");

    $idPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    if (!$idPostre) {
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

    $crearReceta = function (int $categoriaId, string $nombre, int $porcionesBase, string $descripcion, string $preparacion, array $lineas) use (
        $pdo, $insLinea, $insAccion, $u, $ing, $accion, &$mensajes
    ) {
        $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
        $yaExiste->execute([$nombre]);
        if ((int) $yaExiste->fetchColumn() > 0) {
            return;
        }
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?,?)');
        $stmtR->execute([$nombre, $descripcion, $categoriaId, $porcionesBase, $preparacion]);
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
        'Pastel de zanahoria con harina de almendras',
        10,
        'Molde redondo de 22 cm. Horneado: 40–50 minutos.',
        "1. Precalienta el horno a 175 °C (350 °F). Engrasa el molde y cubre el fondo con papel para hornear.\n" .
        "2. En un recipiente, mezcla la harina de almendras, el polvo de hornear, el bicarbonato, la canela, la nuez moscada y la sal.\n" .
        "3. En otro recipiente, bate los huevos con el azúcar durante 2–3 minutos. Agrega el aceite y la vainilla.\n" .
        "4. Incorpora los ingredientes secos a la mezcla líquida y remueve suavemente hasta integrarlos.\n" .
        "5. Agrega la zanahoria rallada, las nueces y las pasas. Mezcla con una espátula. La masa quedará más húmeda y densa que una preparada con harina de trigo.\n" .
        "6. Vierte la mezcla en el molde y nivela la superficie.\n" .
        "7. Hornea durante 40–50 minutos. Comprueba la cocción introduciendo un palillo en el centro; debe salir sin masa cruda, aunque puede presentar algunas migas húmedas.\n" .
        "8. Déjalo enfriar en el molde durante 15 minutos. Luego desmolda cuidadosamente y espera a que se enfríe por completo antes de decorar.",
        [
            ['Harina de almendras', 'Harina de almendras', 3, 'Taza', 111.32, [], false, false, null],
            ['Zanahoria', 'Zanahoria rallada finamente', 2, 'Taza', 7.52, ['Rallado'], false, false, null],
            ['Huevo', 'Huevo', 4, 'Unidad', 6.50, [], false, false, null],
            ['Azúcar morena', 'Azúcar morena', 0.75, 'Taza', 14.55, [], false, false, null],
            ['Aceite vegetal', 'Aceite vegetal o aceite de coco derretido', 0.5, 'Taza', 35.28, [], false, false, null],
            ['Extracto de vainilla', 'Vainilla', 1, 'Cucharadita', 44.99, [], false, false, null],
            ['Polvo de hornear', 'Polvo de hornear', 2, 'Cucharadita', 2.50, [], false, false, null],
            ['Bicarbonato de sodio', 'Bicarbonato de sodio', 0.5, 'Cucharadita', 1.04, [], false, false, null],
            ['Canela en polvo', 'Canela en polvo', 2, 'Cucharadita', 3.80, [], false, false, null],
            ['Nuez moscada molida', 'Nuez moscada', 0.25, 'Cucharadita', 6.56, [], false, false, null],
            ['Sal', 'Sal', 0.5, 'Cucharadita', 0.20, [], false, false, null],
            ['Nueces', 'Nueces picadas', 0.5, 'Taza', 96.03, ['Picado'], false, true, null],
            ['Pasas', 'Pasas', 0.33, 'Taza', 71.90, [], false, true, null],
        ]
    );

    // "Yogur con granola y frutas" se quitó de aquí (Eyaelkys: "deja de
    // agregar la receta el yogen, que me la sigues duplicando"): esta receta
    // era, en la práctica, la misma que "Yogurt con frutas y granola" de
    // sembrarRecetasReposteria3() (yogur en capas con granola y frutas),
    // creada aquí por separado con un nombre ligeramente distinto sin darse
    // cuenta de que ya existía. Como la comprobación de "¿ya existe?" de
    // $crearReceta() compara el nombre exacto, las dos convivían como
    // recetas separadas — y si Eyaelkys borraba esta, setup.php se la volvía
    // a crear en la siguiente corrida porque para el código ya no "existía".
    // Quitar el bloque de aquí no borra la fila si ya está en su base de
    // datos: eso lo hace ella misma desde Recetas, como con cualquier receta
    // que ya no quiere.

    return $mensajes;
}

/**
 * Quinta tanda de recetas nuevas pedidas por Eyaelkys por chat (sección 31):
 * Yogur helado con granola (yogur griego licuado con fruta congelada,
 * servido o congelado 3-4 horas).
 *
 * No hizo falta ningún ingrediente nuevo en el catálogo — todos ya existían
 * de tandas anteriores. Al "Yogur griego natural" solo le faltaba la
 * densidad para poder pedirse en Taza (se agrega en
 * establecerDensidadIngredientes() junto con las demás, con su fuente).
 *
 * La receta ofrece varias opciones para dos líneas: "frutas congeladas:
 * fresas, mango o frutos rojos" y "miel o azúcar" — se enlazan al catálogo
 * de Fresa y Miel de abeja respectivamente (los primeros que menciona),
 * pero el nombre de la línea conserva las alternativas tal como las escribió
 * ella, igual que ya se hizo con "Aceite vegetal o aceite de coco derretido"
 * en sembrarRecetasReposteria4(). "Frutas frescas para decorar" no tiene
 * cantidad ni fruta específica en la receta, así que queda sin enlazar al
 * catálogo (ingrediente_id null) y marcada "al gusto" igual que "Miel o
 * sirope al gusto", ambas opcionales — ninguna de las dos entra en el costo
 * (ver costoTotalReceta() en includes/helpers.php).
 *
 * Costo de cada línea calculado con costoPorUnidadUso() +
 * convertirCostoPorUnidad(), no a mano, igual que las tandas anteriores.
 */
function sembrarRecetasReposteria5(PDO $pdo): array
{
    $mensajes = [];

    $idPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    if (!$idPostre) {
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

    $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
    $yaExiste->execute(['Yogur helado con granola']);
    if ((int) $yaExiste->fetchColumn() > 0) {
        return $mensajes;
    }

    $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?,?)');
    $stmtR->execute([
        'Yogur helado con granola',
        'Yogur helado cremoso, servido con granola. Tiempo de preparación: 15 minutos. Congelación (opcional, para más firmeza): 3–4 horas.',
        $idPostre,
        6,
        "1. Coloca en la licuadora el yogur griego, la leche, la miel o azúcar, la vainilla y las frutas congeladas.\n" .
        "2. Licúa hasta obtener una mezcla espesa, cremosa y uniforme. Si está demasiado densa, agrega un poco más de leche.\n" .
        "3. Para servirlo inmediatamente, distribuye la mezcla en vasos o copas y agrega la granola por encima.\n" .
        "4. Si deseas una consistencia más firme, coloca la preparación en un recipiente con tapa y congélala durante 3–4 horas.\n" .
        "5. Antes de servir, déjala reposar a temperatura ambiente durante 5–10 minutos.\n" .
        "6. Sirve en vasos y añade la granola, frutas frescas y un poco de miel o sirope.\n" .
        "\n" .
        'Importante: agrega la granola justo antes de servir para que permanezca crujiente.',
    ]);
    $recetaId = (int) $pdo->lastInsertId();

    $lineas = [
        ['Yogur griego natural', 'Yogur griego natural', 4, 'Taza', 185.95, [], false, false, null],
        ['Leche entera', 'Leche', 0.5, 'Taza', 17.76, [], false, false, null],
        ['Miel de abeja', 'Miel o azúcar', 0.5, 'Taza', 189.05, [], false, false, null],
        ['Extracto de vainilla', 'Vainilla', 1, 'Cucharadita', 44.99, [], false, false, null],
        ['Fresa', 'Frutas congeladas (fresa, mango o frutos rojos)', 2, 'Taza', 55.96, ['Congelado'], false, false, null],
        ['Granola', 'Granola', 1.5, 'Taza', 44.18, [], false, false, null],
        [null, 'Frutas frescas para decorar', 0, 'Unidad', 0, [], true, true, null],
        ['Miel de abeja', 'Miel o sirope al gusto', 0, 'Cucharada', 11.82, [], true, true, null],
    ];
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
    $mensajes[] = 'Receta "Yogur helado con granola" creada (6 porciones base, ' . count($lineas) . ' ingredientes).';

    return $mensajes;
}

/**
 * Sexta tanda de recetas nuevas (sección 43): Eyaelkys pidió sembrar 4
 * recetas de un recetario de clase (fotos de un PDF proyectado, módulo
 * "RA0090 – Cocción en medio ácido") — Ceviche de pescado, Escabeche de
 * vegetales, Pie de limón frío y Mousse de limón. A diferencia de todas las
 * tandas anteriores (puro postre/repostería), esta es la primera con
 * recetas saladas, así que hizo falta una categoría de receta nueva,
 * "Guarnición" (ver db/schema.sql), para "Escabeche de vegetales": no es ni
 * el plato fuerte ni un aperitivo. Decisiones confirmadas con Eyaelkys antes
 * de sembrar:
 * - "Ceviche de pescado" → categoría Plato fuerte; "Escabeche de vegetales"
 *   → categoría Guarnición (nueva).
 * - El pescado blanco del ceviche es Mero (ella lo confirmó; más caro que
 *   tilapia, que era la otra opción que se le propuso).
 * - "Crema de leche" (Pie de limón) y "Crema para batir" (Mousse de limón)
 *   son DOS ingredientes de catálogo distintos para ella (media crema/crema
 *   de leche normal vs. crema para batir que sí monta en picos) — antes del
 *   catálogo solo existía "Crema para batir"; "Crema de leche" es nueva.
 *
 * Doce ingredientes nuevos entraron al catálogo: Pescado blanco, Naranja
 * agria, Ají cubanela, Cebolla roja, Cebolla blanca, Pimiento, Calabacín,
 * Cilantro, Vinagre, Laurel, Galletas y Crema de leche (el resto — Limón
 * verde, Leche condensada, Crema para batir, Gelatina sin sabor, Zanahoria,
 * Agua, Azúcar blanca, Sal, Pimienta negra molida — ya existían de tandas
 * anteriores y se reusan con su costo ya establecido, sin volver a
 * investigarlos). Precios de referencia:
 * - Cebolla roja, Cebolla blanca, Ají cubanela, Pimiento: Informe de
 *   Precios del Ministerio de Agricultura de RD, 20 de mayo de 2026
 *   (agricultura.gob.do) — precio oficial por libra. La cantidad de
 *   unidades por libra (cuántas cebollas/ajíes/pimientos entran en una
 *   libra) es una estimación de peso promedio, no un dato del informe.
 * - Naranja agria: mismo informe, precio por docena (RD$216.00) convertido
 *   a por unidad; el rendimiento de jugo por naranja (≈0.4 taza) es una
 *   estimación, no viene del informe.
 * - Pescado blanco (Mero), Calabacín, Cilantro, Vinagre, Laurel, Galletas,
 *   Crema de leche: NO se encontró un precio puntual verificable de un
 *   supermercado dominicano específico en esta investigación (a diferencia
 *   de las tandas anteriores, que sí citaban un producto y precio exactos).
 *   Son estimaciones razonables de mercado — Eyaelkys debe revisarlas y
 *   ajustarlas desde Ingredientes con el precio real de su proveedor en
 *   cuanto pueda; cada nota_compra lo deja explícito.
 *
 * Otras decisiones tomadas al transcribir, sin preguntarle (ninguna
 * cambia el costo de forma importante ni es ambigua a nivel de negocio):
 * - Ninguna de las 4 recetas indicaba cuántas porciones rinde. Se
 *   estimaron: Ceviche 6, Escabeche 6, Pie de limón 8, Mousse de limón 6
 *   (igual que "Mousse de chinola", su receta de mousse ya existente) — a
 *   revisar y ajustar por Eyaelkys si no son las que usa en clase.
 * - "Sal y pimienta" (ceviche) y "Laurel y pimienta" (escabeche) no traían
 *   cantidad en la foto: se cargaron como "al gusto" (cantidad 0, no
 *   entran en el costo), igual que otras líneas "al gusto" de tandas
 *   anteriores.
 * - El último paso del ceviche mencionaba seguir "el protocolo del centro"
 *   de la institución donde se tomó la foto — se cambió a una frase
 *   genérica de manejo higiénico de pescado crudo, porque ese protocolo
 *   específico no es de la escuela de Eyaelkys.
 * - "Ralladura de limón" (pie) tampoco traía cantidad: se cargó "al gusto"
 *   igual que los casos anteriores, enlazada al mismo catálogo de "Limón
 *   verde" que el jugo.
 * - "395 g" de leche condensada (pie y mousse) es el tamaño exacto de una
 *   lata estándar (La Lechera/Nestlé), así que ambas líneas se cargaron
 *   como "1 Lata" directamente.
 * - "10 g" de gelatina sin sabor (mousse) se convirtió a cucharaditas
 *   usando la equivalencia ya guardada en el catálogo (1 sobre ≈ 7 g ≈ 3
 *   cucharaditas): 10 g ≈ 4.29 cucharaditas.
 *
 * Costo de cada línea calculado a mano (no con costoPorUnidadUso() en
 * vivo, igual que sembrarRecetasReposteria1()/2()): el detalle de cada
 * conversión está en el nota_compra del ingrediente nuevo o en el
 * comentario junto a la línea, cuando la unidad de la receta no coincide
 * con la unidad de uso del catálogo.
 */
function sembrarRecetasCevicheEscabecheYLimon(PDO $pdo): array
{
    $mensajes = [];

    $pdo->exec("INSERT IGNORE INTO ingredientes_catalogo
        (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
        VALUES
        ('Pescado blanco',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Pescado y marisco'),
         '🐟',
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         1, 220.00,
         'Filete de mero (pescado blanco confirmado por Eyaelkys). Estimación de mercado para RD, sin fuente puntual verificada en esta investigación — ajusta el precio real desde Ingredientes en cuanto tengas el de tu pescadería/proveedor.'),
        ('Naranja agria',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Fruta'),
         '🍊',
         (SELECT id FROM unidades_medida WHERE nombre='Taza'),
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         0.4, 18.00,
         'RD\$216.00/docena (Informe de Precios, Ministerio de Agricultura RD, 20 de mayo de 2026, agricultura.gob.do) = RD\$18.00/unidad ≈ 0.4 taza de jugo por naranja (estimación de rendimiento, no del informe)'),
        ('Ají cubanela',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🌶️',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         7, 75.00,
         'RD\$75.00/libra (Informe de Precios, Ministerio de Agricultura RD, 20 de mayo de 2026, agricultura.gob.do) ≈ 7 ajíes/libra (≈65 g c/u, estimación de peso)'),
        ('Cebolla roja',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🧅',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         3, 50.00,
         'RD\$50.00/libra, cebolla roja criolla (Informe de Precios, Ministerio de Agricultura RD, 20 de mayo de 2026, agricultura.gob.do) ≈ 3 cebollas medianas/libra (estimación de peso)'),
        ('Cebolla blanca',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🧅',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         3, 50.00,
         'RD\$50.00/libra, cebolla amarilla/blanca importada (Informe de Precios, Ministerio de Agricultura RD, 20 de mayo de 2026, agricultura.gob.do) ≈ 3 cebollas medianas/libra (estimación de peso)'),
        ('Pimiento',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🫑',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         3, 55.00,
         'RD\$55.00/libra, ají morrón/pimiento (Informe de Precios, Ministerio de Agricultura RD, 20 de mayo de 2026, agricultura.gob.do) ≈ 3 pimientos/libra (estimación de peso)'),
        ('Calabacín',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🥒',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Libra'),
         2, 45.00,
         'Estimación de mercado para RD (≈RD\$45.00/libra) — no aparece en los informes de precios agrícolas revisados en esta investigación, ajusta si tienes el precio real. ≈ 2 calabacines medianos/libra (estimación de peso)'),
        ('Cilantro',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Vegetal'),
         '🌿',
         (SELECT id FROM unidades_medida WHERE nombre='Manojo'),
         (SELECT id FROM unidades_medida WHERE nombre='Manojo'),
         1, 25.00,
         'Estimación de mercado para RD (≈RD\$25.00/manojo), sin fuente puntual verificada — ajusta si tienes el precio real'),
        ('Vinagre',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'),
         '🍶',
         (SELECT id FROM unidades_medida WHERE nombre='Mililitro'),
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         355, 55.00,
         'Estimación de mercado para RD, botella de 355 ml / 12 oz ≈ RD\$55.00, sin fuente puntual verificada — ajusta si tienes el precio real de tu proveedor'),
        ('Laurel',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Condimento y especia'),
         '🍃',
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         15, 70.00,
         'Estimación de mercado para RD, paquete de hojas secas ≈15 hojas por RD\$70.00, sin fuente puntual verificada — ajusta si tienes el precio real'),
        ('Galletas',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Repostería'),
         '🍪',
         (SELECT id FROM unidades_medida WHERE nombre='Gramo'),
         (SELECT id FROM unidades_medida WHERE nombre='Paquete'),
         200, 75.00,
         'Galletas tipo María, estimación de mercado para RD, paquete de 200 g ≈ RD\$75.00, sin fuente puntual verificada — ajusta si tienes el precio real'),
        ('Crema de leche',
         (SELECT id FROM categorias_ingrediente WHERE nombre='Lácteo y huevo'),
         '🥛',
         (SELECT id FROM unidades_medida WHERE nombre='Mililitro'),
         (SELECT id FROM unidades_medida WHERE nombre='Unidad'),
         225, 110.00,
         'Crema de leche/media crema — NO es \"Crema para batir\" (esa ya existía en el catálogo y no monta en picos igual; son dos productos distintos a pedido de Eyaelkys). Estimación de mercado para RD, lata de 225 g ≈ RD\$110.00, sin fuente puntual verificada — ajusta si tienes el precio real')");

    $idGuarnicion = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Guarnición');
    $idPlatoFuerte = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Plato fuerte');
    $idPostre = idPorNombre($pdo, 'categorias_receta', 'nombre', 'Postre');
    if (!$idGuarnicion || !$idPlatoFuerte || !$idPostre) {
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

    $crearReceta = function (int $categoriaId, string $nombre, ?string $descripcion, int $porcionesBase, string $preparacion, array $lineas) use (
        $pdo, $insLinea, $insAccion, $u, $ing, $accion, &$mensajes
    ) {
        $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
        $yaExiste->execute([$nombre]);
        if ((int) $yaExiste->fetchColumn() > 0) {
            return;
        }
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?,?)');
        $stmtR->execute([$nombre, $descripcion, $categoriaId, $porcionesBase, $preparacion]);
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
        $idPlatoFuerte,
        'Ceviche de pescado',
        'Ceviche de pescado blanco (mero) marinado en cítricos.',
        6,
        "Mantener el pescado refrigerado hasta el momento de prepararlo.\n\n" .
        "Mezclar el pescado en cubos con el jugo de limón, la naranja agria, la cebolla roja, el ají cubanela y el cilantro.\n\n" .
        "Sazonar con sal y pimienta al gusto, y refrigerar.\n\n" .
        'Servir frío, manejando el pescado con las medidas de higiene adecuadas para consumo sin cocción térmica.',
        [
            ['Pescado blanco', 'Pescado blanco (mero) en cubos', 1, 'Kilogramo', 485.02, ['Cortado en cubos'], false, false, null],
            // Costo a mano: RD$68.00/libra de Limón verde ÷ 13 cucharadas/libra = 5.23/cucharada × 16 cucharadas/taza.
            ['Limón verde', 'Jugo de limón', 1, 'Taza', 83.69, [], false, false, null],
            ['Naranja agria', 'Naranja agria', 0.5, 'Taza', 45.00, [], false, false, null],
            ['Cebolla roja', 'Cebolla roja', 1, 'Unidad', 16.67, [], false, false, null],
            ['Ají cubanela', 'Ají cubanela', 1, 'Unidad', 10.71, [], false, false, null],
            ['Cilantro', 'Cilantro', 0.5, 'Taza', 20.00, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );

    $crearReceta(
        $idGuarnicion,
        'Escabeche de vegetales',
        'Vegetales mixtos cocidos brevemente y marinados en vinagre, para acompañar.',
        6,
        "Cortar los vegetales (zanahoria, cebolla, pimiento y calabacín) en el tamaño deseado.\n\n" .
        "Hervir el agua junto con el vinagre, el azúcar, la sal y las especias.\n\n" .
        "Agregar los vegetales y cocinar brevemente.\n\n" .
        'Dejar enfriar y conservar refrigerado.',
        [
            ['Zanahoria', 'Zanahoria', 2, 'Unidad', 4.17, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 16.67, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Calabacín', 'Calabacín', 1, 'Unidad', 22.50, [], false, false, null],
            // Costo a mano: RD$55.00/botella 355 ml ÷ 355 ml = 0.1549/ml × 236.588 ml/taza.
            ['Vinagre', 'Vinagre', 1, 'Taza', 36.66, [], false, false, null],
            ['Agua', 'Agua', 1, 'Taza', 0, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 2, 'Cucharada', 0.97, [], false, false, null],
            ['Sal', 'Sal', 1, 'Cucharadita', 0.20, [], false, false, null],
            ['Laurel', 'Laurel al gusto', 0, 'Unidad', 4.67, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );

    $crearReceta(
        $idPostre,
        'Pie de limón frío',
        'Pie frío de base de galleta con relleno de limón, sin hornear.',
        8,
        "Formar la base mezclando las galletas trituradas con la mantequilla derretida, y presionarla en el molde.\n\n" .
        "Mezclar la leche condensada, la crema de leche y el jugo de limón.\n\n" .
        "Verter la mezcla sobre la base de galleta.\n\n" .
        'Refrigerar durante 4 horas y decorar con ralladura de limón antes de servir.',
        [
            ['Galletas', 'Galletas trituradas', 250, 'Gramo', 0.38, [], false, false, null],
            // Costo a mano: RD$70.00/taza de Mantequilla ÷ 226 g/taza (ver establecerDensidadIngredientes()).
            ['Mantequilla', 'Mantequilla derretida', 120, 'Gramo', 0.31, ['Derretido'], false, false, null],
            // 395 g = 1 lata estándar (La Lechera/Nestlé); 0.5 lata ya costaba 110.00 en "Mousse de chinola".
            ['Leche condensada', 'Leche condensada', 1, 'Lata', 220.00, [], false, false, null],
            ['Crema de leche', 'Crema de leche', 250, 'Mililitro', 0.49, [], false, false, null],
            // Costo a mano: mismo cálculo que "Jugo de limón" de arriba, llevado a mililitro (5.23/cda ÷ 14.787 ml/cda).
            ['Limón verde', 'Jugo de limón', 120, 'Mililitro', 0.35, [], false, false, null],
            ['Limón verde', 'Ralladura de limón al gusto', 0, 'Unidad', 5.23, ['Rallado'], true, true, null],
        ]
    );

    $crearReceta(
        $idPostre,
        'Mousse de limón',
        'Mousse fría de limón, individual, con gelatina sin sabor.',
        6,
        "Hidratar la gelatina sin sabor en el agua y dejar reposar unos minutos.\n\n" .
        "Disolver la gelatina hidratada suavemente, sin dejar hervir.\n\n" .
        "Mezclar el jugo de limón con la leche condensada.\n\n" .
        "Incorporar la gelatina disuelta.\n\n" .
        "Batir la crema para batir a picos suaves e integrarla con movimientos envolventes.\n\n" .
        'Distribuir en vasitos, porcionar y refrigerar.',
        [
            ['Limón verde', 'Jugo de limón', 200, 'Mililitro', 0.35, [], false, false, null],
            ['Leche condensada', 'Leche condensada', 1, 'Lata', 220.00, [], false, false, null],
            // Costo a mano: RD$62.80/taza de Crema para batir (ver sembrarRecetasReposteria3()) ÷ 236.588 ml/taza.
            ['Crema para batir', 'Crema para batir', 300, 'Mililitro', 0.27, ['Batido'], false, false, null],
            // 10 g ≈ 4.29 cucharaditas, usando la equivalencia ya guardada en el catálogo (1 sobre ≈ 7 g ≈ 3 cucharaditas).
            ['Gelatina sin sabor', 'Gelatina sin sabor', 4.29, 'Cucharadita', 16.67, [], false, false, null],
            ['Agua', 'Agua', 50, 'Mililitro', 0, [], false, false, null],
        ]
    );

    return $mensajes;
}

/**
 * Migra los padres/tutores que hoy viven como texto libre en
 * estudiantes.padre_tutor/telefono_padre_tutor a la tabla "padres" +
 * "padre_estudiante" (pedido de Eyaelkys: "Debemos llevar el nombre y el
 * telefono a la tabla de padres y ahi mismo hacer la relacion con ese
 * estudiante porque esa ya la conoces a nivel de la DB").
 *
 * Guardada como el resto de migraciones de datos del proyecto: solo toca un
 * estudiante que TODAVÍA no tenga ningún vínculo en padre_estudiante (si ya
 * lo tiene —porque se migró antes, o porque Eyaelkys ya lo cargó a mano
 * desde el CRUD nuevo— no hace nada), así que correr setup.php de nuevo
 * nunca duplica nada. Para no crear un padre repetido cuando dos hermanos
 * comparten el mismo padre/tutor, busca primero si ya existe un padre con el
 * mismo nombre y teléfono (la comparación de nombre usa la collation
 * utf8mb4_unicode_ci de la tabla, que ya no distingue mayúsculas/minúsculas)
 * antes de crear uno nuevo.
 */
function migrarPadresDesdeTextoLibre(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'padres', 'id') || !columnaExiste($pdo, 'estudiantes', 'padre_tutor')) {
        return $mensajes;
    }

    $yaVinculadoStmt = $pdo->prepare('SELECT 1 FROM padre_estudiante WHERE estudiante_id = ? LIMIT 1');
    // "telefono <=> ?" en vez de "telefono = ?" para que también empareje
    // dos padres sin teléfono cargado (NULL <=> NULL es verdadero, a
    // diferencia de NULL = NULL).
    $buscarPadreStmt = $pdo->prepare('SELECT id FROM padres WHERE nombre = ? AND (telefono <=> ?) LIMIT 1');
    $crearPadreStmt = $pdo->prepare('INSERT INTO padres (nombre, telefono) VALUES (?, ?)');
    $vincularStmt = $pdo->prepare('INSERT IGNORE INTO padre_estudiante (padre_id, estudiante_id) VALUES (?, ?)');

    $padresCreados = 0;
    $estudiantesVinculados = 0;

    $stmt = $pdo->query("SELECT id, padre_tutor, telefono_padre_tutor FROM estudiantes WHERE padre_tutor IS NOT NULL AND TRIM(padre_tutor) <> ''");
    foreach ($stmt->fetchAll() as $fila) {
        $yaVinculadoStmt->execute([$fila['id']]);
        if ($yaVinculadoStmt->fetchColumn()) {
            continue;
        }

        $nombre = trim($fila['padre_tutor']);
        $telefono = trim((string) ($fila['telefono_padre_tutor'] ?? ''));
        $telefono = $telefono !== '' ? $telefono : null;

        $buscarPadreStmt->execute([$nombre, $telefono]);
        $padreId = $buscarPadreStmt->fetchColumn();
        if (!$padreId) {
            $crearPadreStmt->execute([$nombre, $telefono]);
            $padreId = (int) $pdo->lastInsertId();
            $padresCreados++;
        }

        $vincularStmt->execute([(int) $padreId, (int) $fila['id']]);
        $estudiantesVinculados++;
    }

    if ($estudiantesVinculados > 0) {
        $mensajes[] = "$estudiantesVinculados estudiante(s) vinculados a $padresCreados padre(s)/tutor(es) nuevos, migrados automáticamente desde el campo de texto \"Padre/madre o tutor\" que ya tenían cargado.";
    }
    return $mensajes;
}

/**
 * Agrega 'fondo' a la lista de métodos de pago válidos en pagos_estudiante
 * (efectivo/transferencia/sin_especificar ya existían) — necesario para que
 * aplicarFondoEstudiante() (includes/helpers.php) pueda registrar un pago
 * hecho con el fondo del estudiante reutilizando ese mismo historial.
 * CREATE TABLE IF NOT EXISTS no modifica una tabla que ya existe, así que
 * este ALTER se hace aquí a mano, comprobando primero si 'fondo' ya está en
 * el ENUM (seguro de ejecutar varias veces).
 */
function agregarMetodoFondoAPagosEstudiante(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'pagos_estudiante', 'metodo')) {
        return $mensajes;
    }
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos_estudiante' AND COLUMN_NAME = 'metodo'"
    );
    $stmt->execute();
    $tipoActual = (string) $stmt->fetchColumn();
    if ($tipoActual !== '' && strpos($tipoActual, "'fondo'") === false) {
        $pdo->exec("ALTER TABLE pagos_estudiante MODIFY metodo ENUM('efectivo','transferencia','sin_especificar','fondo') NOT NULL DEFAULT 'sin_especificar'");
        $mensajes[] = 'Se habilitó "fondo" como método de pago (para los pagos aplicados desde el fondo del estudiante).';
    }
    return $mensajes;
}

/**
 * Agrega la columna "metodo" (efectivo/transferencia) a fondo_movimientos,
 * a pedido de Eyaelkys, para poder indicar cómo entró un depósito al fondo
 * — igual que en pagos_estudiante. Solo aplica a depósitos (una aplicación
 * no la usa, queda NULL). CREATE TABLE IF NOT EXISTS no modifica una tabla
 * que ya existe, así que esto se agrega aquí a mano, comprobando primero si
 * la columna ya está (seguro de ejecutar varias veces).
 */
function agregarMetodoAFondoMovimientos(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'fondo_movimientos', 'id') || columnaExiste($pdo, 'fondo_movimientos', 'metodo')) {
        return $mensajes;
    }
    $pdo->exec("ALTER TABLE fondo_movimientos ADD COLUMN metodo ENUM('efectivo','transferencia') NULL AFTER monto");
    $mensajes[] = 'Se agregó el método (efectivo/transferencia) a los movimientos del fondo.';
    return $mensajes;
}

/**
 * Agrega estudiantes.usuario_id (Paso 5: el estudiante también puede tener
 * su propia cuenta de acceso, vía invitación — ver panel_estudiante.php y
 * consumirInvitacionRegistro() en includes/helpers.php). Va aquí y no en el
 * CREATE TABLE de db/schema.sql porque "estudiantes" ya se había entregado
 * sin esta columna. Mismo patrón que padres.usuario_id (Paso 1): NULL
 * mientras no tenga cuenta, único (un usuario de login le pertenece, como
 * mucho, a un estudiante).
 */
function agregarUsuarioIdAEstudiantes(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'estudiantes', 'id') || columnaExiste($pdo, 'estudiantes', 'usuario_id')) {
        return $mensajes;
    }
    $pdo->exec('ALTER TABLE estudiantes ADD COLUMN usuario_id INT UNSIGNED NULL');
    $pdo->exec('ALTER TABLE estudiantes ADD UNIQUE KEY uq_estudiantes_usuario (usuario_id)');
    $pdo->exec('ALTER TABLE estudiantes ADD CONSTRAINT fk_estudiantes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL');
    $mensajes[] = 'Se agregó "usuario_id" a la tabla estudiantes (para su propia cuenta de acceso, ver panel_estudiante.php).';
    return $mensajes;
}

/**
 * Agrega usuarios.ultimo_acceso (DATETIME NULL): la última vez que ese
 * usuario inició sesión o navegó por el sistema — se muestra en Usuarios y
 * roles → lista de usuarios, a pedido de Eyaelkys ("necesito saber la
 * última conexión de los usuarios"). Se llena desde includes/auth.php
 * (registrarAccesoUsuario(): al iniciar sesión y, mientras navegan, como
 * máximo una vez cada 5 minutos por sesión). Va aquí y no en el CREATE TABLE
 * de db/schema.sql porque "usuarios" ya existe en producción. Queda NULL
 * hasta el primer acceso posterior a esta migración (no hay forma de
 * reconstruir accesos anteriores), y la lista lo muestra como "Sin registro".
 */
function agregarUltimoAccesoAUsuarios(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'usuarios', 'id') || columnaExiste($pdo, 'usuarios', 'ultimo_acceso')) {
        return $mensajes;
    }
    $pdo->exec('ALTER TABLE usuarios ADD COLUMN ultimo_acceso DATETIME NULL');
    $mensajes[] = 'Se agregó "ultimo_acceso" a la tabla usuarios (última conexión de cada usuario, visible en Usuarios y roles). Se irá llenando a medida que cada quien vuelva a entrar.';
    return $mensajes;
}

/**
 * Agrega eventos.responsable_compra_id y practicas.responsable_compra_id
 * (INT UNSIGNED NULL, FK a estudiantes con ON DELETE SET NULL): el estudiante
 * encargado de ir a hacer la compra de ese evento/práctica, a pedido de
 * Eyaelkys ("asignar a nivel de la práctica y del evento el responsable de
 * realizar la compra [...] siempre será un estudiante de los incluidos [...]
 * se asigna 1"). Se elige con un dropdown en el detalle (ver
 * obtenerResponsableCompra()/asignarResponsableCompra() en
 * includes/helpers.php). Que sea alguien de los asignados lo garantiza la
 * aplicación (no una FK compuesta, porque ON DELETE SET NULL sobre una FK
 * compuesta también intentaría anular evento_id/practica_id, que son NOT
 * NULL): al guardar se valida y, si se quita al estudiante del evento o la
 * práctica, el responsable se libera. Va aquí y no en el CREATE TABLE de
 * db/schema.sql porque ambas tablas ya existen en producción. Cada tabla se
 * migra por separado y es segura de correr varias veces.
 */
function agregarResponsableCompra(PDO $pdo): array
{
    $mensajes = [];
    foreach (['eventos' => 'evento', 'practicas' => 'práctica'] as $tabla => $nombre) {
        if (!columnaExiste($pdo, $tabla, 'id') || columnaExiste($pdo, $tabla, 'responsable_compra_id')) {
            continue;
        }
        $pdo->exec("ALTER TABLE $tabla ADD COLUMN responsable_compra_id INT UNSIGNED NULL");
        $pdo->exec("ALTER TABLE $tabla ADD CONSTRAINT fk_{$tabla}_responsable_compra FOREIGN KEY (responsable_compra_id) REFERENCES estudiantes(id) ON DELETE SET NULL");
        $mensajes[] = "Se agregó \"responsable_compra_id\" a la tabla $tabla (el estudiante encargado de hacer la compra de cada $nombre, se elige en su detalle).";
    }
    return $mensajes;
}

/**
 * Agrega recetas.porciones_prueba (INT UNSIGNED NULL), a pedido de
 * Eyaelkys: las recetas que diseña el maestro para la clase están pensadas
 * para que los 17 estudiantes las prueben, así que cada receta necesita dos
 * números: "porciones" (lo que rinde realmente un plato, para eventos y como
 * referencia futura) y "porciones de prueba" (cuántas degustaciones salen de
 * la misma tanda). Las cantidades de los ingredientes siguen siendo las de
 * una tanda; en prácticas se escala contra las porciones de prueba y en
 * eventos contra las porciones reales (ver porcionesReferencia() en
 * includes/helpers.php). NULL = la receta no tiene porciones de prueba y se
 * comporta exactamente como antes. Va aquí y no en el CREATE TABLE de
 * db/schema.sql porque "recetas" ya existe en producción.
 */
function agregarPorcionesPruebaARecetas(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'recetas', 'id') || columnaExiste($pdo, 'recetas', 'porciones_prueba')) {
        return $mensajes;
    }
    $pdo->exec('ALTER TABLE recetas ADD COLUMN porciones_prueba INT UNSIGNED NULL AFTER porciones_base');
    $mensajes[] = 'Columna "porciones_prueba" agregada a la tabla recetas (cuántas degustaciones rinde la tanda de la receta; vacío = igual que antes, las recetas existentes no cambian).';
    return $mensajes;
}

/**
 * Agrega recetas.icono (VARCHAR(16) NULL): el emoji que se muestra antes del
 * nombre de la receta en tarjetas y títulos (ej. "🍎 Cheesecake de manzana"),
 * a pedido de Eyaelkys. Al crearse la columna se rellena UNA vez, para las
 * recetas existentes, con el ícono que sugiere iconoSugeridoReceta()
 * (includes/helpers.php) según el nombre y la categoría; las recetas cuyo
 * nombre ya empieza con un emoji escrito a mano se dejan sin ícono propio
 * (para no repetirlo). Después de eso, cada receta conserva el que se
 * elija en su formulario (vacío = se sugiere uno al guardar). Va aquí y no
 * en el CREATE TABLE de db/schema.sql porque "recetas" ya existe en producción.
 */
function agregarIconoARecetas(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'recetas', 'id') || columnaExiste($pdo, 'recetas', 'icono')) {
        return $mensajes;
    }
    $pdo->exec('ALTER TABLE recetas ADD COLUMN icono VARCHAR(16) NULL AFTER nombre');
    $filas = $pdo->query('SELECT r.id, r.nombre, cr.nombre AS categoria FROM recetas r LEFT JOIN categorias_receta cr ON cr.id = r.categoria_id')->fetchAll();
    $upd = $pdo->prepare('UPDATE recetas SET icono = ? WHERE id = ?');
    $n = 0;
    foreach ($filas as $f) {
        if (empiezaConEmoji((string) $f['nombre'])) {
            continue;
        }
        $upd->execute([iconoSugeridoReceta((string) $f['nombre'], (string) ($f['categoria'] ?? '')), (int) $f['id']]);
        $n++;
    }
    $mensajes[] = "Columna \"icono\" agregada a la tabla recetas y se asignó un ícono sugerido a $n recetas (puedes cambiarlo desde el formulario de cada receta).";
    return $mensajes;
}

/**
 * Agrega practica_receta.base_calculo (VARCHAR(10) NOT NULL DEFAULT
 * 'prueba'), a pedido de Eyaelkys: en cada práctica se elige, receta por
 * receta, si se calcula y trabaja con las porciones de prueba (degustaciones
 * para la clase) o con las porciones reales (platos completos). 'prueba' es
 * el valor por defecto y equivale al comportamiento de siempre (si la receta
 * no tiene porciones de prueba, se usan las reales sin importar este valor).
 * Va aquí y no en el CREATE TABLE de db/schema.sql porque "practica_receta"
 * ya existe en producción.
 */
function agregarBaseCalculoAPracticaReceta(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'practica_receta', 'practica_id') || columnaExiste($pdo, 'practica_receta', 'base_calculo')) {
        return $mensajes;
    }
    $pdo->exec("ALTER TABLE practica_receta ADD COLUMN base_calculo VARCHAR(10) NOT NULL DEFAULT 'prueba'");
    $mensajes[] = 'Columna "base_calculo" agregada a la tabla practica_receta (en cada práctica se elige si cada receta se calcula con porciones de prueba o reales; las prácticas existentes quedaron como siempre).';
    return $mensajes;
}

/**
 * Soporte común de la carga de las 80 recetas (tandas 1 a 4, más abajo):
 * crea (si faltan) las categorías "Sopa y crema" y "Salsa y conserva" y las
 * unidades Hoja, Tira, Lonja, Tubo y Rodaja (cantidades enteras, como
 * Diente o Rebanada). Todo con INSERT IGNORE: correrlo varias veces no
 * duplica nada.
 */
function prepararCargaRecetas80(PDO $pdo): void
{
    foreach (['Sopa y crema', 'Salsa y conserva'] as $nombreCat) {
        if (!idPorNombre($pdo, 'categorias_receta', 'nombre', $nombreCat)) {
            $orden = (int) $pdo->query('SELECT COALESCE(MAX(orden), 0) + 10 FROM categorias_receta')->fetchColumn();
            $pdo->prepare('INSERT IGNORE INTO categorias_receta (nombre, orden) VALUES (?, ?)')->execute([$nombreCat, $orden]);
        }
    }
    $tieneEntera = columnaExiste($pdo, 'unidades_medida', 'es_entera');
    $unidades = [['Hoja', 'hoja', 190], ['Tira', 'tira', 200], ['Lonja', 'lonja', 210], ['Tubo', 'tubo', 220], ['Rodaja', 'rodaja', 230]];
    foreach ($unidades as [$nombre, $abrev, $orden]) {
        if (idPorNombre($pdo, 'unidades_medida', 'nombre', $nombre)) {
            continue;
        }
        if ($tieneEntera) {
            $pdo->prepare('INSERT IGNORE INTO unidades_medida (nombre, abreviatura, orden, es_entera) VALUES (?, ?, ?, 1)')->execute([$nombre, $abrev, $orden]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO unidades_medida (nombre, abreviatura, orden) VALUES (?, ?, ?)')->execute([$nombre, $abrev, $orden]);
        }
    }
}

/**
 * Inserta ingredientes nuevos en el catálogo (INSERT IGNORE por nombre: si
 * Eyaelkys ya tiene uno con ese nombre, no se toca). Cada fila:
 * [nombre, categoría, ícono, unidad de uso, unidad de compra, contenido por
 * compra, precio de compra, nota].
 */
function sembrarIngredientesNuevos80(PDO $pdo, array $filas): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO ingredientes_catalogo
            (nombre, categoria_id, icono, unidad_id, unidad_compra_id, contenido_por_compra, precio_compra, nota_compra)
         VALUES (?, (SELECT id FROM categorias_ingrediente WHERE nombre = ?), ?,
                 (SELECT id FROM unidades_medida WHERE nombre = ?),
                 (SELECT id FROM unidades_medida WHERE nombre = ?), ?, ?, ?)'
    );
    foreach ($filas as $f) {
        $stmt->execute($f);
    }
}

/**
 * Completa peso_unidad_g / densidad_g_ml de ingredientes del catálogo SOLO
 * donde están vacíos (NULL), para que la lista de compra pueda convertir,
 * por ejemplo, "1 cebolla" a libras. No pisa valores que ya existan.
 * Cada fila: [nombre, densidad g/ml o null, peso por unidad en g o null].
 */
function completarPesoDensidad80(PDO $pdo, array $filas): void
{
    $tieneDens = columnaExiste($pdo, 'ingredientes_catalogo', 'densidad_g_ml');
    $tienePeso = columnaExiste($pdo, 'ingredientes_catalogo', 'peso_unidad_g');
    foreach ($filas as [$nombre, $dens, $peso]) {
        if ($tieneDens && $dens !== null) {
            $pdo->prepare('UPDATE ingredientes_catalogo SET densidad_g_ml = ? WHERE nombre = ? AND densidad_g_ml IS NULL')->execute([$dens, $nombre]);
        }
        if ($tienePeso && $peso !== null) {
            $pdo->prepare('UPDATE ingredientes_catalogo SET peso_unidad_g = ? WHERE nombre = ? AND peso_unidad_g IS NULL')->execute([$peso, $nombre]);
        }
    }
}

/**
 * Crea una receta de la carga de las 80 recetas, con sus ingredientes, solo
 * si no existe ya una con ese nombre. Devuelve el mensaje para mostrar en
 * setup.php, o null si la receta ya existía. Si algo falla en una receta,
 * se deshace solo esa receta y se informa, sin detener el resto de
 * setup.php. Cada línea: [catálogo|null, nombre, cantidad, unidad, costo
 * por unidad, acciones, al gusto, opcional, reemplazo].
 */
function crearRecetaCarga80(PDO $pdo, string $categoria, string $nombre, string $descripcion, int $porcionesBase, string $preparacion, array $lineas): ?string
{
    $yaExiste = $pdo->prepare('SELECT COUNT(*) FROM recetas WHERE nombre = ?');
    $yaExiste->execute([$nombre]);
    if ((int) $yaExiste->fetchColumn() > 0) {
        return null;
    }
    $categoriaId = idPorNombre($pdo, 'categorias_receta', 'nombre', $categoria);
    if (!$categoriaId) {
        return "No se creó la receta \"$nombre\": falta la categoría \"$categoria\".";
    }
    try {
        $pdo->beginTransaction();
        $stmtR = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion) VALUES (?,?,?,?,?)');
        $stmtR->execute([$nombre, $descripcion, $categoriaId, $porcionesBase, $preparacion]);
        $recetaId = (int) $pdo->lastInsertId();
        if (columnaExiste($pdo, 'recetas', 'porciones_prueba')) {
            $pdo->prepare('UPDATE recetas SET porciones_prueba = 17 WHERE id = ?')->execute([$recetaId]);
        }
        if (columnaExiste($pdo, 'recetas', 'icono')) {
            $pdo->prepare('UPDATE recetas SET icono = ? WHERE id = ?')->execute([iconoSugeridoReceta($nombre, $categoria), $recetaId]);
        }
        $insLinea = $pdo->prepare(
            'INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, reemplazo, al_gusto, opcional, orden)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $insAccion = $pdo->prepare('INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES (?,?)');
        $orden = 1;
        foreach ($lineas as [$catNombre, $nombreLinea, $cantidad, $unidadNombre, $costo, $acciones, $alGusto, $opcional, $reemplazo]) {
            $unidadId = idPorNombre($pdo, 'unidades_medida', 'nombre', $unidadNombre);
            if (!$unidadId) {
                throw new RuntimeException("falta la unidad \"$unidadNombre\"");
            }
            $insLinea->execute([
                $recetaId,
                recetaCarga80IdCatalogo($pdo, $catNombre),
                $nombreLinea,
                $cantidad,
                $unidadId,
                $costo,
                $reemplazo,
                $alGusto ? 1 : 0,
                $opcional ? 1 : 0,
                $orden,
            ]);
            $lineaId = (int) $pdo->lastInsertId();
            foreach ($acciones as $accNombre) {
                $accId = idPorNombre($pdo, 'acciones_ingrediente', 'nombre', $accNombre);
                if ($accId) {
                    $insAccion->execute([$lineaId, $accId]);
                }
            }
            $orden++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return "No se pudo crear la receta \"$nombre\": " . $e->getMessage();
    }
    return "Receta \"$nombre\" creada ($porcionesBase porciones reales, 17 de prueba, " . count($lineas) . ' ingredientes).';
}

/** Id del ingrediente del catálogo por nombre (null si no hay catálogo o no existe). */
function recetaCarga80IdCatalogo(PDO $pdo, ?string $nombre): ?int
{
    return $nombre === null ? null : idPorNombre($pdo, 'ingredientes_catalogo', 'nombre', $nombre);
}

/**
 * Carga de las 80 recetas de elaboración gastronómica (PDF de Eyaelkys) —
 * tanda 1 de 4: recetas 1 a 20. Cada receta se crea solo si su nombre no
 * existe todavía (idempotente), con sus ingredientes enlazados al catálogo,
 * porciones reales estimadas (porciones_base), 17 porciones de prueba
 * (las degustaciones que rinde el lote del PDF) y un ícono sugerido.
 *
 * Supuestos de esta tanda (a revisar por Eyaelkys):
 * - Las cantidades son las del PDF (lote de 17 degustaciones). Las porciones
 *   reales (porciones_base) son una estimación mía de platos completos.
 * - Ingredientes que no estaban en el catálogo se crean con precio de
 *   referencia (estimación de mercado RD, marcada en su nota de compra).
 * - Rangos: se costea con el número mayor (ej. 180–220 g de harina → 220 g)
 *   y la preparación conserva el rango.
 * - Las advertencias sanitarias de la preparación van como líneas "⚠".
 * - 1 cebolla/tomate/zanahoria/papa por unidad: peso medio asumido (cebolla 150 g, tomate 130 g, zanahoria 80 g, papa 200 g).
 * - Pulpa de maracuyá: ≈165 ml de pulpa por libra de chinola.
 * - Caldo ≈ RD$40/litro (estimación; el costo real depende de si es casero).
 * - Jugo de piña: ≈700 ml de jugo por piña entera.
 * - Garbanzos/habichuelas cocidos: costeados al 40 % de su peso en seco (rinden ≈2.5×); se compran secos.
 * - "Hongos" = champiñones.
 * - Salsa de tomate en ml/g: lata de 227 g ≈ 227 ml.
 * - 4 filetes de 180 g = 720 g de filete de res (lomito).
 */
function sembrarRecetas80Tanda1(PDO $pdo): array
{
    $mensajes = [];
    prepararCargaRecetas80($pdo);
    sembrarIngredientesNuevos80($pdo, [
        ['Pulpa de tamarindo', 'Fruta', '🌰', 'Libra', 'Libra', 1, 140.00, 'Pulpa de tamarindo. Estimación de mercado RD (≈RD$140/libra). Ajusta si tienes el precio real.'],
        ['Caldo (pollo o vegetales)', 'Enlatado y conserva', '🍲', 'Litro', 'Litro', 1, 40.00, 'Caldo preparado o hecho con cubitos/base: estimación ≈RD$40/litro. Ajusta si tienes el costo real.'],
        ['Jengibre fresco', 'Vegetal', '🫚', 'Libra', 'Libra', 1, 150.00, 'Estimación de mercado RD (≈RD$150/libra). Ajusta si tienes el precio real.'],
        ['Cebollitas pequeñas (perla)', 'Vegetal', '🧅', 'Libra', 'Libra', 1, 70.00, 'Cebollitas para encurtir. Estimación de mercado RD (≈RD$70/libra). Ajusta si tienes el precio real.'],
        ['Remolacha', 'Vegetal', '🫜', 'Libra', 'Libra', 1, 40.00, 'Estimación de mercado RD (≈RD$40/libra). Ajusta si tienes el precio real.'],
        ['Chalota', 'Vegetal', '🧅', 'Unidad', 'Unidad', 1, 15.00, 'Chalota (echalote). Estimación de mercado RD (≈RD$15 c/u). Ajusta si tienes el precio real.'],
        ['Champiñones', 'Vegetal', '🍄', 'Libra', 'Libra', 1, 140.00, 'Champiñones frescos. Estimación de mercado RD (≈RD$140/libra). Ajusta si tienes el precio real.'],
        ['Apio', 'Vegetal', '🥬', 'Rama', 'Manojo', 8, 40.00, 'Estimación de mercado RD: manojo de ≈8 ramas por RD$40.00. Ajusta si tienes el precio real.'],
        ['Arroz arborio', 'Grano y cereal', '🍚', 'Libra', 'Libra', 1, 180.00, 'Arroz para risotto. Estimación de mercado RD, sin fuente puntual verificada. Ajusta si tienes el precio real.'],
        ['Berenjena', 'Vegetal', '🍆', 'Libra', 'Libra', 1, 55.00, 'Estimación de mercado RD (≈RD$55/libra). 1 berenjena mediana ≈ 300 g. Ajusta si tienes el precio real.'],
        ['Filete de res (lomito)', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 450.00, 'Lomito/filete de res. Estimación de mercado RD (≈RD$450/libra). Ajusta si tienes el precio real.'],
    ]);
    // Peso/densidad de referencia SOLO si el catálogo no los tenía (para convertir unidades en la lista de compra).
    completarPesoDensidad80($pdo, [
        ['Apio', null, 50],
        ['Berenjena', null, 300],
        ['Calabacín', null, 227],
        ['Cebolla blanca', null, 150],
        ['Cilantro', null, 50],
        ['Levadura', null, 11],
        ['Miel de abeja', 1.42, null],
        ['Papa', null, 200],
        ['Pechuga de pollo', null, 200],
        ['Perejil', null, 50],
        ['Queso rallado', 0.4, null],
        ['Sal', 1.2, null],
        ['Tomate', null, 130],
        ['Zanahoria', null, 80],
    ]);

    // 1. Camarones en salsa de tamarindo
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Camarones en salsa de tamarindo',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Limpiar camarones y refrigerar.',
            'Sofreír ajo y cebolla.',
            'Diluir el tamarindo en agua y colar; añadir y reducir 5 minutos.',
            '⚠ Incorporar camarones y cocinar hasta que estén opacos y bien cocidos.',
        ]),
        [
            ['Camarón', 'Camarones', 500, 'Gramo', 0.62, [], false, false, null],
            ['Pulpa de tamarindo', 'Pulpa de tamarindo', 100, 'Gramo', 0.31, [], false, false, null],
            ['Agua', 'Agua', 100, 'Mililitro', 0.00, [], false, false, null],
            ['Cebolla blanca', 'Cebolla pequeña', 1, 'Unidad', 13.89, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 2. Pollo en salsa de maracuyá
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pollo en salsa de maracuyá',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sazonar y dorar el pollo.',
            'Reservar.',
            'Sofreír cebolla, agregar pulpa, caldo y miel.',
            '⚠ Regresar el pollo y cocinar hasta alcanzar 74 °C en el centro.',
            'Ajustar acidez.',
        ]),
        [
            ['Pechuga de pollo', 'Pechuga de pollo', 600, 'Gramo', 0.38, [], false, false, null],
            ['Chinola', 'Pulpa de chinola (maracuyá) colada', 150, 'Mililitro', 0.47, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 100, 'Mililitro', 0.04, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Miel de abeja', 'Miel', 15, 'Gramo', 0.55, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 3. Pescado en salsa de naranja agria
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pescado en salsa de naranja agria',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sazonar el pescado.',
            'Sofreír ajo y pimiento; agregar jugo y caldo.',
            '⚠ Colocar el pescado en la salsa y cocinar suavemente hasta que esté completamente cocido.',
        ]),
        [
            ['Filete de pescado', 'Filetes de pescado', 600, 'Gramo', 0.33, [], false, false, null],
            ['Naranja agria', 'Jugo de naranja agria', 120, 'Mililitro', 0.19, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 80, 'Mililitro', 0.04, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 4. Zanahorias encurtidas con jengibre
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Zanahorias encurtidas con jengibre',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Cortar zanahorias finas.',
            'Hervir agua, vinagre, azúcar, sal y jengibre.',
            'Verter sobre zanahorias en recipiente limpio.',
            '⚠ Enfriar rápidamente y refrigerar.',
            '⚠ Producto refrigerado, no conserva estable.',
        ]),
        [
            ['Zanahoria', 'Zanahorias', 0.4, 'Kilogramo', 68.34, [], false, false, null],
            ['Vinagre', 'Vinagre', 0.2, 'Litro', 154.93, [], false, false, null],
            ['Agua', 'Agua', 200, 'Mililitro', 0.00, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 20, 'Gramo', 0.08, [], false, false, null],
            ['Jengibre fresco', 'Jengibre fresco', 10, 'Gramo', 0.33, [], false, false, null],
            ['Sal', 'Sal', 8, 'Gramo', 0.03, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 5. Cerdo en salsa agridulce de piña
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Cerdo en salsa agridulce de piña',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cortar el cerdo y dorarlo.',
            'Saltear pimiento y piña; incorporar jugo, vinagre y azúcar.',
            '⚠ Regresar cerdo y terminar cocción hasta temperatura interna segura.',
            'Reducir salsa.',
        ]),
        [
            ['Carne de cerdo', 'Lomo de cerdo', 600, 'Gramo', 0.33, [], false, false, null],
            ['Piña', 'Piña', 0.18, 'Kilogramo', 136.36, [], false, false, null],
            ['Piña', 'Jugo de piña', 80, 'Mililitro', 0.13, [], false, false, null],
            ['Vinagre', 'Vinagre', 30, 'Mililitro', 0.15, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 25, 'Gramo', 0.08, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 6. Chutney de mango
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Chutney de mango',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        10,
        implode("\n\n", [
            'Picar mango y cebolla.',
            'Cocer todos los ingredientes a fuego suave 25–35 minutos, removiendo hasta espesar.',
            'Enfriar y refrigerar.',
            '⚠ No almacenar a temperatura ambiente.',
        ]),
        [
            ['Mango', 'Mango', 0.5, 'Kilogramo', 136.00, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Vinagre', 'Vinagre', 0.12, 'Litro', 154.93, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 90, 'Gramo', 0.08, [], false, false, null],
            ['Jengibre fresco', 'Jengibre fresco', 10, 'Gramo', 0.33, [], false, false, null],
            ['Canela en polvo', 'Canela (1 pizca)', 0, 'Cucharadita', 3.80, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 7. Cebollitas encurtidas con remolacha
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Cebollitas encurtidas con remolacha',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Cortar cebolla y remolacha.',
            'Hervir agua, vinagre, sal y azúcar.',
            'Cubrir vegetales, enfriar y refrigerar al menos 12 horas.',
            '⚠ Mantener refrigerado.',
        ]),
        [
            ['Cebollitas pequeñas (perla)', 'Cebollitas pequeñas', 0.35, 'Kilogramo', 154.32, [], false, false, null],
            ['Remolacha', 'Remolacha cocida', 0.1, 'Kilogramo', 88.18, ['Cocido'], false, false, null],
            ['Vinagre', 'Vinagre', 0.18, 'Litro', 154.93, [], false, false, null],
            ['Agua', 'Agua', 180, 'Mililitro', 0.00, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 25, 'Gramo', 0.08, [], false, false, null],
            ['Sal', 'Sal', 8, 'Gramo', 0.03, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 8. Pescado con reducción de limón
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pescado con reducción de limón',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cocinar pescado a la plancha.',
            'Reducir caldo con chalota y limón; retirar del fuego y montar con mantequilla.',
            'Servir sobre el pescado cocido.',
        ]),
        [
            ['Pescado blanco', 'Pescado blanco', 600, 'Gramo', 0.49, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 100, 'Mililitro', 0.35, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 150, 'Mililitro', 0.04, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 25, 'Gramo', 0.31, [], false, false, null],
            ['Chalota', 'Chalota', 1, 'Unidad', 15.00, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 9. Asopao de camarones
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Asopao de camarones',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        5,
        implode("\n\n", [
            'Preparar sofrito con cebolla, ají y tomate.',
            'Agregar arroz y caldo caliente; hervir suave hasta que el arroz esté tierno.',
            '⚠ Añadir camarones al final y cocer completamente.',
            'Terminar con cilantro.',
        ]),
        [
            ['Camarón', 'Camarones', 450, 'Gramo', 0.62, [], false, false, null],
            ['Arroz', 'Arroz', 250, 'Gramo', 0.10, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 1500, 'Mililitro', 0.04, [], false, false, null],
            ['Tomate', 'Tomate', 0.15, 'Kilogramo', 103.62, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Ají cubanela', 'Ají', 1, 'Unidad', 10.71, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Cilantro', 'Cilantro', 0, 'Manojo', 25.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 10. Crema de hongos
    $m = crearRecetaCarga80(
        $pdo,
        'Sopa y crema',
        'Crema de hongos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Saltear cebolla y hongos.',
            'Añadir caldo y cocer 15 minutos.',
            'Licuar con cuidado, incorporar crema, calentar sin hervir fuerte y rectificar.',
        ]),
        [
            ['Champiñones', 'Champiñones', 450, 'Gramo', 0.31, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo de vegetales', 700, 'Mililitro', 0.04, [], false, false, null],
            ['Crema de leche', 'Crema de leche', 150, 'Mililitro', 0.49, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 25, 'Gramo', 0.31, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 11. Pescado al vapor con hierbas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pescado al vapor con hierbas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sazonar pescado con ajo, limón y hierbas.',
            '⚠ Colocar en vaporera sobre agua hirviendo, tapar y cocinar hasta que se desmenuce fácilmente y esté completamente cocido.',
        ]),
        [
            ['Filete de pescado', 'Pescado', 600, 'Gramo', 0.33, [], false, false, null],
            ['Limón verde', 'Limón', 1, 'Unidad', 5.23, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Perejil', 'Perejil', 20, 'Gramo', 0.50, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 10, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 12. Ñoquis de papa
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Ñoquis de papa',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Hervir papas y hacer puré seco.',
            'Mezclar con huevo, sal y harina necesaria (180–220 g) sin amasar en exceso.',
            'Formar cilindros, cortar y hervir por tandas hasta que floten y estén cocidos.',
        ]),
        [
            ['Papa', 'Papas', 0.7, 'Kilogramo', 83.78, [], false, false, null],
            ['Harina de trigo', 'Harina de trigo (180–220 g, según la humedad de la papa)', 0.22, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Queso rallado', 'Queso rallado', 30, 'Gramo', 0.56, ['Rallado'], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 13. Sopa minestrone
    $m = crearRecetaCarga80(
        $pdo,
        'Sopa y crema',
        'Sopa minestrone',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sofreír cebolla y apio.',
            'Añadir verduras, tomate y caldo; hervir 15 minutos.',
            'Agregar pasta y habichuelas, terminar cocción y sazonar.',
        ]),
        [
            ['Zanahoria', 'Zanahoria', 0.15, 'Kilogramo', 68.34, [], false, false, null],
            ['Calabacín', 'Calabacín', 150, 'Gramo', 0.10, [], false, false, null],
            ['Apio', 'Apio', 100, 'Gramo', 0.10, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Tomate', 'Tomate', 0.2, 'Kilogramo', 103.62, [], false, false, null],
            ['Habichuelas blancas (secas)', 'Habichuelas blancas cocidas', 0.15, 'Kilogramo', 44.60, ['Cocido'], false, false, null],
            ['Pasta (coditos) Princesa', 'Pasta (coditos)', 100, 'Gramo', 0.11, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 1200, 'Mililitro', 0.04, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 14. Pechuga de pollo escalfada
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pechuga de pollo escalfada',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Calentar caldo con vegetales hasta hervor suave.',
            '⚠ Sumergir pollo, mantener cocción suave sin hervor violento y verificar 74 °C internos antes de servir.',
        ]),
        [
            ['Pechuga de pollo', 'Pechuga', 650, 'Gramo', 0.38, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 1200, 'Mililitro', 0.04, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Zanahoria', 'Zanahoria', 1, 'Unidad', 5.47, [], false, false, null],
            ['Hojas de laurel', 'Laurel', 1, 'Hoja', 0.36, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 15. Risotto de hongos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Risotto de hongos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sofreír cebolla y hongos.',
            'Añadir arroz, nacarar e incorporar caldo poco a poco removiendo 18–22 minutos.',
            'Terminar con mantequilla y queso.',
        ]),
        [
            ['Arroz arborio', 'Arroz arborio', 320, 'Gramo', 0.40, [], false, false, null],
            ['Champiñones', 'Hongos (champiñones)', 250, 'Gramo', 0.31, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo caliente', 1000, 'Mililitro', 0.04, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 35, 'Gramo', 0.31, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 60, 'Gramo', 1.63, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 16. Crema de espinacas
    $m = crearRecetaCarga80(
        $pdo,
        'Sopa y crema',
        'Crema de espinacas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sofreír cebolla, agregar papa y caldo; cocer hasta ablandar.',
            'Incorporar espinacas 3 minutos, licuar, añadir leche y calentar.',
        ]),
        [
            ['Espinaca', 'Espinacas', 350, 'Gramo', 0.24, [], false, false, null],
            ['Papa', 'Papa mediana', 1, 'Unidad', 16.76, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 700, 'Mililitro', 0.04, [], false, false, null],
            ['Leche entera', 'Leche', 0.12, 'Litro', 74.00, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 17. Lasaña de berenjena
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Lasaña de berenjena',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Asar láminas de berenjena.',
            '⚠ Cocinar carne con cebolla y salsa hasta cocción completa.',
            'Alternar capas con queso y hornear a 190 °C 25–30 minutos.',
        ]),
        [
            ['Berenjena', 'Berenjenas', 2, 'Unidad', 36.38, [], false, false, null],
            ['Carne de res molida', 'Carne molida', 350, 'Gramo', 0.40, [], false, false, null],
            ['Salsa de tomate', 'Salsa tomate', 350, 'Mililitro', 0.20, [], false, false, null],
            ['Queso mozzarella', 'Queso mozzarella', 200, 'Gramo', 0.52, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 18. Filete de res a la parrilla
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Filete de res a la parrilla',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Secar y sazonar filetes.',
            '⚠ Precalentar parrilla y cocinar por ambos lados hasta el punto deseado conforme a normas de seguridad alimentaria.',
            'Reposar y servir.',
        ]),
        [
            ['Filete de res (lomito)', 'Filete de res de 180 g', 4, 'Unidad', 178.57, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Romero (seco)', 'Romero al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 19. Focaccia con romero
    $m = crearRecetaCarga80(
        $pdo,
        'Panadería',
        'Focaccia con romero',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Mezclar harina, agua, levadura y sal; amasar o hacer pliegues.',
            'Fermentar hasta duplicar.',
            'Extender en bandeja aceitada, reposar, marcar hoyuelos, añadir aceite y romero; hornear 220 °C 20–25 minutos.',
        ]),
        [
            ['Harina de trigo', 'Harina', 0.5, 'Kilogramo', 61.73, [], false, false, null],
            ['Agua', 'Agua', 350, 'Mililitro', 0.00, [], false, false, null],
            ['Levadura', 'Levadura seca', 7, 'Gramo', 3.18, [], false, false, null],
            ['Sal', 'Sal', 10, 'Gramo', 0.03, [], false, false, null],
            ['Aceite de oliva', 'Aceite oliva', 45, 'Mililitro', 0.45, [], false, false, null],
            ['Romero (seco)', 'Romero al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 20. Pizza cuatro quesos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pizza cuatro quesos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Preparar y fermentar masa.',
            'Dividir y estirar.',
            'Distribuir quesos y hornear en horno muy caliente, 230–250 °C, hasta base dorada y queso fundido.',
        ]),
        [
            ['Harina de trigo', 'Harina', 0.5, 'Kilogramo', 61.73, [], false, false, null],
            ['Agua', 'Agua', 300, 'Mililitro', 0.00, [], false, false, null],
            ['Levadura', 'Levadura', 7, 'Gramo', 3.18, [], false, false, null],
            ['Sal', 'Sal', 10, 'Gramo', 0.03, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Queso mozzarella', 'Queso mozzarella', 100, 'Gramo', 0.52, [], false, false, null],
            ['Queso ricotta', 'Ricota', 80, 'Gramo', 0.40, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 60, 'Gramo', 1.63, [], false, false, null],
            ['Queso azul', 'Queso azul', 60, 'Gramo', 0.71, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    return $mensajes;
}

/**
 * Carga de las 80 recetas de elaboración gastronómica (PDF de Eyaelkys) —
 * tanda 2 de 4: recetas 21 a 40. Cada receta se crea solo si su nombre no
 * existe todavía (idempotente), con sus ingredientes enlazados al catálogo,
 * porciones reales estimadas (porciones_base), 17 porciones de prueba
 * (las degustaciones que rinde el lote del PDF) y un ícono sugerido.
 *
 * Supuestos de esta tanda (a revisar por Eyaelkys):
 * - Las cantidades son las del PDF (lote de 17 degustaciones). Las porciones
 *   reales (porciones_base) son una estimación mía de platos completos.
 * - Ingredientes que no estaban en el catálogo se crean con precio de
 *   referencia (estimación de mercado RD, marcada en su nota de compra).
 * - Rangos: se costea con el número mayor (ej. 180–220 g de harina → 220 g)
 *   y la preparación conserva el rango.
 * - Las advertencias sanitarias de la preparación van como líneas "⚠".
 * - "Queso" sin tipo: se costeó como mozzarella.
 * - 1 cebolla/tomate/zanahoria/papa por unidad: peso medio asumido (cebolla 150 g, tomate 130 g, zanahoria 80 g, papa 200 g).
 * - Aceite sin cantidad (freír/sofreír): "al gusto", no entra al costo; ajusta si quieres costearlo.
 * - Línea sin catálogo: preparación intermedia costeada con el costo por kilo de la receta que la produce.
 * - Caldo ≈ RD$40/litro (estimación; el costo real depende de si es casero).
 * - Salsa de tomate en ml/g: lata de 227 g ≈ 227 ml.
 * - Bacalao desalado (500 g): costeado al 70 % del precio del bacalao salado (el desalado pesa más que el seco).
 * - Garbanzos/habichuelas cocidos: costeados al 40 % de su peso en seco (rinden ≈2.5×); se compran secos.
 */
function sembrarRecetas80Tanda2(PDO $pdo): array
{
    $mensajes = [];
    prepararCargaRecetas80($pdo);
    sembrarIngredientesNuevos80($pdo, [
        ['Solomillo de cerdo', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 230.00, 'Solomillo/lomito de cerdo. Estimación de mercado RD (≈RD$230/libra). Ajusta si tienes el precio real.'],
        ['Carne de res para guisar y brochetas', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 230.00, 'Res en cubos. Estimación de mercado RD (≈RD$230/libra). Ajusta si tienes el precio real.'],
        ['Baguette', 'Panadería', '🥖', 'Unidad', 'Unidad', 1, 45.00, 'Estimación de mercado RD, baguette de panadería. Ajusta si tienes el precio real.'],
        ['Berenjena', 'Vegetal', '🍆', 'Libra', 'Libra', 1, 55.00, 'Estimación de mercado RD (≈RD$55/libra). 1 berenjena mediana ≈ 300 g. Ajusta si tienes el precio real.'],
        ['Maicena', 'Repostería', '🌽', 'Gramo', 'Paquete', 400, 95.00, 'Fécula de maíz, paquete de 400 g por ≈RD$95. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Jarrete de res', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 200.00, 'Jarrete en rodajas (ossobuco). Estimación de mercado RD (≈RD$200/libra). Ajusta si tienes el precio real.'],
        ['Apio', 'Vegetal', '🥬', 'Rama', 'Manojo', 8, 40.00, 'Estimación de mercado RD: manojo de ≈8 ramas por RD$40.00. Ajusta si tienes el precio real.'],
        ['Caldo (pollo o vegetales)', 'Enlatado y conserva', '🍲', 'Litro', 'Litro', 1, 40.00, 'Caldo preparado o hecho con cubitos/base: estimación ≈RD$40/litro. Ajusta si tienes el costo real.'],
        ['Arroz bomba', 'Grano y cereal', '🍚', 'Libra', 'Libra', 1, 160.00, 'Arroz para paella. Estimación de mercado RD, sin fuente puntual verificada. Ajusta si tienes el precio real.'],
        ['Azafrán', 'Condimento y especia', '🌼', 'Gramo', 'Paquete', 0.5, 150.00, 'Estimación de mercado RD: sobre de 0.5 g por RD$150.00 (≈RD$300/g). Ajusta si tienes el precio real.'],
        ['Ciruelas pasas', 'Fruta', '🍑', 'Libra', 'Libra', 1, 250.00, 'Estimación de mercado RD (≈RD$250/libra). Ajusta si tienes el precio real.'],
        ['Cordero (pierna o paleta)', 'Cárnico', '🐑', 'Libra', 'Libra', 1, 380.00, 'Estimación de mercado RD (≈RD$380/libra). Ajusta si tienes el precio real.'],
        ['Rabo de res', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 210.00, 'Estimación de mercado RD (≈RD$210/libra). Ajusta si tienes el precio real.'],
    ]);
    // Peso/densidad de referencia SOLO si el catálogo no los tenía (para convertir unidades en la lista de compra).
    completarPesoDensidad80($pdo, [
        ['Apio', null, 50],
        ['Berenjena', null, 300],
        ['Calabacín', null, 227],
        ['Cebolla blanca', null, 150],
        ['Pan rallado', 0.45, null],
        ['Papa', null, 200],
        ['Perejil', null, 50],
        ['Queso rallado', 0.4, null],
        ['Sal', 1.2, null],
        ['Tomate', null, 130],
        ['Zanahoria', null, 80],
    ]);

    // 21. Solomillo de cerdo al horno
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Solomillo de cerdo al horno',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Marinar con aceite, ajo, mostaza y tomillo.',
            '⚠ Sellar en sartén y terminar en horno a 190 °C hasta cocción interna segura.',
            'Reposar antes de cortar.',
        ]),
        [
            ['Solomillo de cerdo', 'Solomillo de cerdo', 700, 'Gramo', 0.51, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Mostaza', 'Mostaza', 1, 'Cucharadita', 1.11, [], false, false, null],
            ['Tomillo (seco)', 'Tomillo al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 22. Papas gratinadas
    $m = crearRecetaCarga80(
        $pdo,
        'Guarnición',
        'Papas gratinadas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Cortar papas finas.',
            'Frotar fuente con ajo; alternar papas, condimentos y queso.',
            'Cubrir con leche y crema; hornear 180 °C 50–65 minutos hasta tiernas.',
        ]),
        [
            ['Papa', 'Papas', 0.8, 'Kilogramo', 83.78, [], false, false, null],
            ['Crema de leche', 'Crema de leche', 300, 'Mililitro', 0.49, [], false, false, null],
            ['Leche entera', 'Leche', 0.15, 'Litro', 74.00, [], false, false, null],
            ['Queso mozzarella', 'Queso (mozzarella)', 120, 'Gramo', 0.52, [], false, false, null],
            ['Ajo', 'Ajo', 1, 'Diente', 14.50, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Nuez moscada molida', 'Nuez moscada al gusto', 0, 'Cucharadita', 6.56, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 23. Brochetas de res a la parrilla
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Brochetas de res a la parrilla',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Marinar res brevemente en refrigeración.',
            'Ensartar alternando vegetales.',
            'Cocinar a la parrilla girando hasta cocción adecuada.',
            '⚠ Evitar contaminación cruzada.',
        ]),
        [
            ['Carne de res para guisar y brochetas', 'Carne de res en cubos', 600, 'Gramo', 0.51, ['Cortado en cubos'], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 15, 'Mililitro', 0.35, [], false, false, null],
            ['Ajo', 'Ajo', 0, 'Diente', 14.50, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 24. Pan de ajo horneado
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Pan de ajo horneado',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Mezclar mantequilla blanda con ajo y perejil.',
            'Untar pan rebanado, espolvorear queso y hornear 190 °C 10–12 minutos.',
        ]),
        [
            ['Baguette', 'Baguette', 1, 'Unidad', 45.00, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 100, 'Gramo', 0.31, [], false, false, null],
            ['Ajo', 'Ajo', 3, 'Diente', 14.50, [], false, false, null],
            ['Perejil', 'Perejil', 15, 'Gramo', 0.50, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 40, 'Gramo', 1.63, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 25. Camarones empanizados
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Camarones empanizados',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Secar y sazonar camarones.',
            'Pasar por harina, huevo y pan.',
            'Freír a 175 °C por tandas hasta cocidos y dorados; escurrir.',
        ]),
        [
            ['Camarón', 'Camarones', 500, 'Gramo', 0.62, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.1, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevos', 2, 'Unidad', 6.50, [], false, false, null],
            ['Pan rallado', 'Pan rallado', 150, 'Gramo', 0.20, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Aceite vegetal', 'Aceite para freír (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 26. Falafel de garbanzos
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Falafel de garbanzos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Escurrir garbanzos crudos remojados y procesar con condimentos.',
            'Añadir harina, refrigerar mezcla y formar bolitas.',
            'Freír a 170–175 °C hasta bien cocidas.',
        ]),
        [
            ['Garbanzos', 'Garbanzos secos (remojados 12 h)', 0.35, 'Kilogramo', 154.32, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Ajo', 'Ajo', 3, 'Diente', 14.50, [], false, false, null],
            ['Perejil', 'Perejil', 0, 'Manojo', 25.00, [], true, false, null],
            ['Harina de trigo', 'Harina', 30, 'Gramo', 0.06, [], false, false, null],
            ['Comino', 'Comino al gusto', 0, 'Cucharadita', 3.09, [], true, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 27. Arepitas de maíz
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Arepitas de maíz',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Mezclar harina con agua, sal y queso; reposar 10 minutos.',
            'Formar discos pequeños y freír hasta dorados y cocidos por dentro.',
        ]),
        [
            ['Harina de maíz', 'Harina de maíz precocida', 0.25, 'Kilogramo', 45.35, [], false, false, null],
            ['Agua', 'Agua', 320, 'Mililitro', 0.00, [], false, false, null],
            ['Queso rallado', 'Queso rallado', 80, 'Gramo', 0.56, ['Rallado'], false, false, null],
            ['Sal', 'Sal', 8, 'Gramo', 0.03, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 28. Calamares a la romana
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Calamares a la romana',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Secar calamares.',
            'Preparar batido ligero de harina, huevo y agua fría.',
            'Rebozar y freír en aceite a 175 °C por tandas hasta dorados y cocidos.',
        ]),
        [
            ['Calamar (anillas)', 'Aros de calamar', 500, 'Gramo', 0.44, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.15, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Agua', 'Agua fría', 180, 'Mililitro', 0.00, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 29. Berenjenas rebozadas
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Berenjenas rebozadas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cortar berenjena en rodajas, salar ligeramente y secar.',
            'Empanar con harina, huevo y pan; freír hasta doradas y tiernas.',
        ]),
        [
            ['Berenjena', 'Berenjenas', 2, 'Unidad', 36.38, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.12, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevos', 2, 'Unidad', 6.50, [], false, false, null],
            ['Pan rallado', 'Pan rallado', 100, 'Gramo', 0.20, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 30. Arancini de queso
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Arancini de queso',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Mantener risotto frío.',
            'Formar bolas rellenas de mozzarella, empanar y freír a 175 °C hasta doradas y calientes en el centro.',
        ]),
        [
            [null, 'Risotto frío del día anterior (receta Risotto de hongos)', 0.5, 'Kilogramo', 284.84, ['Frío'], false, false, null],
            ['Queso mozzarella', 'Queso mozzarella', 120, 'Gramo', 0.52, [], false, false, null],
            ['Harina de trigo', 'Harina', 80, 'Gramo', 0.06, [], false, false, null],
            ['Huevo', 'Huevos', 2, 'Unidad', 6.50, [], false, false, null],
            ['Pan rallado', 'Pan rallado', 120, 'Gramo', 0.20, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 31. Bolitas de plátano maduro
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Bolitas de plátano maduro',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Hervir plátanos hasta tiernos, escurrir y hacer puré.',
            'Agregar fécula, formar bolas con queso dentro y freír hasta doradas.',
        ]),
        [
            ['Plátano maduro', 'Plátanos maduros', 3, 'Unidad', 20.00, [], false, false, null],
            ['Queso mozzarella', 'Queso (mozzarella)', 120, 'Gramo', 0.52, [], false, false, null],
            ['Maicena', 'Fécula de maíz (maicena)', 40, 'Gramo', 0.24, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 32. Tempura de vegetales
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Tempura de vegetales',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cortar vegetales finos.',
            'Mezclar harina, huevo y agua helada sin batir en exceso.',
            'Sumergir y freír a 175 °C en tandas hasta crujientes.',
        ]),
        [
            ['Zanahoria', 'Zanahoria', 1, 'Unidad', 5.47, [], false, false, null],
            ['Calabacín', 'Calabacín', 1, 'Unidad', 22.50, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.16, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Agua', 'Agua helada', 220, 'Mililitro', 0.00, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 33. Ossobuco de res
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Ossobuco de res',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Enharinar y sellar jarrete.',
            'Sofreír vegetales, agregar tomate y caldo.',
            'Tapar y brasear a fuego bajo 2–3 horas hasta tierno.',
        ]),
        [
            ['Jarrete de res', 'Jarrete de res (rodajas)', 4, 'Rodaja', 121.25, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Zanahoria', 'Zanahoria', 1, 'Unidad', 5.47, [], false, false, null],
            ['Apio', 'Apio', 1, 'Rama', 5.00, [], false, false, null],
            ['Tomate', 'Tomate', 0.3, 'Kilogramo', 103.62, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 500, 'Mililitro', 0.04, [], false, false, null],
            ['Harina de trigo', 'Harina', 30, 'Gramo', 0.06, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 34. Paella mixta
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Paella mixta',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Dorar pollo y cocinarlo.',
            'Sofreír pimiento y tomate; agregar arroz, caldo y azafrán.',
            'Cocer sin remover excesivamente.',
            '⚠ Incorporar mariscos al final hasta cocción segura.',
        ]),
        [
            ['Arroz bomba', 'Arroz bomba', 350, 'Gramo', 0.35, [], false, false, null],
            ['Pollo entero', 'Pollo', 300, 'Gramo', 0.21, [], false, false, null],
            ['Camarón', 'Camarones', 200, 'Gramo', 0.62, [], false, false, null],
            ['Mejillones (carne)', 'Mejillones limpios', 200, 'Gramo', 0.50, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Tomate', 'Tomate', 1, 'Unidad', 13.47, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 1000, 'Mililitro', 0.04, [], false, false, null],
            ['Azafrán', 'Azafrán al gusto', 0, 'Gramo', 300.00, [], true, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 35. Lomo de cerdo en salsa de ciruelas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Lomo de cerdo en salsa de ciruelas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Sellar lomo y reservar.',
            'Sofreír cebolla, añadir ciruelas, caldo y vinagre.',
            '⚠ Regresar carne, tapar y cocinar suavemente hasta temperatura segura.',
            'Licuar salsa si se desea.',
        ]),
        [
            ['Carne de cerdo', 'Lomo de cerdo', 700, 'Gramo', 0.33, [], false, false, null],
            ['Ciruelas pasas', 'Ciruelas pasas', 180, 'Gramo', 0.55, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 300, 'Mililitro', 0.04, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Vinagre', 'Vinagre', 20, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 36. Pastelón de berenjena y carne
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pastelón de berenjena y carne',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Asar berenjenas en láminas.',
            'Cocinar carne con cebolla y tomate completamente.',
            'Montar capas con queso y gratinar a 190 °C 20–25 minutos.',
        ]),
        [
            ['Berenjena', 'Berenjenas', 3, 'Unidad', 36.38, [], false, false, null],
            ['Carne de res molida', 'Carne molida', 400, 'Gramo', 0.40, [], false, false, null],
            ['Salsa de tomate', 'Salsa tomate', 250, 'Gramo', 0.20, [], false, false, null],
            ['Queso mozzarella', 'Queso (mozzarella)', 200, 'Gramo', 0.52, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 37. Cordero estofado con romero
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Cordero estofado con romero',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Dorar cordero.',
            'Sofreír vegetales, incorporar tomate, romero y caldo.',
            'Tapar y cocinar lentamente 1.5–2 horas hasta tierno.',
        ]),
        [
            ['Cordero (pierna o paleta)', 'Cordero', 750, 'Gramo', 0.84, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Zanahoria', 'Zanahorias', 2, 'Unidad', 5.47, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 400, 'Mililitro', 0.04, [], false, false, null],
            ['Tomate', 'Tomate', 0.15, 'Kilogramo', 103.62, [], false, false, null],
            ['Romero (seco)', 'Romero al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 38. Bacalao con garbanzos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Bacalao con garbanzos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Desalar bacalao en refrigeración con cambios de agua.',
            'Preparar sofrito, añadir tomate y garbanzos.',
            '⚠ Incorporar bacalao y guisar hasta cocción completa.',
        ]),
        [
            ['Bacalao (filete salado)', 'Bacalao desalado', 500, 'Gramo', 0.40, [], false, false, null],
            ['Garbanzos', 'Garbanzos cocidos', 0.35, 'Kilogramo', 61.73, ['Cocido'], false, false, null],
            ['Tomate', 'Tomate', 0.25, 'Kilogramo', 103.62, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Pimiento', 'Pimiento', 1, 'Unidad', 18.33, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 39. Rabo de res estofado
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Rabo de res estofado',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Dorar rabo.',
            'Sofreír vegetales, añadir tomate y caldo.',
            'Cocinar tapado 3–4 horas a fuego lento hasta que la carne esté tierna.',
        ]),
        [
            ['Rabo de res', 'Rabo de res', 900, 'Gramo', 0.46, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Zanahoria', 'Zanahoria', 1, 'Unidad', 5.47, [], false, false, null],
            ['Tomate', 'Tomate', 0.25, 'Kilogramo', 103.62, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 700, 'Mililitro', 0.04, [], false, false, null],
            ['Tomillo (seco)', 'Tomillo al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 40. Locrio de mariscos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Locrio de mariscos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        5,
        implode("\n\n", [
            'Sofreír vegetales y tomate; agregar arroz y caldo.',
            'Cocer casi completamente, añadir mariscos y terminar hasta que estén cocidos y el arroz tierno.',
        ]),
        [
            ['Arroz', 'Arroz', 350, 'Gramo', 0.10, [], false, false, null],
            ['Camarón', 'Camarones', 250, 'Gramo', 0.62, [], false, false, null],
            ['Calamar (anillas)', 'Calamares', 250, 'Gramo', 0.44, [], false, false, null],
            ['Mejillones (carne)', 'Mejillones limpios', 200, 'Gramo', 0.50, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Ají cubanela', 'Ají', 1, 'Unidad', 10.71, [], false, false, null],
            ['Tomate', 'Tomate', 0.15, 'Kilogramo', 103.62, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 750, 'Mililitro', 0.04, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    return $mensajes;
}

/**
 * Carga de las 80 recetas de elaboración gastronómica (PDF de Eyaelkys) —
 * tanda 3 de 4: recetas 41 a 60. Cada receta se crea solo si su nombre no
 * existe todavía (idempotente), con sus ingredientes enlazados al catálogo,
 * porciones reales estimadas (porciones_base), 17 porciones de prueba
 * (las degustaciones que rinde el lote del PDF) y un ícono sugerido.
 *
 * Supuestos de esta tanda (a revisar por Eyaelkys):
 * - Las cantidades son las del PDF (lote de 17 degustaciones). Las porciones
 *   reales (porciones_base) son una estimación mía de platos completos.
 * - Ingredientes que no estaban en el catálogo se crean con precio de
 *   referencia (estimación de mercado RD, marcada en su nota de compra).
 * - Rangos: se costea con el número mayor (ej. 180–220 g de harina → 220 g)
 *   y la preparación conserva el rango.
 * - Las advertencias sanitarias de la preparación van como líneas "⚠".
 * - Café preparado: 1 cucharada de café molido por cada ≈100 ml.
 * - Leche condensada/evaporada en ml: lata de 395 g ≈ 304 ml; lata evaporada de 354 ml.
 * - Línea sin catálogo: preparación intermedia costeada con el costo por kilo de la receta que la produce.
 * - Garbanzos/habichuelas cocidos: costeados al 40 % de su peso en seco (rinden ≈2.5×); se compran secos.
 * - 1 cebolla/tomate/zanahoria/papa por unidad: peso medio asumido (cebolla 150 g, tomate 130 g, zanahoria 80 g, papa 200 g).
 * - "Queso" sin tipo: se costeó como mozzarella.
 */
function sembrarRecetas80Tanda3(PDO $pdo): array
{
    $mensajes = [];
    prepararCargaRecetas80($pdo);
    sembrarIngredientesNuevos80($pdo, [
        ['Bizcochos de soletilla', 'Repostería', '🍪', 'Gramo', 'Paquete', 200, 220.00, 'Estimación de mercado RD: paquete de 200 g por RD$220.00. Ajusta si tienes el precio real.'],
        ['Frutos rojos (congelados)', 'Fruta', '🍓', 'Libra', 'Libra', 1, 450.00, 'Mezcla de frutos rojos congelados. Estimación de mercado RD (≈RD$450/libra). Ajusta si tienes el precio real.'],
        ['Maicena', 'Repostería', '🌽', 'Gramo', 'Paquete', 400, 95.00, 'Fécula de maíz, paquete de 400 g por ≈RD$95. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Clara de huevo pasteurizada', 'Lácteo y huevo', '🥚', 'Unidad', 'Paquete', 13, 250.00, 'Claras líquidas pasteurizadas: estimación RD$250 por envase de ≈13 claras. Ajusta si tienes el precio real.'],
        ['Fruta variada (de temporada)', 'Fruta', '🍓', 'Libra', 'Libra', 1, 80.00, 'Mezcla de frutas de temporada. Estimación RD (≈RD$80/libra). Ajusta según las frutas que uses.'],
        ['Albahaca fresca', 'Vegetal', '🌿', 'Gramo', 'Paquete', 30, 45.00, 'Estimación de mercado RD: manojo/paquete de ≈30 g por RD$45.00. Ajusta si tienes el precio real.'],
        ['Guayaba', 'Fruta', '🍈', 'Libra', 'Libra', 1, 60.00, 'Estimación de mercado RD (≈RD$60/libra). Ajusta si tienes el precio real.'],
        ['Tahini', 'Condimento y especia', '🥜', 'Gramo', 'Unidad', 454, 650.00, 'Pasta de ajonjolí, frasco de 454 g por ≈RD$650. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Apio', 'Vegetal', '🥬', 'Rama', 'Manojo', 8, 40.00, 'Estimación de mercado RD: manojo de ≈8 ramas por RD$40.00. Ajusta si tienes el precio real.'],
        ['Remolacha', 'Vegetal', '🫜', 'Libra', 'Libra', 1, 40.00, 'Estimación de mercado RD (≈RD$40/libra). Ajusta si tienes el precio real.'],
        ['Queso de cabra', 'Lácteo y huevo', '🧀', 'Libra', 'Libra', 1, 900.00, 'Estimación de mercado RD (≈RD$900/libra). Ajusta si tienes el precio real.'],
        ['Vinagre balsámico', 'Condimento y especia', '🍶', 'Mililitro', 'Unidad', 250, 280.00, 'Botella de 250 ml por ≈RD$280. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Champiñones', 'Vegetal', '🍄', 'Libra', 'Libra', 1, 140.00, 'Champiñones frescos. Estimación de mercado RD (≈RD$140/libra). Ajusta si tienes el precio real.'],
    ]);
    // Peso/densidad de referencia SOLO si el catálogo no los tenía (para convertir unidades en la lista de compra).
    completarPesoDensidad80($pdo, [
        ['Ají morrón rojo', null, 200],
        ['Apio', null, 50],
        ['Cebolla blanca', null, 150],
        ['Cilantro', null, 50],
        ['Manzana', null, 180],
        ['Perejil', null, 50],
        ['Puerro', null, 200],
        ['Sal', 1.2, null],
        ['Tomate', null, 130],
        ['Zanahoria', null, 80],
    ]);

    // 41. Tiramisú
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Tiramisú',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Batir crema con azúcar e incorporar mascarpone.',
            'Remojar brevemente soletillas en café; alternar capas con crema.',
            'Refrigerar 4 horas y espolvorear cacao.',
            '⚠ Versión sin huevo crudo.',
        ]),
        [
            ['Queso mascarpone', 'Queso mascarpone', 250, 'Gramo', 0.77, [], false, false, null],
            ['Crema para batir', 'Crema para batir', 200, 'Mililitro', 0.26, ['Batido'], false, false, null],
            ['Azúcar blanca', 'Azúcar', 60, 'Gramo', 0.08, [], false, false, null],
            ['Bizcochos de soletilla', 'Bizcochos de soletilla', 180, 'Gramo', 1.10, [], false, false, null],
            ['Café molido', 'Café frío', 0.25, 'Litro', 42.31, ['Frío'], false, false, null],
            ['Cocoa en polvo', 'Cacao al gusto', 0, 'Cucharada', 1.49, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 42. Panna cotta de frutos rojos
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Panna cotta de frutos rojos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Hidratar gelatina en agua.',
            'Calentar crema, leche y azúcar sin hervir; disolver gelatina.',
            'Verter en moldes y refrigerar 4 horas.',
            'Cocer frutos rojos para salsa, enfriar y servir.',
        ]),
        [
            ['Crema para batir', 'Crema para batir', 500, 'Mililitro', 0.26, [], false, false, null],
            ['Leche entera', 'Leche', 0.15, 'Litro', 74.00, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 80, 'Gramo', 0.08, [], false, false, null],
            ['Gelatina sin sabor', 'Gelatina sin sabor', 10, 'Gramo', 3.33, [], false, false, null],
            ['Agua', 'Agua', 50, 'Mililitro', 0.00, [], false, false, null],
            ['Frutos rojos (congelados)', 'Frutos rojos', 180, 'Gramo', 0.99, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 43. Profiteroles con crema pastelera
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Profiteroles con crema pastelera',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Hervir agua, leche y mantequilla; agregar harina y secar masa.',
            'Enfriar un poco, añadir huevos gradualmente; formar y hornear 200 °C 25–30 minutos.',
            'Preparar crema pastelera cocida con leche, yemas, azúcar y maicena; enfriar y rellenar.',
        ]),
        [
            ['Agua', 'Agua', 125, 'Mililitro', 0.00, [], false, false, null],
            ['Leche entera', 'Leche', 125, 'Mililitro', 0.07, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 100, 'Gramo', 0.31, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.15, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevos', 4, 'Unidad', 6.50, [], false, false, null],
            ['Leche entera', 'Leche (crema pastelera)', 0.4, 'Litro', 74.00, [], false, false, null],
            ['Huevo', 'Yemas de huevo (crema pastelera)', 3, 'Unidad', 6.50, [], false, false, null],
            ['Azúcar blanca', 'Azúcar (crema pastelera)', 80, 'Gramo', 0.08, [], false, false, null],
            ['Maicena', 'Maicena (crema pastelera)', 30, 'Gramo', 0.24, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 44. Tarta de manzana
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Tarta de manzana',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Arenar harina con mantequilla y azúcar; unir con huevo y refrigerar.',
            'Forrar molde, colocar manzanas laminadas con azúcar y canela; hornear 180 °C 35–45 minutos.',
        ]),
        [
            ['Harina de trigo', 'Harina', 0.25, 'Kilogramo', 61.73, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 125, 'Gramo', 0.31, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 80, 'Gramo', 0.08, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Manzana', 'Manzanas', 4, 'Unidad', 40.00, [], false, false, null],
            ['Azúcar blanca', 'Azúcar (para las manzanas)', 30, 'Gramo', 0.08, [], false, false, null],
            ['Canela en polvo', 'Canela al gusto', 0, 'Cucharadita', 3.80, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 45. Tres leches de café
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Tres leches de café',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        10,
        implode("\n\n", [
            'Batir huevos con azúcar a punto cinta; incorporar harina y polvo.',
            'Hornear 180 °C 25–30 minutos.',
            'Mezclar tres leches y café, perforar bizcocho frío y remojar; refrigerar.',
        ]),
        [
            ['Huevo', 'Huevos', 4, 'Unidad', 6.50, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 0.12, 'Kilogramo', 77.16, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.12, 'Kilogramo', 61.73, [], false, false, null],
            ['Polvo de hornear', 'Polvo hornear', 1, 'Cucharadita', 2.50, [], false, false, null],
            ['Leche evaporada', 'Leche evaporada', 200, 'Mililitro', 0.20, [], false, false, null],
            ['Leche condensada', 'Leche condensada', 200, 'Mililitro', 0.36, [], false, false, null],
            ['Crema de leche', 'Crema de leche', 200, 'Mililitro', 0.49, [], false, false, null],
            ['Café molido', 'Café', 80, 'Mililitro', 0.04, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 46. Éclairs de chocolate
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Éclairs de chocolate',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        10,
        implode("\n\n", [
            'Preparar masa choux hirviendo líquidos y mantequilla; añadir harina y secar; integrar huevos.',
            'Escudillar bastones y hornear 200 °C 25–30 minutos.',
            'Enfriar, rellenar con crema pastelera segura y cubrir con chocolate fundido.',
        ]),
        [
            ['Agua', 'Agua', 125, 'Mililitro', 0.00, [], false, false, null],
            ['Leche entera', 'Leche', 125, 'Mililitro', 0.07, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 100, 'Gramo', 0.31, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.15, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevos', 4, 'Unidad', 6.50, [], false, false, null],
            [null, 'Crema pastelera fría (receta de Profiteroles)', 0.4, 'Kilogramo', 125.40, ['Frío'], false, false, null],
            ['Chocolate oscuro para hornear', 'Chocolate', 150, 'Gramo', 1.41, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 47. Pavlova con frutas
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Pavlova con frutas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Batir claras e incorporar azúcar poco a poco; añadir maicena y vinagre.',
            'Formar disco y secar en horno a 110 °C 75–90 minutos.',
            'Enfriar, cubrir con crema y fruta justo antes de servir.',
        ]),
        [
            ['Clara de huevo pasteurizada', 'Claras pasteurizadas', 4, 'Unidad', 19.23, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 0.22, 'Kilogramo', 77.16, [], false, false, null],
            ['Maicena', 'Maicena', 10, 'Gramo', 0.24, [], false, false, null],
            ['Vinagre', 'Vinagre', 5, 'Mililitro', 0.15, [], false, false, null],
            ['Crema para batir', 'Crema para batir (batida)', 200, 'Mililitro', 0.26, ['Batido'], false, false, null],
            ['Fruta variada (de temporada)', 'Frutas', 0.25, 'Kilogramo', 176.37, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 48. Crème brûlée
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Crème brûlée',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        5,
        implode("\n\n", [
            'Calentar crema con vainilla.',
            'Mezclar yemas y azúcar, temperar con crema y colar.',
            'Hornear en baño María a 150 °C hasta cuajar; enfriar y refrigerar.',
            'Espolvorear azúcar y caramelizar antes de servir.',
        ]),
        [
            ['Crema para batir', 'Crema para batir', 500, 'Mililitro', 0.26, [], false, false, null],
            ['Huevo', 'Yemas de huevo', 5, 'Unidad', 6.50, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 90, 'Gramo', 0.08, [], false, false, null],
            ['Vainilla blanca', 'Vainilla al gusto', 0, 'Cucharadita', 0.55, [], true, false, null],
            ['Azúcar blanca', 'Azúcar (para la costra)', 50, 'Gramo', 0.08, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 49. Pesto de albahaca
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Pesto de albahaca',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Lavar y secar albahaca.',
            'Procesar con queso, nueces y ajo; emulsionar con aceite.',
            '⚠ Envasar limpio, fechar y refrigerar de inmediato.',
            'Consumir pronto o congelar.',
        ]),
        [
            ['Albahaca fresca', 'Albahaca', 70, 'Gramo', 1.50, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 50, 'Gramo', 1.63, [], false, false, null],
            ['Nueces', 'Nueces', 40, 'Gramo', 0.84, [], false, false, null],
            ['Ajo', 'Ajo', 1, 'Diente', 14.50, [], false, false, null],
            ['Aceite de oliva', 'Aceite oliva', 120, 'Mililitro', 0.45, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 50. Mermelada de guayaba
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Mermelada de guayaba',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        10,
        implode("\n\n", [
            'Cocer guayaba con agua, licuar y colar semillas.',
            'Añadir azúcar y limón, cocinar hasta consistencia de mermelada.',
            'Enfriar, envasar y refrigerar.',
            '⚠ No es receta validada para conserva de despensa.',
        ]),
        [
            ['Guayaba', 'Guayaba', 700, 'Gramo', 0.13, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 0.35, 'Kilogramo', 77.16, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 40, 'Mililitro', 0.35, [], false, false, null],
            ['Agua', 'Agua', 150, 'Mililitro', 0.00, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 51. Compota de manzana
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Compota de manzana',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Pelar y cortar manzanas.',
            'Cocer con agua, azúcar y canela hasta ablandar.',
            '⚠ Triturar, añadir limón, enfriar rápidamente, envasar y refrigerar.',
        ]),
        [
            ['Manzana', 'Manzana', 700, 'Gramo', 0.22, [], false, false, null],
            ['Agua', 'Agua', 120, 'Mililitro', 0.00, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 25, 'Gramo', 0.08, [], false, false, null],
            ['Canela en polvo', 'Canela al gusto', 0, 'Cucharadita', 3.80, [], true, false, null],
            ['Limón verde', 'Jugo de limón', 10, 'Mililitro', 0.35, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 52. Salsa bechamel
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Salsa bechamel',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Fundir mantequilla, agregar harina y cocer 2 minutos.',
            'Incorporar leche gradualmente batiendo; hervir suave hasta espesar.',
            '⚠ Enfriar rápidamente en recipientes poco profundos y refrigerar.',
        ]),
        [
            ['Leche entera', 'Leche', 0.5, 'Litro', 74.00, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 40, 'Gramo', 0.31, [], false, false, null],
            ['Harina de trigo', 'Harina', 40, 'Gramo', 0.06, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Nuez moscada molida', 'Nuez moscada al gusto', 0, 'Cucharadita', 6.56, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 53. Hummus de garbanzos
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Hummus de garbanzos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Procesar garbanzos con tahini, limón, ajo y agua hasta textura cremosa.',
            'Agregar aceite, ajustar sazón.',
            '⚠ Porcionar, fechar y refrigerar.',
        ]),
        [
            ['Garbanzos', 'Garbanzos cocidos', 0.4, 'Kilogramo', 61.73, ['Cocido'], false, false, null],
            ['Tahini', 'Tahini', 60, 'Gramo', 1.43, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 50, 'Mililitro', 0.35, [], false, false, null],
            ['Ajo', 'Ajo', 1, 'Diente', 14.50, [], false, false, null],
            ['Agua', 'Agua fría', 50, 'Mililitro', 0.00, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 25, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 54. Salsa de pimientos asados
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Salsa de pimientos asados',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Asar pimientos hasta piel tostada; cubrir, pelar y retirar semillas.',
            'Procesar con ajo, aceite y limón.',
            'Enfriar, envasar y refrigerar.',
        ]),
        [
            ['Ají morrón rojo', 'Pimientos rojos', 3, 'Unidad', 32.63, [], false, false, null],
            ['Ajo', 'Ajo', 1, 'Diente', 14.50, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 30, 'Mililitro', 0.15, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 15, 'Mililitro', 0.35, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 55. Puré de batata
    $m = crearRecetaCarga80(
        $pdo,
        'Guarnición',
        'Puré de batata',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        5,
        implode("\n\n", [
            'Hervir batata pelada hasta tierna.',
            'Escurrir, triturar con mantequilla y leche caliente.',
            '⚠ Porcionar en recipientes poco profundos, enfriar rápidamente y refrigerar.',
        ]),
        [
            ['Batata', 'Batata', 0.7, 'Kilogramo', 77.16, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 60, 'Gramo', 0.31, [], false, false, null],
            ['Leche entera', 'Leche', 0.1, 'Litro', 74.00, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Nuez moscada molida', 'Nuez moscada al gusto', 0, 'Cucharadita', 6.56, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 56. Caldo concentrado de vegetales
    $m = crearRecetaCarga80(
        $pdo,
        'Salsa y conserva',
        'Caldo concentrado de vegetales',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Lavar y cortar vegetales.',
            'Cocer a fuego suave 60 minutos, colar y reducir si se desea.',
            '⚠ Enfriar rápidamente, fechar y refrigerar o congelar.',
        ]),
        [
            ['Cebolla blanca', 'Cebollas', 2, 'Unidad', 13.89, [], false, false, null],
            ['Zanahoria', 'Zanahorias', 2, 'Unidad', 5.47, [], false, false, null],
            ['Apio', 'Apio (ramas)', 2, 'Rama', 5.00, [], false, false, null],
            ['Puerro', 'Puerro', 1, 'Unidad', 19.84, [], false, false, null],
            ['Agua', 'Agua', 2, 'Litro', 0.00, [], false, false, null],
            ['Hojas de laurel', 'Laurel al gusto', 0, 'Hoja', 0.36, [], true, false, null],
            ['Perejil', 'Perejil', 0, 'Manojo', 25.00, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 57. Tartar de tomate y aguacate
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Tartar de tomate y aguacate',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            '⚠ Desinfectar vegetales.',
            'Cortar tomate y aguacate en cubos, mezclar con cebolla, limón y aceite.',
            'Montar con aro ante el comensal y servir de inmediato.',
        ]),
        [
            ['Tomate', 'Tomate', 0.35, 'Kilogramo', 103.62, [], false, false, null],
            ['Aguacate', 'Aguacates', 2, 'Unidad', 32.00, [], false, false, null],
            ['Cebolla roja', 'Cebolla morada', 40, 'Gramo', 0.10, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 25, 'Mililitro', 0.35, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Cilantro', 'Cilantro', 0, 'Manojo', 25.00, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 58. Carpaccio de remolacha cocida
    $m = crearRecetaCarga80(
        $pdo,
        'Aperitivo',
        'Carpaccio de remolacha cocida',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Laminar remolacha cocida finamente.',
            'Disponer en plato ante el cliente, añadir queso, nueces, aceite y vinagre.',
        ]),
        [
            ['Remolacha', 'Remolacha cocida (fría)', 0.4, 'Kilogramo', 88.18, ['Cocido', 'Frío'], false, false, null],
            ['Queso de cabra', 'Queso de cabra', 40, 'Gramo', 1.98, [], false, false, null],
            ['Nueces', 'Nueces', 30, 'Gramo', 0.84, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Vinagre balsámico', 'Vinagre balsámico', 15, 'Mililitro', 1.12, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 59. Pasta Alfredo frente al cliente
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pasta Alfredo frente al cliente',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cocer pasta al dente en cocina.',
            '⚠ En estación de servicio segura, fundir mantequilla, añadir pasta y agua.',
            'Incorporar parmesano fuera de fuego fuerte, emulsionar y emplatar.',
        ]),
        [
            ['Pasta (fettuccine)', 'Fettuccine', 350, 'Gramo', 0.37, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 80, 'Gramo', 0.31, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 150, 'Gramo', 1.63, [], false, false, null],
            ['Agua', 'Agua de cocción de la pasta', 120, 'Mililitro', 0.00, [], false, false, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 60. Omelet de queso y champiñones
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Omelet de queso y champiñones',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Saltear champiñones previamente.',
            '⚠ Batir huevos, cocinar por porciones en sartén limpia, añadir champiñones y queso, plegar y terminar hasta cocción segura.',
        ]),
        [
            ['Huevo', 'Huevos', 8, 'Unidad', 6.50, [], false, false, null],
            ['Champiñones', 'Champiñones', 200, 'Gramo', 0.31, [], false, false, null],
            ['Queso mozzarella', 'Queso (mozzarella)', 120, 'Gramo', 0.52, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 25, 'Gramo', 0.31, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Pimienta negra molida', 'Pimienta al gusto', 0, 'Cucharadita', 2.40, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    return $mensajes;
}

/**
 * Carga de las 80 recetas de elaboración gastronómica (PDF de Eyaelkys) —
 * tanda 4 de 4: recetas 61 a 80. Cada receta se crea solo si su nombre no
 * existe todavía (idempotente), con sus ingredientes enlazados al catálogo,
 * porciones reales estimadas (porciones_base), 17 porciones de prueba
 * (las degustaciones que rinde el lote del PDF) y un ícono sugerido.
 *
 * Supuestos de esta tanda (a revisar por Eyaelkys):
 * - Las cantidades son las del PDF (lote de 17 degustaciones). Las porciones
 *   reales (porciones_base) son una estimación mía de platos completos.
 * - Ingredientes que no estaban en el catálogo se crean con precio de
 *   referencia (estimación de mercado RD, marcada en su nota de compra).
 * - Rangos: se costea con el número mayor (ej. 180–220 g de harina → 220 g)
 *   y la preparación conserva el rango.
 * - Las advertencias sanitarias de la preparación van como líneas "⚠".
 * - Caldo ≈ RD$40/litro (estimación; el costo real depende de si es casero).
 * - Aceite sin cantidad (freír/sofreír): "al gusto", no entra al costo; ajusta si quieres costearlo.
 * - Salsa de tomate en ml/g: lata de 227 g ≈ 227 ml.
 * - "Queso" sin tipo: se costeó como mozzarella.
 * - 1 cebolla/tomate/zanahoria/papa por unidad: peso medio asumido (cebolla 150 g, tomate 130 g, zanahoria 80 g, papa 200 g).
 * - Arroz cocido refrigerado (450 g) = ≈150 g de arroz en crudo.
 * - "Hongos" = champiñones.
 * - 2 pechugas de pato = 650 g.
 * - Línea sin catálogo: preparación intermedia costeada con el costo por kilo de la receta que la produce.
 */
function sembrarRecetas80Tanda4(PDO $pdo): array
{
    $mensajes = [];
    prepararCargaRecetas80($pdo);
    sembrarIngredientesNuevos80($pdo, [
        ['Filete de res (lomito)', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 450.00, 'Lomito/filete de res. Estimación de mercado RD (≈RD$450/libra). Ajusta si tienes el precio real.'],
        ['Caldo (pollo o vegetales)', 'Enlatado y conserva', '🍲', 'Litro', 'Litro', 1, 40.00, 'Caldo preparado o hecho con cubitos/base: estimación ≈RD$40/litro. Ajusta si tienes el costo real.'],
        ['Tortillas de trigo', 'Panadería', '🌯', 'Unidad', 'Paquete', 10, 120.00, 'Paquete de 10 tortillas por ≈RD$120. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Chicharrón de cerdo', 'Cárnico', '🥓', 'Libra', 'Libra', 1, 260.00, 'Estimación de mercado RD (≈RD$260/libra). Ajusta si tienes el precio real.'],
        ['Pasta para canelones (tubos)', 'Grano y cereal', '🍝', 'Tubo', 'Paquete', 20, 150.00, 'Paquete de ≈20 tubos por ≈RD$150. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Guisantes (arvejas)', 'Vegetal', '🫛', 'Libra', 'Libra', 1, 110.00, 'Guisantes/arvejas congelados. Estimación de mercado RD (≈RD$110/libra). Ajusta si tienes el precio real.'],
        ['Salsa de soja', 'Condimento y especia', '🍶', 'Mililitro', 'Unidad', 296, 95.00, 'Botella de ≈296 ml por RD$95. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Polenta', 'Grano y cereal', '🌽', 'Libra', 'Libra', 1, 60.00, 'Harina de maíz gruesa/polenta. Estimación de mercado RD (≈RD$60/libra). Ajusta si tienes el precio real.'],
        ['Champiñones', 'Vegetal', '🍄', 'Libra', 'Libra', 1, 140.00, 'Champiñones frescos. Estimación de mercado RD (≈RD$140/libra). Ajusta si tienes el precio real.'],
        ['Solomillo de cerdo', 'Cárnico', '🥩', 'Libra', 'Libra', 1, 230.00, 'Solomillo/lomito de cerdo. Estimación de mercado RD (≈RD$230/libra). Ajusta si tienes el precio real.'],
        ['Masa de hojaldre', 'Panadería', '🥐', 'Gramo', 'Paquete', 450, 320.00, 'Hojaldre congelado, paquete de ≈450 g por RD$320. Estimación de mercado RD. Ajusta si tienes el precio real.'],
        ['Pechuga de pato', 'Cárnico', '🦆', 'Libra', 'Libra', 1, 620.00, 'Estimación de mercado RD (≈RD$620/libra). Ajusta si tienes el precio real.'],
        ['Frutos rojos (congelados)', 'Fruta', '🍓', 'Libra', 'Libra', 1, 450.00, 'Mezcla de frutos rojos congelados. Estimación de mercado RD (≈RD$450/libra). Ajusta si tienes el precio real.'],
        ['Fruta variada (de temporada)', 'Fruta', '🍓', 'Libra', 'Libra', 1, 80.00, 'Mezcla de frutas de temporada. Estimación RD (≈RD$80/libra). Ajusta según las frutas que uses.'],
    ]);
    // Peso/densidad de referencia SOLO si el catálogo no los tenía (para convertir unidades en la lista de compra).
    completarPesoDensidad80($pdo, [
        ['Cebolla blanca', null, 150],
        ['Cilantro', null, 50],
        ['Lechuga', null, 400],
        ['Mayonesa', 0.95, null],
        ['Miel de abeja', 1.42, null],
        ['Pan rallado', 0.45, null],
        ['Papa', null, 200],
        ['Pechuga de pollo', null, 200],
        ['Perejil', null, 50],
        ['Sal', 1.2, null],
        ['Tomate', null, 130],
        ['Zanahoria', null, 80],
    ]);

    // 61. Fresas con chocolate tibio
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Fresas con chocolate tibio',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            '⚠ Lavar y desinfectar fresas.',
            'Calentar crema y verter sobre chocolate; mezclar con mantequilla.',
            'Presentar fresas y salsear ante el cliente.',
        ]),
        [
            ['Fresa', 'Fresas', 400, 'Gramo', 0.33, [], false, false, null],
            ['Chocolate oscuro para hornear', 'Chocolate', 180, 'Gramo', 1.41, [], false, false, null],
            ['Crema para batir', 'Crema para batir', 100, 'Mililitro', 0.26, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 10, 'Gramo', 0.31, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 62. Filete de res trinchado
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Filete de res trinchado',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        5,
        implode("\n\n", [
            'Sazonar y sellar filete.',
            '⚠ Terminar en horno según punto y normas sanitarias.',
            '⚠ Reposar, llevar a estación segura y trinchar ante el cliente.',
            'Salsear.',
        ]),
        [
            ['Filete de res (lomito)', 'Filete de res entero', 750, 'Gramo', 0.99, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Ajo', 'Ajo', 0, 'Diente', 14.50, [], true, false, null],
            ['Romero (seco)', 'Romero al gusto', 0, 'Cucharadita', 7.78, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
            ['Caldo (pollo o vegetales)', 'Jugo de carne (o caldo)', 100, 'Mililitro', 0.04, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 63. Macedonia tropical con yogur
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Macedonia tropical con yogur',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Lavar, pelar y cortar frutas; refrigerar.',
            'Mezclar yogur y miel.',
            'Montar porciones ante el cliente, rociar limón y acompañar con yogur.',
        ]),
        [
            ['Piña', 'Piña', 0.2, 'Kilogramo', 136.36, [], false, false, null],
            ['Lechosa', 'Lechosa (papaya)', 0.2, 'Kilogramo', 55.12, [], false, false, null],
            ['Mango', 'Mango', 0.2, 'Kilogramo', 136.00, [], false, false, null],
            ['Guineo', 'Guineos', 2, 'Unidad', 3.80, [], false, false, null],
            ['Yogurt natural (envase grande)', 'Yogur natural', 0.25, 'Kilogramo', 145.63, [], false, false, null],
            ['Miel de abeja', 'Miel', 15, 'Gramo', 0.55, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 15, 'Mililitro', 0.35, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 64. Tortillas de trigo rellenas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Tortillas de trigo rellenas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Cocinar y desmenuzar pollo.',
            '⚠ Mantener a temperatura segura.',
            '⚠ Preparar vegetales desinfectados y salsa de yogur.',
            '⚠ Calentar tortillas y montar ante el cliente evitando contaminación cruzada.',
        ]),
        [
            ['Tortillas de trigo', 'Tortillas de trigo', 8, 'Unidad', 12.00, [], false, false, null],
            ['Pechuga de pollo', 'Pollo cocido', 350, 'Gramo', 0.38, ['Cocido'], false, false, null],
            ['Lechuga', 'Lechuga', 150, 'Gramo', 0.14, [], false, false, null],
            ['Tomate', 'Tomate', 0.15, 'Kilogramo', 103.62, [], false, false, null],
            ['Yogurt natural (envase grande)', 'Yogur', 0.12, 'Kilogramo', 145.63, [], false, false, null],
            ['Limón verde', 'Jugo de limón', 30, 'Mililitro', 0.35, [], false, false, null],
            ['Comino', 'Comino al gusto', 0, 'Cucharadita', 3.09, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 65. Mofongo de camarones
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Mofongo de camarones',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Freír plátanos hasta tiernos.',
            'Majar con ajo y chicharrón.',
            'Cocinar camarones en salsa ligera de ajo y caldo hasta cocidos; montar mofongo y servir.',
            '⚠ Completar limpieza y cierre de estación.',
        ]),
        [
            ['Plátano verde', 'Plátanos verdes', 4, 'Unidad', 20.00, [], false, false, null],
            ['Camarón', 'Camarones', 450, 'Gramo', 0.62, [], false, false, null],
            ['Ajo', 'Ajo', 4, 'Diente', 14.50, [], false, false, null],
            ['Chicharrón de cerdo', 'Chicharrón cocido', 60, 'Gramo', 0.57, ['Cocido'], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 350, 'Mililitro', 0.04, [], false, false, null],
            ['Aceite vegetal', 'Aceite (al gusto)', 0, 'Litro', 147.00, [], true, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 66. Pechuga rellena de espinacas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pechuga rellena de espinacas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Saltear espinacas y ajo, enfriar y mezclar con queso.',
            'Abrir pechugas, rellenar y cerrar.',
            '⚠ Sellar y hornear hasta 74 °C internos; sanitizar estación.',
        ]),
        [
            ['Pechuga de pollo', 'Pechugas de pollo', 4, 'Unidad', 76.72, [], false, false, null],
            ['Espinaca', 'Espinacas', 180, 'Gramo', 0.24, [], false, false, null],
            ['Queso crema', 'Queso crema', 160, 'Gramo', 1.27, [], false, false, null],
            ['Ajo', 'Ajo', 2, 'Diente', 14.50, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 67. Canelones de ricota
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Canelones de ricota',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Cocer o hidratar pasta según fabricante.',
            'Mezclar ricota y espinacas cocidas, rellenar, cubrir con salsa y queso.',
            'Hornear 190 °C 25–35 minutos.',
            '⚠ Realizar cierre.',
        ]),
        [
            ['Pasta para canelones (tubos)', 'Tubos de canelón', 12, 'Tubo', 7.50, [], false, false, null],
            ['Queso ricotta', 'Ricota', 350, 'Gramo', 0.40, [], false, false, null],
            ['Espinaca', 'Espinacas', 180, 'Gramo', 0.24, [], false, false, null],
            ['Salsa de tomate', 'Salsa tomate', 450, 'Mililitro', 0.20, [], false, false, null],
            ['Queso mozzarella', 'Queso mozzarella', 120, 'Gramo', 0.52, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 68. Pastel de papa y carne
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pastel de papa y carne',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Hervir papas y preparar puré.',
            'Cocinar carne completamente con cebolla y tomate.',
            'Montar carne y puré en fuente, añadir queso y gratinar.',
            '⚠ Limpiar estación.',
        ]),
        [
            ['Papa', 'Papas', 0.85, 'Kilogramo', 83.78, [], false, false, null],
            ['Carne de res molida', 'Carne molida', 450, 'Gramo', 0.40, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Tomate', 'Tomate', 0.2, 'Kilogramo', 103.62, [], false, false, null],
            ['Leche entera', 'Leche', 0.1, 'Litro', 74.00, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 40, 'Gramo', 0.31, [], false, false, null],
            ['Queso mozzarella', 'Queso (mozzarella)', 100, 'Gramo', 0.52, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 69. Hamburguesa artesanal
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Hamburguesa artesanal',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Formar 4 hamburguesas sin compactar en exceso.',
            '⚠ Cocinar carne molida a 71 °C internos.',
            'Tostar panes, montar con vegetales limpios y queso.',
            '⚠ Cerrar estación.',
        ]),
        [
            ['Carne de res molida', 'Carne molida de res', 600, 'Gramo', 0.40, [], false, false, null],
            ['Pan de hamburguesa', 'Pan de hamburguesa', 4, 'Unidad', 11.13, [], false, false, null],
            ['Lechuga', 'Lechuga (hojas)', 4, 'Hoja', 3.56, [], false, false, null],
            ['Tomate', 'Tomate', 1, 'Unidad', 13.47, [], false, false, null],
            ['Queso cheddar', 'Queso en lonjas', 4, 'Lonja', 9.70, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Mayonesa', 'Salsa de la casa al gusto', 0, 'Cucharada', 4.72, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 70. Arroz frito oriental
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Arroz frito oriental',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cocinar pollo completamente y reservar.',
            'Saltear verduras, cuajar huevos, incorporar arroz frío y pollo; calentar todo completamente y sazonar con soja.',
            '⚠ No dejar arroz a temperatura ambiente.',
        ]),
        [
            ['Arroz', 'Arroz cocido refrigerado (≈150 g de arroz crudo)', 150, 'Gramo', 0.10, ['Cocido'], false, false, null],
            ['Pechuga de pollo', 'Pollo', 200, 'Gramo', 0.38, [], false, false, null],
            ['Huevo', 'Huevos', 2, 'Unidad', 6.50, [], false, false, null],
            ['Zanahoria', 'Zanahoria', 0.1, 'Kilogramo', 68.34, [], false, false, null],
            ['Guisantes (arvejas)', 'Guisantes', 80, 'Gramo', 0.24, [], false, false, null],
            ['Salsa de soja', 'Salsa soja', 40, 'Mililitro', 0.32, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 71. Tacos de pescado a la plancha
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Tacos de pescado a la plancha',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            '⚠ Cortar repollo desinfectado y mezclar salsa de yogur y limón.',
            'Sazonar y cocinar pescado a la plancha completamente.',
            'Calentar tortillas y montar.',
            '⚠ Limpiar superficies.',
        ]),
        [
            ['Filete de pescado', 'Pescado', 600, 'Gramo', 0.33, [], false, false, null],
            ['Tortillas de trigo', 'Tortillas de trigo', 8, 'Unidad', 12.00, [], false, false, null],
            ['Repollo', 'Repollo', 0.2, 'Kilogramo', 77.16, [], false, false, null],
            ['Yogurt natural (envase grande)', 'Yogur', 0.1, 'Kilogramo', 145.63, [], false, false, null],
            ['Limón verde', 'Limón', 1, 'Unidad', 5.23, [], false, false, null],
            ['Aguacate', 'Aguacate', 1, 'Unidad', 32.00, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 15, 'Mililitro', 0.15, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 72. Sándwich club
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Sándwich club',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Cocinar tocineta y pollo completamente; tostar pan.',
            '⚠ Montar tres capas con mayonesa, pollo y vegetales desinfectados.',
            '⚠ Cortar, presentar y completar cierre.',
        ]),
        [
            ['Pan de sándwich', 'Pan de sándwich (rebanadas)', 12, 'Rebanada', 4.91, [], false, false, null],
            ['Pechuga de pollo', 'Pollo cocido', 400, 'Gramo', 0.38, ['Cocido'], false, false, null],
            ['Tocineta', 'Tocineta (tiras)', 8, 'Tira', 5.95, [], false, false, null],
            ['Lechuga', 'Lechuga (hojas)', 4, 'Hoja', 3.56, [], false, false, null],
            ['Tomate', 'Tomates', 2, 'Unidad', 13.47, [], false, false, null],
            ['Mayonesa', 'Mayonesa', 80, 'Gramo', 0.33, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 73. Raviolis de espinacas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Raviolis de espinacas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Amasar harina y huevos; reposar 30 minutos.',
            'Mezclar espinacas cocidas y escurridas con ricota y queso.',
            'Estirar, rellenar, sellar y hervir 3–5 minutos; servir con mantequilla.',
        ]),
        [
            ['Harina de trigo', 'Harina', 0.3, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevos', 3, 'Unidad', 6.50, [], false, false, null],
            ['Queso ricotta', 'Ricota', 200, 'Gramo', 0.40, [], false, false, null],
            ['Espinaca', 'Espinacas', 180, 'Gramo', 0.24, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 40, 'Gramo', 1.63, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 30, 'Gramo', 0.31, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 74. Pescado en costra de hierbas
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pescado en costra de hierbas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Mezclar pan, hierbas, mantequilla y ralladura.',
            '⚠ Cubrir pescado sazonado y hornear a 190 °C 15–20 minutos según grosor hasta cocción completa.',
        ]),
        [
            ['Filete de pescado', 'Pescado', 650, 'Gramo', 0.33, [], false, false, null],
            ['Pan rallado', 'Pan rallado', 100, 'Gramo', 0.20, [], false, false, null],
            ['Perejil', 'Perejil', 25, 'Gramo', 0.50, [], false, false, null],
            ['Cilantro', 'Cilantro', 20, 'Gramo', 0.50, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 40, 'Gramo', 0.31, [], false, false, null],
            ['Limón verde', 'Limón', 1, 'Unidad', 5.23, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 75. Polenta cremosa con hongos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Polenta cremosa con hongos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Verter polenta en caldo hirviendo removiendo; cocer según envase hasta tierna.',
            'Saltear hongos con ajo; terminar polenta con mantequilla y queso y servir juntos.',
        ]),
        [
            ['Polenta', 'Polenta', 220, 'Gramo', 0.13, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 900, 'Mililitro', 0.04, [], false, false, null],
            ['Champiñones', 'Hongos (champiñones)', 250, 'Gramo', 0.31, [], false, false, null],
            ['Queso parmesano', 'Queso parmesano', 60, 'Gramo', 1.63, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 40, 'Gramo', 0.31, [], false, false, null],
            ['Ajo', 'Ajo', 1, 'Diente', 14.50, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 76. Wellington de cerdo
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Wellington de cerdo',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        6,
        implode("\n\n", [
            'Sellar cerdo y enfriar.',
            'Picar y saltear hongos con cebolla hasta secos; enfriar.',
            'Untar cerdo con mostaza, envolver con hongos y hojaldre.',
            '⚠ Barnizar y hornear 200 °C hasta hojaldre dorado y cerdo con cocción interna segura.',
        ]),
        [
            ['Solomillo de cerdo', 'Solomillo de cerdo', 650, 'Gramo', 0.51, [], false, false, null],
            ['Masa de hojaldre', 'Masa de hojaldre', 350, 'Gramo', 0.71, [], false, false, null],
            ['Champiñones', 'Champiñones', 250, 'Gramo', 0.31, [], false, false, null],
            ['Cebolla blanca', 'Cebolla', 1, 'Unidad', 13.89, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Aceite vegetal', 'Aceite', 20, 'Mililitro', 0.15, [], false, false, null],
            ['Mostaza', 'Mostaza al gusto', 0, 'Cucharada', 3.33, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 77. Ñoquis con queso azul
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Ñoquis con queso azul',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Hervir papa, hacer puré y unir con huevo y harina; formar ñoquis.',
            'Hervir hasta cocidos.',
            'Fundir queso azul en crema a fuego suave y mezclar con ñoquis.',
        ]),
        [
            ['Papa', 'Papa', 0.7, 'Kilogramo', 83.78, [], false, false, null],
            ['Harina de trigo', 'Harina', 0.2, 'Kilogramo', 61.73, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            ['Queso azul', 'Queso azul', 100, 'Gramo', 0.71, [], false, false, null],
            ['Crema de leche', 'Crema de leche', 200, 'Mililitro', 0.49, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 78. Pechuga de pato con frutos rojos
    $m = crearRecetaCarga80(
        $pdo,
        'Plato fuerte',
        'Pechuga de pato con frutos rojos',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Marcar piel del pato y dorar comenzando por piel.',
            '⚠ Terminar cocción a temperatura segura.',
            'Reducir frutos rojos con caldo, miel y vinagre; reposar pato, cortar y servir.',
        ]),
        [
            ['Pechuga de pato', 'Pechuga de pato (2 pechugas)', 650, 'Gramo', 1.37, [], false, false, null],
            ['Frutos rojos (congelados)', 'Frutos rojos', 200, 'Gramo', 0.99, [], false, false, null],
            ['Caldo (pollo o vegetales)', 'Caldo', 120, 'Mililitro', 0.04, [], false, false, null],
            ['Miel de abeja', 'Miel', 20, 'Gramo', 0.55, [], false, false, null],
            ['Vinagre', 'Vinagre', 15, 'Mililitro', 0.15, [], false, false, null],
            ['Sal', 'Sal al gusto', 0, 'Cucharadita', 0.20, [], true, true, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 79. Tartaletas de frutas
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Tartaletas de frutas',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        8,
        implode("\n\n", [
            'Preparar masa quebrada, reposar, forrar moldes y hornear a 180 °C 15–20 minutos.',
            'Enfriar, rellenar con crema pastelera refrigerada y decorar con frutas lavadas.',
        ]),
        [
            ['Harina de trigo', 'Harina', 0.25, 'Kilogramo', 61.73, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 125, 'Gramo', 0.31, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 80, 'Gramo', 0.08, [], false, false, null],
            ['Huevo', 'Huevo', 1, 'Unidad', 6.50, [], false, false, null],
            [null, 'Crema pastelera cocida (receta de Profiteroles)', 0.4, 'Kilogramo', 125.40, ['Cocido'], false, false, null],
            ['Fruta variada (de temporada)', 'Fruta variada', 0.25, 'Kilogramo', 176.37, [], false, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    // 80. Soufflé de chocolate
    $m = crearRecetaCarga80(
        $pdo,
        'Postre',
        'Soufflé de chocolate',
        'Receta del recetario de 80 elaboraciones; el lote del PDF rinde 17 degustaciones.',
        4,
        implode("\n\n", [
            'Fundir chocolate con mantequilla.',
            'Mezclar yemas y harina; batir claras con azúcar hasta picos suaves.',
            'Incorporar con movimientos envolventes, llenar moldes preparados y hornear 190 °C 12–16 minutos; servir inmediatamente.',
        ]),
        [
            ['Chocolate oscuro para hornear', 'Chocolate', 180, 'Gramo', 1.41, [], false, false, null],
            ['Mantequilla', 'Mantequilla', 40, 'Gramo', 0.31, [], false, false, null],
            ['Huevo', 'Huevos (separados)', 4, 'Unidad', 6.50, [], false, false, null],
            ['Azúcar blanca', 'Azúcar', 60, 'Gramo', 0.08, [], false, false, null],
            ['Harina de trigo', 'Harina', 20, 'Gramo', 0.06, [], false, false, null],
            ['Mantequilla', 'Mantequilla (para los moldes)', 0, 'Cucharadita', 1.46, [], true, false, null],
        ]
    );
    if ($m !== null) {
        $mensajes[] = $m;
    }

    return $mensajes;
}

/**
 * Agrega eventos.fecha_tentativa (TINYINT(1) NOT NULL DEFAULT 0), a pedido
 * de Eyaelkys: "en la fecha del evento, quiero poder decir que la fecha sea
 * tentativa; en caso de tentativa que salga solo el mes". Con 1, todo el
 * sistema muestra únicamente el mes y el año del evento (ver
 * fmtFechaEvento() en includes/helpers.php) y eventos/form.php guarda como
 * fecha el último día de ese mes (así el evento sigue siendo "próximo"
 * durante todo el mes y ningún filtro por fecha necesita cambiar). Los
 * eventos que ya existían quedan con fecha exacta (0). Va aquí y no en el
 * CREATE TABLE de db/schema.sql porque "eventos" ya existe en producción.
 */
function agregarFechaTentativaAEventos(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'eventos', 'id') || columnaExiste($pdo, 'eventos', 'fecha_tentativa')) {
        return $mensajes;
    }
    $pdo->exec('ALTER TABLE eventos ADD COLUMN fecha_tentativa TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha');
    $mensajes[] = 'Columna "fecha_tentativa" agregada a la tabla eventos (si está activa, el evento muestra solo el mes y el año; los eventos existentes quedaron con fecha exacta).';
    return $mensajes;
}

/**
 * Mueve las facturas de gastos que quedaron guardadas en
 * assets/uploads/facturas/ (dentro del repositorio, así que un despliegue
 * las borra) hacia private/facturas/ (el enlace `private/` en la raíz del
 * proyecto -> ../shared/private, fuera del repositorio, igual que ya se hizo
 * con las fotos de recetas y de eventos en `public/`). Pedido de Eyaelkys:
 * "Las facturas deben ir al private no a los assets."
 *
 * Solo se activa si `private/` ya existe como carpeta real en este entorno
 * (el enlace simbólico/junction tiene que estar creado y apuntando a algo
 * real — si no, no hace nada, para no intentar mover archivos a un destino
 * que no sirve). Por cada gasto con una factura vieja: si el archivo
 * físico todavía existe en la ruta antigua, se mueve y solo ENTONCES se
 * actualiza la columna `factura` en la base de datos (nunca al revés, para
 * no dejar la base de datos apuntando a un archivo que en realidad no se
 * movió). Si el archivo físico ya no está (por ejemplo, porque un
 * despliegue anterior lo borró antes de este arreglo), se deja la fila tal
 * cual y se avisa por separado, para que Eyaelkys sepa que esa factura en
 * particular habría que volver a cargarla a mano.
 *
 * Como el resto de migraciones del proyecto, es segura de correr varias
 * veces: una vez que una fila ya apunta a private/facturas/... deja de
 * aparecer en el WHERE y no se vuelve a tocar.
 */
function migrarFacturasAPrivado(PDO $pdo): array
{
    $mensajes = [];
    if (!columnaExiste($pdo, 'gastos', 'factura')) {
        return $mensajes;
    }

    $directorioDestino = __DIR__ . '/private/facturas';
    $directorioPrivado = __DIR__ . '/private';
    if (!is_dir($directorioPrivado)) {
        // El enlace `private/` no está creado (o no apunta a una carpeta
        // real) en este entorno todavía — no hay dónde mover nada, así que
        // no se intenta, para no arriesgar los archivos actuales.
        return $mensajes;
    }
    if (!is_dir($directorioDestino)) {
        // Ver Sección 20 del documento maestro: mkdir() recursivo casi
        // nunca logra crear una subcarpeta nueva a través de un enlace
        // simbólico/junction, así que esto probablemente no alcance por sí
        // solo — pero se deja el intento por si acaso, igual que en
        // recetas/form.php y eventos/form.php.
        @mkdir($directorioDestino, 0775, true);
    }

    $movidos = 0;
    $noEncontrados = 0;
    $stmt = $pdo->query("SELECT id, factura FROM gastos WHERE factura LIKE 'assets/uploads/facturas/%'");
    $actualizarStmt = $pdo->prepare('UPDATE gastos SET factura = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $fila) {
        $nombreArchivo = basename($fila['factura']);
        $rutaVieja = __DIR__ . '/' . $fila['factura'];
        $rutaNueva = $directorioDestino . '/' . $nombreArchivo;

        if (!is_file($rutaVieja)) {
            $noEncontrados++;
            continue;
        }
        if (!is_dir($directorioDestino) || !@rename($rutaVieja, $rutaNueva)) {
            $noEncontrados++;
            continue;
        }
        $actualizarStmt->execute(['private/facturas/' . $nombreArchivo, $fila['id']]);
        $movidos++;
    }

    if ($movidos > 0) {
        $mensajes[] = "$movidos factura(s) movidas de assets/uploads/facturas/ a private/facturas/ (ya no se pierden en un despliegue).";
    }
    if ($noEncontrados > 0) {
        $mensajes[] = "$noEncontrados factura(s) en la base de datos apuntaban a assets/uploads/facturas/ pero el archivo ya no estaba en el servidor (probablemente se perdió en un despliegue anterior) — esos gastos quedarían sin la imagen de la factura hasta volver a cargarla a mano.";
    }
    return $mensajes;
}

/**
 * Rol "Padres": ve la nueva pestaña "Fotos" (trabajos de los chicos) dentro
 * de una práctica, igual que ya ve "Estudiantes y pagos" — a pedido de
 * Eyaelkys ("podran ser mostradas a los padres para que puedan ver las
 * creaciones de los chicos"). El módulo "practicas_fotos" en sí lo crea
 * `schema.sql` (INSERT IGNORE, como el resto del catálogo de módulos);
 * aquí solo se siembra el permiso de "ver" para Padres, y solo si esa fila
 * todavía no existe — si Eyaelkys ya la personalizó desde Usuarios y
 * roles → Roles, esto no la pisa.
 */
function otorgarAccesoPadresAFotosPracticas(PDO $pdo): array
{
    $mensajes = [];
    $idRolPadres = idPorNombre($pdo, 'roles', 'nombre', 'Padres');
    $idModulo = idPorNombre($pdo, 'modulos', 'clave', 'practicas_fotos');
    if (!$idRolPadres || !$idModulo) {
        return $mensajes;
    }
    $existeStmt = $pdo->prepare('SELECT 1 FROM permisos_rol WHERE rol_id = ? AND modulo_id = ?');
    $existeStmt->execute([$idRolPadres, $idModulo]);
    if (!$existeStmt->fetchColumn()) {
        $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (?,?,1,0,0,0)')
            ->execute([$idRolPadres, $idModulo]);
        $mensajes[] = 'Rol "Padres": acceso de solo lectura otorgado a la pestaña "Fotos" (trabajos de los chicos) de una práctica.';
    }
    return $mensajes;
}

/**
 * Rol "Padres": ve la nueva pestaña "Fotos" de un evento (galería, distinta
 * del banner), igual que ya ve "Estudiantes y pagos" — mismo pedido y mismo
 * patrón que otorgarAccesoPadresAFotosPracticas() (Sección 37, fotos de
 * eventos). El módulo "eventos_fotos" en sí lo crea `schema.sql` (INSERT
 * IGNORE); aquí solo se siembra el permiso de "ver" para Padres, y solo si
 * esa fila todavía no existe.
 */
function otorgarAccesoPadresAFotosEventos(PDO $pdo): array
{
    $mensajes = [];
    $idRolPadres = idPorNombre($pdo, 'roles', 'nombre', 'Padres');
    $idModulo = idPorNombre($pdo, 'modulos', 'clave', 'eventos_fotos');
    if (!$idRolPadres || !$idModulo) {
        return $mensajes;
    }
    $existeStmt = $pdo->prepare('SELECT 1 FROM permisos_rol WHERE rol_id = ? AND modulo_id = ?');
    $existeStmt->execute([$idRolPadres, $idModulo]);
    if (!$existeStmt->fetchColumn()) {
        $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_id, ver, crear, editar, eliminar) VALUES (?,?,1,0,0,0)')
            ->execute([$idRolPadres, $idModulo]);
        $mensajes[] = 'Rol "Padres": acceso de solo lectura otorgado a la pestaña "Fotos" de un evento.';
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
        $mensajes = array_merge($mensajes, migrarPagosEstudianteExistentes($pdo));
        $mensajes = array_merge($mensajes, migrarCatalogosYRoles($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoEspecias($pdo));
        $mensajes = array_merge($mensajes, renombrarVainillaLiquidaAExtracto($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoLimonMielVainilla($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoMantequillaGuineoAvena($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoPasta($pdo));
        $mensajes = array_merge($mensajes, corregirUnidadUsoFresaYGelatina($pdo));
        $mensajes = array_merge($mensajes, establecerDensidadIngredientes($pdo));
        $mensajes = array_merge($mensajes, establecerPesoUnidadIngredientes($pdo));
        $mensajes = array_merge($mensajes, establecerModoCompraDefecto($pdo));
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
        $mensajes = array_merge($mensajes, sembrarRecetasReposteria4($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetasReposteria5($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetasCevicheEscabecheYLimon($pdo));
        $mensajes = array_merge($mensajes, migrarPadresDesdeTextoLibre($pdo));
        $mensajes = array_merge($mensajes, agregarMetodoFondoAPagosEstudiante($pdo));
        $mensajes = array_merge($mensajes, agregarMetodoAFondoMovimientos($pdo));
        $mensajes = array_merge($mensajes, agregarUsuarioIdAEstudiantes($pdo));
        $mensajes = array_merge($mensajes, agregarUltimoAccesoAUsuarios($pdo));
        $mensajes = array_merge($mensajes, agregarResponsableCompra($pdo));
        $mensajes = array_merge($mensajes, agregarPorcionesPruebaARecetas($pdo));
        $mensajes = array_merge($mensajes, agregarBaseCalculoAPracticaReceta($pdo));
        $mensajes = array_merge($mensajes, agregarIconoARecetas($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetas80Tanda1($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetas80Tanda2($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetas80Tanda3($pdo));
        $mensajes = array_merge($mensajes, sembrarRecetas80Tanda4($pdo));
        $mensajes = array_merge($mensajes, agregarFechaTentativaAEventos($pdo));
        $mensajes = array_merge($mensajes, migrarFacturasAPrivado($pdo));
        $mensajes = array_merge($mensajes, otorgarAccesoPadresAFotosPracticas($pdo));
        $mensajes = array_merge($mensajes, otorgarAccesoPadresAFotosEventos($pdo));

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
