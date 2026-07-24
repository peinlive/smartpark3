<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/crear.php
// v3n: crear 1 celda. Valida que el nivel pertenezca al conjunto y que
//      el código sea único en el conjunto. Si es privada, valida apto dueño.

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

    // Validar que el nivel pertenezca al conjunto
    $nivel = null;
    if ($nivelId >= 1) {
        $st = $pdo->prepare("SELECT id, codigo FROM niveles_parqueadero
                              WHERE id = :id AND conjunto_id = :c AND activo = 1");
        $st->execute([':id' => $nivelId, ':c' => $conjuntoId]);
        $nivel = $st->fetch();
        if (!$nivel) $errores[] = 'El nivel seleccionado no existe o está inactivo.';
    }

    // Verificar código único en el conjunto
    if ($codigo !== '') {
        $st = $pdo->prepare("SELECT id FROM celdas
                              WHERE conjunto_id = :c AND nombre_visible = :cd LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':cd' => $codigo]);
        if ($st->fetchColumn()) $errores[] = "Ya existe una celda con el código '{$codigo}' en este conjunto.";
    }

    // Si es privada → apto dueño OBLIGATORIO. Si es mov_reducida → OPCIONAL.
    $aptoDuenoId = null;
    if ($tipo === 'privada') {
        if ($aptoDuenoNum === '') {
            $errores[] = 'Las celdas privadas requieren un apartamento dueño.';
        } else {
            $st = $pdo->prepare("SELECT id FROM apartamentos
                                  WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
            $st->execute([':c' => $conjuntoId, ':n' => $aptoDuenoNum]);
            $aptoDuenoId = (int)$st->fetchColumn();
            if (!$aptoDuenoId) $errores[] = "El apartamento '{$aptoDuenoNum}' no existe.";
        }
    } elseif ($tipo === 'movilidad_reducida' && $aptoDuenoNum !== '') {
        // Apto dueño es opcional, pero si lo pones debe existir
        $st = $pdo->prepare("SELECT id FROM apartamentos
                              WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':n' => $aptoDuenoNum]);
        $aptoDuenoId = (int)$st->fetchColumn();
        if (!$aptoDuenoId) $errores[] = "El apartamento '{$aptoDuenoNum}' no existe.";
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // Calcular numero_orden automáticamente (MAX + 1 dentro del nivel)
            $stOrd = $pdo->prepare("SELECT COALESCE(MAX(numero_orden), 0) + 1
                                      FROM celdas WHERE nivel_id = :nv AND conjunto_id = :c");
            $stOrd->execute([':nv' => $nivelId, ':c' => $conjuntoId]);
            $numeroOrden = (int)$stOrd->fetchColumn();

            $ins = $pdo->prepare("INSERT INTO celdas
                    (conjunto_id, nivel_id, numero_orden, nombre_visible, tipo, apto_dueno_id,
                     permite_carro, permite_moto, activa, observaciones)
                VALUES (:c, :nv, :no, :nm, :t, :ad, :pc, :pm, 1, :ob)");
            $ins->execute([
                ':c'  => $conjuntoId, ':nv' => $nivelId,
                ':no' => $numeroOrden, ':nm' => $codigo, ':t' => $tipo,
                ':ad' => $aptoDuenoId, ':pc' => $permiteCarro, ':pm' => $permiteMoto,
                ':ob' => $observ ?: null,
            ]);
            $celdaId = (int)$pdo->lastInsertId();
            $pdo->commit();
            if (function_exists('flash_set')) flash_set('ok', "Celda '$codigo' creada en {$nivel['codigo']}.");
            // v3o: respetar return_url si vino
            $retorno = $_POST['return_url'] ?? ($_GET['return'] ?? '/parqueadero');
            if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
                $retorno = '/parqueadero';
            }
            redirect($retorno);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al guardar.';
        }
    }
}

// GET / repintar form
$niveles = $pdo->prepare("SELECT id, codigo, nombre, tipo FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$_pageTitle = 'Nueva celda';
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
    <h1 class="page-head__title">Nueva celda</h1>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/parqueadero') ?>">← Volver a celdas</a>
    <a class="btn" href="<?= url('/parqueadero/crear_bloque') ?>">📦 Crear en bloque</a>
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

<form method="POST" class="form-celda">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <div class="form-grid-2">
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
        <div class="form-row">
            <label>Código de la celda *</label>
            <input type="text" name="codigo" maxlength="20" required placeholder="A-15, B-22..."
                   value="<?= e($_POST['codigo'] ?? '') ?>">
            <div class="help">Debe ser único en todo el conjunto.</div>
        </div>
    </div>

    <div class="form-row">
        <label>Tipo de celda *</label>
        <select name="tipo" id="tipo-select" onchange="togglePrivBlock()">
            <?php foreach ([
                'comun'              => '🌐 Común',
                'privada'            => '🔒 Privada (asignada a un apto)',
                'moto_comun'         => '🏍️ Moto común',
                'libre'              => '🆓 Libre (cualquiera)',
                'movilidad_reducida' => '♿ Movilidad reducida',
            ] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= ($_POST['tipo'] ?? 'comun') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <div id="priv-block">
            <label id="priv-label">Apartamento dueño *</label>
            <input type="text" name="apto_dueno_numero" maxlength="20" placeholder="ej: 1502"
                   value="<?= e($_POST['apto_dueno_numero'] ?? '') ?>">
            <div class="help" id="priv-help">Número visible del apartamento dueño.</div>
        </div>
    </div>

    <div class="form-row">
        <label>Permite</label>
        <div class="checkboxes">
            <label><input type="checkbox" name="permite_carro" value="1" <?= !isset($_POST['permite_carro']) || !empty($_POST['permite_carro']) ? 'checked' : '' ?>> 🚗 Carro</label>
            <label><input type="checkbox" name="permite_moto"  value="1" <?= !empty($_POST['permite_moto']) ? 'checked' : '' ?>> 🏍️ Moto</label>
        </div>
    </div>

    <div class="form-row">
        <label>Observaciones (opcional)</label>
        <textarea name="observaciones" maxlength="255" rows="2"><?= e($_POST['observaciones'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn--primary">Crear celda</button>
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
        hlp.innerHTML = 'Solo si una persona específica la usa permanentemente. Si es de uso general para residentes con movilidad reducida, déjalo vacío.';
    } else {
        blk.classList.remove('show');
    }
}
togglePrivBlock();
</script>

<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
