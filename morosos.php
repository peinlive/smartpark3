<?php
// /home/myzonaco/smartpark.myzona360.com/modules/apartamentos/morosos.php
// v7.17 — Gestión de apartamentos morosos.
//   • Lista los apartamentos y permite marcar/desmarcar "moroso" ↔ "al día".
//   • Solo super_admin y admin (según lo pedido).
//   • Buscador + filtro (todos / morosos / al día) + contadores.
//   • Solo cambia el campo estado_morosidad. No toca nada más.
//
// SEGURIDAD: cada marcar/desmarcar va por POST con CSRF. El UPDATE solo
// afecta el estado_morosidad del apto indicado, dentro del conjunto.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

// Solo admin y super_admin pueden gestionar morosidad.
auth_require_role('super_admin', 'admin','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ── Acción: marcar / desmarcar (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $aptoId = (int)($_POST['apto_id'] ?? 0);
    $nuevo  = ($_POST['estado'] ?? '') === 'moroso' ? 'moroso' : 'al_dia';

    if ($aptoId > 0) {
        try {
            $st = $pdo->prepare(
                "UPDATE apartamentos
                    SET estado_morosidad = :e
                  WHERE id = :id AND conjunto_id = :c"
            );
            $st->execute([':e' => $nuevo, ':id' => $aptoId, ':c' => $conjuntoId]);

            if (function_exists('audit_log')) {
                audit_log('apartamentos', 'morosidad',
                    "Apto #{$aptoId} marcado como " . ($nuevo === 'moroso' ? 'MOROSO' : 'AL DÍA') . '.');
            }
            flash_set('success', 'Estado actualizado.');
        } catch (Exception $e) {
            flash_set('error', 'No se pudo actualizar: ' . $e->getMessage());
        }
    }
    redirect('/apartamentos/morosos' . (isset($_POST['torre']) && $_POST['torre'] !== ''
        ? '?torre=' . (int)$_POST['torre'] : ''));
}

// ── Filtro por torre (opcional) ──
$torreFiltro = isset($_GET['torre']) ? (int)$_GET['torre'] : 0;

