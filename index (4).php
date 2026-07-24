<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones_cuartos/index.php
// v1.0 (3V): Lista de asignaciones de cuartos útiles.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$f_tipo    = in_array($_GET['tipo'] ?? '', ['uso_propio','prestamo_gratis','alquiler'], true) ? $_GET['tipo'] : '';
$f_estado  = in_array($_GET['estado'] ?? '', ['activas','archivadas'], true) ? $_GET['estado'] : 'activas';
$f_dueno   = clean_string($_GET['apto_dueno'] ?? '', 20);
$f_usuario = clean_string($_GET['apto_usuario'] ?? '', 20);

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

$where = ['cu.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];
if ($f_tipo   !== '') { $where[] = 'ac.tipo = :tp';  $params[':tp'] = $f_tipo; }
if ($f_estado === 'activas')    $where[] = 'ac.activa = 1 AND ac.archivado_en IS NULL';
if ($f_estado === 'archivadas') $where[] = 'ac.archivado_en IS NOT NULL';
if ($f_dueno   !== '') { $where[] = 'ad.numero_visible = :dn'; $params[':dn'] = $f_dueno; }
if ($f_usuario !== '') { $where[] = 'au.numero_visible = :un'; $params[':un'] = $f_usuario; }
$whereSql = implode(' AND ', $where);

$stC = $pdo->prepare("SELECT COUNT(*) FROM asignaciones_cuartos ac
                       JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
                  LEFT JOIN apartamentos ad ON ad.id = ac.apto_dueno_id
                  LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id
                      WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

// KPIs
$kSt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ac.activa = 1 AND ac.archivado_en IS NULL THEN 1 ELSE 0 END) AS activas,
        SUM(CASE WHEN ac.tipo = 'alquiler' AND ac.activa = 1 AND ac.archivado_en IS NULL THEN 1 ELSE 0 END) AS alq,
        SUM(CASE WHEN ac.tipo = 'prestamo_gratis' AND ac.activa = 1 AND ac.archivado_en IS NULL THEN 1 ELSE 0 END) AS pres,
        COALESCE(SUM(CASE WHEN ac.tipo = 'alquiler' AND ac.activa = 1 AND ac.archivado_en IS NULL THEN ac.valor_mensual ELSE 0 END), 0) AS total_mes
    FROM asignaciones_cuartos ac
    JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
    WHERE cu.conjunto_id = :c");
$kSt->execute([':c' => $conjuntoId]);
$kpi = $kSt->fetch();

$sql = "SELECT ac.*, cu.codigo AS cuarto_nombre, cu.area_m2 AS metros2,
               ad.numero_visible AS apto_dueno_num,
               au.numero_visible AS apto_usuario_num,
               up.nombre_completo AS creado_por_nombre
          FROM asignaciones_cuartos ac
          JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
     LEFT JOIN apartamentos ad ON ad.id = ac.apto_dueno_id
     LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id
     LEFT JOIN usuarios up ON up.id = ac.creado_por
         WHERE $whereSql
      ORDER BY ac.activa DESC, ac.fecha_inicio DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$asigs = $st->fetchAll();

function tipoAsigBadge($t) {
    $map = [
        'uso_propio'      => ['🏠 Uso propio',    '#dbeafe', '#1e40af'],
        'prestamo_gratis' => ['🤝 Préstamo',      '#dcfce7', '#166534'],
        'alquiler'        => ['💰 Alquiler',      '#fef3c7', '#92400e'],
    ];
    $x = $map[$t] ?? [$t, '#e5e7eb', '#374151'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:{$x[1]};color:{$x[2]}\">{$x[0]}</span>";
}

$_pageTitle = 'Asignaciones de cuartos';
include INCLUDES_PATH . '/header.php';
?>

<style>
.kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;flex:1;min-width:120px;}
.kpi-card strong{display:block;font-size:20px;} .kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.kpi-card.act strong{color:#1e6cff;} .kpi-card.alq strong{color:#d97706;} .kpi-card.pres strong{color:#15803d;}
.kpi-card.money strong{color:#059669;font-size:18px;}
.pill--act{background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill--arch{background:#e5e7eb;color:#6b7280;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.valor-mes{color:#059669;font-weight:700;font-family:monospace;}
</style>

<div class="page-head">
    <h1 class="page-head__title">🔑 Asignaciones de cuartos útiles</h1>
    <p class="page-head__sub">Uso propio, préstamos y alquileres.</p>
</div>

<div class="kpi-row">
    <div class="kpi-card"><strong><?= (int)$kpi['total'] ?></strong><span>Total</span></div>
    <div class="kpi-card act"><strong><?= (int)$kpi['activas'] ?></strong><span>Activas</span></div>
    <div class="kpi-card pres"><strong><?= (int)$kpi['pres'] ?></strong><span>🤝 Préstamos</span></div>
    <div class="kpi-card alq"><strong><?= (int)$kpi['alq'] ?></strong><span>💰 Alquileres</span></div>
    <div class="kpi-card money"><strong>$<?= number_format((float)$kpi['total_mes'], 0, ',', '.') ?></strong><span>Total mensual</span></div>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/asignaciones_cuartos/crear') ?>">+ Nueva asignación</a>
    <a class="btn" href="<?= url('/cuartos') ?>">🚪 Ver cuartos</a>
</div>

<form method="get" action="<?= url('/asignaciones_cuartos') ?>" class="filters">
    <select name="tipo">
        <option value="">Todos los tipos</option>
        <option value="uso_propio"      <?= $f_tipo === 'uso_propio'      ? 'selected' : '' ?>>🏠 Uso propio</option>
        <option value="prestamo_gratis" <?= $f_tipo === 'prestamo_gratis' ? 'selected' : '' ?>>🤝 Préstamo</option>
        <option value="alquiler"        <?= $f_tipo === 'alquiler'        ? 'selected' : '' ?>>💰 Alquiler</option>
    </select>
    <select name="estado">
        <option value="activas"    <?= $f_estado === 'activas'    ? 'selected' : '' ?>>Activas</option>
        <option value="archivadas" <?= $f_estado === 'archivadas' ? 'selected' : '' ?>>Archivadas</option>
    </select>
    <input type="text" name="apto_dueno"   placeholder="Apto dueño"   value="<?= e($f_dueno) ?>"   maxlength="20" style="width:110px">
    <input type="text" name="apto_usuario" placeholder="Apto usuario" value="<?= e($f_usuario) ?>" maxlength="20" style="width:110px">
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/asignaciones_cuartos') ?>">Limpiar</a>
</form>

<?php if (empty($asigs)): ?>
    <div class="notice notice--info">No hay asignaciones con esos filtros.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Cuarto</th><th>Apto dueño</th><th>Apto usuario</th><th>Tipo</th>
                <th>Valor mensual</th><th>Fecha inicio</th><th>Fecha fin</th><th>Estado</th>
                <th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($asigs as $a):
            $id = (int)$a['id'];
            $activa = ((int)$a['activa'] === 1 && !$a['archivado_en']);
        ?>
            <tr>
                <td>
                    <strong><?= e($a['cuarto_nombre']) ?></strong>
                    <?php if ($a['metros2']): ?><br><small class="t-muted"><?= e($a['metros2']) ?> m²</small><?php endif; ?>
                </td>
                <td><?= $a['apto_dueno_num'] ? '<strong>' . e($a['apto_dueno_num']) . '</strong>' : '<span class="t-muted">—</span>' ?></td>
                <td>
                    <?php if ($a['apto_usuario_num']): ?>
                        <strong><?= e($a['apto_usuario_num']) ?></strong>
                        <?php if ($a['apto_usuario_num'] === $a['apto_dueno_num']): ?>
                            <br><small style="color:#166534">= dueño</small>
                        <?php endif; ?>
                    <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                </td>
                <td><?= tipoAsigBadge($a['tipo']) ?></td>
                <td>
                    <?php if ($a['tipo'] === 'alquiler' && $a['valor_mensual']): ?>
                        <span class="valor-mes">$<?= number_format((float)$a['valor_mensual'], 0, ',', '.') ?></span>
                    <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                </td>
                <td><?= $a['fecha_inicio'] ? e(date('d/m/Y', strtotime($a['fecha_inicio']))) : '—' ?></td>
                <td><?= $a['fecha_fin'] ? e(date('d/m/Y', strtotime($a['fecha_fin']))) : '<span class="t-muted">—</span>' ?></td>
                <td>
                    <?php if ($activa): ?><span class="pill--act">✅ Activa</span>
                    <?php else: ?>
                        <span class="pill--arch">📦 Archivada</span>
                        <?php if ($a['archivado_en']): ?>
                            <br><small class="t-muted"><?= e(date('d/m/Y', strtotime($a['archivado_en']))) ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="t-right">
                    <?php if ($activa): ?>
                        <button type="button" class="btn btn--sm" style="background:#fee2e2;color:#991b1b"
                                onclick="asigCancelar(<?= $id ?>)" title="Cancelar / archivar">📦 Archivar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']); $base = url('/asignaciones_cuartos') . '?' . http_build_query($qs); $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif;
            endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
window.ASIG_CSRF = <?= json_encode(csrf_token()) ?>;
window.ASIG_CANC_URL = <?= json_encode(url('/asignaciones_cuartos/cancelar')) ?>;
function asigCancelar(id) {
    if (!confirm('¿Archivar esta asignación? El cuarto queda libre para reasignar.')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.ASIG_CANC_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.ASIG_CSRF+'">' +
                  '<input type="hidden" name="id" value="'+id+'">' +
                  '<input type="hidden" name="return_url" value="'+window.location.pathname+window.location.search+'">';
    document.body.appendChild(f); f.submit();
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
