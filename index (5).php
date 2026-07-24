<?php
// /home/myzonaco/smartpark.myzona360.com/modules/usuarios/index.php
// v1.0 (3V): Lista de usuarios con estado (activo/bloqueado) y roles.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$pdo = db();
$u   = auth_user();
$uidActual = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

$f_rol    = clean_string($_GET['rol'] ?? '', 40);
$f_estado = in_array($_GET['estado'] ?? '', ['activos','inactivos','bloqueados'], true) ? $_GET['estado'] : '';
$f_q      = clean_string($_GET['q'] ?? '', 100);

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

// Un admin (no super_admin) sólo ve usuarios de su conjunto
$where = [];
$params = [];
if (!$esSuperAdmin) { $where[] = 'u.conjunto_id = :c'; $params[':c'] = $conjuntoId; }

if ($f_estado === 'activos')     { $where[] = 'u.activo = 1 AND (u.bloqueado_hasta IS NULL OR u.bloqueado_hasta < NOW())'; }
if ($f_estado === 'inactivos')   { $where[] = 'u.activo = 0'; }
if ($f_estado === 'bloqueados')  { $where[] = 'u.bloqueado_hasta > NOW()'; }
if ($f_q !== '') {
    $where[] = '(u.username LIKE :q OR u.nombre_completo LIKE :q OR u.email LIKE :q)';
    $params[':q'] = '%' . $f_q . '%';
}
if ($f_rol !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM usuario_roles ur2 JOIN roles r2 ON r2.id = ur2.rol_id
                        WHERE ur2.usuario_id = u.id AND r2.codigo = :rl)';
    $params[':rl'] = $f_rol;
}
$whereSql = $where ? implode(' AND ', $where) : '1=1';

// KPIs
$kSql = "SELECT COUNT(*) AS total,
                SUM(CASE WHEN activo=1 AND (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW()) THEN 1 ELSE 0 END) AS act,
                SUM(CASE WHEN activo=0 THEN 1 ELSE 0 END) AS inact,
                SUM(CASE WHEN bloqueado_hasta > NOW() THEN 1 ELSE 0 END) AS block
           FROM usuarios u ";
$kSql .= $esSuperAdmin ? '' : ' WHERE u.conjunto_id = :c ';
$kSt = $pdo->prepare($kSql);
$kSt->execute($esSuperAdmin ? [] : [':c' => $conjuntoId]);
$kpi = $kSt->fetch();

$stC = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

$sql = "SELECT u.id, u.username, u.nombre_completo, u.email, u.celular,
               u.activo, u.bloqueado_hasta, u.ultimo_login, u.creado_en,
               GROUP_CONCAT(r.codigo ORDER BY r.nivel DESC SEPARATOR ',') AS roles
          FROM usuarios u
     LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
     LEFT JOIN roles r ON r.id = ur.rol_id
         WHERE $whereSql
      GROUP BY u.id
      ORDER BY u.nombre_completo
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$usuarios = $st->fetchAll();

$rolesAll = $pdo->query("SELECT codigo, nombre FROM roles ORDER BY nivel DESC")->fetchAll();

