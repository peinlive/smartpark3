<?php
// /home/myzonaco/smartpark.myzona360.com/modules/auditoria/index.php
// v1.0 (3AF): Visualización del audit_log — quién hizo qué y cuándo.
//   Solo LECTURA. No escribe en BD.
//   Roles: super_admin (visualizar todo el conjunto).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// Filtros
$f_accion   = clean_string($_GET['accion'] ?? '', 80);
$f_entidad  = clean_string($_GET['entidad'] ?? '', 80);
$f_usuario  = (int)($_GET['usuario_id'] ?? 0);
$f_desde    = clean_string($_GET['desde'] ?? '', 10);
$f_hasta    = clean_string($_GET['hasta'] ?? '', 10);
$f_texto    = clean_string($_GET['q'] ?? '', 200);
$f_pagina   = max(1, (int)($_GET['p'] ?? 1));
$porPagina  = 50;

$where  = ["(al.conjunto_id = :cid OR al.conjunto_id IS NULL)"];
$params = [':cid' => $conjuntoId];

if ($f_accion !== '')  { $where[] = "al.accion = :ac";        $params[':ac']  = $f_accion; }
if ($f_entidad !== '') { $where[] = "al.entidad = :en";       $params[':en']  = $f_entidad; }
if ($f_usuario > 0)    { $where[] = "al.usuario_id = :uid";   $params[':uid'] = $f_usuario; }
if ($f_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_desde)) {
    $where[] = "al.creado_en >= :fd";
    $params[':fd'] = $f_desde . ' 00:00:00';
}
if ($f_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_hasta)) {
    $where[] = "al.creado_en <= :fh";
    $params[':fh'] = $f_hasta . ' 23:59:59';
}
if ($f_texto !== '') {
    $where[] = "(al.descripcion LIKE :qt OR al.datos_despues LIKE :qt2)";
    $params[':qt']  = '%' . $f_texto . '%';
    $params[':qt2'] = '%' . $f_texto . '%';
}
$whereSql = implode(' AND ', $where);

