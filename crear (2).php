<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones_cuartos/crear.php
// v1.0 (3V): Crear asignación de cuarto útil.
//   - Al crear, se archiva la activa previa del mismo cuarto.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];

// Cuartos activos del conjunto para elegir
$cuartos = $pdo->prepare("SELECT c.id, c.codigo AS nombre_visible, c.area_m2 AS metros2, c.apto_dueno_id,
                                 ad.numero_visible AS apto_dueno_num,
                                 (SELECT ac.id FROM asignaciones_cuartos ac
                                   WHERE ac.cuarto_id = c.id AND ac.activa = 1 AND ac.archivado_en IS NULL
                                   LIMIT 1) AS asignacion_activa
                            FROM cuartos_utiles c
                       LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
                           WHERE c.conjunto_id = :c AND c.activo = 1
                        ORDER BY c.codigo");
$cuartos->execute([':c' => $conjuntoId]);
$cuartos = $cuartos->fetchAll();

$prev = [
    'cuarto_id'       => (int)($_GET['cuarto_id'] ?? 0),
    'tipo'            => 'prestamo_gratis',
    'apto_usuario_num'=> '',
    'valor_mensual'   => '',
    'fecha_inicio'    => date('Y-m-d'),
    'fecha_fin'       => '',
    'observacion'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $prev['cuarto_id']        = (int)($_POST['cuarto_id'] ?? 0);
    $prev['tipo']             = in_array($_POST['tipo'] ?? '', ['uso_propio','prestamo_gratis','alquiler'], true) ? $_POST['tipo'] : 'prestamo_gratis';
    $prev['apto_usuario_num'] = clean_string($_POST['apto_usuario_num'] ?? '', 20);
    $prev['valor_mensual']    = clean_string($_POST['valor_mensual'] ?? '', 15);
    $prev['fecha_inicio']     = clean_string($_POST['fecha_inicio'] ?? '', 10);
    $prev['fecha_fin']        = clean_string($_POST['fecha_fin'] ?? '', 10);
    $prev['observacion']      = clean_string($_POST['observacion'] ?? '', 500);

    // Validar cuarto
    $cuartoObj = null;
    foreach ($cuartos as $c) if ((int)$c['id'] === $prev['cuarto_id']) { $cuartoObj = $c; break; }
    if (!$cuartoObj) $errores[] = 'Selecciona un cuarto válido.';
    else {
        // apto_dueno_id se toma del cuarto
        $aptoDuenoId = (int)$cuartoObj['apto_dueno_id'];
        if ($aptoDuenoId < 1) $errores[] = 'El cuarto seleccionado no tiene apto dueño definido.';
    }

    // Buscar apto usuario según tipo
    $aptoUsuarioId = null;
    if (empty($errores)) {
        if ($prev['tipo'] === 'uso_propio') {
            $aptoUsuarioId = $aptoDuenoId;
        } else {
            if ($prev['apto_usuario_num'] === '') $errores[] = 'Escribe el apto que va a usar el cuarto.';
            else {
                $stA = $pdo->prepare("SELECT id FROM apartamentos WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
                $stA->execute([':n' => $prev['apto_usuario_num'], ':c' => $conjuntoId]);
                $aptoUsuarioId = (int)$stA->fetchColumn();
                if (!$aptoUsuarioId) $errores[] = "Apto '{$prev['apto_usuario_num']}' no existe.";
            }
        }
    }

    // Validar valor mensual si es alquiler
    $valorMensual = null;
    if (empty($errores) && $prev['tipo'] === 'alquiler') {
        $valorMensual = (float)str_replace(['.', ','], ['', '.'], $prev['valor_mensual']);
        if ($valorMensual <= 0) $errores[] = 'Para alquiler, el valor mensual debe ser mayor a 0.';
    }

    // Validar fechas
    if ($prev['fecha_inicio'] === '' || !strtotime($prev['fecha_inicio'])) $errores[] = 'Fecha de inicio inválida.';
    if ($prev['fecha_fin'] !== '' && !strtotime($prev['fecha_fin'])) $errores[] = 'Fecha de fin inválida.';
    if ($prev['fecha_fin'] !== '' && strtotime($prev['fecha_fin']) < strtotime($prev['fecha_inicio'])) {
        $errores[] = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // Archivar la asignación activa previa (si hay)
            $pdo->prepare("UPDATE asignaciones_cuartos SET
                    activa = 0, archivado_en = NOW()
                WHERE cuarto_id = :cu AND activa = 1 AND archivado_en IS NULL")
                ->execute([':cu' => $prev['cuarto_id']]);

            // Insertar nueva asignación
            $ins = $pdo->prepare("INSERT INTO asignaciones_cuartos
                    (cuarto_id, apto_dueno_id, apto_usuario_id, tipo, valor_mensual,
                     fecha_inicio, fecha_fin, activa, observacion, creado_por)
                VALUES (:cu, :ad, :au, :tp, :vm, :fi, :ff, 1, :ob, :cp)");
            $ins->execute([
                ':cu' => $prev['cuarto_id'],
                ':ad' => $aptoDuenoId,
                ':au' => $aptoUsuarioId,
                ':tp' => $prev['tipo'],
                ':vm' => $valorMensual,
                ':fi' => $prev['fecha_inicio'],
                ':ff' => $prev['fecha_fin'] !== '' ? $prev['fecha_fin'] : null,
                ':ob' => $prev['observacion'] !== '' ? $prev['observacion'] : null,
                ':cp' => $uid ?: null,
            ]);

            $pdo->commit();
            flash_set('ok', 'Asignación registrada.');
            redirect('/asignaciones_cuartos');
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al crear.';
        }
    }
}

$_pageTitle = 'Nueva asignación de cuarto';
include INCLUDES_PATH . '/header.php';
?>

<style>
.asig-form{max-width:640px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.asig-form label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;margin-top:12px;}
.asig-form input,.asig-form select,.asig-form textarea{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:5px;font-size:14px;}
.asig-form textarea{resize:vertical;min-height:60px;}
.asig-info{background:#eff6ff;border:1px solid #93c5fd;padding:10px 14px;border-radius:6px;margin-top:6px;font-size:13px;display:none;}
.asig-info.show{display:block;}
.warn-existente{background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:5px;font-size:13px;margin-top:6px;display:none;}
.warn-existente.show{display:block;}
.tipo-help{background:#f8fafc;padding:6px 10px;border-radius:4px;font-size:12px;color:#6b7280;margin-top:4px;}
#campo-usuario, #campo-valor { transition:opacity .2s; }
#campo-usuario.oculto, #campo-valor.oculto { display:none; }
</style>

<div class="page-head">
    <h1 class="page-head__title">🔑 Nueva asignación de cuarto</h1>
</div>

<div class="toolbar"><a class="btn" href="<?= url('/asignaciones_cuartos') ?>">← Volver</a></div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="asig-form">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <label>Cuarto útil *</label>
    <select name="cuarto_id" id="sel-cuarto" required onchange="onCuartoChange()">
        <option value="">— Selecciona un cuarto —</option>
        <?php foreach ($cuartos as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                    data-dueno="<?= e($c['apto_dueno_num'] ?? '') ?>"
                    data-activa="<?= $c['asignacion_activa'] ? '1' : '0' ?>"
                    <?= $prev['cuarto_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['nombre_visible']) ?>
                <?= $c['apto_dueno_num'] ? ' — dueño ' . e($c['apto_dueno_num']) : ' (SIN DUEÑO)' ?>
                <?= $c['asignacion_activa'] ? ' [YA ASIGNADO]' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="asig-info" id="cuarto-info"></div>
    <div class="warn-existente" id="warn-existe">
        ⚠️ Este cuarto YA tiene una asignación activa. Al guardar, se ARCHIVARÁ la anterior automáticamente.
    </div>

    <label>Tipo de asignación *</label>
    <select name="tipo" id="sel-tipo" required onchange="onTipoChange()">
        <option value="uso_propio"      <?= $prev['tipo'] === 'uso_propio'      ? 'selected' : '' ?>>🏠 Uso propio (el dueño lo usa)</option>
        <option value="prestamo_gratis" <?= $prev['tipo'] === 'prestamo_gratis' ? 'selected' : '' ?>>🤝 Préstamo gratis (a otro apto sin cobro)</option>
        <option value="alquiler"        <?= $prev['tipo'] === 'alquiler'        ? 'selected' : '' ?>>💰 Alquiler (a otro apto con cobro mensual)</option>
    </select>

    <div id="campo-usuario">
        <label>Apto que usa el cuarto *</label>
        <input type="text" name="apto_usuario_num" maxlength="20"
               value="<?= e($prev['apto_usuario_num']) ?>"
               placeholder="ej: 1502">
        <div class="tipo-help">Escribe el número del apartamento que va a usar el cuarto.</div>
    </div>

    <div id="campo-valor">
        <label>Valor mensual (COP) *</label>
        <input type="text" name="valor_mensual" maxlength="15"
               value="<?= e($prev['valor_mensual']) ?>"
               placeholder="ej: 150000">
        <div class="tipo-help">Solo números. Sin puntos ni comas.</div>
    </div>

    <label>Fecha de inicio *</label>
    <input type="date" name="fecha_inicio" required value="<?= e($prev['fecha_inicio']) ?>">

    <label>Fecha de fin (opcional)</label>
    <input type="date" name="fecha_fin" value="<?= e($prev['fecha_fin']) ?>">
    <div class="tipo-help">Déjala vacía si es indefinida.</div>

    <label>Observaciones</label>
    <textarea name="observacion" maxlength="500"><?= e($prev['observacion']) ?></textarea>

    <div style="margin-top:18px;display:flex;gap:8px">
        <button type="submit" class="btn btn--primary">💾 Crear asignación</button>
        <a class="btn" href="<?= url('/asignaciones_cuartos') ?>">Cancelar</a>
    </div>
</form>

<script>
function onCuartoChange() {
    var s = document.getElementById('sel-cuarto');
    var op = s.options[s.selectedIndex];
    var info = document.getElementById('cuarto-info');
    var warn = document.getElementById('warn-existe');
    if (!op || !op.value) { info.classList.remove('show'); warn.classList.remove('show'); return; }

    var dueno = op.getAttribute('data-dueno');
    var activa = op.getAttribute('data-activa') === '1';
    info.innerHTML = dueno ? ('🏠 Dueño del cuarto: <strong>' + dueno + '</strong>') : '⚠️ Este cuarto no tiene dueño asignado.';
    info.classList.add('show');
    warn.classList.toggle('show', activa);
    onTipoChange();
}

function onTipoChange() {
    var tipo = document.getElementById('sel-tipo').value;
    document.getElementById('campo-usuario').classList.toggle('oculto', tipo === 'uso_propio');
    document.getElementById('campo-valor').classList.toggle('oculto', tipo !== 'alquiler');

    // Auto-completar apto_usuario_num si es uso_propio
    if (tipo === 'uso_propio') {
        var s = document.getElementById('sel-cuarto');
        var op = s.options[s.selectedIndex];
        var inp = document.querySelector('input[name="apto_usuario_num"]');
        if (op && op.value && inp) inp.value = op.getAttribute('data-dueno') || '';
    }
}

onCuartoChange();
onTipoChange();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
