<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/editar.php
// v3q: editar cuarto útil.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    if (function_exists('flash_set')) flash_set('error', 'ID inválido.');
    redirect('/cuartos');
}

$st = $pdo->prepare("SELECT c.*, a.numero_visible AS apto_dueno_numero
                       FROM cuartos_utiles c
                  LEFT JOIN apartamentos a ON a.id = c.apto_dueno_id
                      WHERE c.id = :id AND c.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$cuarto = $st->fetch();
if (!$cuarto) {
    if (function_exists('flash_set')) flash_set('error', 'Cuarto no encontrado.');
    redirect('/cuartos');
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $codigo   = clean_string($_POST['codigo'] ?? '', 30);
    $nivelId  = (int)($_POST['nivel_id'] ?? 0);
    $aptoNum  = clean_string($_POST['apto_dueno_numero'] ?? '', 20);
    $areaTxt  = str_replace(',', '.', trim((string)($_POST['area_m2'] ?? '')));
    $area     = $areaTxt !== '' ? (float)$areaTxt : null;
    $obs      = clean_string($_POST['observaciones'] ?? '', 255);

    if ($codigo === '') $errores[] = 'El código es obligatorio.';
    if ($area !== null && ($area < 0 || $area > 9999.99)) $errores[] = 'Área debe estar entre 0 y 9999.99 m².';

    if ($nivelId > 0) {
        $sn = $pdo->prepare("SELECT id FROM niveles_parqueadero WHERE id = :id AND conjunto_id = :c");
        $sn->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        if (!$sn->fetchColumn()) $errores[] = 'El nivel seleccionado no existe.';
    } else {
        $nivelId = null;
    }

    if ($codigo !== '') {
        $sc = $pdo->prepare("SELECT id FROM cuartos_utiles
                              WHERE conjunto_id = :c AND codigo = :cd AND id <> :id LIMIT 1");
        $sc->execute([':c' => $conjuntoId, ':cd' => $codigo, ':id' => $id]);
        if ($sc->fetchColumn()) $errores[] = "Ya existe OTRO cuarto con el código '$codigo'.";
    }

    $aptoDuenoId = null;
    if ($aptoNum !== '') {
        $sa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $sa->execute([':c' => $conjuntoId, ':n' => $aptoNum]);
        $aptoDuenoId = (int)$sa->fetchColumn();
        if (!$aptoDuenoId) $errores[] = "El apartamento '$aptoNum' no existe.";
    }

    if (empty($errores)) {
        try {
            $up = $pdo->prepare("UPDATE cuartos_utiles SET
                    nivel_id = :nv, codigo = :cd, apto_dueno_id = :ad,
                    area_m2 = :ar, observaciones = :ob
                WHERE id = :id AND conjunto_id = :c");
            $up->execute([
                ':nv' => $nivelId, ':cd' => $codigo, ':ad' => $aptoDuenoId,
                ':ar' => $area, ':ob' => $obs ?: null,
                ':id' => $id, ':c' => $conjuntoId,
            ]);
            if (function_exists('flash_set')) flash_set('ok', "Cuarto '$codigo' actualizado.");
            $retorno = $_POST['return_url'] ?? ($_GET['return'] ?? '/cuartos');
            if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
                $retorno = '/cuartos';
            }
            redirect($retorno);
        } catch (Exception $e) {
            $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al actualizar.';
        }
    } else {
        // Repintar
        $cuarto = array_merge($cuarto, [
            'codigo' => $codigo, 'nivel_id' => $nivelId, 'apto_dueno_numero' => $aptoNum,
            'area_m2' => $area, 'observaciones' => $obs,
        ]);
    }
}

$niveles = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$_pageTitle = 'Editar cuarto ' . $cuarto['codigo'];
include INCLUDES_PATH . '/header.php';
?>

<style>
.form-cu{max-width:600px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.form-cu .form-row{margin-bottom:14px;}
.form-cu label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.form-cu input,.form-cu select,.form-cu textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.form-cu .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-cu .help{font-size:12px;color:#6b7280;margin-top:2px;}
</style>

<div class="page-head"><h1 class="page-head__title">Editar cuarto <?= e($cuarto['codigo']) ?></h1></div>

<div class="toolbar">
    <a class="btn" href="<?= e($_GET['return'] ?? url('/cuartos')) ?>">← Volver</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="form-cu">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="return_url" value="<?= e($_GET['return'] ?? '') ?>">

    <div class="grid-2">
        <div class="form-row">
            <label>Código *</label>
            <input type="text" name="codigo" maxlength="30" required value="<?= e($cuarto['codigo']) ?>">
        </div>
        <div class="form-row">
            <label>Nivel (opcional)</label>
            <select name="nivel_id">
                <option value="">— Sin nivel —</option>
                <?php foreach ($niveles as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= (int)$cuarto['nivel_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                        <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Apto dueño (opcional)</label>
            <input type="text" name="apto_dueno_numero" maxlength="20" value="<?= e($cuarto['apto_dueno_numero']) ?>">
            <div class="help">Deja vacío para desvincular.</div>
        </div>
        <div class="form-row">
            <label>Área m²</label>
            <input type="text" name="area_m2" value="<?= $cuarto['area_m2'] !== null ? e($cuarto['area_m2']) : '' ?>">
        </div>
    </div>

    <div class="form-row">
        <label>Observaciones</label>
        <textarea name="observaciones" maxlength="255" rows="2"><?= e($cuarto['observaciones']) ?></textarea>
    </div>

    <button type="submit" class="btn btn--primary">Guardar cambios</button>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
