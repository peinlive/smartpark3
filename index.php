<?php
// /home/myzonaco/smartpark.myzona360.com/modules/lecturas/index.php
// Historial de lecturas de placa por OCR.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$f_placa  = clean_string($_GET['placa']  ?? '', 15);
$f_fuente = in_array($_GET['fuente'] ?? '', ['consulta','revista','porteria','manual'], true) ? $_GET['fuente'] : '';
$f_tipo   = in_array($_GET['tipo']   ?? '', ['residente','visitante','no_encontrado'], true) ? $_GET['tipo'] : '';
$f_desde  = clean_string($_GET['desde'] ?? '', 10);
$f_hasta  = clean_string($_GET['hasta'] ?? '', 10);
$f_nivel  = clean_string($_GET['nivel'] ?? '', 10);

$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset = ($pagina - 1) * $porPagina;

$where = ['l.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];

if ($f_placa !== '') {
    $where[] = 'l.placa_detectada LIKE :placa';
    $params[':placa'] = '%' . strtoupper(preg_replace('/[^A-Z0-9]/','', strtoupper($f_placa))) . '%';
}
if ($f_fuente !== '') { $where[] = 'l.fuente = :fu'; $params[':fu'] = $f_fuente; }
if ($f_tipo !== '')   { $where[] = 'l.tipo_resultado = :tp'; $params[':tp'] = $f_tipo; }
if ($f_nivel !== '')  { $where[] = 'l.nivel = :ni'; $params[':ni'] = $f_nivel; }
if ($f_desde !== '')  { $where[] = 'DATE(l.creado_en) >= :d1'; $params[':d1'] = $f_desde; }
if ($f_hasta !== '')  { $where[] = 'DATE(l.creado_en) <= :d2'; $params[':d2'] = $f_hasta; }

$whereSql = implode(' AND ', $where);

$sqlC = "SELECT COUNT(*) FROM lecturas_placas l WHERE $whereSql";
$stC = $pdo->prepare($sqlC); $stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

$sql = "SELECT l.*, us.nombre_completo AS usuario_nombre,
               v.placa AS veh_placa, va.numero_visible AS veh_apto,
               vi.placa AS vis_placa, via2.numero_visible AS vis_apto
          FROM lecturas_placas l
     LEFT JOIN usuarios us ON us.id = l.usuario_id
     LEFT JOIN vehiculos v ON v.id = l.vehiculo_id
     LEFT JOIN apartamentos va ON va.id = v.apartamento_id
     LEFT JOIN visitantes_vehiculos vi ON vi.id = l.visitante_id
     LEFT JOIN apartamentos via2 ON via2.id = vi.apartamento_id
         WHERE $whereSql
      ORDER BY l.creado_en DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($params);
$lecturas = $st->fetchAll();

// Stats globales del día
$stStats = $pdo->prepare("
    SELECT
        SUM(CASE WHEN tipo_resultado='residente' THEN 1 ELSE 0 END) AS res,
        SUM(CASE WHEN tipo_resultado='visitante' THEN 1 ELSE 0 END) AS vis,
        SUM(CASE WHEN tipo_resultado='no_encontrado' THEN 1 ELSE 0 END) AS nf,
        COUNT(*) AS total
      FROM lecturas_placas
     WHERE conjunto_id = :c AND DATE(creado_en) = CURDATE()");
$stStats->execute([':c' => $conjuntoId]);
$stats = $stStats->fetch();

$_pageTitle = 'Lecturas de placa';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">📷 Lecturas de placa (OCR)</h1>
    <p class="page-head__sub"><?= $total ?> lectura<?= $total === 1 ? '' : 's' ?> · Historial de revistas y consultas con foto.</p>
</div>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Hoy total</div>
        <div class="card__value"><?= (int)$stats['total'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">🏠 Residentes</div>
        <div class="card__value"><?= (int)$stats['res'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">👥 Visitantes</div>
        <div class="card__value"><?= (int)$stats['vis'] ?></div>
    </div>
    <div class="card <?= (int)$stats['nf']>0?'card--warn':'' ?>">
        <div class="card__label">❓ No encontrados</div>
        <div class="card__value"><?= (int)$stats['nf'] ?></div>
    </div>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/consultas') ?>">📷 Nueva lectura</a>
</div>

<form method="get" action="<?= url('/lecturas') ?>" class="filters">
    <input type="text" name="placa" placeholder="Placa" value="<?= e($f_placa) ?>" maxlength="15">
    <select name="fuente">
        <option value="">Todas las fuentes</option>
        <option value="consulta" <?= $f_fuente === 'consulta' ? 'selected' : '' ?>>Consulta</option>
        <option value="revista"  <?= $f_fuente === 'revista'  ? 'selected' : '' ?>>Revista parqueadero</option>
        <option value="porteria" <?= $f_fuente === 'porteria' ? 'selected' : '' ?>>Portería</option>
    </select>
    <select name="tipo">
        <option value="">Todos los resultados</option>
        <option value="residente"     <?= $f_tipo === 'residente'     ? 'selected' : '' ?>>🏠 Residentes</option>
        <option value="visitante"     <?= $f_tipo === 'visitante'     ? 'selected' : '' ?>>👥 Visitantes</option>
        <option value="no_encontrado" <?= $f_tipo === 'no_encontrado' ? 'selected' : '' ?>>❓ No encontrados</option>
    </select>
    <input type="text" name="nivel" placeholder="Nivel (P2)" value="<?= e($f_nivel) ?>" maxlength="10">
    <input type="date" name="desde" value="<?= e($f_desde) ?>" title="Desde">
    <input type="date" name="hasta" value="<?= e($f_hasta) ?>" title="Hasta">
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/lecturas') ?>">Limpiar</a>
</form>

<?php if (empty($lecturas)): ?>
    <div class="notice notice--info">No hay lecturas con esos filtros.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table data-table--compact">
        <thead>
            <tr>
                <th>Foto</th><th>Placa</th><th>Conf.</th><th>Resultado</th>
                <th>Apto</th><th>Fuente</th><th>Nivel/Celda</th>
                <th>Usuario</th><th>Fecha</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lecturas as $l): ?>
            <tr>
                <td>
                    <?php if (!empty($l['foto_path'])): ?>
                        <a href="<?= e(url_foto($l['foto_path'])) ?>" target="_blank" title="Ver foto">
                            <img src="<?= e(url_foto($l['foto_path'])) ?>" alt="" class="row-thumb">
                        </a>
                    <?php else: ?>
                        <span class="row-thumb row-thumb--empty">📷</span>
                    <?php endif; ?>
                </td>
                <td><strong><?= e($l['placa_detectada']) ?></strong></td>
                <td>
                    <?php $c = (float)$l['confidence']; ?>
                    <?php if ($c >= 0.85): ?><span class="pill pill--ok"><?= round($c*100) ?>%</span>
                    <?php elseif ($c >= 0.60): ?><span class="pill pill--warn"><?= round($c*100) ?>%</span>
                    <?php else: ?><span class="pill pill--danger"><?= round($c*100) ?>%</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($l['tipo_resultado'] === 'residente'): ?>
                        <span class="pill pill--info">🏠 Residente</span>
                        <?php if ($l['vehiculo_id']): ?>
                            <br><a href="<?= url('/vehiculos/ver?id=' . (int)$l['vehiculo_id']) ?>" style="font-size:12px">Ver vehículo</a>
                        <?php endif; ?>
                    <?php elseif ($l['tipo_resultado'] === 'visitante'): ?>
                        <span class="pill pill--warn">👥 Visitante</span>
                        <?php if ($l['visitante_id']): ?>
                            <br><a href="<?= url('/visitantes/ver?id=' . (int)$l['visitante_id']) ?>" style="font-size:12px">Ver visitante</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pill pill--muted">❓ No encontrado</span>
                    <?php endif; ?>
                </td>
                <td><?= e($l['veh_apto'] ?? $l['vis_apto'] ?? '—') ?></td>
                <td><small><?= e(ucfirst($l['fuente'])) ?></small></td>
                <td><small><?= e($l['nivel'] ?: '—') ?> / <?= e($l['celda'] ?: '—') ?></small></td>
                <td><small><?= e($l['usuario_nombre'] ?? '—') ?></small></td>
                <td><small><?= e(fecha_humana($l['creado_en'])) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']);
            $base = url('/lecturas') . '?' . http_build_query($qs);
            $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
