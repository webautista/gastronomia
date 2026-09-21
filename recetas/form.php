<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'recetas', $id ? 'editar' : 'crear', $base);

$receta = ['nombre' => '', 'descripcion' => '', 'categoria_id' => '', 'porciones_base' => '', 'preparacion' => '', 'foto' => null];
$ingredientes = [['ingrediente_id' => '', 'nombre' => '', 'cantidad' => '', 'unidad_id' => '', 'costo_unitario' => '', 'reemplazo' => '', 'al_gusto' => 0, 'opcional' => 0, 'acciones' => []]];
$errores = [];

$accionesIngrediente = db()->query('SELECT * FROM acciones_ingrediente WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$accionesValidas = array_column($accionesIngrediente, 'id');
$accionesNombrePorId = array_column($accionesIngrediente, 'nombre', 'id');

/**
 * Texto del botón desplegable de "Preparación (cortes/acciones)" de una
 * línea de ingrediente, a partir de los nombres ya marcados para esa línea.
 * Se calcula del lado del servidor (no con JavaScript al cargar la página)
 * para que una receta con acciones ya guardadas se vea bien de una vez, sin
 * depender de que el JS corra primero.
 */
function etiquetaAccionesSeleccionadas(array $nombres): string
{
    if (!$nombres) {
        return 'Preparación (cortes/acciones)';
    }
    $nombres = array_values($nombres);
    if (count($nombres) <= 2) {
        return implode(', ', $nombres);
    }
    return implode(', ', array_slice($nombres, 0, 2)) . ' +' . (count($nombres) - 2) . ' más';
}

$categorias = db()->query('SELECT * FROM categorias_receta WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$unidades = db()->query('SELECT * FROM unidades_medida WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
// Mapa unidad_id -> ¿se compra completa? (ej. no se puede comprar medio
// huevo ni media lata). Lo usa el JS para redondear el monto por línea.
$unidadesEnteras = [];
// Mapa unidad_id -> tipo_medida/factor_base, para que el JS pueda convertir
// el costo automáticamente cuando se cambia la unidad de una línea a otra
// compatible (ej. Onza -> Gramo), en vez de dejar el costo de la unidad
// vieja multiplicando una cantidad en la unidad nueva.
$unidadesInfo = [];
foreach ($unidades as $u) {
    $unidadesEnteras[(int) $u['id']] = (bool) ($u['es_entera'] ?? false);
    $unidadesInfo[(int) $u['id']] = [
        'tipo_medida' => $u['tipo_medida'] ?? null,
        'factor_base' => $u['factor_base'] !== null ? (float) $u['factor_base'] : null,
    ];
}
$categoriasIngrediente = db()->query('SELECT * FROM categorias_ingrediente WHERE activo = 1 ORDER BY orden ASC, nombre ASC')->fetchAll();
$catalogoIngredientes = db()->query(
    'SELECT i.*, u.abreviatura AS unidad_abrev FROM ingredientes_catalogo i
     JOIN unidades_medida u ON u.id = i.unidad_id
     WHERE i.activo = 1 ORDER BY i.nombre ASC'
)->fetchAll();
$idsCatalogoValidos = array_column($catalogoIngredientes, 'id');
// Mapa nombre -> datos, para que el JS autocomplete unidad y costo al elegir
// del selector con búsqueda (datalist) o al crear uno nuevo desde el modal.
$catalogoPorNombre = [];
foreach ($catalogoIngredientes as $ci) {
    $catalogoPorNombre[$ci['nombre']] = [
        'id' => (int) $ci['id'],
        'unidad_id' => (int) $ci['unidad_id'],
        'costo_unitario' => round(costoPorUnidadUso($ci), 2),
        // Gramos por mililitro, cuando este ingrediente la tiene cargada
        // (ej. mantequilla, harina) — le permite al JS convertir su costo
        // entre unidades de masa y de volumen. null para la mayoría.
        'densidad_g_ml' => $ci['densidad_g_ml'] !== null ? (float) $ci['densidad_g_ml'] : null,
        // Gramos que pesa 1 "Unidad" de este ingrediente, cuando la tiene
        // cargada (ej. fresa) — le permite al JS convertir su costo
        // desde/hacia la unidad de conteo "Unidad". null para la mayoría.
        'peso_unidad_g' => ($ci['peso_unidad_g'] ?? null) !== null ? (float) $ci['peso_unidad_g'] : null,
        // Unidad de compra y cuántas unidades de uso trae (ej. Gelatina sin
        // sabor: se usa por Cucharadita pero se compra por Paquete, y trae
        // 3 cucharaditas) — le permite al JS convertir el costo también
        // hacia/desde la unidad de compra, aunque no haya densidad ni peso
        // por unidad cargados (ver convertirCantidadEntreUnidades() "caso
        // 4" en includes/helpers.php). Es el mismo puente que ya existe
        // siempre en el catálogo, ahora también disponible aquí.
        'unidad_compra_id' => (int) $ci['unidad_compra_id'],
        'contenido_por_compra' => (float) $ci['contenido_por_compra'],
    ];
}
// Id de la unidad de conteo "Unidad", para que el JS sepa cuándo aplicar el
// puente peso_unidad_g (ver tipoYFactorDeUnidad() en includes/helpers.php,
// misma idea del lado del servidor).
$idUnidadConteo = null;
foreach ($unidades as $u) {
    if ($u['nombre'] === 'Unidad') {
        $idUnidadConteo = (int) $u['id'];
        break;
    }
}

if ($id) {
    $stmt = db()->prepare('SELECT * FROM recetas WHERE id = ?');
    $stmt->execute([$id]);
    $encontrada = $stmt->fetch();
    if (!$encontrada) {
        flash('Esa receta ya no existe.', 'error');
        redirect('index.php');
    }
    $receta = $encontrada;

    $stmt = db()->prepare('SELECT * FROM ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC');
    $stmt->execute([$id]);
    $filas = $stmt->fetchAll();
    if ($filas) {
        $idsFilas = array_column($filas, 'id');
        $accionesPorFila = [];
        $in = implode(',', array_fill(0, count($idsFilas), '?'));
        $stmtAcc = db()->prepare("SELECT receta_ingrediente_id, accion_id FROM ingrediente_accion WHERE receta_ingrediente_id IN ($in)");
        $stmtAcc->execute($idsFilas);
        foreach ($stmtAcc->fetchAll() as $fa) {
            $accionesPorFila[(int) $fa['receta_ingrediente_id']][] = (int) $fa['accion_id'];
        }
        foreach ($filas as &$fila) {
            $fila['acciones'] = $accionesPorFila[(int) $fila['id']] ?? [];
        }
        unset($fila);
        $ingredientes = $filas;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $receta['nombre']         = trim($_POST['nombre'] ?? '');
    $receta['descripcion']    = trim($_POST['descripcion'] ?? '');
    $receta['categoria_id']   = intOrNull($_POST['categoria_id'] ?? null);
    $receta['porciones_base'] = intOrNull($_POST['porciones_base'] ?? null);
    $receta['preparacion']    = trim($_POST['preparacion'] ?? '');

    $ingNombres        = $_POST['ing_nombre'] ?? [];
    $ingCantidad       = $_POST['ing_cantidad'] ?? [];
    $ingUnidad         = $_POST['ing_unidad_id'] ?? [];
    $ingCosto          = $_POST['ing_costo'] ?? [];
    $ingIngredienteId  = $_POST['ing_ingrediente_id'] ?? [];
    $ingReemplazo      = $_POST['ing_reemplazo'] ?? [];
    $ingAlGusto        = $_POST['ing_al_gusto'] ?? [];
    $ingOpcional       = $_POST['ing_opcional'] ?? [];
    $ingAccionesPost   = $_POST['ing_acciones'] ?? [];

    $unidadesValidas = array_column($unidades, 'id');
    $unidadPorDefecto = $unidadesValidas[0] ?? null;

    $ingredientesNuevos = [];
    foreach ($ingNombres as $i => $nombreIng) {
        $nombreIng = trim($nombreIng);
        if ($nombreIng === '') {
            continue;
        }
        $unidadId = intOrNull($ingUnidad[$i] ?? null);
        if (!in_array($unidadId, $unidadesValidas, true)) {
            $unidadId = $unidadPorDefecto;
        }
        $ingredienteId = intOrNull($ingIngredienteId[$i] ?? null);
        if (!in_array($ingredienteId, $idsCatalogoValidos, true)) {
            $ingredienteId = null;
        }
        $esAlGusto = !empty($ingAlGusto[$i]);
        $reemplazo = trim($ingReemplazo[$i] ?? '');
        $accionesFila = [];
        if (!empty($ingAccionesPost[$i]) && is_array($ingAccionesPost[$i])) {
            foreach ($ingAccionesPost[$i] as $accId) {
                $accId = intOrNull($accId);
                if ($accId && in_array($accId, $accionesValidas, true)) {
                    $accionesFila[] = $accId;
                }
            }
        }
        $ingredientesNuevos[] = [
            'ingrediente_id' => $ingredienteId,
            'nombre'         => $nombreIng,
            'cantidad'       => $esAlGusto ? 0 : (float) ($ingCantidad[$i] ?? 0),
            'unidad_id'      => $unidadId,
            'costo_unitario' => (float) ($ingCosto[$i] ?? 0),
            'reemplazo'      => $reemplazo !== '' ? $reemplazo : null,
            'al_gusto'       => $esAlGusto ? 1 : 0,
            'opcional'       => !empty($ingOpcional[$i]) ? 1 : 0,
            'acciones'       => array_values(array_unique($accionesFila)),
        ];
    }

    if ($receta['nombre'] === '') {
        $errores[] = 'El nombre de la receta es obligatorio.';
    }
    if (!$receta['porciones_base'] || $receta['porciones_base'] < 1) {
        $errores[] = 'Las porciones base deben ser un número mayor a 0.';
    }
    if (!in_array($receta['categoria_id'], array_column($categorias, 'id'), true)) {
        $errores[] = 'Categoría no válida.';
    }

    // Foto: solo se valida el tipo/tamaño aquí. El archivo no se mueve ni se
    // borra la foto anterior todavía — eso pasa más abajo, y solo si el
    // resto del formulario también es válido, para no perder la foto vieja
    // si el guardado termina fallando por otro motivo.
    $eliminarFoto = !empty($_POST['eliminar_foto']);
    $subioArchivoValido = false;
    $extensionSubida = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            $errores[] = 'No se pudo subir la foto. Intenta de nuevo.';
        } else {
            $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = @mime_content_type($_FILES['foto']['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                $errores[] = 'La foto debe ser una imagen JPG, PNG o WEBP.';
            } elseif ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
                $errores[] = 'La foto no puede pesar más de 5 MB.';
            } else {
                $subioArchivoValido = true;
                $extensionSubida = $tiposPermitidos[$mime];
            }
        }
    }

    if (!$errores) {
        // Ahora sí: mover el archivo nuevo (o borrar la foto actual si se
        // pidió quitarla) justo antes de guardar en la base de datos.
        $fotoFinal = $receta['foto'] ?? null;
        if ($subioArchivoValido) {
            // Las fotos de recetas son contenido público del sistema, así que
            // se guardan en el almacenamiento compartido fuera del repositorio
            // (enlace `public/` en la raíz del proyecto -> ../shared/public),
            // para que sobrevivan a los despliegues y no se suban a git.
            $directorioDestino = __DIR__ . '/../public/recetas';
            if (!is_dir($directorioDestino)) {
                mkdir($directorioDestino, 0775, true);
            }
            $nombreArchivo = 'receta_' . ($id ?: 'nueva') . '_' . bin2hex(random_bytes(6)) . '.' . $extensionSubida;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                if ($fotoFinal) {
                    @unlink(__DIR__ . '/../' . $fotoFinal);
                }
                $fotoFinal = 'public/recetas/' . $nombreArchivo;
            } else {
                $errores[] = 'No se pudo guardar la foto en el servidor. Vuelve a intentarlo.';
            }
        } elseif ($eliminarFoto && $fotoFinal) {
            @unlink(__DIR__ . '/../' . $fotoFinal);
            $fotoFinal = null;
        }
    }

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $descripcionFinal = $receta['descripcion'] !== '' ? $receta['descripcion'] : null;
            if ($id) {
                $stmt = $pdo->prepare('UPDATE recetas SET nombre=?, descripcion=?, categoria_id=?, porciones_base=?, preparacion=?, foto=? WHERE id=?');
                $stmt->execute([$receta['nombre'], $descripcionFinal, $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null, $fotoFinal, $id]);
                $pdo->prepare('DELETE FROM ingredientes WHERE receta_id = ?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO recetas (nombre, descripcion, categoria_id, porciones_base, preparacion, foto) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$receta['nombre'], $descripcionFinal, $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null, $fotoFinal]);
                $id = (int) $pdo->lastInsertId();
            }

            // Una consulta preparada por fila de ingrediente es inevitable (cada
            // una necesita su propio lastInsertId() para poder enlazar sus
            // acciones). Pero las acciones sí se acumulan aquí y se insertan
            // todas juntas en un solo INSERT de varias filas al final, en vez
            // de una consulta por cada combinación ingrediente+acción — con
            // varios ingredientes y varias acciones por línea, esto puede ser
            // la diferencia entre un puñado de consultas y varias decenas, lo
            // que se nota sobre todo si el hosting o la conexión a la base de
            // datos tiene algo de latencia.
            $stmtIng = $pdo->prepare('INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, reemplazo, al_gusto, opcional, orden) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $paresAccion = [];
            foreach ($ingredientesNuevos as $orden => $ing) {
                $stmtIng->execute([$id, $ing['ingrediente_id'], $ing['nombre'], $ing['cantidad'], $ing['unidad_id'], $ing['costo_unitario'], $ing['reemplazo'], $ing['al_gusto'], $ing['opcional'], $orden]);
                $nuevoIngId = (int) $pdo->lastInsertId();
                foreach ($ing['acciones'] as $accId) {
                    $paresAccion[] = [$nuevoIngId, $accId];
                }
            }
            if ($paresAccion) {
                $marcadores = implode(',', array_fill(0, count($paresAccion), '(?,?)'));
                $valores = [];
                foreach ($paresAccion as $par) {
                    $valores[] = $par[0];
                    $valores[] = $par[1];
                }
                $pdo->prepare("INSERT INTO ingrediente_accion (receta_ingrediente_id, accion_id) VALUES $marcadores")->execute($valores);
            }

            $pdo->commit();
            flash($id ? 'Receta actualizada.' : 'Receta creada.');
            redirect('index.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errores[] = 'No se pudo guardar la receta. Intenta de nuevo.';
        }
    } else {
        // Conservar lo que el usuario escribió si hubo errores de validación.
        $ingredientes = $ingredientesNuevos ?: $ingredientes;
    }
}

$pageTitle = $id ? 'Editar receta' : 'Nueva receta';
$activeNav = 'recetas';
$breadcrumb = '<a href="index.php">Recetas</a> &nbsp;/&nbsp; <b>' . e($pageTitle) . '</b>';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-head"><div><h1><?= e($pageTitle) ?></h1></div></div>

<?php if ($errores): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errores)) ?></div>
<?php endif; ?>