function rolBadge($codigo) {
    $mapa = [
        'super_admin' => ['#7c3aed','#ede9fe'],
        'admin'       => ['#1e40af','#dbeafe'],
        'supervisor'  => ['#0e7490','#cffafe'],
        'porteria'    => ['#9a3412','#fed7aa'],
        'ronda'       => ['#166534','#dcfce7'],
    ];
    $c = $mapa[$codigo] ?? ['#374151','#e5e7eb'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:{$c[1]};color:{$c[0]};margin:1px\">$codigo</span>";
}

$_pageTitle = 'Usuarios';
include INCLUDES_PATH . '/header.php';
?>

<style>
.kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;flex:1;min-width:100px;}
.kpi-card strong{display:block;font-size:20px;} .kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.kpi-card.act strong{color:#15803d;} .kpi-card.inact strong{color:#6b7280;} .kpi-card.block strong{color:#dc2626;}
.pill--act{background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill--inact{background:#e5e7eb;color:#4b5563;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill--block{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
</style>

<div class="page-head">
    <h1 class="page-head__title">🧑‍💼 Usuarios</h1>
    <p class="page-head__sub"><?= $total ?> usuario<?= $total === 1 ? '' : 's' ?>.</p>
</div>

<div class="kpi-row">
    <div class="kpi-card"><strong><?= (int)$kpi['total'] ?></strong><span>Total</span></div>
    <div class="kpi-card act"><strong><?= (int)$kpi['act'] ?></strong><span>Activos</span></div>
    <div class="kpi-card inact"><strong><?= (int)$kpi['inact'] ?></strong><span>Inactivos</span></div>
    <div class="kpi-card block"><strong><?= (int)$kpi['block'] ?></strong><span>Bloqueados</span></div>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/usuarios/crear') ?>">+ Nuevo usuario</a>
</div>

<form method="get" action="<?= url('/usuarios') ?>" class="filters">
    <input type="text" name="q" placeholder="Buscar por nombre, usuario o email" value="<?= e($f_q) ?>" style="min-width:220px">
    <select name="rol">
        <option value="">Todos los roles</option>
        <?php foreach ($rolesAll as $r): ?>
            <option value="<?= e($r['codigo']) ?>" <?= $f_rol === $r['codigo'] ? 'selected' : '' ?>>
                <?= e($r['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="estado">
        <option value="">Cualquier estado</option>
        <option value="activos"     <?= $f_estado === 'activos'     ? 'selected' : '' ?>>✅ Activos</option>
        <option value="inactivos"   <?= $f_estado === 'inactivos'   ? 'selected' : '' ?>>⏸️ Inactivos</option>
        <option value="bloqueados"  <?= $f_estado === 'bloqueados'  ? 'selected' : '' ?>>🔒 Bloqueados</option>
    </select>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/usuarios') ?>">Limpiar</a>
</form>

<?php if (empty($usuarios)): ?>
    <div class="notice notice--info">No hay usuarios que coincidan con los filtros.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nombre</th><th>Username</th><th>Email</th><th>Roles</th>
                <th>Estado</th><th>Último login</th>
                <th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $usr):
            $id = (int)$usr['id'];
            $bloqueado = $usr['bloqueado_hasta'] && strtotime($usr['bloqueado_hasta']) > time();
            $tieneSuperAdmin = strpos((string)$usr['roles'], 'super_admin') !== false;
            $puedoEditar = $esSuperAdmin || !$tieneSuperAdmin;
        ?>
            <tr>
                <td>
                    <strong><?= e($usr['nombre_completo']) ?></strong>
                    <?php if ($id === $uidActual): ?><small style="color:#1e6cff"> (tú)</small><?php endif; ?>
                </td>
                <td><code><?= e($usr['username']) ?></code></td>
                <td><?= e($usr['email']) ?></td>
                <td>
                    <?php if ($usr['roles']): foreach (explode(',', $usr['roles']) as $rc): echo rolBadge($rc); endforeach; else: ?><span class="t-muted">sin roles</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($bloqueado): ?>
                        <span class="pill--block">🔒 Bloqueado</span>
                        <br><small class="t-muted">hasta <?= e(date('d/m H:i', strtotime($usr['bloqueado_hasta']))) ?></small>
                    <?php elseif ((int)$usr['activo'] === 1): ?>
                        <span class="pill--act">✅ Activo</span>
                    <?php else: ?>
                        <span class="pill--inact">⏸️ Inactivo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $usr['ultimo_login'] ? e(date('d/m/Y H:i', strtotime($usr['ultimo_login']))) : '<span class="t-muted">nunca</span>' ?>
                </td>
                <td class="t-right">
                    <?php if ($puedoEditar): ?>
                        <a class="btn btn--sm" href="<?= url('/usuarios/editar?id=' . $id) ?>" title="Editar">✏️</a>
                        <?php if ($id !== $uidActual): ?>
                            <button type="button" class="btn btn--sm" style="background:#fee2e2;color:#991b1b"
                                    onclick="usrEliminar(<?= $id ?>, '<?= e(addslashes($usr['username'])) ?>')" title="Eliminar">🗑️</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="t-muted" style="font-size:11px">— sin permiso —</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']); $base = url('/usuarios') . '?' . http_build_query($qs); $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif;
            endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
window.USR_CSRF = <?= json_encode(csrf_token()) ?>;
window.USR_ELIM_URL = <?= json_encode(url('/usuarios/eliminar')) ?>;
function usrEliminar(id, username) {
    if (!confirm('⚠️ ¿ELIMINAR el usuario "' + username + '"?\n\nNo se puede deshacer.')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.USR_ELIM_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.USR_CSRF+'">' +
                  '<input type="hidden" name="id" value="'+id+'">';
    document.body.appendChild(f); f.submit();
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
