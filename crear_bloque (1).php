<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/crear_bloque.php
// v3q: crear varios cuartos en bloque con prefijo + rango.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];
$preview = null;
$creados = 0;

function genCodigosCU($prefijo, $desde, $hasta, $padding) {
    $out = [];
    for ($i = $desde; $i <= $hasta; $i++) {
        $num = $padding > 0 ? str_pad((string)$i, $padding, '0', STR_PAD_LEFT) : (string)$i;
        $out[] = $prefijo . $num;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $accion  = $_POST['accion'] ?? 'preview';
    $nivelId = (int)($_POST['nivel_id'] ?? 0);
    $prefijo = clean_string($_POST['prefijo'] ?? '', 20);
    $desde   = max(1,   (int)($_POST['desde'] ?? 1));
    $hasta   = max($desde, (int)($_POST['hasta'] ?? 1));
    $padding = max(0, min(5, (int)($_POST['padding'] ?? 0)));
    $areaTxt = str_replace(',', '.', trim((string)($_POST['area_m2'] ?? '')));
    $area    = $areaTxt !== '' ? (float)$areaTxt : null;

    if ($prefijo === '')      $errores[] = 'El prefijo es obligatorio.';
    if ($hasta - $desde + 1 > 500) $errores[] = 'Máximo 500 cuartos por bloque.';

    if ($nivelId > 0) {
        $st = $pdo->prepare("SELECT id, codigo FROM niveles_parqueadero
                              WHERE id = :id AND conjunto_id = :c AND activo = 1");
        $st->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        $nivel = $st->fetch();
        if (!$nivel) $errores[] = 'El nivel no existe.';
    } else {
        $nivelId = null;
        $nivel = ['codigo' => '(sin nivel)'];
    }

    if (empty($errores)) {
        $codigosGen = genCodigosCU($prefijo, $desde, $hasta, $padding);

        $placeholders = [];
        $params = [':c' => $conjuntoId];
        foreach ($codigosGen as $i => $cod) {
            $placeholders[] = ':cod' . $i;
            $params[':cod' . $i] = $cod;
        }
        $inList = implode(',', $placeholders);
        $sql = "SELECT codigo FROM cuartos_utiles WHERE conjunto_id = :c AND codigo IN ($inList)";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $existentes = $st->fetchAll(PDO::FETCH_COLUMN);

        $aCrear = array_values(array_diff($codigosGen, $existentes));

        if ($accion === 'crear') {
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO cuartos_utiles
                        (conjunto_id, nivel_id, codigo, area_m2, activo)
                    VALUES (:c, :nv, :cd, :ar, 1)");
                foreach ($aCrear as $cod) {
                    $ins->execute([
                        ':c' => $conjuntoId, ':nv' => $nivelId,
                        ':cd' => $cod, ':ar' => $area,
                    ]);
                    $creados++;
                }
                $pdo->commit();
                if (function_exists('flash_set')) {
                    $msg = "Se crearon $creados cuarto(s) en {$nivel['codigo']}.";
                    if (!empty($existentes)) $msg .= " Se saltaron " . count($existentes) . " ya existentes.";
                    flash_set('ok', $msg);
                }
                redirect('/cuartos');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al crear el bloque.';
            }
        } else {
            $preview = [
                'nivel_codigo' => $nivel['codigo'],
                'total'        => count($codigosGen),
                'crear'        => $aCrear,
                'existentes'   => $existentes,
                'codigos'      => $codigosGen,
            ];
        }
    }
}

$niveles = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$_pageTitle = 'Crear cuartos en bloque';
include INCLUDES_PATH . '/header.php';
?>

<style>
.form-bloque{max-width:780px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.form-bloque .form-row{margin-bottom:14px;}
.form-bloque label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.form-bloque input,.form-bloque select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.form-bloque .grid-3{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px;}
.form-bloque .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media (max-width:600px){.form-bloque .grid-3,.form-bloque .grid-2{grid-template-columns:1fr;}}
.form-bloque .help{font-size:12px;color:#6b7280;margin-top:2px;}
.preview-box{margin-top:20px;background:#fff;border:2px solid #3b82f6;border-radius:8px;padding:16px;}
.preview-box h3{margin:0 0 10px 0;font-size:15px;color:#1e3a8a;}
.preview-codigos{display:flex;flex-wrap:wrap;gap:6px;max-height:180px;overflow-y:auto;margin-top:8px;background:#f9fafb;padding:10px;border-radius:5px;}
.codigo-tag{padding:3px 8px;background:#dbeafe;color:#1e3a8a;border-radius:4px;font-size:12px;font-family:monospace;}
.codigo-tag.dup{background:#fee2e2;color:#991b1b;text-decoration:line-through;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📦 Crear cuartos en bloque</h1>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/cuartos') ?>">← Volver</a>
    <a class="btn" href="<?= url('/cuartos/crear') ?>">+ Crear individual</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="form-bloque">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="accion" value="preview">

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

    <div class="grid-3">
        <div class="form-row">
            <label>Prefijo *</label>
            <input type="text" name="prefijo" maxlength="20" required placeholder="CU-, CB-"
                   value="<?= e($_POST['prefijo'] ?? '') ?>">
            <div class="help">Ej: "CU-" generará CU-1, CU-2...</div>
        </div>
        <div class="form-row">
            <label>Desde *</label>
            <input type="number" name="desde" min="1" max="9999" required value="<?= (int)($_POST['desde'] ?? 1) ?>">
        </div>
        <div class="form-row">
            <label>Hasta *</label>
            <input type="number" name="hasta" min="1" max="9999" required value="<?= (int)($_POST['hasta'] ?? 20) ?>">
        </div>
        <div class="form-row">
            <label>Padding</label>
            <select name="padding">
                <option value="0" <?= (int)($_POST['padding'] ?? 0) === 0 ? 'selected' : '' ?>>Sin (1,2,3)</option>
                <option value="2" <?= (int)($_POST['padding'] ?? 0) === 2 ? 'selected' : '' ?>>2 dígitos (01,02)</option>
                <option value="3" <?= (int)($_POST['padding'] ?? 0) === 3 ? 'selected' : '' ?>>3 dígitos (001,002)</option>
            </select>
        </div>
    </div>

    <div class="form-row">
        <label>Área m² (opcional, se aplica a todos)</label>
        <input type="text" name="area_m2" placeholder="3.5" value="<?= e($_POST['area_m2'] ?? '') ?>">
    </div>

    <button type="submit" class="btn btn--primary">👁️ Ver vista previa</button>
</form>

<?php if ($preview): ?>
<div class="preview-box">
    <h3>Vista previa: <?= count($preview['crear']) ?> cuarto(s) a crear en <strong><?= e($preview['nivel_codigo']) ?></strong></h3>
    <?php if (!empty($preview['existentes'])): ?>
        <div class="flash flash--error" style="margin-bottom:10px">
            ⚠️ <strong><?= count($preview['existentes']) ?> ya existen</strong> y se saltarán: <?= e(implode(', ', $preview['existentes'])) ?>
        </div>
    <?php endif; ?>

    <div class="preview-codigos">
        <?php foreach ($preview['codigos'] as $c):
            $dup = in_array($c, $preview['existentes'], true); ?>
            <span class="codigo-tag <?= $dup ? 'dup' : '' ?>"><?= e($c) ?></span>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($preview['crear'])): ?>
        <form method="POST" style="margin-top:14px" onsubmit="return confirm('¿Confirmar creación de <?= count($preview['crear']) ?> cuarto(s)?');">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="accion"   value="crear">
            <input type="hidden" name="nivel_id" value="<?= (int)($_POST['nivel_id'] ?? 0) ?>">
            <input type="hidden" name="prefijo"  value="<?= e($_POST['prefijo']) ?>">
            <input type="hidden" name="desde"    value="<?= (int)$_POST['desde'] ?>">
            <input type="hidden" name="hasta"    value="<?= (int)$_POST['hasta'] ?>">
            <input type="hidden" name="padding"  value="<?= (int)$_POST['padding'] ?>">
            <input type="hidden" name="area_m2"  value="<?= e($_POST['area_m2'] ?? '') ?>">
            <button type="submit" class="btn btn--primary">✅ Confirmar y crear <?= count($preview['crear']) ?> cuarto(s)</button>
        </form>
    <?php else: ?>
        <div class="flash flash--error" style="margin-top:10px">Todos los cuartos ya existen. Nada que crear.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
