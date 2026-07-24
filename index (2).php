<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/index.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$f_placa = clean_string($_GET['placa'] ?? '', 15);
$f_apto  = clean_string($_GET['apto']  ?? '', 20);
$f_torre = clean_int($_GET['torre']   ?? null, 1, 99);
$f_tipo  = in_array($_GET['tipo']  ?? '', ['carro','moto'], true) ? $_GET['tipo'] : '';
$f_vista = in_array($_GET['vista'] ?? '', ['activos','archivados','recurrentes','todos'], true) ? $_GET['vista'] : 'activos';

$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset = ($pagina - 1) * $porPagina;

$where = ['v.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];
if ($f_placa !== '') { $where[] = 'v.placa LIKE :placa'; $params[':placa'] = '%' . normalizar_placa($f_placa) . '%'; }
if ($f_apto !== '')  { $where[] = 'a.numero_visible LIKE :apto'; $params[':apto'] = '%' . $f_apto . '%'; }
if ($f_torre !== null) { $where[] = 't.numero = :torre'; $params[':torre'] = $f_torre; }
if ($f_tipo !== '')  { $where[] = 'v.tipo = :tipo'; $params[':tipo'] = $f_tipo; }
switch ($f_vista) {
    case 'activos':     $where[] = 'v.archivado_en IS NULL'; break;
    case 'archivados':  $where[] = 'v.archivado_en IS NOT NULL'; break;
    case 'recurrentes': $where[] = 'v.archivado_en IS NULL AND v.recurrente = 1'; break;
}
$whereSql = implode(' AND ', $where);

$sqlC = "SELECT COUNT(*) FROM visitantes_vehiculos v
         JOIN apartamentos a ON a.id = v.apartamento_id
         JOIN torres t ON t.id = a.torre_id WHERE $whereSql";
$stC = $pdo->prepare($sqlC); $stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

$sql = "SELECT v.*, a.numero_visible AS apto_numero, t.numero AS torre_numero
          FROM visitantes_vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
          JOIN torres t ON t.id = a.torre_id
         WHERE $whereSql
      ORDER BY v.ultima_visita DESC, v.creado_en DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($params);
$visitantes = $st->fetchAll();

$torres = $pdo->prepare("SELECT id, numero FROM torres WHERE conjunto_id = :c AND activo = 1 ORDER BY numero");
$torres->execute([':c' => $conjuntoId]);
$torres = $torres->fetchAll();

$_pageTitle = 'Visitantes';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Visitantes</h1>
    <p class="page-head__sub"><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?>.</p>
</div>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn btn--primary" href="<?= url('/visitantes/crear') ?>">+ Registrar visita</a>
    <a class="btn" href="<?= url('/consultas') ?>">🔍 Consulta rápida por placa</a>
    
</div>

<form method="get" action="<?= url('/visitantes') ?>" class="filters">
    <input type="text" name="placa" placeholder="Placa" value="<?= e($f_placa) ?>" maxlength="15">
    <input type="text" name="apto" placeholder="Apto" value="<?= e($f_apto) ?>" maxlength="20">
    <select name="torre">
        <option value="">Todas las torres</option>
        <?php foreach ($torres as $t): ?>
            <option value="<?= (int)$t['numero'] ?>" <?= $f_torre === (int)$t['numero'] ? 'selected' : '' ?>>Torre <?= (int)$t['numero'] ?></option>
        <?php endforeach; ?>
    </select>
    <select name="tipo">
        <option value="">Todos</option>
        <option value="carro" <?= $f_tipo === 'carro' ? 'selected' : '' ?>>🚗 Carro</option>
        <option value="moto" <?= $f_tipo === 'moto' ? 'selected' : '' ?>>🏍️ Moto</option>
    </select>
    <select name="vista">
        <option value="activos"     <?= $f_vista === 'activos'     ? 'selected' : '' ?>>✓ Activos</option>
        <option value="recurrentes" <?= $f_vista === 'recurrentes' ? 'selected' : '' ?>>⭐ Recurrentes</option>
        <option value="archivados"  <?= $f_vista === 'archivados'  ? 'selected' : '' ?>>📁 Archivados</option>
        <option value="todos"       <?= $f_vista === 'todos'       ? 'selected' : '' ?>>Todos</option>
    </select>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/visitantes') ?>">Limpiar</a>
</form>

<?php if (empty($visitantes)): ?>
    <div class="notice notice--info">No hay visitantes registrados con esos filtros.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Placa</th><th>Tipo</th><th>Apto que visita</th><th>Visitante</th>
                <th>Visitas</th><th>Última</th><th>Estado</th><th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($visitantes as $v): ?>
            <tr>
                <td><strong><?= e($v['placa']) ?></strong></td>
                <td><?= $v['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></td>
                <td>
                    <strong><?= e($v['apto_numero']) ?></strong>
                    <span class="t-muted">T<?= (int)$v['torre_numero'] ?></span>
                </td>
                <td>
                    <?= e($v['nombre_visitante'] ?: '—') ?>
                    <?php if ($v['parentesco']): ?>
                        <br><small class="t-muted"><?= e($v['parentesco']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$v['recurrente'] === 1): ?>
                        ⭐ <strong><?= (int)$v['visitas_count'] ?></strong>
                    <?php else: ?>
                        <?= (int)$v['visitas_count'] ?>
                    <?php endif; ?>
                </td>
                <td><small><?= e(fecha_humana($v['ultima_visita'])) ?></small></td>
                <td>
                    <?php if ($v['archivado_en']): ?><span class="pill pill--muted">📁 Archivado</span>
                    <?php elseif ((int)$v['recurrente'] === 1): ?><span class="pill pill--info">⭐ Recurrente</span>
                    <?php else: ?><span class="pill pill--ok">Activo</span><?php endif; ?>
                </td>
                <td class="t-right">
                    <a class="btn btn--sm" href="<?= url('/visitantes/ver?id=' . (int)$v['id']) ?>">Ver</a>
                    <?php if (!$v['archivado_en']): ?>
                        <form method="post" action="<?= url('/visitantes/visita_mas') ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" class="btn btn--sm" title="Marcar nueva visita">+1</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']);
            $base = url('/visitantes') . '?' . http_build_query($qs);
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