<div class="card card-pad form-card" style="max-width:760px;">
  <form method="post" enctype="multipart/form-data" id="formReceta">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre de la receta</label>
      <input type="text" id="nombre" name="nombre" required value="<?= e($receta['nombre']) ?>">
    </div>

    <div class="field">
      <label for="descripcion">Descripción</label>
      <textarea id="descripcion" name="descripcion" rows="2" placeholder="Ej. Un clásico dominicano, cremoso y fácil de escalar para grupos grandes."><?= e($receta['descripcion']) ?></textarea>
      <div class="hint">Opcional. Una presentación breve de la receta — se muestra en el listado y al ver la receta, antes de los ingredientes.</div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="categoria_id">Categoría</label>
        <select id="categoria_id" name="categoria_id">
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === (int) $receta['categoria_id'] ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (can($usuarioActual, 'configuracion', 'ver')): ?>
          <div class="hint">¿Falta una categoría? Agrégala en <a href="../configuracion/catalogos.php?tipo=categorias_receta">Configuración</a>.</div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="porciones_base">Porciones base</label>
        <input type="number" id="porciones_base" name="porciones_base" min="1" required value="<?= e((string) $receta['porciones_base']) ?>">
      </div>
    </div>

    <div class="field">
      <label>Foto de referencia</label>
      <?php if (!empty($receta['foto'])): ?>
        <div data-foto-actual>
          <img class="recipe-photo-preview" src="<?= e($base . '/' . $receta['foto']) ?>" alt="Foto de <?= e($receta['nombre']) ?>">
          <div style="margin:8px 0;">
            <button type="button" class="btn btn-danger btn-sm" data-eliminar-foto><?= icon('trash') ?> Eliminar foto</button>
          </div>
        </div>
        <div class="hint" data-foto-marcada style="display:none;color:var(--danger);margin-bottom:8px;">Esta foto se eliminará al guardar los cambios.</div>
        <input type="hidden" name="eliminar_foto" value="0" data-input-eliminar-foto>
      <?php else: ?>
        <div class="recipe-photo-box" style="margin-bottom:8px;">Sin foto todavía</div>
      <?php endif; ?>
      <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
      <div class="hint">Opcional. Así los estudiantes saben cómo debe verse la receta terminada. JPG, PNG o WEBP, hasta 5 MB.</div>
    </div>

    <div class="field">
      <label>Ingredientes (por las porciones base indicadas)</label>
      <div class="ing-row ing-row-labels">
        <span>Cantidad</span><span></span><span>Ingrediente</span><span>Unidad</span><span>Costo/unid</span><span>Monto</span><span></span><span></span><span></span>
      </div>
      <div id="ingRows">
        <?php foreach ($ingredientes as $ing): $esAlGusto = !empty($ing['al_gusto']);
          $accionesFilaIds = $ing['acciones'] ?? [];
          $accionesFilaNombres = array_values(array_intersect_key($accionesNombrePorId, array_flip($accionesFilaIds)));
          $tieneAcciones = count($accionesFilaNombres) > 0;
        ?>
          <div class="ing-row-block" data-ing-row>
            <div class="ing-row">
              <!-- "Al gusto" deja este campo inerte con "readonly", nunca con
                   "disabled": un campo disabled NO se manda en el POST, y como
                   "ing_cantidad[]" es un array plano (sin índice explícito por
                   fila), eso corre uno a la izquierda TODOS los valores de
                   cantidad de las filas siguientes — el bug real reportado
                   ("el ingrediente de abajo no guarda su costo"), porque su
                   cantidad terminaba leyendo el valor de la fila equivocada
                   (o ninguno, quedando en 0). "readonly" sí se envía (vacío),
                   y el servidor igual fuerza cantidad=0 para una fila "al
                   gusto" sin importar qué llegue. -->
              <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad" data-role="ing-cantidad" value="<?= $esAlGusto ? '' : e((string) $ing['cantidad']) ?>" <?= $esAlGusto ? 'readonly' : '' ?>>
              <label class="al-gusto-check"><input type="checkbox" data-role="ing-al-gusto-check" <?= $esAlGusto ? 'checked' : '' ?>> Al gusto</label>
              <input type="text" name="ing_nombre[]" placeholder="Ingrediente" list="catalogoIngredientesList" autocomplete="off" value="<?= e($ing['nombre']) ?>">
              <input type="hidden" name="ing_ingrediente_id[]" data-role="ing-id" value="<?= e((string) ($ing['ingrediente_id'] ?? '')) ?>">
              <select name="ing_unidad_id[]" data-role="ing-unidad" data-prev="<?= (int) ($ing['unidad_id'] ?? 0) ?>">
                <?php foreach ($unidades as $u): ?>
                  <option value="<?= (int) $u['id'] ?>" data-entera="<?= !empty($u['es_entera']) ? '1' : '0' ?>" <?= (int) $u['id'] === (int) ($ing['unidad_id'] ?? 0) ? 'selected' : '' ?>><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <input type="number" step="any" name="ing_costo[]" placeholder="Costo/unid RD$" data-role="ing-costo" value="<?= e((string) $ing['costo_unitario']) ?>">
              <span class="mono ing-monto" data-role="ing-monto">RD$ 0</span>
              <button type="button" class="icon-btn" data-actualizar-catalogo title="Actualizar unidad y costo desde el catálogo"><?= icon('refresh') ?></button>
              <button type="button" class="icon-btn icon-btn-add" data-abrir-modal-ingrediente title="Crear ingrediente nuevo"><?= icon('plus') ?></button>
              <button type="button" class="icon-btn" data-quitar-fila title="Quitar fila"><?= icon('x') ?></button>
            </div>
            <div class="ing-row-extra">
              <label class="opcional-check"><input type="checkbox" name="ing_opcional[]" value="1" <?= !empty($ing['opcional']) ? 'checked' : '' ?>> Opcional</label>
              <input type="text" name="ing_reemplazo[]" placeholder="Reemplazo (opcional, ej. o mantequilla de maní)" list="catalogoIngredientesList" value="<?= e((string) ($ing['reemplazo'] ?? '')) ?>" style="flex:1;min-width:200px;">
              <div class="acciones-dropdown" data-role="acciones-dropdown">
                <button type="button" class="acciones-toggle" data-role="acciones-toggle" data-has-value="<?= $tieneAcciones ? '1' : '0' ?>">
                  <span data-role="acciones-label"><?= e(etiquetaAccionesSeleccionadas($accionesFilaNombres)) ?></span>
                  <?= icon('chevronDown') ?>
                </button>
                <div class="acciones-panel" data-role="acciones-panel" hidden>
                  <?php foreach ($accionesIngrediente as $ac): ?>
                    <label class="accion-item"><input type="checkbox" data-role="ing-accion-check" value="<?= (int) $ac['id'] ?>" <?= in_array((int) $ac['id'], $accionesFilaIds, true) ? 'checked' : '' ?>> <?= e($ac['nombre']) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <input type="hidden" name="ing_al_gusto[]" data-role="ing-al-gusto-hidden" value="<?= $esAlGusto ? '1' : '0' ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="addIngRow" style="margin-top:4px;"><?= icon('plus') ?> Agregar ingrediente</button>
      <div class="hint">Escribe para buscar en el catálogo (autocompleta unidad y costo) o usa el botón <?= icon('plus') ?> para dar de alta uno que no exista todavía. Cantidad y costo se pueden ajustar a mano. Si cambias la unidad de una fila a otra compatible (ej. de Onza a Gramo, o de Litro a Cucharada), el <b>costo/unid</b> se recalcula solo para que el monto siga siendo correcto; si la unidad nueva no es convertible (ej. a Unidad o Lata), el costo hay que ajustarlo a mano. El botón <?= icon('refresh') ?> vuelve a traer el costo actual del catálogo para esa fila. Marca <b>Al gusto</b> cuando la cantidad no se mide (esa línea no entra en el costo total). <b>Opcional</b> es solo informativo (por defecto, toda línea es requerida). <b>Reemplazo</b> es para anotar una alternativa cuando la receta es "esto o lo otro" (ej. "o mantequilla de maní"). <b>Preparación</b> deja marcar uno o más cortes/acciones para esa línea (ej. Espinaca — Cocida y Picada) — se administran desde Configuración. El <b>monto</b> es lo que costaría comprar esa cantidad; si la unidad se compra completa (ej. huevo, manzana, lata), se redondea hacia arriba.</div>
      <div class="ing-total">Costo total estimado de la receta: <span class="mono" id="ingCostoTotal">RD$ 0</span></div>
    </div>

    <div class="field">
      <label for="preparacion">Preparación (pasos a seguir)</label>
      <textarea id="preparacion" name="preparacion" rows="8" placeholder="1. Precalentar el horno a...&#10;2. Mezclar...&#10;3. ..."><?= e($receta['preparacion']) ?></textarea>
      <div class="hint">Opcional. Describe los pasos en el orden en que se deben seguir.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit" id="btnGuardarReceta" data-texto-normal="<?= $id ? 'Guardar cambios' : 'Crear receta' ?>"><?= $id ? 'Guardar cambios' : 'Crear receta' ?></button>
    </div>
  </form>
