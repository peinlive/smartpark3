<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones/crear.php
// v3n: crear asignación de celda a otro apto (uso propio / préstamo / alquiler).
//      Valida que la celda exista y que no haya OTRA asignación activa para esa celda.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $celdaCodigo  = clean_string($_POST['celda_codigo']  ?? '', 30);
    $aptoUsuarNum = clean_string($_POST['apto_usuario']  ?? '', 20);
    $tipo         = in_array($_POST['tipo'] ?? '', ['uso_propio','prestamo_gratis','alquiler'], true) ? $_POST['tipo'] : 'uso_propio';
    $valor        = $_POST['valor_mensual'] !== '' ? (float)str_replace([',', '$', '.'], ['.','',''], $_POST['valor_mensual'] ?? '0') : null;
    $fechaIni     = $_POST['fecha_inicio'] ?? '';
    $fechaFin     = $_POST['fecha_fin']    ?? '';
    $obs          = clean_string($_POST['observacion'] ?? '', 500);

    if ($celdaCodigo === '') $errores[] = 'El código de la celda es obligatorio.';
    if ($aptoUsuarNum === '') $errores[] = 'El apartamento usuario es obligatorio.';
    if ($fechaIni === '' || !strtotime($fechaIni)) $errores[] = 'Fecha de inicio inválida.';
    if ($fechaFin !== '' && !strtotime($fechaFin)) $errores[] = 'Fecha de fin inválida.';
    if ($fechaFin !== '' && strtotime($fechaFin) < strtotime($fechaIni)) $errores[] = 'La fecha de fin debe ser posterior a la de inicio.';
    if ($tipo === 'alquiler' && (!$valor || $valor <= 0)) $errores[] = 'Los alquileres requieren un valor mensual positivo.';

    // Validar celda (debe ser PRIVADA — solo las privadas tienen dueño)
    $celda = null;
    if ($celdaCodigo !== '') {
        $st = $pdo->prepare("SELECT c.*, c.nombre_visible AS codigo, a.numero_visible AS apto_dueno_num
                               FROM celdas c
                          LEFT JOIN apartamentos a ON a.id = c.apto_dueno_id
                              WHERE c.conjunto_id = :c AND c.nombre_visible = :cd LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':cd' => $celdaCodigo]);
        $celda = $st->fetch();
        if (!$celda) {
            $errores[] = "La celda '{$celdaCodigo}' no existe.";
        } elseif ((int)$celda['activa'] === 0) {
            $errores[] = "La celda '{$celdaCodigo}' está inactiva.";
        } elseif ($celda['tipo'] !== 'privada') {
            $errores[] = "Solo las celdas PRIVADAS se pueden asignar a otros aptos. Esta es de tipo '{$celda['tipo']}'.";
        } elseif (!$celda['apto_dueno_id']) {
            $errores[] = "La celda '{$celdaCodigo}' no tiene apartamento dueño. Edítala primero.";
        }
    }

    // Validar apto usuario
    $aptoUsuar = null;
    if ($aptoUsuarNum !== '') {
        $st = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                              WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':n' => $aptoUsuarNum]);
        $aptoUsuar = $st->fetch();
        if (!$aptoUsuar) $errores[] = "El apartamento usuario '{$aptoUsuarNum}' no existe.";
    }

    // Validar que no haya OTRA asignación activa para esta celda
    if ($celda) {
        $st = $pdo->prepare("SELECT id FROM asignaciones_celdas
                              WHERE celda_id = :ci AND activa = 1 LIMIT 1");
        $st->execute([':ci' => (int)$celda['id']]);
        if ($st->fetchColumn()) {
            $errores[] = "Ya hay una asignación ACTIVA para la celda '{$celdaCodigo}'. Cancela la actual antes de crear otra.";
        }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO asignaciones_celdas
                    (celda_id, apto_dueno_id, apto_usuario_id, tipo, valor_mensual,
                     fecha_inicio, fecha_fin, activa, observacion, creado_por)
                VALUES (:ci, :ad, :au, :t, :vm, :fi, :ff, 1, :ob, :cp)");
            $ins->execute([
                ':ci' => (int)$celda['id'],
                ':ad' => (int)$celda['apto_dueno_id'],
                ':au' => (int)$aptoUsuar['id'],
                ':t'  => $tipo,
                ':vm' => $tipo === 'alquiler' ? $valor : null,
                ':fi' => date('Y-m-d', strtotime($fechaIni)),
                ':ff' => $fechaFin !== '' ? date('Y-m-d', strtotime($fechaFin)) : null,
                ':ob' => $obs ?: null,
                ':cp' => (int)($u['id'] ?? 0) ?: null,
            ]);
            $pdo->commit();
            if (function_exists('flash_set')) flash_set('ok', "Asignación creada: celda {$celdaCodigo} → apto {$aptoUsuarNum}.");
            redirect('/asignaciones');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al guardar.';
        }
    }
}

