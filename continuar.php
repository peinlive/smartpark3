<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/continuar.php
// v1.0 (3AS): Pantalla intermedia tras terminar el nivel de una revista.
//   Muestra resumen y ofrece: agregar otro nivel (crea nueva revista y va al ejecutor)
//   o finalizar el ciclo (volver al listado).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect('/revistas');

// Cargar la revista recién terminada (para mostrar resumen)
$stR = $pdo->prepare("SELECT r.id, r.nivel, r.estado, r.celdas_revisadas, r.celdas_ocupadas,
                             r.celdas_vacias, r.total_celdas, r.iniciado_en, r.terminado_en
                        FROM revistas r
                       WHERE r.id = :id AND r.conjunto_id = :c LIMIT 1");
$stR->execute([':id' => $id, ':c' => $conjuntoId]);
$rev = $stR->fetch();
if (!$rev) { flash_set('error', 'Revista no encontrada.'); redirect('/revistas'); }

$errores = [];

// ── POST: usuario eligió agregar otro nivel ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $nuevoNivel = clean_string($_POST['nivel'] ?? '', 10);

    if ($nuevoNivel === '') {
        $errores[] = 'Elige un nivel.';
    } elseif ($nuevoNivel === $rev['nivel']) {
        $errores[] = 'Ya terminaste ese nivel. Elige otro.';
    } else {
        // Verificar que el nivel exista y tenga celdas
        $stV = $pdo->prepare("SELECT n.id, n.codigo,
                (SELECT COUNT(*) FROM celdas c WHERE c.nivel_id = n.id AND c.activa = 1) AS total_celdas
              FROM niveles_parqueadero n
             WHERE n.codigo = :nv AND n.conjunto_id = :c AND n.activo = 1 LIMIT 1");
        $stV->execute([':nv' => $nuevoNivel, ':c' => $conjuntoId]);
        $nivelData = $stV->fetch();

        if (!$nivelData) $errores[] = 'Nivel no encontrado.';
        elseif ((int)$nivelData['total_celdas'] === 0) $errores[] = "El nivel {$nuevoNivel} no tiene celdas.";

        // ¿Ya hay revista en curso de ese nivel?
        if (empty($errores)) {
            $stE = $pdo->prepare("SELECT id FROM revistas
                                   WHERE conjunto_id = :c AND nivel = :nv AND estado = 'en_curso'
                                   ORDER BY id DESC LIMIT 1");
            $stE->execute([':c' => $conjuntoId, ':nv' => $nuevoNivel]);
            $enCursoId = (int)$stE->fetchColumn();
            if ($enCursoId > 0) {
                // Ya hay una en curso de ese nivel — llevarla directo al ejecutor
                if (function_exists('flash_set')) flash_set('warn', "Ya hay una revista EN CURSO en {$nuevoNivel}. Te llevo a ella.");
                redirect('/revistas/ejecutar?id=' . $enCursoId);
            }
        }

        if (empty($errores)) {
            try {
                $ins = $pdo->prepare("INSERT INTO revistas
                        (conjunto_id, nivel, usuario_id, total_celdas, estado, iniciado_en)
                    VALUES (:c, :nv, :us, :tc, 'en_curso', NOW())");
                $ins->execute([
                    ':c'  => $conjuntoId, ':nv' => $nuevoNivel, ':us' => $uid,
                    ':tc' => (int)$nivelData['total_celdas'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                if (function_exists('audit_log')) {
                    audit_log('iniciar_revista_nivel_adicional', 'revistas', $newId,
                              "Continuó con nivel {$nuevoNivel} tras terminar #{$id} ({$rev['nivel']})",
                              null, ['nivel' => $nuevoNivel, 'anterior_id' => $id]);
                }

                if (function_exists('flash_set')) flash_set('ok', "Nivel {$nuevoNivel} iniciado. Continúa la revisión.");
                redirect('/revistas/ejecutar?id=' . $newId);
            } catch (Exception $ex) {
                $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al iniciar el nuevo nivel.';
            }
        }
    }
}

// Niveles disponibles (excluir el que se acaba de terminar)
$stN = $pdo->prepare("SELECT n.id, n.codigo, n.nombre,
        (SELECT COUNT(*) FROM celdas c WHERE c.nivel_id = n.id AND c.activa = 1) AS total_celdas,
        (SELECT COUNT(*) FROM revistas rr
          WHERE rr.conjunto_id = n.conjunto_id AND rr.nivel = n.codigo
            AND rr.estado = 'terminada'
            AND DATE(rr.terminado_en) = CURDATE()) AS terminadas_hoy
    FROM niveles_parqueadero n
    WHERE n.conjunto_id = :c AND n.activo = 1
    ORDER BY n.orden");
$stN->execute([':c' => $conjuntoId]);
$niveles = $stN->fetchAll();

$_pageTitle = 'Continuar revista';
include INCLUDES_PATH . '/header.php';
?>

<style>
.cnt-head{background:linear-gradient(135deg,#065f46,#059669);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px;}
.cnt-head h1{margin:0;font-size:22px;}
.cnt-head p{margin:6px 0 0;font-size:13px;opacity:.9;}

.cnt-resumen{background:#fff;border:1px solid #d1fae5;border-radius:10px;padding:20px 24px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.cnt-resumen h3{margin:0 0 12px;font-size:15px;color:#065f46;padding-bottom:8px;border-bottom:2px solid #d1fae5;}
.cnt-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px;}
.cnt-stat{background:#f0fdf4;padding:14px;border-radius:8px;text-align:center;border:1px solid #d1fae5;}
.cnt-stat .n{display:block;font-size:24px;font-weight:700;color:#065f46;}
.cnt-stat .l{font-size:11px;color:#166534;text-transform:uppercase;letter-spacing:.5px;}

.cnt-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px 26px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.cnt-card h3{margin:0 0 12px;font-size:15px;color:#111827;padding-bottom:8px;border-bottom:2px solid #f3f4f6;}

.niv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:10px;}
.niv-tile{background:#f9fafb;border:2px solid #d1d5db;border-radius:8px;padding:14px;text-align:center;cursor:pointer;transition:all .15s;position:relative;}
.niv-tile:hover{border-color:#059669;background:#f0fdf4;}
.niv-tile input{position:absolute;opacity:0;pointer-events:none;}
.niv-tile.selected{border-color:#059669;background:#dcfce7;box-shadow:0 0 0 3px rgba(5,150,105,.15);}
.niv-tile.terminado{opacity:.55;border-style:dashed;}
.niv-tile.actual{opacity:.35;pointer-events:none;background:#f3f4f6;}
.niv-tile .cod{font-size:20px;font-weight:700;color:#111827;}
.niv-tile .nom{font-size:11px;color:#6b7280;margin:3px 0 6px;}
.niv-tile .met{display:inline-block;font-size:10px;background:#fff;padding:2px 8px;border-radius:4px;color:#374151;border:1px solid #e5e7eb;}
.niv-tile .badge-hoy{position:absolute;top:6px;right:6px;background:#dcfce7;color:#166534;font-size:9px;padding:2px 5px;border-radius:8px;}
.niv-tile .badge-act{position:absolute;top:6px;right:6px;background:#e0e7ff;color:#3730a3;font-size:9px;padding:2px 5px;border-radius:8px;}

.cnt-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
.cnt-btn{padding:12px 22px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.cnt-btn--primary{background:#059669;color:#fff;}
.cnt-btn--primary:hover{background:#047857;}
.cnt-btn--primary:disabled{background:#9ca3af;cursor:not-allowed;}
.cnt-btn--secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db;}
.cnt-btn--secondary:hover{background:#e5e7eb;}

.errores{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:6px;margin:10px 0;font-size:13px;}
</style>

<div class="cnt-head">
    <h1>✅ Nivel <?= e($rev['nivel']) ?> terminado</h1>
    <p>¿Quieres agregar otro nivel a esta revista o finalizar aquí?</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/revistas') ?>">← Volver al listado</a>
    <a class="btn" href="<?= url('/revistas/ver?id=' . $id) ?>">👁️ Ver detalle nivel <?= e($rev['nivel']) ?></a>
</div>

<div class="cnt-resumen">
    <h3>📋 Resumen del nivel recién terminado</h3>
    <div class="cnt-stats">
        <div class="cnt-stat">
            <span class="n"><?= (int)$rev['celdas_revisadas'] ?></span>
            <span class="l">Revisadas</span>
        </div>
        <div class="cnt-stat" style="background:#e0f2fe;border-color:#bae6fd">
            <span class="n" style="color:#0369a1"><?= (int)$rev['celdas_ocupadas'] ?></span>
            <span class="l" style="color:#0c4a6e">Ocupadas</span>
        </div>
        <div class="cnt-stat" style="background:#fef3c7;border-color:#fde68a">
            <span class="n" style="color:#92400e"><?= (int)$rev['celdas_vacias'] ?></span>
            <span class="l" style="color:#78350f">Vacías</span>
        </div>
        <div class="cnt-stat" style="background:#f3f4f6;border-color:#d1d5db">
            <span class="n" style="color:#374151"><?= (int)$rev['total_celdas'] ?></span>
            <span class="l">Total celdas</span>
        </div>
    </div>
    <p style="font-size:12px;color:#6b7280;margin-top:14px">
        Iniciada: <?= e(date('d/m/Y H:i', strtotime($rev['iniciado_en']))) ?> ·
        Terminada: <?= e(date('d/m/Y H:i', strtotime($rev['terminado_en']))) ?>
    </p>
</div>

<?php if (!empty($errores)): ?>
    <div class="errores"><?php foreach ($errores as $er) echo '<div>• ' . e($er) . '</div>'; ?></div>
<?php endif; ?>

<div class="cnt-card">
    <h3>➕ Agregar otro nivel a esta revista</h3>
    <p style="font-size:13px;color:#6b7280">
        Elige un nivel para continuar la revisión. Se abrirá una nueva sesión de revista en ese nivel.
        <span class="niv-tile" style="display:inline-block;padding:2px 6px;font-size:10px;border-style:dashed;cursor:default">HOY</span>
        indica un nivel ya revisado hoy.
    </p>

    <form method="post" action="<?= url('/revistas/continuar?id=' . $id) ?>">
        <?= csrf_field() ?>

        <div class="niv-grid">
            <?php foreach ($niveles as $n):
                $esActual = ($n['codigo'] === $rev['nivel']);
                $yaHoy = (int)$n['terminadas_hoy'] > 0;
                $sinCeldas = (int)$n['total_celdas'] === 0;
                $classes = 'niv-tile';
                if ($esActual) $classes .= ' actual';
                if ($yaHoy && !$esActual) $classes .= ' terminado';
            ?>
                <label class="<?= $classes ?>" onclick="cntSelect(this)" data-cod="<?= e($n['codigo']) ?>">
                    <input type="radio" name="nivel" value="<?= e($n['codigo']) ?>" <?= $sinCeldas ? 'disabled' : '' ?>>
                    <?php if ($esActual): ?>
                        <span class="badge-act">RECIÉN HECHO</span>
                    <?php elseif ($yaHoy): ?>
                        <span class="badge-hoy">HOY</span>
                    <?php endif; ?>
                    <span class="cod"><?= e($n['codigo']) ?></span>
                    <?php if (!empty($n['nombre'])): ?>
                        <div class="nom"><?= e($n['nombre']) ?></div>
                    <?php endif; ?>
                    <span class="met"><?= (int)$n['total_celdas'] ?> celdas</span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="cnt-actions">
            <button type="submit" class="cnt-btn cnt-btn--primary" id="btnContinuar" disabled>
                ▶️ Continuar con este nivel
            </button>
            <a class="cnt-btn cnt-btn--secondary" href="<?= url('/revistas') ?>">
                🏁 Finalizar aquí (volver al listado)
            </a>
        </div>
    </form>
</div>

<script>
function cntSelect(el){
    var input = el.querySelector('input[type=radio]');
    if (!input || input.disabled) return;
    if (el.classList.contains('actual')) return;
    document.querySelectorAll('.niv-tile.selected').forEach(function(t){ t.classList.remove('selected'); });
    el.classList.add('selected');
    input.checked = true;
    document.getElementById('btnContinuar').disabled = false;
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
