<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/index.php
// Listado de revistas + botón nueva.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$f_nivel  = clean_string($_GET['nivel'] ?? '', 10);
$f_estado = in_array($_GET['estado'] ?? '', ['en_curso','terminada','cancelada'], true) ? $_GET['estado'] : '';

$where = ['r.conjunto_id = :c'];
$params = [':c' => $conjuntoId];
if ($f_nivel !== '')  { $where[] = 'r.nivel = :nv'; $params[':nv'] = $f_nivel; }
if ($f_estado !== '') { $where[] = 'r.estado = :es'; $params[':es'] = $f_estado; }

$st = $pdo->prepare("
    SELECT r.*, us.nombre_completo AS usuario_nombre
      FROM revistas r
 LEFT JOIN usuarios us ON us.id = r.usuario_id
     WHERE " . implode(' AND ', $where) . "
  ORDER BY r.iniciado_en DESC
     LIMIT 100
");
$st->execute($params);
$revistas = $st->fetchAll();

// Detectar si hay una revista EN CURSO del usuario
$enCurso = null;
foreach ($revistas as $r) {
    if ($r['estado'] === 'en_curso' && (int)$r['usuario_id'] === (int)$u['id']) {
        $enCurso = $r; break;
    }
}

// Niveles existentes en la BD
$niveles = $pdo->prepare("SELECT DISTINCT nivel FROM parqueadero_celdas WHERE conjunto_id = :c ORDER BY nivel");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll(PDO::FETCH_COLUMN);

$_pageTitle = 'Revistas de parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">🌙 Revistas de parqueadero</h1>
    <p class="page-head__sub">Recorrido nocturno celda por celda con foto y OCR.</p>
</div>

<?php if ($enCurso): ?>
    <div class="flash flash--warn" style="margin-bottom:14px">
        ⚠️ Tienes una <strong>revista en curso</strong> en nivel <?= e($enCurso['nivel']) ?>
        (<?= (int)$enCurso['celdas_revisadas'] ?>/<?= (int)$enCurso['total_celdas'] ?> celdas).
        <a class="btn btn--sm btn--primary" href="<?= url('/rondas/ejecutar?id=' . (int)$enCurso['id']) ?>">↪ Continuar</a>
    </div>
<?php endif; ?>

<div class="toolbar">
    <a class="btn btn--primary btn--lg" href="<?= url('/rondas/nueva') ?>">🌙 Iniciar nueva revista</a>
    <a class="btn" href="<?= url('/lecturas') ?>">📷 Ver todas las lecturas</a>
</div>

<form method="get" action="<?= url('/rondas') ?>" class="filters">
    <select name="nivel">
        <option value="">Todos los niveles</option>
        <?php foreach ($niveles as $nv): ?>
            <option value="<?= e($nv) ?>" <?= $f_nivel === $nv ? 'selected' : '' ?>><?= e($nv) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="estado">
        <option value="">Todos los estados</option>
        <option value="en_curso"  <?= $f_estado === 'en_curso'  ? 'selected' : '' ?>>🔄 En curso</option>
        <option value="terminada" <?= $f_estado === 'terminada' ? 'selected' : '' ?>>✓ Terminadas</option>
        <option value="cancelada" <?= $f_estado === 'cancelada' ? 'selected' : '' ?>>✗ Canceladas</option>
    </select>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/rondas') ?>">Limpiar</a>
</form>

<?php if (empty($revistas)): ?>
    <div class="notice notice--info">Aún no se han realizado revistas. Click en "Iniciar nueva revista".</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Inicio</th><th>Nivel</th><th>Rondero</th>
                <th class="t-right">Progreso</th><th class="t-right">Ocupadas</th><th class="t-right">Vacías</th>
                <th>Estado</th><th>Duración</th><th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($revistas as $r):
            $dur = '';
            if ($r['terminado_en']) {
                $dur = round((strtotime($r['terminado_en']) - strtotime($r['iniciado_en'])) / 60) . ' min';
            } elseif ($r['estado'] === 'en_curso') {
                $dur = round((time() - strtotime($r['iniciado_en'])) / 60) . ' min';
            }
            $pct = $r['total_celdas'] > 0 ? round(100 * $r['celdas_revisadas'] / $r['total_celdas']) : 0;
        ?>
            <tr>
                <td><small><?= e(fecha_humana($r['iniciado_en'])) ?></small></td>
                <td><strong><?= e($r['nivel']) ?></strong></td>
                <td><?= e($r['usuario_nombre'] ?? '—') ?></td>
                <td class="t-right">
                    <?= (int)$r['celdas_revisadas'] ?> / <?= (int)$r['total_celdas'] ?>
                    <br><small class="t-muted"><?= $pct ?>%</small>
                </td>
                <td class="t-right"><?= (int)$r['celdas_ocupadas'] ?></td>
                <td class="t-right"><?= (int)$r['celdas_vacias'] ?></td>
                <td>
                    <?php if ($r['estado'] === 'en_curso'): ?><span class="pill pill--warn">🔄 En curso</span>
                    <?php elseif ($r['estado'] === 'terminada'): ?><span class="pill pill--ok">✓ Terminada</span>
                    <?php else: ?><span class="pill pill--muted">✗ Cancelada</span><?php endif; ?>
                </td>
                <td><small><?= $dur ?></small></td>
                <td class="t-right">
                    <a class="btn btn--sm" href="<?= url('/rondas/ver?id=' . (int)$r['id']) ?>">Ver</a>
                    <?php if ($r['estado'] === 'en_curso' && (int)$r['usuario_id'] === (int)$u['id']): ?>
                        <a class="btn btn--sm btn--primary" href="<?= url('/rondas/ejecutar?id=' . (int)$r['id']) ?>">Continuar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
