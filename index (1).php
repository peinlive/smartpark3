<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones/index.php
// v3n: lista de asignaciones de celdas (uso propio, préstamos, alquileres entre aptos).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ───── Filtros ─────
$f_celda  = clean_string($_GET['celda']  ?? '', 30);
$f_dueno  = clean_string($_GET['dueno']  ?? '', 20);
$f_usuar  = clean_string($_GET['usuar']  ?? '', 20);
$f_tipo   = in_array($_GET['tipo']  ?? '', ['uso_propio','prestamo_gratis','alquiler'], true) ? $_GET['tipo'] : '';
$f_vista  = in_array($_GET['vista'] ?? '', ['activas','canceladas','todas'], true) ? $_GET['vista'] : 'activas';

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

$where  = ['c.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];

if ($f_celda !== '') { $where[] = 'c.nombre_visible LIKE :cd';  $params[':cd'] = '%' . $f_celda . '%'; }
if ($f_dueno !== '') { $where[] = 'ad.numero_visible LIKE :ad'; $params[':ad'] = '%' . $f_dueno . '%'; }
if ($f_usuar !== '') { $where[] = 'au.numero_visible LIKE :au'; $params[':au'] = '%' . $f_usuar . '%'; }
if ($f_tipo  !== '') { $where[] = 'asg.tipo = :tp';        $params[':tp'] = $f_tipo; }
if     ($f_vista === 'activas')    $where[] = 'asg.activa = 1';
elseif ($f_vista === 'canceladas') $where[] = 'asg.activa = 0';
$whereSql = implode(' AND ', $where);

// Conteo
$stC = $pdo->prepare("SELECT COUNT(*) FROM asignaciones_celdas asg
                        JOIN celdas c        ON c.id  = asg.celda_id
                        JOIN apartamentos ad ON ad.id = asg.apto_dueno_id
                        JOIN apartamentos au ON au.id = asg.apto_usuario_id
                       WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

// Listado
$sql = "SELECT asg.*,
               c.nombre_visible AS celda_codigo,
               n.codigo  AS nivel_codigo,
               ad.numero_visible AS apto_dueno_num,
               au.numero_visible AS apto_usuar_num,
               td.numero AS torre_dueno,
               tu.numero AS torre_usuar
          FROM asignaciones_celdas asg
          JOIN celdas c        ON c.id  = asg.celda_id
          JOIN niveles_parqueadero n ON n.id = c.nivel_id
          JOIN apartamentos ad ON ad.id = asg.apto_dueno_id
          JOIN apartamentos au ON au.id = asg.apto_usuario_id
          JOIN torres td       ON td.id = ad.torre_id
          JOIN torres tu       ON tu.id = au.torre_id
         WHERE $whereSql
      ORDER BY asg.activa DESC, asg.fecha_inicio DESC, asg.id DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

function pintarTipoAsig($t) {
    $map = [
        'uso_propio'      => ['🏠 Uso propio',      'background:#dcfce7;color:#166534'],
        'prestamo_gratis' => ['🤝 Préstamo gratis', 'background:#dbeafe;color:#1e3a8a'],
        'alquiler'        => ['💵 Alquiler',        'background:#fef3c7;color:#92400e'],
    ];
    $i = $map[$t] ?? [$t, 'background:#f3f4f6;color:#374151'];
    return '<span class="pill" style="' . $i[1] . '">' . $i[0] . '</span>';
}

$_pageTitle = 'Asignaciones de celdas';
include INCLUDES_PATH . '/header.php';
?>

<style>
.tbl-money{font-family:monospace;text-align:right;}
.row-cancel{background:#f9fafb;color:#9ca3af;}
.row-cancel td{text-decoration:line-through;text-decoration-thickness:1px;}
.row-cancel td:last-child{text-decoration:none;}
</style>

<div class="page-head">
    <h1 class="page-head__title">Asignaciones de celdas</h1>
    <p class="page-head__sub">Préstamos, alquileres y usos propios. <?= $total ?> resultado<?= $total === 1 ? '' : 's' ?>.</p>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/asignaciones/crear') ?>">+ Nueva asignación</a>
    <a class="btn" href="<?= url('/parqueadero') ?>">← Celdas</a>
</div>

<form method="get" action="<?= url('/asignaciones') ?>" class="filters">
    <input type="text" name="celda" placeholder="Código celda" value="<?= e($f_celda) ?>" maxlength="30">
    <input type="text" name="dueno" placeholder="Apto dueño"    value="<?= e($f_dueno) ?>" maxlength="20">
    <input type="text" name="usuar" placeholder="Apto usuario"  value="<?= e($f_usuar) ?>" maxlength="20">
    <select name="tipo">
        <option value="">Todos los tipos</option>
        <option value="uso_propio"      <?= $f_tipo === 'uso_propio'      ? 'selected' : '' ?>>🏠 Uso propio</option>
        <option value="prestamo_gratis" <?= $f_tipo === 'prestamo_gratis' ? 'selected' : '' ?>>🤝 Préstamo</option>
        <option value="alquiler"        <?= $f_tipo === 'alquiler'        ? 'selected' : '' ?>>💵 Alquiler</option>
    </select>
    <select name="vista">
        <option value="activas"    <?= $f_vista === 'activas'    ? 'selected' : '' ?>>✓ Activas</option>
        <option value="canceladas" <?= $f_vista === 'canceladas' ? 'selected' : '' ?>>○ Canceladas</option>
        <option value="todas"      <?= $f_vista === 'todas'      ? 'selected' : '' ?>>Todas</option>
    </select>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/asignaciones') ?>">Limpiar</a>
</form>

<?php if (empty($rows)): ?>
    <div class="notice notice--info">No hay asignaciones que coincidan.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Celda</th>
                <th>Nivel</th>
                <th>Dueño</th>
                <th>→</th>
                <th>Usuario</th>
                <th>Tipo</th>
                <th>$ Mensual</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Estado</th>
                <th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $rowCls = (int)$r['activa'] === 0 ? 'row-cancel' : '';
            $id = (int)$r['id'];
        ?>
            <tr class="<?= $rowCls ?>">
                <td><strong><?= e($r['celda_codigo']) ?></strong></td>
                <td><?= e($r['nivel_codigo']) ?></td>
                <td><?= e($r['apto_dueno_num']) ?> <small class="t-muted">(T<?= (int)$r['torre_dueno'] ?>)</small></td>
                <td class="t-muted">→</td>
                <td><?= e($r['apto_usuar_num']) ?> <small class="t-muted">(T<?= (int)$r['torre_usuar'] ?>)</small></td>
                <td><?= pintarTipoAsig($r['tipo']) ?></td>
                <td class="tbl-money"><?= $r['valor_mensual'] ? '$' . number_format((float)$r['valor_mensual'], 0, ',', '.') : '—' ?></td>
                <td><?= e(date('d/m/Y', strtotime($r['fecha_inicio']))) ?></td>
                <td><?= $r['fecha_fin'] ? e(date('d/m/Y', strtotime($r['fecha_fin']))) : '<span class="t-muted">indefinido</span>' ?></td>
                <td>
                    <?php if ((int)$r['activa'] === 1): ?>
                        <span class="pill pill--ok">Activa</span>
                    <?php else: ?>
                        <span class="pill pill--muted">Cancelada</span>
                    <?php endif; ?>
                </td>
                <td class="t-right">
                    <?php if ((int)$r['activa'] === 1): ?>
                        <form method="POST" action="<?= url('/asignaciones/cancelar') ?>" style="display:inline"
                              onsubmit="return confirm('¿Cancelar esta asignación?\n\nLa celda quedará disponible para reasignar.');">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn--sm" style="background:#fee2e2;color:#991b1b">❌ Cancelar</button>
                        </form>
                    <?php else: ?>
                        <span class="t-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']); $base = url('/asignaciones') . '?' . http_build_query($qs); $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif;
            endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
