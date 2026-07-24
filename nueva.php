<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/nueva.php
// Iniciar nueva revista: seleccionar nivel + crear celdas si es la primera vez.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$niveles_validos = ['S98','S99','P1','P2','P3','P4'];
$errores = [];

// Si el usuario ya tiene una revista en curso, redirigir
$st = $pdo->prepare("SELECT id, nivel FROM revistas
                      WHERE conjunto_id = :c AND usuario_id = :u AND estado = 'en_curso' LIMIT 1");
$st->execute([':c' => $conjuntoId, ':u' => $u['id']]);
if ($r = $st->fetch()) {
    flash_set('warn', "Ya tienes una revista en curso en nivel {$r['nivel']}. Termínala o cancélala antes de iniciar otra.");
    redirect('/rondas/ejecutar?id=' . (int)$r['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $nivel = clean_string($_POST['nivel'] ?? '', 10);
    $total_celdas = clean_int($_POST['total_celdas'] ?? null, 1, 500) ?? 65;

    if (!in_array($nivel, $niveles_validos, true)) $errores[] = 'Nivel inválido.';

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // Verificar si ya existen celdas configuradas para este nivel
            $sc = $pdo->prepare("SELECT COUNT(*) FROM parqueadero_celdas
                                  WHERE conjunto_id = :c AND nivel = :n");
            $sc->execute([':c' => $conjuntoId, ':n' => $nivel]);
            $existentes = (int)$sc->fetchColumn();

            if ($existentes === 0) {
                // Auto-crear celdas numeradas 01..N
                $ins = $pdo->prepare("INSERT INTO parqueadero_celdas
                        (conjunto_id, nivel, numero_celda, es_privada, orden)
                    VALUES (:c, :n, :nu, 0, :o)");
                for ($i = 1; $i <= $total_celdas; $i++) {
                    $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    $ins->execute([':c' => $conjuntoId, ':n' => $nivel, ':nu' => $num, ':o' => $i]);
                }
                $celdasReales = $total_celdas;
            } else {
                $celdasReales = $existentes;
            }

            // Crear la revista
            $ir = $pdo->prepare("INSERT INTO revistas
                    (conjunto_id, nivel, usuario_id, total_celdas, estado, iniciado_en)
                VALUES (:c, :n, :u, :tc, 'en_curso', NOW())");
            $ir->execute([':c' => $conjuntoId, ':n' => $nivel, ':u' => $u['id'], ':tc' => $celdasReales]);
            $revistaId = (int)$pdo->lastInsertId();

            $pdo->commit();
            flash_set('ok', "Revista #{$revistaId} iniciada en nivel {$nivel}.");
            redirect('/rondas/ejecutar?id=' . $revistaId);
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errores[] = APP_DEBUG ? $ex->getMessage() : 'Error al iniciar la revista.';
        }
    }
}

// Cuenta de celdas por nivel ya configuradas
$niveles_config = $pdo->prepare("SELECT nivel, COUNT(*) AS c FROM parqueadero_celdas
                                   WHERE conjunto_id = :c GROUP BY nivel");
$niveles_config->execute([':c' => $conjuntoId]);
$config_map = [];
foreach ($niveles_config as $r) $config_map[$r['nivel']] = (int)$r['c'];

$_pageTitle = 'Nueva revista';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">🌙 Nueva revista de parqueadero</h1>
    <p class="page-head__sub">Selecciona el nivel para iniciar el recorrido.</p>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/rondas/nueva') ?>" class="form-grid" style="max-width:500px">
    <?= csrf_field() ?>
    <div class="form-section">
        <h3 class="form-section__title">Nivel a revisar</h3>
        <div class="nivel-grid">
            <?php foreach ($niveles_validos as $nv):
                $c = $config_map[$nv] ?? 0; ?>
                <label class="nivel-card">
                    <input type="radio" name="nivel" value="<?= $nv ?>" required>
                    <div class="nivel-card__inner">
                        <strong><?= $nv ?></strong>
                        <small><?= $c > 0 ? "{$c} celdas" : "sin configurar" ?></small>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-section" id="primeraVezBox" style="display:none">
        <h3 class="form-section__title">⚠️ Primera vez en este nivel</h3>
        <p>Este nivel no tiene celdas configuradas. ¿Cuántas celdas tiene?</p>
        <label class="field">
            <span>Total de celdas</span>
            <input type="number" name="total_celdas" min="1" max="500" value="65">
            <small class="field__hint">Se crearán automáticamente numeradas 01 a N. Después puedes editar/marcar privadas en BD.</small>
        </label>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/rondas') ?>">Cancelar</a>
        <button type="submit" class="btn btn--primary btn--lg">▶ Iniciar revista</button>
    </div>
</form>

<style>
.nivel-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.nivel-card{cursor:pointer;}
.nivel-card input{position:absolute;opacity:0;pointer-events:none;}
.nivel-card__inner{padding:18px;border:2px solid var(--color-border);border-radius:10px;
    text-align:center;background:#fff;transition:all .15s;}
.nivel-card__inner strong{display:block;font-size:22px;}
.nivel-card__inner small{display:block;color:var(--color-muted);margin-top:4px;font-size:12px;}
.nivel-card input:checked + .nivel-card__inner{border-color:var(--color-primary);
    background:#eff6ff;box-shadow:0 0 0 3px rgba(30,108,255,.12);}
.btn--lg{padding:14px 28px;font-size:16px;}
</style>

<script>
var configMap = <?= json_encode($config_map) ?>;
document.querySelectorAll('input[name="nivel"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var existe = (configMap[r.value] || 0) > 0;
        document.getElementById('primeraVezBox').style.display = existe ? 'none' : 'block';
    });
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
