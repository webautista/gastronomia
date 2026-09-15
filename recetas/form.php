<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$base = '..';
$usuarioActual = requireLogin($base);
$id = intOrNull($_GET['id'] ?? null);
requirePermission($usuarioActual, 'recetas', $id ? 'editar' : 'crear', $base);

$receta = ['nombre' => '', 'categoria_id' => '', 'porciones_base' => '', 'preparacion' => '', 'foto' => null];
$ingredientes = [['ingrediente_id' => '', 'nombre' => '', 'cantidad' => '', 'unidad_id' => '', 'costo_unitario' => '']];
$errores = [];

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
    ];
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
        $ingredientes = $filas;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $receta['nombre']         = trim($_POST['nombre'] ?? '');
    $receta['categoria_id']   = intOrNull($_POST['categoria_id'] ?? null);
    $receta['porciones_base'] = intOrNull($_POST['porciones_base'] ?? null);
    $receta['preparacion']    = trim($_POST['preparacion'] ?? '');

    $ingNombres        = $_POST['ing_nombre'] ?? [];
    $ingCantidad       = $_POST['ing_cantidad'] ?? [];
    $ingUnidad         = $_POST['ing_unidad_id'] ?? [];
    $ingCosto          = $_POST['ing_costo'] ?? [];
    $ingIngredienteId  = $_POST['ing_ingrediente_id'] ?? [];

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
        $ingredientesNuevos[] = [
            'ingrediente_id' => $ingredienteId,
            'nombre'         => $nombreIng,
            'cantidad'       => (float) ($ingCantidad[$i] ?? 0),
            'unidad_id'      => $unidadId,
            'costo_unitario' => (float) ($ingCosto[$i] ?? 0),
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
            $directorioDestino = __DIR__ . '/../assets/uploads/recetas';
            if (!is_dir($directorioDestino)) {
                mkdir($directorioDestino, 0775, true);
            }
            $nombreArchivo = 'receta_' . ($id ?: 'nueva') . '_' . bin2hex(random_bytes(6)) . '.' . $extensionSubida;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $directorioDestino . '/' . $nombreArchivo)) {
                if ($fotoFinal) {
                    @unlink(__DIR__ . '/../' . $fotoFinal);
                }
                $fotoFinal = 'assets/uploads/recetas/' . $nombreArchivo;
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
            if ($id) {
                $stmt = $pdo->prepare('UPDATE recetas SET nombre=?, categoria_id=?, porciones_base=?, preparacion=?, foto=? WHERE id=?');
                $stmt->execute([$receta['nombre'], $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null, $fotoFinal, $id]);
                $pdo->prepare('DELETE FROM ingredientes WHERE receta_id = ?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO recetas (nombre, categoria_id, porciones_base, preparacion, foto) VALUES (?,?,?,?,?)');
                $stmt->execute([$receta['nombre'], $receta['categoria_id'], $receta['porciones_base'], $receta['preparacion'] !== '' ? $receta['preparacion'] : null, $fotoFinal]);
                $id = (int) $pdo->lastInsertId();
            }

            $stmtIng = $pdo->prepare('INSERT INTO ingredientes (receta_id, ingrediente_id, nombre, cantidad, unidad_id, costo_unitario, orden) VALUES (?,?,?,?,?,?,?)');
            foreach ($ingredientesNuevos as $orden => $ing) {
                $stmtIng->execute([$id, $ing['ingrediente_id'], $ing['nombre'], $ing['cantidad'], $ing['unidad_id'], $ing['costo_unitario'], $orden]);
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
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label for="nombre">Nombre de la receta</label>
      <input type="text" id="nombre" name="nombre" required value="<?= e($receta['nombre']) ?>">
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
        <span>Ingrediente</span><span>Cantidad</span><span>Unidad</span><span>Costo/unid</span><span>Monto</span><span></span><span></span><span></span>
      </div>
      <div id="ingRows">
        <?php foreach ($ingredientes as $ing): ?>
          <div class="ing-row" data-ing-row>
            <input type="text" name="ing_nombre[]" placeholder="Ingrediente" list="catalogoIngredientesList" autocomplete="off" value="<?= e($ing['nombre']) ?>">
            <input type="hidden" name="ing_ingrediente_id[]" data-role="ing-id" value="<?= e((string) ($ing['ingrediente_id'] ?? '')) ?>">
            <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad" data-role="ing-cantidad" value="<?= e((string) $ing['cantidad']) ?>">
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
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="addIngRow" style="margin-top:4px;"><?= icon('plus') ?> Agregar ingrediente</button>
      <div class="hint">Escribe para buscar en el catálogo (autocompleta unidad y costo) o usa el botón <?= icon('plus') ?> para dar de alta uno que no exista todavía. Cantidad y costo se pueden ajustar a mano. Si cambias la unidad de una fila a otra compatible (ej. de Onza a Gramo, o de Litro a Cucharada), el <b>costo/unid</b> se recalcula solo para que el monto siga siendo correcto; si la unidad nueva no es convertible (ej. a Unidad o Lata), el costo hay que ajustarlo a mano. El botón <?= icon('refresh') ?> vuelve a traer el costo actual del catálogo para esa fila (útil en una receta ya guardada, si el precio del catálogo cambió o si la fila quedó con un costo mal convertido de antes). El <b>monto</b> es lo que costaría comprar esa cantidad; si la unidad se compra completa (ej. huevo, manzana, lata), se redondea hacia arriba — media manzana igual cuenta como una manzana comprada.</div>
      <div class="ing-total">Costo total estimado de la receta: <span class="mono" id="ingCostoTotal">RD$ 0</span></div>
    </div>

    <div class="field">
      <label for="preparacion">Preparación (pasos a seguir)</label>
      <textarea id="preparacion" name="preparacion" rows="8" placeholder="1. Precalentar el horno a...&#10;2. Mezclar...&#10;3. ..."><?= e($receta['preparacion']) ?></textarea>
      <div class="hint">Opcional. Describe los pasos en el orden en que se deben seguir.</div>
    </div>

    <div class="form-actions">
      <a class="btn btn-secondary" href="index.php">Cancelar</a>
      <button class="btn btn-primary" type="submit"><?= $id ? 'Guardar cambios' : 'Crear receta' ?></button>
    </div>
  </form>
</div>

<datalist id="catalogoIngredientesList">
  <?php foreach ($catalogoIngredientes as $ci): ?>
    <option value="<?= e($ci['nombre']) ?>"><?= e($ci['icono'] ?: '') ?> <?= e($ci['nombre']) ?> — <?= money(costoPorUnidadUso($ci)) ?>/<?= e($ci['unidad_abrev']) ?></option>
  <?php endforeach; ?>
</datalist>

<template id="ingRowTemplate">
  <div class="ing-row" data-ing-row>
    <input type="text" name="ing_nombre[]" placeholder="Ingrediente" list="catalogoIngredientesList" autocomplete="off">
    <input type="hidden" name="ing_ingrediente_id[]" data-role="ing-id" value="">
    <input type="number" step="any" name="ing_cantidad[]" placeholder="Cantidad" data-role="ing-cantidad">
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

    // Igual que convertirCostoPorUnidad() en includes/helpers.php: convierte
    // un costo por unidad (ej. RD$/Onza) a su equivalente en otra unidad
    // (ej. RD$/Gramo) cuando ambas son del mismo tipo de medida (masa o
    // volumen) y tienen su factor de conversión cargado. Devuelve null si no
    // son convertibles automáticamente (ej. una es masa y la otra es una
    // unidad de conteo como Unidad o Lata) — ahí el costo se ajusta a mano.
    function convertirCostoPorUnidad(costoPorUnidadOrigen, idOrigen, idDestino) {
      if (!idOrigen || !idDestino) return null;
      if (idOrigen === idDestino) return costoPorUnidadOrigen;
      var uo = UNIDADES_INFO[idOrigen], ud = UNIDADES_INFO[idDestino];
      if (!uo || !ud || !uo.tipo_medida || !ud.tipo_medida || uo.tipo_medida !== ud.tipo_medida) return null;
      if (!uo.factor_base || !ud.factor_base) return null;
      return costoPorUnidadOrigen * (ud.factor_base / uo.factor_base);
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
        var costoConvertido = convertirCostoPorUnidad(costoActual, idAnterior, idNuevo);
        if (costoConvertido !== null) {
          inputCosto.value = costoConvertido.toFixed(2);
        }
      }
      selectUnidad.setAttribute('data-prev', idNuevo || '');
    }

    function recalcularFila(fila) {
      var cantidad = parseFloat(fila.querySelector('[data-role="ing-cantidad"]').value) || 0;
      var costo = parseFloat(fila.querySelector('[data-role="ing-costo"]').value) || 0;
      var selectUnidad = fila.querySelector('[data-role="ing-unidad"]');
      var opcion = selectUnidad ? selectUnidad.options[selectUnidad.selectedIndex] : null;
      var esEntera = !!(opcion && opcion.getAttribute('data-entera') === '1');
      var monto = cantidadDeCompra(cantidad, esEntera) * costo;
      var montoEl = fila.querySelector('[data-role="ing-monto"]');
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
      var datos = CATALOGO[nombreExacto];
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
      var datos = nombre ? CATALOGO[nombre] : null;
      if (!datos) {
        window.alert('Este nombre no coincide con ningún ingrediente activo del catálogo, así que no se puede actualizar automáticamente. Revisa que esté escrito igual que en Ingredientes.');
        return;
      }
      var hiddenId = fila.querySelector('[data-role="ing-id"]');
      var selectUnidad = fila.querySelector('[data-role="ing-unidad"]');
      var inputCosto = fila.querySelector('input[name="ing_costo[]"]');
      var idUnidadFila = selectUnidad ? (parseInt(selectUnidad.value, 10) || null) : null;
      if (hiddenId) hiddenId.value = datos.id;
      var costoConvertido = convertirCostoPorUnidad(datos.costo_unitario, datos.unidad_id, idUnidadFila);
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
      }
    });

    document.addEventListener('click', function (e) {
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

    recalcularTotal();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