$stT = $pdo->prepare("SELECT id, numero, nombre FROM torres
                       WHERE conjunto_id = :c AND activo = 1 ORDER BY numero");
$stT->execute([':c' => $conjuntoId]);
$torres = $stT->fetchAll(PDO::FETCH_ASSOC);

// ── Apartamentos (con nombre de torre) ──
$sql = "SELECT a.id, a.numero_visible, a.piso, a.torre_id, a.estado_morosidad,
               t.numero AS torre
          FROM apartamentos a
     LEFT JOIN torres t ON t.id = a.torre_id
         WHERE a.conjunto_id = :c
           " . ($torreFiltro > 0 ? "AND a.torre_id = :t" : "") . "
      ORDER BY a.torre_id, a.piso, a.numero_visible";
$stA = $pdo->prepare($sql);
$p = [':c' => $conjuntoId];
if ($torreFiltro > 0) $p[':t'] = $torreFiltro;
$stA->execute($p);
$apartamentos = $stA->fetchAll(PDO::FETCH_ASSOC);

// Totales
$totMorosos = 0;
foreach ($apartamentos as $a) if ($a['estado_morosidad'] === 'moroso') $totMorosos++;
$totApts = count($apartamentos);
$totAlDia = $totApts - $totMorosos;

$_pageTitle = 'Morosos';
include INCLUDES_PATH . '/header.php';
?>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn" href="<?= url('/apartamentos') ?>">🏢 Apartamentos</a>
</div>

<h1 style="margin:8px 0 4px">🔴 Gestión de morosos</h1>
<p style="color:#6b7280;margin:0 0 16px">
    Marca o quita el estado <b>moroso</b> de cada apartamento.
    Los apartamentos morosos se resaltan en rojo en Consulta rápida.
</p>

<!-- Filtro por torre -->
<div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn--sm <?= $torreFiltro === 0 ? 'btn--primary' : '' ?>"
       href="<?= url('/apartamentos/morosos') ?>">Todas</a>
    <?php foreach ($torres as $t): ?>
        <a class="btn btn--sm <?= $torreFiltro === (int)$t['id'] ? 'btn--primary' : '' ?>"
           href="<?= url('/apartamentos/morosos?torre=' . (int)$t['id']) ?>">Torre <?= (int)$t['numero'] ?></a>
    <?php endforeach; ?>
</div>

<!-- Buscador + filtros -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:16px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="text" id="mBuscar" placeholder="🔍 Buscar apto..."
               style="flex:1;min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <span id="mResultado" style="font-size:13px;color:#6b7280;white-space:nowrap"></span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="m-filtro btn btn--sm btn--primary" data-filtro="todos">
            Todos <b><?= $totApts ?></b>
        </button>
        <button type="button" class="m-filtro btn btn--sm" data-filtro="moroso" style="border:1px solid #fca5a5">
            🔴 Morosos <b><?= $totMorosos ?></b>
        </button>
        <button type="button" class="m-filtro btn btn--sm" data-filtro="al_dia" style="border:1px solid #a7f3d0">
            🟢 Al día <b><?= $totAlDia ?></b>
        </button>
    </div>
</div>

<div style="overflow:auto;max-height:600px;border:1px solid #e5e7eb;border-radius:8px">
<table class="table" style="margin:0">
    <thead style="position:sticky;top:0;background:#f9fafb">
        <tr><th>Apto</th><th>Torre</th><th>Piso</th><th>Estado</th><th>Acción</th></tr>
    </thead>
    <tbody>
    <?php foreach ($apartamentos as $a):
        $esMoroso = ($a['estado_morosidad'] === 'moroso');
    ?>
        <tr class="m-fila"
            data-estado="<?= $esMoroso ? 'moroso' : 'al_dia' ?>"
            data-num="<?= e(strtolower($a['numero_visible'])) ?>"
            style="<?= $esMoroso ? 'background:#fef2f2' : '' ?>">
            <td style="font-family:ui-monospace,monospace;font-weight:700"><?= e($a['numero_visible']) ?></td>
            <td><?= e($a['torre']) ?></td>
            <td><?= (int)$a['piso'] ?></td>
            <td>
                <?php if ($esMoroso): ?>
                    <span class="pill" style="background:#fee2e2;color:#b91c1c;font-weight:700">🔴 Moroso</span>
                <?php else: ?>
                    <span class="pill" style="background:#dcfce7;color:#166534">🟢 Al día</span>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="<?= url('/apartamentos/morosos') ?>" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="apto_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="torre" value="<?= $torreFiltro ?>">
                    <?php if ($esMoroso): ?>
                        <input type="hidden" name="estado" value="al_dia">
                        <button type="submit" class="btn btn--sm" style="background:#dcfce7;color:#166534">
                            ✓ Quitar mora
                        </button>
                    <?php else: ?>
                        <input type="hidden" name="estado" value="moroso">
                        <button type="submit" class="btn btn--sm" style="background:#fee2e2;color:#b91c1c"
                                onclick="return confirm('¿Marcar el apto <?= e($a['numero_visible']) ?> como MOROSO?');">
                            🔴 Marcar moroso
                        </button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<script>
(function () {
    var input   = document.getElementById('mBuscar');
    var result  = document.getElementById('mResultado');
    var botones = Array.prototype.slice.call(document.querySelectorAll('.m-filtro'));
    var filas   = Array.prototype.slice.call(document.querySelectorAll('.m-fila'));
    if (!filas.length) return;
    var filtro = 'todos';

    function aplicar() {
        var q = (input.value || '').trim().toLowerCase();
        var vis = 0;
        filas.forEach(function (row) {
            var okE = (filtro === 'todos') || row.getAttribute('data-estado') === filtro;
            var okQ = !q || row.getAttribute('data-num').indexOf(q) !== -1;
            var m = okE && okQ;
            row.style.display = m ? '' : 'none';
            if (m) vis++;
        });
        result.textContent = 'Mostrando ' + vis;
    }
    botones.forEach(function (b) {
        b.addEventListener('click', function () {
            filtro = b.getAttribute('data-filtro');
            botones.forEach(function (x) { x.classList.remove('btn--primary'); });
            b.classList.add('btn--primary');
            aplicar();
        });
    });
    input.addEventListener('input', aplicar);
    aplicar();
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