// Total
$stC = $pdo->prepare("SELECT COUNT(*) FROM audit_log al WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPaginas = max(1, (int)ceil($total / $porPagina));
if ($f_pagina > $totalPaginas) $f_pagina = $totalPaginas;
$offset = ($f_pagina - 1) * $porPagina;

// Registros
$sql = "SELECT al.id, al.conjunto_id, al.usuario_id, al.accion, al.entidad,
               al.entidad_id, al.descripcion, al.datos_antes, al.datos_despues,
               al.ip, al.user_agent, al.creado_en,
               up.nombre_completo AS usuario_nombre, up.usuario AS usuario_login
          FROM audit_log al
     LEFT JOIN usuarios up ON up.id = al.usuario_id
         WHERE $whereSql
      ORDER BY al.creado_en DESC, al.id DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$registros = $st->fetchAll();

// Catálogo de acciones distintas para el select (últimos 90 días)
$stAcc = $pdo->prepare("SELECT DISTINCT accion FROM audit_log
                         WHERE (conjunto_id = :c OR conjunto_id IS NULL)
                           AND creado_en >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                         ORDER BY accion");
$stAcc->execute([':c' => $conjuntoId]);
$catAcciones = array_column($stAcc->fetchAll(), 'accion');

$stEnt = $pdo->prepare("SELECT DISTINCT entidad FROM audit_log
                        WHERE (conjunto_id = :c OR conjunto_id IS NULL)
                          AND entidad IS NOT NULL
                          AND creado_en >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                        ORDER BY entidad");
$stEnt->execute([':c' => $conjuntoId]);
$catEntidades = array_column($stEnt->fetchAll(), 'entidad');

// Catálogo de usuarios (para el filtro)
$stU = $pdo->prepare("SELECT id, nombre_completo, usuario FROM usuarios
                       WHERE conjunto_id = :c AND activo = 1
                    ORDER BY nombre_completo");
$stU->execute([':c' => $conjuntoId]);
$catUsuarios = $stU->fetchAll();

// KPIs
$stK = $pdo->prepare("SELECT COUNT(*) FROM audit_log
                       WHERE (conjunto_id = :c OR conjunto_id IS NULL)
                         AND creado_en >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stK->execute([':c' => $conjuntoId]);
$kpi24h = (int)$stK->fetchColumn();

$stK7 = $pdo->prepare("SELECT COUNT(*) FROM audit_log
                        WHERE (conjunto_id = :c OR conjunto_id IS NULL)
                          AND creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stK7->execute([':c' => $conjuntoId]);
$kpi7d = (int)$stK7->fetchColumn();

$stKU = $pdo->prepare("SELECT COUNT(DISTINCT usuario_id) FROM audit_log
                        WHERE (conjunto_id = :c OR conjunto_id IS NULL)
                          AND creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          AND usuario_id IS NOT NULL");
$stKU->execute([':c' => $conjuntoId]);
$kpiUsuarios7d = (int)$stKU->fetchColumn();

// Export a CSV (todo el resultado del filtro, sin paginar)
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha','Usuario','Login','Acción','Entidad','ID Entidad','Descripción','IP','User-Agent'], ';');
    $sqlExp = "SELECT al.*, up.nombre_completo AS usuario_nombre, up.usuario AS usuario_login
                 FROM audit_log al
            LEFT JOIN usuarios up ON up.id = al.usuario_id
                WHERE $whereSql
             ORDER BY al.creado_en DESC LIMIT 5000";
    $stE = $pdo->prepare($sqlExp);
    $stE->execute($params);
    foreach ($stE->fetchAll() as $r) {
        fputcsv($out, [
            $r['creado_en'],
            $r['usuario_nombre'] ?? '',
            $r['usuario_login'] ?? '',
            $r['accion'],
            $r['entidad'] ?? '',
            $r['entidad_id'] ?? '',
            $r['descripcion'] ?? '',
            $r['ip'] ?? '',
            $r['user_agent'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// Colores por tipo de acción
$colorAccion = function($accion) {
    $a = strtolower($accion);
    if (strpos($a, 'delete') !== false || strpos($a, 'eliminar') !== false || strpos($a, 'borr') !== false) return ['#dc2626','#fee2e2'];
    if (strpos($a, 'update') !== false || strpos($a, 'edit') !== false || strpos($a, 'modif') !== false) return ['#d97706','#fef3c7'];
    if (strpos($a, 'create') !== false || strpos($a, 'insert') !== false || strpos($a, 'crear') !== false || strpos($a, 'nuevo') !== false || strpos($a, 'reg') !== false) return ['#15803d','#dcfce7'];
    if (strpos($a, 'login') !== false || strpos($a, 'logout') !== false || strpos($a, 'auth') !== false) return ['#1e40af','#dbeafe'];
    return ['#4b5563','#f3f4f6'];
};

$_pageTitle = 'Auditoría';
include INCLUDES_PATH . '/header.php';
?>

<style>
.aud-head{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
.aud-head h1{margin:0;font-size:20px;}
.aud-head p{margin:6px 0 0;font-size:13px;opacity:.95;}

.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:12px 0 16px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;text-align:center;}
.kpi-card.morado{border-left:5px solid #7c3aed;background:#faf5ff;}
.kpi-card.morado strong{color:#6b21a8;}
.kpi-card strong{display:block;font-size:26px;line-height:1;font-family:monospace;color:#1f2937;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;display:block;margin-top:4px;}

.aud-tabla{width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.aud-tabla th{background:#4c1d95;color:#fff;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.3px;position:sticky;top:0;}
.aud-tabla td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
.aud-tabla tr:hover{background:#faf5ff;}
.aud-tabla .fecha{white-space:nowrap;font-family:monospace;color:#6b7280;font-size:11px;}
.aud-tabla .usuario{font-weight:600;color:#1f2937;}
.accion-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;font-family:monospace;}
.entidad-pill{display:inline-block;padding:1px 6px;border-radius:6px;background:#e0e7ff;color:#4c1d95;font-size:10px;font-weight:600;}
.datos-toggle{background:none;border:none;color:#7c3aed;cursor:pointer;font-size:11px;padding:0;text-decoration:underline;}
.datos-pre{background:#faf5ff;border:1px solid #e9d5ff;border-radius:4px;padding:6px 8px;font-family:monospace;font-size:10px;color:#374151;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow:auto;margin-top:4px;}
.pag-nav{display:flex;justify-content:space-between;align-items:center;margin:14px 0;padding:10px 14px;background:#f8fafc;border-radius:6px;font-size:12px;}
.pag-nav a{padding:5px 12px;background:#7c3aed;color:#fff;border-radius:5px;text-decoration:none;font-size:11px;}
.pag-nav a.disabled{background:#e5e7eb;color:#9ca3af;pointer-events:none;}

.sin-datos{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px;background:#fff;border:1px dashed #e5e7eb;border-radius:8px;}
</style>

<div class="aud-head">
    <h1>🗃️ Auditoría del sistema</h1>
    <p>Registro histórico de acciones realizadas por los usuarios. Solo visualización — no se puede editar.</p>
</div>

<div class="toolbar">
    <?php $qs = $_GET; $qs['export'] = 'csv'; ?>
    <a class="btn btn--primary" href="<?= url('/auditoria') ?>?<?= e(http_build_query($qs)) ?>">📥 Exportar CSV</a>
    <button type="button" class="btn" onclick="window.print()">🖨️ Imprimir</button>
</div>

<div class="kpi-row">
    <div class="kpi-card morado">
        <strong><?= number_format($kpi24h, 0, ',', '.') ?></strong>
        <span>⚡ Acciones últimas 24h</span>
    </div>
    <div class="kpi-card">
        <strong><?= number_format($kpi7d, 0, ',', '.') ?></strong>
        <span>📅 Últimos 7 días</span>
    </div>
    <div class="kpi-card">
        <strong><?= number_format($kpiUsuarios7d, 0, ',', '.') ?></strong>
        <span>👥 Usuarios activos (7d)</span>
    </div>
    <div class="kpi-card">
        <strong><?= number_format($total, 0, ',', '.') ?></strong>
        <span>🔎 Coinciden con filtros</span>
    </div>
</div>

<form method="get" action="<?= url('/auditoria') ?>" class="filters" style="flex-wrap:wrap">
    <input type="text" name="q" placeholder="Buscar en descripción..." value="<?= e($f_texto) ?>" style="min-width:200px">

    <select name="usuario_id">
        <option value="">👥 Todos los usuarios</option>
        <?php foreach ($catUsuarios as $usr): ?>
            <option value="<?= (int)$usr['id'] ?>" <?= $f_usuario === (int)$usr['id'] ? 'selected' : '' ?>>
                <?= e($usr['nombre_completo'] ?: $usr['usuario']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="accion">
        <option value="">🎯 Todas las acciones</option>
        <?php foreach ($catAcciones as $ac): ?>
            <option value="<?= e($ac) ?>" <?= $f_accion === $ac ? 'selected' : '' ?>><?= e($ac) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="entidad">
        <option value="">📦 Todas las entidades</option>
        <?php foreach ($catEntidades as $en): ?>
            <option value="<?= e($en) ?>" <?= $f_entidad === $en ? 'selected' : '' ?>><?= e($en) ?></option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="desde" value="<?= e($f_desde) ?>" title="Desde">
    <input type="date" name="hasta" value="<?= e($f_hasta) ?>" title="Hasta">

    <button type="submit" class="btn btn--primary" style="background:#7c3aed">Filtrar</button>
    <a class="btn" href="<?= url('/auditoria') ?>">Limpiar</a>
</form>

<?php if (empty($registros)): ?>
    <div class="sin-datos">
        📭 No hay registros que coincidan con los filtros aplicados.
        <?php if (empty($f_texto) && !$f_usuario && $f_accion === '' && $f_entidad === '' && $f_desde === '' && $f_hasta === ''): ?>
            <br><small style="margin-top:8px;display:block">La tabla audit_log no tiene registros aún, o las acciones del sistema no están escribiendo en ella.</small>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="pag-nav">
        <div>
            Mostrando <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $porPagina, $total)) ?>
            de <?= number_format($total) ?> registros
            (página <?= $f_pagina ?> de <?= $totalPaginas ?>)
        </div>
        <div>
            <?php $qsPag = $_GET; ?>
            <?php $qsPag['p'] = 1; ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina === 1 ? 'disabled' : '' ?>">« Primera</a>
            <?php $qsPag['p'] = max(1, $f_pagina - 1); ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina === 1 ? 'disabled' : '' ?>">‹ Anterior</a>
            <?php $qsPag['p'] = min($totalPaginas, $f_pagina + 1); ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina >= $totalPaginas ? 'disabled' : '' ?>">Siguiente ›</a>
            <?php $qsPag['p'] = $totalPaginas; ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina >= $totalPaginas ? 'disabled' : '' ?>">Última »</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="aud-tabla">
            <thead>
                <tr>
                    <th style="min-width:130px">Fecha / Hora</th>
                    <th style="min-width:120px">Usuario</th>
                    <th style="min-width:130px">Acción</th>
                    <th style="min-width:100px">Entidad</th>
                    <th>Descripción</th>
                    <th style="min-width:110px">IP</th>
                    <th style="min-width:60px">Datos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registros as $r):
                    list($colorTxt, $colorBg) = $colorAccion($r['accion']);
                ?>
                    <tr>
                        <td class="fecha">
                            <?= e(date('d/m/Y', strtotime($r['creado_en']))) ?><br>
                            <span style="color:#4b5563"><?= e(date('H:i:s', strtotime($r['creado_en']))) ?></span>
                        </td>
                        <td class="usuario">
                            <?php if ($r['usuario_nombre'] || $r['usuario_login']): ?>
                                <?= e($r['usuario_nombre'] ?: $r['usuario_login']) ?>
                                <?php if ($r['usuario_login'] && $r['usuario_nombre']): ?>
                                    <br><small class="t-muted">@<?= e($r['usuario_login']) ?></small>
                                <?php endif; ?>
                            <?php elseif ($r['usuario_id']): ?>
                                <span class="t-muted">Usuario #<?= (int)$r['usuario_id'] ?></span>
                            <?php else: ?>
                                <span class="t-muted">Sistema</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="accion-pill" style="background:<?= $colorBg ?>;color:<?= $colorTxt ?>">
                                <?= e($r['accion']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r['entidad']): ?>
                                <span class="entidad-pill"><?= e($r['entidad']) ?></span>
                                <?php if ($r['entidad_id']): ?>
                                    <br><small class="t-muted">#<?= (int)$r['entidad_id'] ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="t-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['descripcion'] ?: '—') ?></td>
                        <td style="font-family:monospace;font-size:10px;color:#6b7280">
                            <?= e($r['ip'] ?: '—') ?>
                        </td>
                        <td>
                            <?php if ($r['datos_antes'] || $r['datos_despues']): ?>
                                <button type="button" class="datos-toggle" onclick="audToggle(<?= (int)$r['id'] ?>)">Ver</button>
                            <?php else: ?>
                                <span class="t-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($r['datos_antes'] || $r['datos_despues']): ?>
                        <tr id="datos-<?= (int)$r['id'] ?>" style="display:none">
                            <td colspan="7" style="background:#faf5ff;padding:12px">
                                <?php if ($r['datos_antes']): ?>
                                    <div style="margin-bottom:8px">
                                        <strong style="font-size:11px;color:#991b1b">Datos ANTES:</strong>
                                        <div class="datos-pre"><?= e($r['datos_antes']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['datos_despues']): ?>
                                    <div>
                                        <strong style="font-size:11px;color:#166534">Datos DESPUÉS:</strong>
                                        <div class="datos-pre"><?= e($r['datos_despues']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['user_agent']): ?>
                                    <div style="margin-top:8px">
                                        <strong style="font-size:11px;color:#6b7280">User-Agent:</strong>
                                        <small style="font-family:monospace;color:#6b7280"><?= e($r['user_agent']) ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pag-nav">
        <div>
            Página <?= $f_pagina ?> de <?= $totalPaginas ?>
        </div>
        <div>
            <?php $qsPag = $_GET; $qsPag['p'] = max(1, $f_pagina - 1); ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina === 1 ? 'disabled' : '' ?>">‹ Anterior</a>
            <?php $qsPag['p'] = min($totalPaginas, $f_pagina + 1); ?>
            <a href="<?= url('/auditoria') ?>?<?= e(http_build_query($qsPag)) ?>" class="<?= $f_pagina >= $totalPaginas ? 'disabled' : '' ?>">Siguiente ›</a>
        </div>
    </div>
<?php endif; ?>

<div style="margin-top:14px;padding:10px 14px;background:#f8fafc;border-radius:6px;font-size:11px;color:#6b7280;line-height:1.6">
    💡 <strong>Sobre este módulo:</strong> muestra las acciones registradas en <code>audit_log</code>.
    Los módulos actuales pueden estar escribiendo o no. Cuando implementemos triggers/hooks en las
    partes críticas (edición de vehículos, eliminación de residentes, cambios de estado, etc.),
    esta pantalla se llenará automáticamente. Máximo 5.000 registros por export CSV.
</div>

<script>
function audToggle(id) {
    var el = document.getElementById('datos-' + id);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}
</script>

<style media="print">
    .toolbar, .filters, .sidebar, .pag-nav, header, footer { display:none !important; }
    .aud-head { background:#4c1d95 !important; -webkit-print-color-adjust:exact; }
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>
