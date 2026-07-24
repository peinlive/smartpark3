<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/nueva.php
// v1.0: Iniciar una nueva revista de parqueadero.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];

// Cargar niveles activos con conteo de celdas
$niveles = $pdo->prepare("SELECT n.id, n.codigo, n.nombre,
        (SELECT COUNT(*) FROM celdas c WHERE c.nivel_id = n.id AND c.activa = 1) AS total_celdas
    FROM niveles_parqueadero n
    WHERE n.conjunto_id = :c AND n.activo = 1
    ORDER BY n.orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $nivelCodigo = clean_string($_POST['nivel'] ?? '', 10);

    if ($nivelCodigo === '') $errores[] = 'Elige un nivel.';

    $nivelData = null;
    foreach ($niveles as $n) {
        if ($n['codigo'] === $nivelCodigo) { $nivelData = $n; break; }
    }
    if (!$nivelData) $errores[] = 'Nivel no encontrado.';
    elseif ((int)$nivelData['total_celdas'] === 0) {
        $errores[] = "El nivel '{$nivelCodigo}' no tiene celdas activas para revisar.";
    }

    // ¿Hay revista en curso del mismo nivel? Sugerir continuar
    if (empty($errores)) {
        $stE = $pdo->prepare("SELECT id FROM revistas
                               WHERE conjunto_id = :c AND nivel = :nv AND estado = 'en_curso'
                            ORDER BY id DESC LIMIT 1");
        $stE->execute([':c' => $conjuntoId, ':nv' => $nivelCodigo]);
        $existenteId = (int)$stE->fetchColumn();
        if ($existenteId > 0 && empty($_POST['forzar_nueva'])) {
            $errores[] = "⚠️ Ya hay una revista EN CURSO en {$nivelCodigo}. Termínala o cancélala primero, "
                       . "o marca 'forzar' abajo si quieres iniciar otra.";
        }
    }

    if (empty($errores)) {
        try {
            $ins = $pdo->prepare("INSERT INTO revistas
                    (conjunto_id, nivel, usuario_id, total_celdas, estado, iniciado_en)
                VALUES (:c, :nv, :us, :tc, 'en_curso', NOW())");
            $ins->execute([
                ':c'  => $conjuntoId, ':nv' => $nivelCodigo, ':us' => $uid,
                ':tc' => (int)$nivelData['total_celdas'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            if (function_exists('flash_set')) flash_set('ok', 'Revista iniciada.');
            redirect('/revistas/ejecutar?id=' . $newId);
        } catch (Exception $ex) {
            $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al iniciar la revista.';
        }
    }
}

$_pageTitle = 'Nueva revista de parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<style>
.nueva-card{max-width:640px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.nueva-card label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.nueva-card select{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:5px;font-size:15px;margin-bottom:10px;}
.nivel-info{background:#dbeafe;color:#1e3a8a;padding:10px 14px;border-radius:6px;margin-top:10px;font-size:14px;}
.check-forzar{display:flex;gap:8px;align-items:center;font-size:13px;margin-top:10px;color:#92400e;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📋 Nueva revista de parqueadero</h1>
    <p class="page-head__sub">
        Selecciona el nivel para empezar. Al terminar podrás agregar más niveles a esta revista.
    </p>
</div>

<div class="toolbar">
<a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
</div>

<div class="nivel-info" style="background:#f0fdf4;color:#166534;border-left:4px solid #16a34a;margin:12px 0">
    <strong>ℹ️ Nuevo flujo unificado (v3AS):</strong>
    empiezas con un nivel; al terminar la revisión de ese nivel, las celdas sin registro
    se marcan automáticamente como <strong>vacías</strong> y puedes elegir agregar otro
    nivel a la misma revista o cerrar aquí.
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="nueva-card">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <label>Nivel a revisar *</label>
    <select name="nivel" required id="sel-nivel" onchange="mostrarNivel()">
        <option value="">— Elige un nivel —</option>
        <?php foreach ($niveles as $n): ?>
            <option value="<?= e($n['codigo']) ?>"
                    data-total="<?= (int)$n['total_celdas'] ?>"
                    data-nombre="<?= e($n['nombre']) ?>"
                    <?= ($_POST['nivel'] ?? '') === $n['codigo'] ? 'selected' : '' ?>>
                <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
                (<?= (int)$n['total_celdas'] ?> celdas activas)
            </option>
        <?php endforeach; ?>
    </select>

    <div id="nivel-info" class="nivel-info" style="display:none">
        <strong id="ni-total">0</strong> celda(s) activas a revisar en <strong id="ni-nombre"></strong>.
    </div>

    <?php if (!empty($errores) && strpos(implode(' ', $errores), 'EN CURSO') !== false): ?>
        <label class="check-forzar">
            <input type="checkbox" name="forzar_nueva" value="1">
            Ya lo sé, quiero iniciar otra revista para el mismo nivel.
        </label>
    <?php endif; ?>

    <div style="margin-top:14px">
        <button type="submit" class="btn btn--primary">▶️ Iniciar revista</button>
    </div>
</form>

<script>
function mostrarNivel() {
    var s = document.getElementById('sel-nivel');
    var op = s.options[s.selectedIndex];
    var info = document.getElementById('nivel-info');
    if (!op || !op.value) { info.style.display = 'none'; return; }
    document.getElementById('ni-total').textContent = op.getAttribute('data-total');
    document.getElementById('ni-nombre').textContent = op.value + (op.getAttribute('data-nombre') ? ' — ' + op.getAttribute('data-nombre') : '');
    info.style.display = 'block';
}
mostrarNivel();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
