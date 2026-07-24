<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/crear_bloque.php
// v3n: crear celdas EN BLOQUE. Ejemplo: prefijo="A-", desde=1, hasta=50 →
//      crea 50 celdas: A-1, A-2, ..., A-50. Útil para alta inicial del parqueadero.
//      También permite preview antes de confirmar.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];
$preview = null;
$creados = 0;
$saltados = [];

function gen_codigos($prefijo, $desde, $hasta, $padding) {
    $codigos = [];
    for ($i = $desde; $i <= $hasta; $i++) {
        $num = $padding > 0 ? str_pad((string)$i, $padding, '0', STR_PAD_LEFT) : (string)$i;
        $codigos[] = $prefijo . $num;
    }
    return $codigos;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $accion       = $_POST['accion'] ?? 'preview';
    $nivelId      = (int)($_POST['nivel_id'] ?? 0);
    $prefijo      = clean_string($_POST['prefijo'] ?? '', 20);
    $desde        = max(1,  (int)($_POST['desde'] ?? 1));
    $hasta        = max($desde, (int)($_POST['hasta'] ?? 1));
    $padding      = max(0, min(5, (int)($_POST['padding'] ?? 0)));
    $tipo         = in_array($_POST['tipo'] ?? '', ['comun','privada','moto_comun','libre','movilidad_reducida'], true) ? $_POST['tipo'] : 'comun';
    $permiteCarro = !empty($_POST['permite_carro']) ? 1 : 0;
    $permiteMoto  = !empty($_POST['permite_moto'])  ? 1 : 0;

    if ($nivelId < 1) $errores[] = 'Selecciona un nivel.';
    if ($prefijo === '') $errores[] = 'El prefijo es obligatorio (puede ser "A-", "B-", etc.).';
    if ($hasta - $desde + 1 > 500) $errores[] = 'Máximo 500 celdas por bloque (ahora intentas ' . ($hasta - $desde + 1) . ').';
    if ($tipo === 'privada') $errores[] = 'Para celdas privadas usa "Crear individual" y asigna el apto dueño una por una.';
    if ($permiteCarro === 0 && $permiteMoto === 0) $errores[] = 'Debe permitir al menos carro o moto.';

    // Validar nivel
    if ($nivelId >= 1) {
        $st = $pdo->prepare("SELECT id, codigo FROM niveles_parqueadero
                              WHERE id = :id AND conjunto_id = :c AND activo = 1");
        $st->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        $nivel = $st->fetch();
        if (!$nivel) $errores[] = 'El nivel no existe o está inactivo.';
    }

    if (empty($errores)) {
        $codigosGen = gen_codigos($prefijo, $desde, $hasta, $padding);

        // Buscar cuáles ya existen
        $placeholders = [];
        $params = [':c' => $conjuntoId];
        foreach ($codigosGen as $i => $cod) {
            $placeholders[] = ':cod' . $i;
            $params[':cod' . $i] = $cod;
        }
        $inList = implode(',', $placeholders);
        $sql = "SELECT nombre_visible FROM celdas WHERE conjunto_id = :c AND nombre_visible IN ($inList)";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $existentes = $st->fetchAll(PDO::FETCH_COLUMN);

        $aCrear = array_values(array_diff($codigosGen, $existentes));

        if ($accion === 'crear') {
            // Insertar masivamente con numero_orden auto-incremental dentro del nivel
            try {
                $pdo->beginTransaction();

                // Punto de partida del numero_orden
                $stOrd = $pdo->prepare("SELECT COALESCE(MAX(numero_orden), 0)
                                          FROM celdas WHERE nivel_id = :nv AND conjunto_id = :c");
                $stOrd->execute([':nv' => $nivelId, ':c' => $conjuntoId]);
                $ordenActual = (int)$stOrd->fetchColumn();

                $ins = $pdo->prepare("INSERT INTO celdas
                        (conjunto_id, nivel_id, numero_orden, nombre_visible, tipo,
                         permite_carro, permite_moto, activa)
                    VALUES (:c, :nv, :no, :nm, :t, :pc, :pm, 1)");
                foreach ($aCrear as $cod) {
                    $ordenActual++;
                    $ins->execute([
                        ':c' => $conjuntoId, ':nv' => $nivelId,
                        ':no' => $ordenActual, ':nm' => $cod,
                        ':t' => $tipo, ':pc' => $permiteCarro, ':pm' => $permiteMoto,
                    ]);
                    $creados++;
                }
                $pdo->commit();
                $saltados = $existentes;
                if (function_exists('flash_set')) {
                    $msg = "Se crearon $creados celda(s) en {$nivel['codigo']}.";
                    if (!empty($existentes)) $msg .= " Se saltaron " . count($existentes) . " ya existentes.";
                    flash_set('ok', $msg);
                }
                redirect('/parqueadero');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al crear el bloque.';
            }
        } else {
            // Preview: mostrar qué se va a crear y qué ya existe
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

$_pageTitle = 'Crear celdas en bloque';
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
.form-bloque .checkboxes{display:flex;gap:16px;}
.form-bloque .checkboxes label{font-size:14px;display:flex;align-items:center;gap:6px;font-weight:normal;}
.form-bloque .checkboxes input{width:auto;}
.preview-box{margin-top:20px;background:#fff;border:2px solid #3b82f6;border-radius:8px;padding:16px;}
.preview-box h3{margin:0 0 10px 0;font-size:15px;color:#1e3a8a;}
.preview-codigos{display:flex;flex-wrap:wrap;gap:6px;max-height:180px;overflow-y:auto;margin-top:8px;background:#f9fafb;padding:10px;border-radius:5px;}
.codigo-tag{padding:3px 8px;background:#dbeafe;color:#1e3a8a;border-radius:4px;font-size:12px;font-family:monospace;}
.codigo-tag.dup{background:#fee2e2;color:#991b1b;text-decoration:line-through;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📦 Crear celdas en bloque</h1>
    <p class="page-head__sub">Crea muchas celdas a la vez con prefijo + rango numérico.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/parqueadero') ?>">← Volver a celdas</a>
    <a class="btn" href="<?= url('/parqueadero/crear') ?>">+ Crear individual</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if (empty($niveles)): ?>
    <div class="notice notice--info">
        ⚠️ No hay niveles activos. <a href="<?= url('/parqueadero/niveles') ?>"><strong>Crea un nivel primero</strong></a>.
    </div>
<?php else: ?>

<form method="POST" class="form-bloque">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="accion" value="preview">

    <div class="form-row">
        <label>Nivel *</label>
        <select name="nivel_id" required>
            <option value="">— Selecciona —</option>
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
            <input type="text" name="prefijo" maxlength="20" required placeholder="A-, B-, S1-A-"
                   value="<?= e($_POST['prefijo'] ?? '') ?>">
            <div class="help">Ej: "A-" generará A-1, A-2...</div>
        </div>
        <div class="form-row">
            <label>Desde *</label>
            <input type="number" name="desde" min="1" max="9999" required value="<?= (int)($_POST['desde'] ?? 1) ?>">
        </div>
        <div class="form-row">
            <label>Hasta *</label>
            <input type="number" name="hasta" min="1" max="9999" required value="<?= (int)($_POST['hasta'] ?? 50) ?>">
        </div>
        <div class="form-row">
            <label>Padding</label>
            <select name="padding">
                <option value="0" <?= (int)($_POST['padding'] ?? 0) === 0 ? 'selected' : '' ?>>Sin padding (1,2,3)</option>
                <option value="2" <?= (int)($_POST['padding'] ?? 0) === 2 ? 'selected' : '' ?>>2 dígitos (01,02)</option>
                <option value="3" <?= (int)($_POST['padding'] ?? 0) === 3 ? 'selected' : '' ?>>3 dígitos (001,002)</option>
            </select>
        </div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Tipo de todas las celdas</label>
            <select name="tipo">
                <option value="comun"              <?= ($_POST['tipo'] ?? 'comun') === 'comun'              ? 'selected' : '' ?>>🌐 Común</option>
                <option value="moto_comun"         <?= ($_POST['tipo'] ?? '')      === 'moto_comun'         ? 'selected' : '' ?>>🏍️ Moto común</option>
                <option value="libre"              <?= ($_POST['tipo'] ?? '')      === 'libre'              ? 'selected' : '' ?>>🆓 Libre</option>
                <option value="movilidad_reducida" <?= ($_POST['tipo'] ?? '')      === 'movilidad_reducida' ? 'selected' : '' ?>>♿ Movilidad reducida</option>
            </select>
            <div class="help">Para celdas privadas usa "Crear individual" (necesita apto dueño).</div>
        </div>
        <div class="form-row">
            <label>Permite</label>
            <div class="checkboxes" style="padding-top:6px">
                <label><input type="checkbox" name="permite_carro" value="1" <?= !isset($_POST['permite_carro']) || !empty($_POST['permite_carro']) ? 'checked' : '' ?>> 🚗 Carro</label>
                <label><input type="checkbox" name="permite_moto"  value="1" <?= !empty($_POST['permite_moto']) ? 'checked' : '' ?>> 🏍️ Moto</label>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn--primary">👁️ Ver vista previa</button>
</form>

<?php if ($preview): ?>
<div class="preview-box">
    <h3>Vista previa: <?= count($preview['crear']) ?> celda(s) a crear en <strong><?= e($preview['nivel_codigo']) ?></strong></h3>
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
        <form method="POST" style="margin-top:14px" onsubmit="return confirm('¿Confirmar la creación de <?= count($preview['crear']) ?> celda(s)?');">
            <input type="hidden" name="_csrf"          value="<?= csrf_token() ?>">
            <input type="hidden" name="accion"         value="crear">
            <input type="hidden" name="nivel_id"       value="<?= (int)$_POST['nivel_id'] ?>">
            <input type="hidden" name="prefijo"        value="<?= e($_POST['prefijo']) ?>">
            <input type="hidden" name="desde"          value="<?= (int)$_POST['desde'] ?>">
            <input type="hidden" name="hasta"          value="<?= (int)$_POST['hasta'] ?>">
            <input type="hidden" name="padding"        value="<?= (int)$_POST['padding'] ?>">
            <input type="hidden" name="tipo"           value="<?= e($_POST['tipo']) ?>">
            <input type="hidden" name="permite_carro"  value="<?= !empty($_POST['permite_carro']) ? '1' : '0' ?>">
            <input type="hidden" name="permite_moto"   value="<?= !empty($_POST['permite_moto']) ? '1' : '0' ?>">
            <button type="submit" class="btn btn--primary">✅ Confirmar y crear <?= count($preview['crear']) ?> celda(s)</button>
        </form>
    <?php else: ?>
        <div class="flash flash--error" style="margin-top:10px">Todas las celdas ya existen. Nada que crear.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
