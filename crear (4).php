<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/crear.php
// v3q: crear un cuarto útil.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

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
    if (mb_strlen($codigo) > 30) $errores[] = 'Código máximo 30 caracteres.';
    if ($area !== null && ($area < 0 || $area > 9999.99)) $errores[] = 'Área debe estar entre 0 y 9999.99 m².';

    // Nivel: opcional pero si viene debe existir
    if ($nivelId > 0) {
        $st = $pdo->prepare("SELECT id FROM niveles_parqueadero
                              WHERE id = :id AND conjunto_id = :c AND activo = 1");
        $st->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        if (!$st->fetchColumn()) $errores[] = 'El nivel seleccionado no existe.';
    } else {
        $nivelId = null;
    }

    // Código único
    if ($codigo !== '') {
        $st = $pdo->prepare("SELECT id FROM cuartos_utiles
                              WHERE conjunto_id = :c AND codigo = :cd LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':cd' => $codigo]);
        if ($st->fetchColumn()) $errores[] = "Ya existe un cuarto con el código '$codigo'.";
    }

    // Apto dueño: opcional
    $aptoDuenoId = null;
    if ($aptoNum !== '') {
        $st = $pdo->prepare("SELECT id FROM apartamentos
                              WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':n' => $aptoNum]);
        $aptoDuenoId = (int)$st->fetchColumn();
        if (!$aptoDuenoId) $errores[] = "El apartamento '$aptoNum' no existe.";
    }

    if (empty($errores)) {
        try {
            $ins = $pdo->prepare("INSERT INTO cuartos_utiles
                    (conjunto_id, nivel_id, codigo, apto_dueno_id, area_m2, activo, observaciones)
                VALUES (:c, :nv, :cd, :ad, :ar, 1, :ob)");
            $ins->execute([
                ':c'  => $conjuntoId, ':nv' => $nivelId, ':cd' => $codigo,
                ':ad' => $aptoDuenoId, ':ar' => $area, ':ob' => $obs ?: null,
            ]);
            if (function_exists('flash_set')) flash_set('ok', "Cuarto '$codigo' creado.");
            $retorno = $_POST['return_url'] ?? ($_GET['return'] ?? '/cuartos');
            if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
                $retorno = '/cuartos';
            }
            redirect($retorno);
        } catch (Exception $e) {
            $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al guardar.';
        }
    }
}

$niveles = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$_pageTitle = 'Nuevo cuarto útil';
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

<div class="page-head"><h1 class="page-head__title">🚪 Nuevo cuarto útil</h1></div>

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
            <input type="text" name="codigo" maxlength="30" required placeholder="CU-101, CU-A-15..."
                   value="<?= e($_POST['codigo'] ?? '') ?>">
            <div class="help">Debe ser único en el conjunto.</div>
        </div>
        <div class="form-row">
            <label>Nivel (opcional)</label>
            <select name="nivel_id">
                <option value="">— Sin nivel —</option>
                <?php foreach ($niveles as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= (int)($_POST['nivel_id'] ?? 0) === (int)$n['id'] ? 'selected' : '' ?>>
                        <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Apto dueño (opcional)</label>
            <input type="text" name="apto_dueno_numero" maxlength="20" placeholder="ej: 1502"
                   value="<?= e($_POST['apto_dueno_numero'] ?? '') ?>">
            <div class="help">Si el cuarto está atado a un apartamento.</div>
        </div>
        <div class="form-row">
            <label>Área m² (opcional)</label>
            <input type="text" name="area_m2" placeholder="3.5"
                   value="<?= e($_POST['area_m2'] ?? '') ?>">
            <div class="help">Metros cuadrados. Ejemplo: 3.5</div>
        </div>
    </div>

    <div class="form-row">
        <label>Observaciones</label>
        <textarea name="observaciones" maxlength="255" rows="2"><?= e($_POST['observaciones'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn--primary">Crear cuarto</button>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
