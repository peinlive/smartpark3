<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/editar.php
// v3n: editar una celda. Permite cambiar nivel, tipo, dueño, permisos, observaciones.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    if (function_exists('flash_set')) flash_set('error', 'ID inválido.');
    redirect('/parqueadero');
}

$st = $pdo->prepare("SELECT c.*, c.nombre_visible AS codigo,
                            n.codigo AS nivel_codigo, a.numero_visible AS apto_dueno_numero
                       FROM celdas c
                       JOIN niveles_parqueadero n ON n.id = c.nivel_id
                  LEFT JOIN apartamentos a ON a.id = c.apto_dueno_id
                      WHERE c.id = :id AND c.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$celda = $st->fetch();
if (!$celda) {
    if (function_exists('flash_set')) flash_set('error', 'Celda no encontrada.');
    redirect('/parqueadero');
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $nivelId      = (int)($_POST['nivel_id'] ?? 0);
    $codigo       = clean_string($_POST['codigo'] ?? '', 20);
    $tipo         = in_array($_POST['tipo'] ?? '', ['comun','privada','moto_comun','libre','movilidad_reducida'], true) ? $_POST['tipo'] : 'comun';
    $aptoDuenoNum = clean_string($_POST['apto_dueno_numero'] ?? '', 20);
    $permiteCarro = !empty($_POST['permite_carro']) ? 1 : 0;
    $permiteMoto  = !empty($_POST['permite_moto'])  ? 1 : 0;
    $observ       = clean_string($_POST['observaciones'] ?? '', 255);

    if ($nivelId < 1) $errores[] = 'El nivel es obligatorio.';
    if ($codigo === '') $errores[] = 'El código es obligatorio.';
    if ($permiteCarro === 0 && $permiteMoto === 0) $errores[] = 'Debe permitir al menos carro o moto.';

    // Validar nivel
    if ($nivelId >= 1) {
        $sn = $pdo->prepare("SELECT id FROM niveles_parqueadero WHERE id = :id AND conjunto_id = :c");
        $sn->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        if (!$sn->fetchColumn()) $errores[] = 'El nivel seleccionado no existe.';
    }

    // Código único (excluyendo este id)
    if ($codigo !== '') {
        $sc = $pdo->prepare("SELECT id FROM celdas
                              WHERE conjunto_id = :c AND nombre_visible = :cd AND id <> :id LIMIT 1");
        $sc->execute([':c' => $conjuntoId, ':cd' => $codigo, ':id' => $id]);
        if ($sc->fetchColumn()) $errores[] = "Ya existe OTRA celda con el código '{$codigo}'.";
    }

    // Apto dueño obligatorio para privadas, opcional para movilidad_reducida, null en otras
    $aptoDuenoId = null;
    if ($tipo === 'privada') {
        if ($aptoDuenoNum === '') {
            $errores[] = 'Las celdas privadas requieren un apartamento dueño.';
        } else {
            $sa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
            $sa->execute([':c' => $conjuntoId, ':n' => $aptoDuenoNum]);
            $aptoDuenoId = (int)$sa->fetchColumn();
            if (!$aptoDuenoId) $errores[] = "El apartamento '{$aptoDuenoNum}' no existe.";
        }
    } elseif ($tipo === 'movilidad_reducida' && $aptoDuenoNum !== '') {
        $sa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $sa->execute([':c' => $conjuntoId, ':n' => $aptoDuenoNum]);
        $aptoDuenoId = (int)$sa->fetchColumn();
        if (!$aptoDuenoId) $errores[] = "El apartamento '{$aptoDuenoNum}' no existe.";
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare("UPDATE celdas SET
                    nivel_id = :nv, nombre_visible = :cd, tipo = :t, apto_dueno_id = :ad,
                    permite_carro = :pc, permite_moto = :pm, observaciones = :ob
                WHERE id = :id AND conjunto_id = :c");
            $up->execute([
                ':nv' => $nivelId, ':cd' => $codigo, ':t' => $tipo, ':ad' => $aptoDuenoId,
                ':pc' => $permiteCarro, ':pm' => $permiteMoto, ':ob' => $observ ?: null,
                ':id' => $id, ':c'  => $conjuntoId,
            ]);
            $pdo->commit();
            if (function_exists('flash_set')) flash_set('ok', "Celda '$codigo' actualizada.");
            // v3o: si vino con ?return= o return_url en POST, volver allí (mantiene filtros)
            $retorno = $_POST['return_url'] ?? ($_GET['return'] ?? '/parqueadero');
            if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
                $retorno = '/parqueadero';
            }
            redirect($retorno);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al actualizar.';
        }
    } else {
        // Repintar con datos POST
        $celda = array_merge($celda, [
            'nivel_id' => $nivelId, 'codigo' => $codigo, 'tipo' => $tipo,
            'apto_dueno_numero' => $aptoDuenoNum,
            'permite_carro' => $permiteCarro, 'permite_moto' => $permiteMoto,
            'observaciones' => $observ,
        ]);
    }
}

$niveles = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$_pageTitle = 'Editar celda ' . $celda['codigo'];
include INCLUDES_PATH . '/header.php';
?>

<style>
.form-celda{max-width:600px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.form-celda .form-row{margin-bottom:14px;}
.form-celda label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.form-celda input,.form-celda select,.form-celda textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.form-celda .form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-celda .help{font-size:12px;color:#6b7280;margin-top:2px;}
.form-celda .checkboxes{display:flex;gap:16px;}
.form-celda .checkboxes label{font-size:14px;display:flex;align-items:center;gap:6px;font-weight:normal;}
.form-celda .checkboxes input{width:auto;}
#priv-block{display:none;background:#dbeafe;padding:12px;border-radius:5px;margin-top:4px;}
#priv-block.show{display:block;}
</style>

<div class="page-head">
    <h1 class="page-head__title">Editar celda <?= e($celda['codigo']) ?></h1>
</div>

<div class="toolbar">
    <a class="btn" href="<?= e($_GET['return'] ?? url('/parqueadero')) ?>">← Volver</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="form-celda">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="return_url" value="<?= e($_GET['return'] ?? '') ?>">

    <div class="form-grid-2">
        <div class="form-row">
            <label>Nivel *</label>
            <select name="nivel_id" required>
                <?php foreach ($niveles as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= (int)$celda['nivel_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                        <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label>Código *</label>
            <input type="text" name="codigo" maxlength="20" required value="<?= e($celda['codigo']) ?>">
        </div>
    </div>

    <div class="form-row">
        <label>Tipo *</label>
        <select name="tipo" id="tipo-select" onchange="togglePrivBlock()">
            <?php foreach ([
                'comun'              => '🌐 Común',
                'privada'            => '🔒 Privada (asignada a un apto)',
                'moto_comun'         => '🏍️ Moto común',
                'libre'              => '🆓 Libre',
                'movilidad_reducida' => '♿ Movilidad reducida',
            ] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $celda['tipo'] === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <div id="priv-block">
            <label id="priv-label">Apartamento dueño *</label>
            <input type="text" name="apto_dueno_numero" maxlength="20" placeholder="ej: 1502"
                   value="<?= e($celda['apto_dueno_numero'] ?? '') ?>">
            <div class="help" id="priv-help">Obligatorio para celdas privadas.</div>
        </div>
    </div>

    <div class="form-row">
        <label>Permite</label>
        <div class="checkboxes">
            <label><input type="checkbox" name="permite_carro" value="1" <?= (int)$celda['permite_carro'] === 1 ? 'checked' : '' ?>> 🚗 Carro</label>
            <label><input type="checkbox" name="permite_moto"  value="1" <?= (int)$celda['permite_moto']  === 1 ? 'checked' : '' ?>> 🏍️ Moto</label>
        </div>
    </div>

    <div class="form-row">
        <label>Observaciones</label>
        <textarea name="observaciones" maxlength="255" rows="2"><?= e($celda['observaciones']) ?></textarea>
    </div>

    <button type="submit" class="btn btn--primary">Guardar cambios</button>
</form>

<script>
function togglePrivBlock() {
    var sel = document.getElementById('tipo-select');
    var blk = document.getElementById('priv-block');
    var lbl = document.getElementById('priv-label');
    var hlp = document.getElementById('priv-help');
    if (sel.value === 'privada') {
        blk.classList.add('show');
        lbl.innerHTML = 'Apartamento dueño *';
        hlp.innerHTML = 'Obligatorio para celdas privadas.';
    } else if (sel.value === 'movilidad_reducida') {
        blk.classList.add('show');
        lbl.innerHTML = 'Apartamento dueño (opcional)';
        hlp.innerHTML = 'Solo si una persona específica la usa permanentemente.';
    } else {
        blk.classList.remove('show');
    }
}
togglePrivBlock();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