</div>

<datalist id="catalogoIngredientesList">
  <?php foreach ($catalogoIngredientes as $ci): ?>
    <option value="<?= e($ci['nombre']) ?>"><?= e($ci['icono'] ?: '') ?> <?= e($ci['nombre']) ?> — <?= money(costoPorUnidadUso($ci)) ?>/<?= e($ci['unidad_abrev']) ?></option>
  <?php endforeach; ?>
</datalist>

<template id="ingRowTemplate">
  <div class="ing-row-block" data-ing-row>
    <div class="ing-row">
      <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad" data-role="ing-cantidad">
      <label class="al-gusto-check"><input type="checkbox" data-role="ing-al-gusto-check"> Al gusto</label>
      <input type="text" name="ing_nombre[]" placeholder="Ingrediente" list="catalogoIngredientesList" autocomplete="off">
      <input type="hidden" name="ing_ingrediente_id[]" data-role="ing-id" value="">
      <select name="ing_unidad_id[]" data-role="ing-unidad" data-prev="">
        <?php foreach ($unidades as $u): ?>
          <option value="<?= (int) $u['id'] ?>" data-entera="<?= !empty($u['es_entera']) ? '1' : '0' ?>"><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <input type="number" step="any" name="ing_costo[]" placeholder="Costo/unid RD$" data-role="ing-costo">
      <span class="mono ing-monto" data-role="ing-monto">RD$ 0</span>
      <button type="button" class="icon-btn" data-actualizar-catalogo title="Actualizar unidad y costo desde el catálogo"><?= icon('refresh') ?></button>
      <button type="button" class="icon-btn icon-btn-add" data-abrir-modal-ingrediente title="Crear ingrediente nuevo"><?= icon('plus') ?></button>
      <button type="button" class="icon-btn" data-quitar-fila title="Quitar fila"><?= icon('x') ?></button>
    </div>
    <div class="ing-row-extra">
      <label class="opcional-check"><input type="checkbox" name="ing_opcional[]" value="1"> Opcional</label>
      <input type="text" name="ing_reemplazo[]" placeholder="Reemplazo (opcional, ej. o mantequilla de maní)" list="catalogoIngredientesList" style="flex:1;min-width:200px;">
      <div class="acciones-dropdown" data-role="acciones-dropdown">
        <button type="button" class="acciones-toggle" data-role="acciones-toggle" data-has-value="0">
          <span data-role="acciones-label">Preparación (cortes/acciones)</span>
          <?= icon('chevronDown') ?>
        </button>
        <div class="acciones-panel" data-role="acciones-panel" hidden>
          <?php foreach ($accionesIngrediente as $ac): ?>
            <label class="accion-item"><input type="checkbox" data-role="ing-accion-check" value="<?= (int) $ac['id'] ?>"> <?= e($ac['nombre']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <input type="hidden" name="ing_al_gusto[]" data-role="ing-al-gusto-hidden" value="0">
  </div>
</template>

<!-- Modal: alta rápida de un ingrediente sin salir de la pantalla -->
<div class="modal-backdrop" id="modalNuevoIngrediente" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalIngTitulo">
    <div class="modal-head">
      <h2 id="modalIngTitulo">Nuevo ingrediente</h2>
      <button type="button" class="icon-btn" data-cerrar-modal-ingrediente title="Cerrar"><?= icon('x') ?></button>
    </div>
    <div id="modalIngErrores" class="alert alert-error" hidden></div>
    <form id="formNuevoIngrediente">
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <div class="field-row">
        <div class="field" style="flex:1;">
          <label for="modalIngNombre">Nombre</label>
          <input type="text" id="modalIngNombre" name="nombre" required>
        </div>
        <div class="field" style="max-width:90px;">
          <label for="modalIngIcono">Ícono</label>
          <input type="text" id="modalIngIcono" name="icono" maxlength="8" placeholder="🥕" style="font-size:1.2rem;text-align:center;">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="modalIngCategoria">Categoría</label>
          <select id="modalIngCategoria" name="categoria_id" required>
            <option value="">— Elige una —</option>
            <?php foreach ($categoriasIngrediente as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="modalIngUnidad">Unidad de uso</label>
          <select id="modalIngUnidad" name="unidad_id" required>
            <option value="">— Elige una —</option>
            <?php foreach ($unidades as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="modalIngPrecio">Precio de referencia (RD$ por esa unidad)</label>
        <input type="number" step="any" min="0" id="modalIngPrecio" name="precio_compra" required>
        <div class="hint">Si se compra en un paquete distinto (ej. cartón de huevos), créalo simple aquí y luego ajústalo desde Ingredientes.</div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-secondary" data-cerrar-modal-ingrediente>Cancelar</button>
        <button type="submit" class="btn btn-primary">Agregar ingrediente</button>
      </div>
    </form>
  </div>
</div>

<script>
  document.getElementById('addIngRow').addEventListener('click', function () {
    var tpl = document.getElementById('ingRowTemplate');
    document.getElementById('ingRows').appendChild(tpl.content.cloneNode(true));
  });

  (function () {
    var btnEliminarFoto = document.querySelector('[data-eliminar-foto]');
    if (btnEliminarFoto) {
      btnEliminarFoto.addEventListener('click', function () {
        if (!window.confirm('¿Eliminar esta foto? Se quitará al guardar los cambios de la receta.')) {
          return;
        }
        document.querySelector('[data-foto-actual]').style.display = 'none';
        document.querySelector('[data-foto-marcada]').style.display = 'block';
        document.querySelector('[data-input-eliminar-foto]').value = '1';
      });
    }
  })();

  (function () {
    var CATALOGO = <?= json_encode($catalogoPorNombre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var UNIDADES_INFO = <?= json_encode($unidadesInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    // Índice de CATALOGO por nombre normalizado (sin acentos, mayúsculas ni
    // espacios de más) — mismo criterio que normalizarNombreIngrediente()
    // en includes/helpers.php. Permite reconocer un nombre escrito a mano
    // que no coincide EXACTO con el catálogo (un acento distinto, "uva" en
    // vez de "Uvas", un espacio de más) para no dejar la línea sin
    // enlazar al ingrediente — motivo real del bug reportado con Uvas
    // (sección 28): si de verdad hay más de un ingrediente del catálogo
    // con el mismo nombre normalizado, no se adivina (se deja sin enlazar,
    // igual que antes).
    function normalizarNombreJs(nombre) {
      return (nombre || '')
        .trim()
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
    }
    var CATALOGO_NORM = {};
    Object.keys(CATALOGO).forEach(function (nombreCat) {
      var norm = normalizarNombreJs(nombreCat);
      if (!norm) return;
      if (!CATALOGO_NORM[norm]) CATALOGO_NORM[norm] = [];
      CATALOGO_NORM[norm].push(nombreCat);
    });
    function buscarEnCatalogo(nombre) {
      if (Object.prototype.hasOwnProperty.call(CATALOGO, nombre)) return CATALOGO[nombre];
      var candidatos = CATALOGO_NORM[normalizarNombreJs(nombre)] || [];
      return candidatos.length === 1 ? CATALOGO[candidatos[0]] : null;
    }
    // Id de la unidad de conteo "Unidad" (o null si por algún motivo no
    // existe en este catálogo de unidades) — ver tipoYFactorDeUnidadJs().
    var ID_UNIDAD_CONTEO = <?= $idUnidadConteo !== null ? (int) $idUnidadConteo : 'null' ?>;
    var datalist = document.getElementById('catalogoIngredientesList');
    var modal = document.getElementById('modalNuevoIngrediente');
    var modalNombre = document.getElementById('modalIngNombre');
    var modalCategoria = document.getElementById('modalIngCategoria');
    var modalUnidad = document.getElementById('modalIngUnidad');
    var modalIcono = document.getElementById('modalIngIcono');
    var modalPrecio = document.getElementById('modalIngPrecio');
    var modalErrores = document.getElementById('modalIngErrores');
    var formModal = document.getElementById('formNuevoIngrediente');
    var ingRows = document.getElementById('ingRows');
    var costoTotalEl = document.getElementById('ingCostoTotal');
    var filaActual = null;

    function money(valor) {
      return 'RD$ ' + Math.round(valor).toLocaleString('es-DO');
    }

    // Igual que cantidadDeCompra()/montoLineaReceta() en includes/helpers.php:
    // si la unidad se compra completa (ej. Unidad, Lata), una cantidad
    // fraccionaria (media manzana, medio huevo) redondea hacia arriba porque
    // no se puede comprar esa fracción.
    function cantidadDeCompra(cantidad, esEntera) {
      if (!(cantidad > 0)) return 0;
      return esEntera ? Math.ceil(cantidad - 0.0000001) : cantidad;
    }

    // Igual que tipoYFactorDeUnidad() en includes/helpers.php: tipo_medida y
    // factor_base EFECTIVOS de una unidad. Para casi cualquier unidad es lo
    // que ya trae UNIDADES_INFO; la excepción es la unidad de conteo
    // "Unidad", que por sí sola no tiene tamaño universal (una unidad de
    // fresa no pesa lo mismo que una de guineo) — pero si el ingrediente
    // tiene su propio "peso por unidad" cargado (pesoUnidadG), se trata como
    // si fuera una unidad de masa cuyo factor_base es ese peso.
    function tipoYFactorDeUnidadJs(idUnidad, pesoUnidadG) {
      var u = UNIDADES_INFO[idUnidad];
      if (!u) return { tipo: null, factor: 0 };
      if (u.tipo_medida && u.factor_base) {
        return { tipo: u.tipo_medida, factor: u.factor_base };
      }
      if (pesoUnidadG && ID_UNIDAD_CONTEO !== null && idUnidad === ID_UNIDAD_CONTEO) {
        return { tipo: 'masa', factor: pesoUnidadG };
      }
      return { tipo: null, factor: 0 };
    }

    // Igual que convertirCantidadEntreUnidades() en includes/helpers.php:
    // convierte una cantidad de una unidad a otra. Mismo tipo_medida (masa
    // con masa, o volumen con volumen) usa el factor universal de cada
    // unidad; tipo_medida distinto (masa vs. volumen) solo es posible si se
    // conoce la densidad (g/ml) de ESTE ingrediente en particular (una
    // cucharada de mantequilla no pesa lo mismo que una de harina) — sin
    // ella, devuelve null. La unidad de conteo "Unidad" entra al mismo
    // mecanismo vía tipoYFactorDeUnidadJs() cuando el ingrediente tiene su
    // "peso por unidad" cargado (pesoUnidadG). idUnidadUsoCat/idUnidadCompraCat/
    // contenidoPorCompra (opcionales): igual que $catalogo en la versión PHP
    // — permiten además puentear directo entre la unidad de uso y la de
    // compra del catálogo (ej. Gelatina sin sabor: Cucharadita <-> Paquete),
    // encadenando hacia el resto cuando hace falta.
    function convertirCantidadEntreUnidadesJs(cantidadOrigen, idOrigen, idDestino, densidadGml, pesoUnidadG, idUnidadUsoCat, idUnidadCompraCat, contenidoPorCompra) {
      if (!idOrigen || !idDestino) return null;
      if (idOrigen === idDestino) return cantidadOrigen;

      if (idUnidadUsoCat && idUnidadCompraCat && idUnidadUsoCat !== idUnidadCompraCat && contenidoPorCompra > 0) {
        if (idOrigen === idUnidadCompraCat && idDestino === idUnidadUsoCat) {
          return cantidadOrigen * contenidoPorCompra;
        }
        if (idOrigen === idUnidadUsoCat && idDestino === idUnidadCompraCat) {
          return cantidadOrigen / contenidoPorCompra;
        }
        if (idOrigen === idUnidadCompraCat) {
          return convertirCantidadEntreUnidadesJs(cantidadOrigen * contenidoPorCompra, idUnidadUsoCat, idDestino, densidadGml, pesoUnidadG);
        }
        if (idDestino === idUnidadCompraCat) {
          var enUso = convertirCantidadEntreUnidadesJs(cantidadOrigen, idOrigen, idUnidadUsoCat, densidadGml, pesoUnidadG);
          return enUso !== null ? enUso / contenidoPorCompra : null;
        }
      }

      var origen = tipoYFactorDeUnidadJs(idOrigen, pesoUnidadG);
      var destino = tipoYFactorDeUnidadJs(idDestino, pesoUnidadG);
      if (!origen.tipo || !destino.tipo || !origen.factor || !destino.factor) return null;
      if (origen.tipo === destino.tipo) {
        return cantidadOrigen * (origen.factor / destino.factor);
      }
      if (!densidadGml) return null;
      var base = cantidadOrigen * origen.factor; // gramos (masa) o ml (volumen)
      var baseDestino;
      if (origen.tipo === 'masa' && destino.tipo === 'volumen') {
        baseDestino = base / densidadGml;
      } else if (origen.tipo === 'volumen' && destino.tipo === 'masa') {
        baseDestino = base * densidadGml;
      } else {
        return null;
      }
      return baseDestino / destino.factor;
    }

    // Igual que convertirCostoPorUnidad() en includes/helpers.php: convierte
    // un costo por unidad (ej. RD$/Onza) a su equivalente en otra unidad
    // (ej. RD$/Gramo), apoyándose en convertirCantidadEntreUnidadesJs().
    // Devuelve null si no son convertibles automáticamente — ahí el costo
    // se ajusta a mano.
    function convertirCostoPorUnidad(costoPorUnidadOrigen, idOrigen, idDestino, densidadGml, pesoUnidadG, idUnidadUsoCat, idUnidadCompraCat, contenidoPorCompra) {
      if (!idOrigen || !idDestino) return null;
      if (idOrigen === idDestino) return costoPorUnidadOrigen;
      var equivalencia = convertirCantidadEntreUnidadesJs(1, idOrigen, idDestino, densidadGml, pesoUnidadG, idUnidadUsoCat, idUnidadCompraCat, contenidoPorCompra);
      if (!equivalencia) return null;
      return costoPorUnidadOrigen / equivalencia;
    }

    // Se dispara al cambiar la unidad de una fila: si la fila tiene un
    // costo cargado (del catálogo o ajustado a mano) para la unidad
    // anterior (data-prev), lo convierte a la unidad nueva para que
    // "cantidad × costo" siga siendo correcto en vez de arrastrar el costo
    // de la unidad vieja sin más. Si la conversión no es posible (unidades
    // no compatibles), deja el costo tal cual para que se ajuste a mano.
    function ajustarCostoPorCambioDeUnidad(selectUnidad) {
      var idAnterior = parseInt(selectUnidad.getAttribute('data-prev'), 10) || null;
      var idNuevo = parseInt(selectUnidad.value, 10) || null;
      var fila = selectUnidad.closest('[data-ing-row]');
      var inputCosto = fila ? fila.querySelector('[data-role="ing-costo"]') : null;
      if (inputCosto && idAnterior && idNuevo && idAnterior !== idNuevo) {
        var costoActual = parseFloat(inputCosto.value) || 0;
        var nombreInput = fila ? fila.querySelector('input[name="ing_nombre[]"]') : null;
        var datosFila = nombreInput ? buscarEnCatalogo(nombreInput.value.trim()) : null;
        var densidadFila = datosFila ? datosFila.densidad_g_ml : null;
        var pesoUnidadFila = datosFila ? datosFila.peso_unidad_g : null;
        var costoConvertido = convertirCostoPorUnidad(costoActual, idAnterior, idNuevo, densidadFila, pesoUnidadFila,
          datosFila ? datosFila.unidad_id : null, datosFila ? datosFila.unidad_compra_id : null, datosFila ? datosFila.contenido_por_compra : null);
        if (costoConvertido !== null) {
          inputCosto.value = costoConvertido.toFixed(2);
        }
      }
      selectUnidad.setAttribute('data-prev', idNuevo || '');
    }

    function recalcularFila(fila) {
      var montoEl = fila.querySelector('[data-role="ing-monto"]');
      var hiddenAlGusto = fila.querySelector('[data-role="ing-al-gusto-hidden"]');
      if (hiddenAlGusto && hiddenAlGusto.value === '1') {
        // "Al gusto": no hay cantidad medible, así que no se puede estimar
        // el costo de esta línea — se excluye del total (igual que en
        // costoTotalReceta() en includes/helpers.php).
        if (montoEl) montoEl.textContent = 'Al gusto';
        return 0;
      }
      var cantidad = parseFloat(fila.querySelector('[data-role="ing-cantidad"]').value) || 0;
      var costo = parseFloat(fila.querySelector('[data-role="ing-costo"]').value) || 0;
      var selectUnidad = fila.querySelector('[data-role="ing-unidad"]');
      var opcion = selectUnidad ? selectUnidad.options[selectUnidad.selectedIndex] : null;
      var esEntera = !!(opcion && opcion.getAttribute('data-entera') === '1');
      var monto = cantidadDeCompra(cantidad, esEntera) * costo;
      if (montoEl) montoEl.textContent = money(monto);
      return monto;
    }

    function recalcularTotal() {
      var total = 0;
      ingRows.querySelectorAll('[data-ing-row]').forEach(function (fila) {
        total += recalcularFila(fila);
      });
      if (costoTotalEl) costoTotalEl.textContent = money(total);
    }

    function autocompletarFila(fila, nombreExacto) {
      var datos = buscarEnCatalogo(nombreExacto);
      var hiddenId = fila.querySelector('[data-role="ing-id"]');
      var selectUnidad = fila.querySelector('select[name="ing_unidad_id[]"]');
      var inputCosto = fila.querySelector('input[name="ing_costo[]"]');
      if (datos) {
        if (hiddenId) hiddenId.value = datos.id;
        if (selectUnidad) {
          selectUnidad.value = datos.unidad_id;
          selectUnidad.setAttribute('data-prev', datos.unidad_id);
        }
        if (inputCosto) inputCosto.value = datos.costo_unitario;
      } else if (hiddenId) {
        hiddenId.value = '';
      }
    }

    // Botón de "actualizar" por fila: para una receta ya guardada donde el
    // costo quedó mal (ej. una fila que se guardó antes de que existiera la
    // conversión automática, o el precio del catálogo cambió desde
    // entonces). A diferencia de volver a escribir el nombre del
    // ingrediente (que siempre trae la unidad del catálogo, aunque no sea
    // la que se usó en la receta), este botón respeta la unidad que ya
    // tiene la fila: si es compatible con la del catálogo, solo convierte
    // el costo a esa unidad; si no es convertible, sí vuelve a la unidad y
    // costo del catálogo (igual que autocompletarFila).
    function actualizarDesdeCatalogo(fila) {
      var nombreInput = fila.querySelector('input[name="ing_nombre[]"]');
      var nombre = nombreInput ? nombreInput.value.trim() : '';
      var datos = nombre ? buscarEnCatalogo(nombre) : null;
      if (!datos) {
        window.alert('Este nombre no coincide con ningún ingrediente activo del catálogo, así que no se puede actualizar automáticamente. Revisa que esté escrito igual que en Ingredientes.');
        return;
      }
      var hiddenId = fila.querySelector('[data-role="ing-id"]');
      var selectUnidad = fila.querySelector('[data-role="ing-unidad"]');
      var inputCosto = fila.querySelector('input[name="ing_costo[]"]');
      var idUnidadFila = selectUnidad ? (parseInt(selectUnidad.value, 10) || null) : null;
      if (hiddenId) hiddenId.value = datos.id;
      var costoConvertido = convertirCostoPorUnidad(datos.costo_unitario, datos.unidad_id, idUnidadFila, datos.densidad_g_ml, datos.peso_unidad_g,
        datos.unidad_id, datos.unidad_compra_id, datos.contenido_por_compra);
      if (costoConvertido !== null) {
        if (inputCosto) inputCosto.value = costoConvertido.toFixed(2);
        if (selectUnidad) selectUnidad.setAttribute('data-prev', idUnidadFila || '');
      } else {
        if (selectUnidad) {
          selectUnidad.value = datos.unidad_id;
          selectUnidad.setAttribute('data-prev', datos.unidad_id);
        }
        if (inputCosto) inputCosto.value = datos.costo_unitario;
      }
      recalcularTotal();
    }

    // Texto del botón del menú de "Preparación (cortes/acciones)": igual que
    // etiquetaAccionesSeleccionadas() en recetas/form.php (PHP), para que el
    // texto se vea igual al cargar la página (calculado en el servidor) y al
    // cambiar la selección en el navegador (calculado aquí).
    function actualizarEtiquetaAcciones(dropdown) {
      var labelEl = dropdown.querySelector('[data-role="acciones-label"]');
      var toggleEl = dropdown.querySelector('[data-role="acciones-toggle"]');
      if (!labelEl || !toggleEl) return;
      var nombres = [];
      dropdown.querySelectorAll('[data-role="ing-accion-check"]:checked').forEach(function (chk) {
        nombres.push(chk.closest('label').textContent.trim());
      });
      if (nombres.length === 0) {
        labelEl.textContent = 'Preparación (cortes/acciones)';
        toggleEl.setAttribute('data-has-value', '0');
      } else if (nombres.length <= 2) {
        labelEl.textContent = nombres.join(', ');
        toggleEl.setAttribute('data-has-value', '1');
      } else {
        labelEl.textContent = nombres.slice(0, 2).join(', ') + ' +' + (nombres.length - 2) + ' más';
        toggleEl.setAttribute('data-has-value', '1');
      }
    }

    function cerrarMenuAcciones(dropdown) {
      dropdown.classList.remove('open');
      var panel = dropdown.querySelector('[data-role="acciones-panel"]');
      if (panel) panel.hidden = true;
    }

    document.addEventListener('input', function (e) {
      var nombreInput = e.target.closest('input[name="ing_nombre[]"]');
      if (nombreInput) {
        var filaNombre = nombreInput.closest('[data-ing-row]');
        if (filaNombre) {
          autocompletarFila(filaNombre, nombreInput.value.trim());
          recalcularTotal();
        }
        return;
      }
      if (e.target.closest('[data-role="ing-cantidad"], [data-role="ing-costo"]')) {
        recalcularTotal();
      }
    });

    document.addEventListener('change', function (e) {
      var selectUnidad = e.target.closest('[data-role="ing-unidad"]');
      if (selectUnidad) {
        ajustarCostoPorCambioDeUnidad(selectUnidad);
        recalcularTotal();
        return;
      }
      // "Al gusto": desactiva la cantidad numérica de esa fila (no aplica
      // cuando no se mide) y sincroniza el campo oculto que sí se envía en
      // el POST (el checkbox por sí solo no manda nada si está destildado).
      var chkAlGusto = e.target.closest('[data-role="ing-al-gusto-check"]');
      if (chkAlGusto) {
        var filaAg = chkAlGusto.closest('[data-ing-row]');
        var hiddenAg = filaAg ? filaAg.querySelector('[data-role="ing-al-gusto-hidden"]') : null;
        var inputCantidadAg = filaAg ? filaAg.querySelector('[data-role="ing-cantidad"]') : null;
        if (hiddenAg) hiddenAg.value = chkAlGusto.checked ? '1' : '0';
        if (inputCantidadAg) {
          // "readonly", no "disabled" — un campo disabled no se manda en el
          // POST y rompe la alineación de "ing_cantidad[]" con las demás
          // filas (ver el comentario junto a este campo en el HTML de arriba).
          inputCantidadAg.readOnly = chkAlGusto.checked;
          if (chkAlGusto.checked) inputCantidadAg.value = '';
        }
        recalcularTotal();
        return;
      }
      // Menú desplegable de "Preparación (cortes/acciones)": al marcar o
      // destildar una opción, actualiza el texto del botón para que se vea
      // de un vistazo qué quedó seleccionado, sin cerrar el menú (así se
      // pueden marcar varias seguidas).
      var chkAccion = e.target.closest('[data-role="ing-accion-check"]');
      if (chkAccion) {
        var dropdownAcc = chkAccion.closest('[data-role="acciones-dropdown"]');
        if (dropdownAcc) actualizarEtiquetaAcciones(dropdownAcc);
      }
    });

    document.addEventListener('click', function (e) {
      // Menú desplegable de acciones: cualquier clic fuera de un menú
      // abierto lo cierra (comportamiento normal de un desplegable).
      document.querySelectorAll('[data-role="acciones-dropdown"].open').forEach(function (abierto) {
        if (!abierto.contains(e.target)) {
          cerrarMenuAcciones(abierto);
        }
      });

      var toggleAcc = e.target.closest('[data-role="acciones-toggle"]');
      if (toggleAcc) {
        var dropdownToggle = toggleAcc.closest('[data-role="acciones-dropdown"]');
        if (dropdownToggle) {
          var yaAbierto = dropdownToggle.classList.contains('open');
          // Cerrar cualquier otro menú de acciones que haya quedado abierto
          // en otra fila, para no tener dos abiertos a la vez.
          document.querySelectorAll('[data-role="acciones-dropdown"].open').forEach(cerrarMenuAcciones);
          if (!yaAbierto) {
            dropdownToggle.classList.add('open');
            dropdownToggle.querySelector('[data-role="acciones-panel"]').hidden = false;
          }
        }
        return;
      }

      var btn = e.target.closest('[data-abrir-modal-ingrediente]');
      if (btn) {
        filaActual = btn.closest('[data-ing-row]');
        var nombreInput = filaActual ? filaActual.querySelector('input[name="ing_nombre[]"]') : null;
        modalNombre.value = nombreInput ? nombreInput.value.trim() : '';
        modalCategoria.value = '';
        modalUnidad.value = '';
        modalIcono.value = '';
        modalPrecio.value = '';
        modalErrores.hidden = true;
        modal.hidden = false;
        modalNombre.focus();
        return;
      }
      if (e.target.closest('[data-cerrar-modal-ingrediente]')) {
        modal.hidden = true;
        return;
      }
      var btnQuitar = e.target.closest('[data-quitar-fila]');
      if (btnQuitar) {
        var filaQuitar = btnQuitar.closest('[data-ing-row]');
        if (filaQuitar) filaQuitar.remove();
        recalcularTotal();
        return;
      }
      var btnActualizar = e.target.closest('[data-actualizar-catalogo]');
      if (btnActualizar) {
        var filaActualizar = btnActualizar.closest('[data-ing-row]');
        if (filaActualizar) actualizarDesdeCatalogo(filaActualizar);
      }
    });

    formModal.addEventListener('submit', function (e) {
      e.preventDefault();
      modalErrores.hidden = true;
      var fd = new FormData(formModal);
      fetch('<?= e($base) ?>/ingredientes/crear_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) {
            modalErrores.textContent = data.errores.join(' ');
            modalErrores.hidden = false;
            return;
          }
          var ing = data.ingrediente;
          CATALOGO[ing.nombre] = { id: ing.id, unidad_id: ing.unidad_id, costo_unitario: ing.costo_unitario };
          var opt = document.createElement('option');
          opt.value = ing.nombre;
          datalist.appendChild(opt);
          if (filaActual) {
            var nombreInput = filaActual.querySelector('input[name="ing_nombre[]"]');
            if (nombreInput) nombreInput.value = ing.nombre;
            autocompletarFila(filaActual, ing.nombre);
            recalcularTotal();
          }
          modal.hidden = true;
        })
        .catch(function () {
          modalErrores.textContent = 'No se pudo conectar. Intenta de nuevo.';
          modalErrores.hidden = false;
        });
    });

    // Las acciones/cortes marcados en cada fila se envían como
    // "ing_acciones[N][]" donde N es la posición de esa fila entre las
    // filas de ingrediente en ese momento (0, 1, 2...). Se reasigna justo
    // antes de enviar, recorriendo las filas en el mismo orden del DOM en
    // que el navegador va a mandar el resto de los campos "ing_xxx[]" — así
    // el índice N siempre corresponde a la fila correcta, sin importar
    // cuántas filas se agregaron o quitaron mientras se editaba.
    var formReceta = document.getElementById('formReceta');
    if (formReceta) {
      formReceta.addEventListener('submit', function () {
        var filas = ingRows.querySelectorAll('[data-ing-row]');
        filas.forEach(function (fila, idx) {
          fila.querySelectorAll('[data-role="ing-accion-check"]').forEach(function (chk) {
            chk.name = 'ing_acciones[' + idx + '][]';
          });
        });

        // Al momento de enviar (ya pasó la validación del navegador, así que
        // sí va a mandar el formulario): deja el botón "trabajando" para que
        // los estudiantes no le den varias veces a Crear/Guardar mientras la
        // página está guardando. No se usa preventDefault, así que el envío
        // sigue su curso normal — esto solo cambia cómo se ve el botón.
        var btnGuardar = document.getElementById('btnGuardarReceta');
        if (btnGuardar) {
          btnGuardar.disabled = true;
          btnGuardar.innerHTML = '<span class="btn-spinner"></span> Guardando...';
        }
      });
    }

    recalcularTotal();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