$_pageTitle = 'Nueva asignación';
include INCLUDES_PATH . '/header.php';
?>

<style>
.form-asg{max-width:700px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.form-asg .form-row{margin-bottom:14px;}
.form-asg label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.form-asg input,.form-asg select,.form-asg textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.form-asg .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-asg .help{font-size:12px;color:#6b7280;margin-top:2px;}
#valor-row{display:none;}
#valor-row.show{display:block;}
</style>

<div class="page-head"><h1 class="page-head__title">Nueva asignación de celda</h1></div>

<div class="toolbar"><a class="btn" href="<?= url('/asignaciones') ?>">← Volver</a></div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="form-asg">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <div class="grid-2">
        <div class="form-row">
            <label>Código de celda *</label>
            <input type="text" name="celda_codigo" maxlength="30" required placeholder="A-15"
                   value="<?= e($_POST['celda_codigo'] ?? '') ?>">
            <div class="help">Debe ser una celda PRIVADA con dueño asignado.</div>
        </div>
        <div class="form-row">
            <label>Apartamento usuario *</label>
            <input type="text" name="apto_usuario" maxlength="20" required placeholder="ej: 1502"
                   value="<?= e($_POST['apto_usuario'] ?? '') ?>">
            <div class="help">A quién se le presta/alquila la celda.</div>
        </div>
    </div>

    <div class="form-row">
        <label>Tipo de asignación *</label>
        <select name="tipo" id="tipo-select" onchange="toggleValor()">
            <option value="uso_propio"      <?= ($_POST['tipo'] ?? 'uso_propio') === 'uso_propio'      ? 'selected' : '' ?>>🏠 Uso propio (el dueño la usa)</option>
            <option value="prestamo_gratis" <?= ($_POST['tipo'] ?? '')           === 'prestamo_gratis' ? 'selected' : '' ?>>🤝 Préstamo gratis (sin cobro)</option>
            <option value="alquiler"        <?= ($_POST['tipo'] ?? '')           === 'alquiler'        ? 'selected' : '' ?>>💵 Alquiler (con cobro mensual)</option>
        </select>
    </div>

    <div class="form-row" id="valor-row">
        <label>Valor mensual *</label>
        <input type="text" name="valor_mensual" placeholder="150000"
               value="<?= e($_POST['valor_mensual'] ?? '') ?>">
        <div class="help">Solo números enteros. Sin puntos ni comas.</div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Fecha de inicio *</label>
            <input type="date" name="fecha_inicio" required
                   value="<?= e($_POST['fecha_inicio'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-row">
            <label>Fecha de fin (opcional)</label>
            <input type="date" name="fecha_fin" value="<?= e($_POST['fecha_fin'] ?? '') ?>">
            <div class="help">Dejar vacío para asignación indefinida.</div>
        </div>
    </div>

    <div class="form-row">
        <label>Observación (opcional)</label>
        <textarea name="observacion" maxlength="500" rows="2"><?= e($_POST['observacion'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn--primary">Crear asignación</button>
</form>

<script>
function toggleValor() {
    var sel = document.getElementById('tipo-select');
    var row = document.getElementById('valor-row');
    if (sel.value === 'alquiler') row.classList.add('show');
    else row.classList.remove('show');
}
toggleValor();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
