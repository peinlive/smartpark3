<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/index.php
// v3BH: agrega columna "Apto usuario" (con badge de tipo asignación) al lado de "Apto dueño".
//   Basado en v3n de Rafael. Cambios mínimos, retrocompatible.
//   Los aptos son clickeables con el helper /public/js/apto_popover.js.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ───── Filtros ─────
$f_codigo = clean_string($_GET['codigo'] ?? '', 30);

$f_niveles = $_GET['nivel'] ?? '';
if (!is_array($f_niveles)) $f_niveles = ($f_niveles !== '' ? [$f_niveles] : []);
$f_niveles = array_values(array_filter(array_map('intval', $f_niveles), fn($x) => $x > 0));

$tiposValidos = ['comun','privada','moto_comun','libre','movilidad_reducida'];
$f_tipos = $_GET['tipo'] ?? '';
if (!is_array($f_tipos)) $f_tipos = ($f_tipos !== '' ? [$f_tipos] : []);
$f_tipos = array_values(array_intersect($f_tipos, $tiposValidos));

$f_apto  = clean_string($_GET['apto']  ?? '', 20);
$f_vista = in_array($_GET['vista'] ?? '', ['activas','inactivas','todas'], true) ? $_GET['vista'] : 'activas';

// ───── Sort (v3BH: agregado apto_usuario) ─────
$sortAllowed = [
    'codigo'       => "CAST(REGEXP_REPLACE(c.nombre_visible, '[^0-9]', '') AS UNSIGNED)",
    'nivel'        => "n.orden, n.codigo, CAST(REGEXP_REPLACE(c.nombre_visible, '[^0-9]', '') AS UNSIGNED)",
    'tipo'         => 'c.tipo',
    'apto_dueno'   => 'a.numero_visible',
    'apto_usuario' => 'au.numero_visible',
    'activa'       => 'c.activa',
    'creado_en'    => 'c.creado_en',
];
$sort = (string)($_GET['sort'] ?? '');
$dir  = strtoupper((string)($_GET['dir'] ?? '')) === 'DESC' ? 'DESC' : 'ASC';
if (!isset($sortAllowed[$sort])) {
    // v7.68: primero por NIVEL, después por el NÚMERO de la celda (menor a mayor)
    $orderBy = "n.orden ASC, n.codigo ASC, CAST(REGEXP_REPLACE(c.nombre_visible, '[^0-9]', '') AS UNSIGNED) ASC, c.nombre_visible ASC";
    $sort = '';
} else {
    $orderBy = $sortAllowed[$sort] . ' ' . $dir . ', c.nombre_visible ASC';
}

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 100;
$offset    = ($pagina - 1) * $porPagina;

// ───── WHERE ─────
$where  = ['c.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];

if ($f_codigo !== '') { $where[] = 'c.nombre_visible LIKE :cd'; $params[':cd'] = '%' . $f_codigo . '%'; }
if (!empty($f_niveles)) {
    $inList = implode(',', array_map('intval', $f_niveles));
    $where[] = "c.nivel_id IN ($inList)";
}
if (!empty($f_tipos)) {
    $tlist = "'" . implode("','", $f_tipos) . "'";
    $where[] = "c.tipo IN ($tlist)";
}
if ($f_apto !== '') {
    // v3BH: buscar por apto dueño Y por apto usuario
    $where[] = '(a.numero_visible LIKE :ap OR au.numero_visible LIKE :ap2)';
    $params[':ap']  = '%' . $f_apto . '%';
    $params[':ap2'] = '%' . $f_apto . '%';
}
if     ($f_vista === 'activas')   $where[] = 'c.activa = 1';
elseif ($f_vista === 'inactivas') $where[] = 'c.activa = 0';
$whereSql = implode(' AND ', $where);

// ───── FROM/JOINs (v3BH: LEFT JOIN asignaciones + apto usuario) ─────
$fromJoins = "  FROM celdas c
                JOIN niveles_parqueadero n ON n.id = c.nivel_id
           LEFT JOIN apartamentos a  ON a.id  = c.apto_dueno_id
           LEFT JOIN asignaciones_celdas ac ON ac.celda_id = c.id
                                            AND ac.activa = 1
                                            AND ac.archivado_en IS NULL
           LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id";

// ───── Conteo ─────
$stC = $pdo->prepare("SELECT COUNT(*) $fromJoins WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

// ───── KPIs globales (no cambian) ─────
$kpi = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN tipo = 'comun'              AND activa = 1 THEN 1 ELSE 0 END) AS comun,
        SUM(CASE WHEN tipo = 'privada'            AND activa = 1 THEN 1 ELSE 0 END) AS privada,
        SUM(CASE WHEN tipo = 'moto_comun'         AND activa = 1 THEN 1 ELSE 0 END) AS moto,
        SUM(CASE WHEN tipo = 'libre'              AND activa = 1 THEN 1 ELSE 0 END) AS libre,
        SUM(CASE WHEN tipo = 'movilidad_reducida' AND activa = 1 THEN 1 ELSE 0 END) AS movred,
        SUM(CASE WHEN activa = 0 THEN 1 ELSE 0 END)                                  AS inactivas
    FROM celdas WHERE conjunto_id = :c");
$kpi->execute([':c' => $conjuntoId]);
$kpi = $kpi->fetch();

// v3BH: KPI adicional — celdas asignadas a otro apto
$kpiAsig = 0;
try {
    $stAsig = $pdo->prepare("SELECT COUNT(DISTINCT ac.celda_id)
                               FROM asignaciones_celdas ac
                               JOIN celdas c ON c.id = ac.celda_id
                              WHERE c.conjunto_id = :c
                                AND ac.activa = 1 AND ac.archivado_en IS NULL
                                AND ac.apto_usuario_id != ac.apto_dueno_id");
    $stAsig->execute([':c' => $conjuntoId]);
    $kpiAsig = (int)$stAsig->fetchColumn();
} catch (Exception $e) { /* defensivo */ }

// ───── Listado (v3BH: campos de asignación) ─────
$sql = "SELECT c.*,
               c.nombre_visible AS codigo,
               n.codigo AS nivel_codigo, n.nombre AS nivel_nombre, n.tipo AS nivel_tipo,
               a.numero_visible AS apto_dueno_numero,
               (SELECT t.numero FROM apartamentos ap JOIN torres t ON t.id = ap.torre_id WHERE ap.id = c.apto_dueno_id) AS apto_dueno_torre,
               au.numero_visible AS apto_usuario_numero,
               (SELECT t2.numero FROM apartamentos ap2 JOIN torres t2 ON t2.id = ap2.torre_id WHERE ap2.id = ac.apto_usuario_id) AS apto_usuario_torre,
               ac.tipo AS asig_tipo,
               ac.valor_mensual AS asig_valor,
               ac.fecha_inicio AS asig_desde,
               ac.fecha_fin AS asig_hasta
        $fromJoins
        WHERE $whereSql
     ORDER BY $orderBy
        LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$celdas = $st->fetchAll();

$niveles = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

function sortLinkP($col, $label, $sortActual, $dirActual) {
    $qs = $_GET; unset($qs['p']);
    $nuevoDir = 'ASC';
    $flecha = '<span class="sort-arrow sort-arrow--inactive">↕</span>';
    if ($sortActual === $col) {
        $nuevoDir = $dirActual === 'ASC' ? 'DESC' : 'ASC';
        $flecha = '<span class="sort-arrow sort-arrow--active">' . ($dirActual === 'ASC' ? '↑' : '↓') . '</span>';
    }
    $qs['sort'] = $col; $qs['dir'] = $nuevoDir;
    $href = url('/parqueadero') . '?' . http_build_query($qs);
    $cls = $sortActual === $col ? 'sort-link is-active' : 'sort-link';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">' . htmlspecialchars($label) . ' ' . $flecha . '</a>';
}

function pintarTipoCelda($tipo) {
    $map = [
        'comun'              => ['🌐 Común',        'background:#dbeafe;color:#1e3a8a'],
        'privada'            => ['🔒 Privada',      'background:#fef3c7;color:#92400e'],
        'moto_comun'         => ['🏍️ Moto común',   'background:#dcfce7;color:#166534'],
        'libre'              => ['🆓 Libre',        'background:#f3f4f6;color:#374151'],
        'movilidad_reducida' => ['♿ Mov. reducida', 'background:#ede9fe;color:#5b21b6'],
    ];
    $info = $map[$tipo] ?? ['👤 ' . ucfirst($tipo), 'background:#f3f4f6;color:#374151'];
    return '<span class="pill" style="' . $info[1] . '">' . $info[0] . '</span>';
}

// v3BH: badge del tipo de asignación
function pintarAsigTipo($tipo, $valor = null) {
    $map = [
        'uso_propio'      => ['✅ Uso propio', '#dcfce7', '#166534'],
        'prestamo_gratis' => ['🤝 Autorizado', '#dbeafe', '#1e40af'],
        'alquiler'        => ['💰 Alquiler',   '#fef3c7', '#92400e'],
    ];
    $t = $map[$tipo] ?? null;
    if (!$t) return '';
    $extra = '';
    if ($tipo === 'alquiler' && $valor > 0) {
        $extra = ' <small>$' . number_format((float)$valor, 0, ',', '.') . '/mes</small>';
    }
    return '<span class="pill" style="background:' . $t[1] . ';color:' . $t[2] . ';font-size:10px">' . $t[0] . $extra . '</span>';
}

function fechaCorta($f) {
    if (empty($f)) return '<span class="t-muted">—</span>';
    $ts = strtotime($f);
    if ($ts === false) return '<span class="t-muted">—</span>';
    return '<span class="fecha-registro">' . date('d/m/Y', $ts) . '<br><small>' . date('H:i', $ts) . '</small></span>';
}

$_pageTitle = 'Parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<style>
.sp-multi{position:relative;display:inline-block;min-width:170px;vertical-align:middle;}
.sp-multi-btn{width:100%;padding:7px 30px 7px 12px;background:#fff;border:1px solid #d1d5db;
    border-radius:5px;cursor:pointer;text-align:left;font-size:13px;color:#374151;white-space:nowrap;
    overflow:hidden;text-overflow:ellipsis;position:relative;line-height:1.4;}
.sp-multi-btn:hover{border-color:#9ca3af;}
.sp-multi-btn:after{content:"\25BE";position:absolute;right:10px;top:50%;
    transform:translateY(-50%);color:#6b7280;font-size:11px;}
.sp-multi-btn.is-active{background:#eff6ff;border-color:#1e6cff;color:#1e3a8a;font-weight:600;}
.sp-multi-panel{position:absolute;top:100%;left:0;margin-top:4px;background:#fff;
    border:1px solid #d1d5db;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.12);
    z-index:50;max-height:280px;overflow-y:auto;padding:6px 0;
    display:none !important;min-width:230px;}
.sp-multi-panel.is-open{display:block !important;}
.sp-multi-panel label{display:flex;align-items:center;gap:8px;padding:7px 12px;
    cursor:pointer;font-size:13px;color:#374151;font-weight:normal;margin:0;}
.sp-multi-panel label:hover{background:#f3f4f6;}
.sp-multi-panel input[type=checkbox]{margin:0;width:auto;}
.sp-multi-actions{display:flex;gap:6px;padding:6px 10px;border-top:1px solid #e5e7eb;}
.sp-multi-actions button{flex:1;padding:5px;font-size:12px;border:1px solid #d1d5db;
    background:#fff;border-radius:4px;cursor:pointer;color:#374151;}
.sp-multi-actions button:hover{background:#f3f4f6;}

.sort-link{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;}
.sort-link:hover{color:#1e6cff;}
.sort-link.is-active{color:#1e6cff;font-weight:600;}
.sort-arrow{font-size:11px;opacity:.55;}
.sort-arrow--active{opacity:1;color:#1e6cff;}
.sort-arrow--inactive{opacity:.35;}
.fecha-registro{font-size:12px;color:#6b7280;white-space:nowrap;line-height:1.3;}
.fecha-registro small{color:#9ca3af;font-size:11px;}
.bulk-bar{position:sticky;bottom:0;background:#1f2937;color:white;padding:14px 20px;margin:16px -16px 0;display:none;align-items:center;gap:12px;box-shadow:0 -3px 10px rgba(0,0,0,.15);border-top:3px solid #3b82f6;z-index:100;flex-wrap:wrap;}
.bulk-bar.visible{display:flex;}
.bulk-count{font-weight:600;flex:1;min-width:140px;}
.bulk-bar button{padding:8px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:500;color:white;}
.bulk-desact{background:#f59e0b;}
.bulk-act{background:#10b981;}
.bulk-elim{background:#dc2626;}
.acciones-fila{display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;}
.acciones-fila .btn--sm{padding:4px 8px;}
.acciones-fila .btn--desact{background:#fef3c7;color:#92400e;}
.acciones-fila .btn--act{background:#d1fae5;color:#065f46;}
.acciones-fila .btn--elim{background:#fee2e2;color:#991b1b;}
.acciones-fila .btn--asig{background:#ede9fe;color:#5b21b6;}
.col-check{width:32px;text-align:center;}
.kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;min-width:90px;}
.kpi-card strong{display:block;font-size:20px;color:#1f2937;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.kpi-card--asig{background:#ede9fe;border-color:#c4b5fd;}
.kpi-card--asig strong{color:#5b21b6;}

/* v3BH: link apto clickeable (popover) */
.apto-link{color:#1e40af;text-decoration:none;font-weight:600;cursor:pointer;
    border-bottom:1px dotted #93c5fd;padding-bottom:1px;}
.apto-link:hover{color:#1d4ed8;border-bottom-style:solid;background:#eff6ff;}
.apto-link--usuario{color:#5b21b6;border-bottom-color:#c4b5fd;}
.apto-link--usuario:hover{color:#4c1d95;background:#f5f3ff;}
</style>

<div class="page-head">
    <h1 class="page-head__title">Parqueadero — Celdas</h1>
    <p class="page-head__sub"><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?>.</p>
</div>
<!-- v7.5: exportar a Excel/CSV -->
<div style="margin:-6px 0 14px">
  <a href="<?= url('/exportar?t=parqueadero') ?>" class="btn btn--sm"
     style="background:#065f46;color:#fff;text-decoration:none;display:inline-flex;
            align-items:center;gap:5px">
    📊 Exportar a Excel
  </a>
  <small style="color:#9ca3af;margin-left:7px">
    Descarga un CSV con todo. Sirve de respaldo y para revisar en Excel.
  </small>
</div>


<div class="kpi-row">
    <div class="kpi-card"><strong><?= (int)$kpi['total'] ?></strong><span>Total</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['comun'] ?></strong><span>🌐 Común</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['privada'] ?></strong><span>🔒 Privada</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['moto'] ?></strong><span>🏍️ Moto</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['libre'] ?></strong><span>🆓 Libre</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['movred'] ?></strong><span>♿ Mov. red.</span></div>
    <div class="kpi-card"><strong><?= (int)$kpi['inactivas'] ?></strong><span>Inactivas</span></div>
    <?php if ($kpiAsig > 0): ?>
        <div class="kpi-card kpi-card--asig"><strong><?= $kpiAsig ?></strong><span>👤 Asignadas a otro</span></div>
    <?php endif; ?>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/parqueadero/crear') ?>">+ Nueva celda</a>
    <a class="btn" href="<?= url('/parqueadero/crear_bloque') ?>">📦 Crear en bloque</a>
    <a class="btn" href="<?= url('/parqueadero/importar') ?>">📥 Importar CSV</a>
    <a class="btn" href="<?= url('/parqueadero/niveles') ?>">⚙️ Gestionar niveles</a>
    <a class="btn" href="<?= url('/asignaciones') ?>"><span>🔑</span> Asignaciones</a>
</div>

<form method="get" action="<?= url('/parqueadero') ?>" class="filters">
    <input type="text" name="codigo" placeholder="Código" value="<?= e($f_codigo) ?>" maxlength="30">

    <div class="sp-multi" data-label="Niveles" data-all="Todos los niveles">
        <button type="button" class="sp-multi-btn">Todos los niveles</button>
        <div class="sp-multi-panel">
            <?php foreach ($niveles as $n): ?>
                <label>
                    <input type="checkbox" name="nivel[]" value="<?= (int)$n['id'] ?>"
                           <?= in_array((int)$n['id'], $f_niveles, true) ? 'checked' : '' ?>>
                    <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sp-multi" data-label="Tipos" data-all="Todos los tipos">
        <button type="button" class="sp-multi-btn">Todos los tipos</button>
        <div class="sp-multi-panel">
            <?php foreach ([
                'comun' => '🌐 Común', 'privada' => '🔒 Privada', 'moto_comun' => '🏍️ Moto común',
                'libre' => '🆓 Libre', 'movilidad_reducida' => '♿ Movilidad reducida'
            ] as $k => $v): ?>
                <label>
                    <input type="checkbox" name="tipo[]" value="<?= $k ?>" <?= in_array($k, $f_tipos, true) ? 'checked' : '' ?>>
                    <?= $v ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <input type="text" name="apto" placeholder="Apto (dueño o usuario)" value="<?= e($f_apto) ?>" maxlength="20">
    <select name="vista">
        <option value="activas"    <?= $f_vista === 'activas'    ? 'selected' : '' ?>>✓ Activas</option>
        <option value="inactivas"  <?= $f_vista === 'inactivas'  ? 'selected' : '' ?>>○ Inactivas</option>
        <option value="todas"      <?= $f_vista === 'todas'      ? 'selected' : '' ?>>Todas</option>
    </select>
    <?php if ($sort): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir"  value="<?= e($dir) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/parqueadero') ?>">Limpiar</a>
</form>

<?php if (empty($celdas)): ?>
    <?php if ((int)$kpi['total'] === 0): ?>
        <div class="notice notice--info">
            🅿️ Aún no hay celdas creadas en este conjunto.<br>
            Comienza por <a href="<?= url('/parqueadero/niveles') ?>"><strong>crear los niveles</strong></a>, luego usa
            <a href="<?= url('/parqueadero/crear_bloque') ?>"><strong>Crear en bloque</strong></a> para dar de alta varias a la vez.
        </div>
    <?php else: ?>
        <div class="notice notice--info">No hay celdas que coincidan con los filtros.</div>
    <?php endif; ?>
<?php else: ?>
    <form id="bulk-form" method="POST" action="<?= url('/parqueadero/acciones_batch') ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '/parqueadero') ?>">

        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" onchange="spkToggleAll(this)"></th>
                    <th><?= sortLinkP('codigo',       'Código',       $sort, $dir) ?></th>
                    <th><?= sortLinkP('nivel',        'Nivel',        $sort, $dir) ?></th>
                    <th><?= sortLinkP('tipo',         'Tipo',         $sort, $dir) ?></th>
                    <th>Permite</th>
                    <th><?= sortLinkP('apto_dueno',   'Apto dueño',   $sort, $dir) ?></th>
                    <th><?= sortLinkP('apto_usuario', 'Apto usuario', $sort, $dir) ?></th>
                    <th>Observaciones</th>
                    <th><?= sortLinkP('creado_en',    'Registro',     $sort, $dir) ?></th>
                    <th><?= sortLinkP('activa',       'Estado',       $sort, $dir) ?></th>
                    <th class="t-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($celdas as $c):
                $id = (int)$c['id'];
                $labelJs = addslashes($c['codigo']);
            ?>
                <tr<?= (int)$c['activa'] === 0 ? ' style="background:#f9fafb;color:#9ca3af"' : '' ?>>
                    <td class="col-check">
                        <input type="checkbox" name="seleccion[]" value="<?= $id ?>" onchange="spkUpdateBulkBar()">
                    </td>
                    <td><strong><?= e($c['codigo']) ?></strong></td>
                    <td><?= e($c['nivel_codigo']) ?></td>
                    <td><?= pintarTipoCelda($c['tipo']) ?></td>
                    <td>
                        <?= (int)$c['permite_carro'] === 1 ? '🚗' : '' ?>
                        <?= (int)$c['permite_moto']  === 1 ? '🏍️' : '' ?>
                    </td>
                    <td>
                        <?php if ($c['apto_dueno_numero']): ?>
                            <a class="apto-link" data-apto="<?= e($c['apto_dueno_numero']) ?>"
                               href="<?= url('/consultas?apto=' . urlencode($c['apto_dueno_numero'])) ?>"
                               title="Ver detalles del apto">
                                <?= e($c['apto_dueno_numero']) ?>
                            </a>
                            <?php if ($c['apto_dueno_torre']): ?><span class="t-muted">(T<?= (int)$c['apto_dueno_torre'] ?>)</span><?php endif; ?>
                        <?php else: ?>
                            <span class="t-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['apto_usuario_numero']): ?>
                            <a class="apto-link apto-link--usuario" data-apto="<?= e($c['apto_usuario_numero']) ?>"
                               href="<?= url('/consultas?apto=' . urlencode($c['apto_usuario_numero'])) ?>"
                               title="Ver detalles del apto usuario">
                                <?= e($c['apto_usuario_numero']) ?>
                            </a>
                            <?php if ($c['apto_usuario_torre']): ?><span class="t-muted">(T<?= (int)$c['apto_usuario_torre'] ?>)</span><?php endif; ?>
                            <br><?= pintarAsigTipo($c['asig_tipo'], $c['asig_valor']) ?>
                        <?php elseif ($c['apto_dueno_numero']): ?>
                            <span class="t-muted" title="No hay asignación activa a otro apto">Usa dueño</span>
                        <?php else: ?>
                            <span class="t-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $obs = trim((string)($c['observaciones'] ?? ''));
                        if ($obs !== '') {
                            $obsCorta = mb_strlen($obs) > 50 ? mb_substr($obs, 0, 47) . '…' : $obs;
                            echo '<span title="' . e($obs) . '">' . e($obsCorta) . '</span>';
                        } else echo '<span class="t-muted">—</span>';
                        ?>
                    </td>
                    <td><?= fechaCorta($c['creado_en']) ?></td>
                    <td>
                        <?php if ((int)$c['activa'] === 1): ?>
                            <span class="pill pill--ok">Activa</span>
                        <?php else: ?>
                            <span class="pill pill--muted">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="t-right">
                        <div class="acciones-fila">
                            <button type="button" class="btn btn--sm btn--asig"
                                    onclick="spkAbrirAsig(<?= $id ?>, '<?= $labelJs ?>')"
                                    title="Asignar/cambiar uso a otro apto">🔑</button>
                            <a class="btn btn--sm" href="<?= url('/parqueadero/editar?id=' . $id) . '&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/parqueadero') ?>" title="Editar">✏️</a>
                            <?php if ((int)$c['activa'] === 1): ?>
                                <button type="button" class="btn btn--sm btn--desact"
                                        onclick="spkAccionFila('desactivar', <?= $id ?>, '<?= $labelJs ?>')" title="Desactivar">⏸️</button>
                            <?php else: ?>
                                <button type="button" class="btn btn--sm btn--act"
                                        onclick="spkAccionFila('activar', <?= $id ?>, '<?= $labelJs ?>')" title="Activar">▶️</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn--sm btn--elim"
                                    onclick="spkAccionFila('eliminar', <?= $id ?>, '<?= $labelJs ?>')" title="Eliminar">🗑️</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div id="bulk-bar" class="bulk-bar">
            <span id="bulk-count" class="bulk-count">0 seleccionada(s)</span>
            <button type="submit" name="accion" value="desactivar" class="bulk-desact" onclick="return spkConfirmar('desactivar')">⏸️ Desactivar</button>
            <?php if ($f_vista === 'inactivas' || $f_vista === 'todas'): ?>
                <button type="submit" name="accion" value="activar" class="bulk-act" onclick="return spkConfirmar('activar')">▶️ Activar</button>
            <?php endif; ?>
            <button type="submit" name="accion" value="eliminar" class="bulk-elim" onclick="return spkConfirmar('eliminar')">🗑️ Eliminar</button>
        </div>
    </form>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php
            $qs = $_GET; unset($qs['p']);
            $base = url('/parqueadero') . '?' . http_build_query($qs);
            $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?>
                    <span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?>
                    <a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
window.SPK_CSRF   = <?= json_encode(csrf_token()) ?>;
window.SPK_BATCH_URL = <?= json_encode(url('/parqueadero/acciones_batch')) ?>;

function spkToggleAll(cb) {
    document.querySelectorAll('input[name="seleccion[]"]').forEach(function (c) { c.checked = cb.checked; });
    spkUpdateBulkBar();
}
function spkUpdateBulkBar() {
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    var bar = document.getElementById('bulk-bar');
    if (!bar) return;
    if (n > 0) { bar.classList.add('visible'); document.getElementById('bulk-count').textContent = n + ' seleccionada(s)'; }
    else bar.classList.remove('visible');
}
function spkConfirmar(accion) {
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    if (n === 0) { alert('No seleccionaste ninguna celda.'); return false; }
    if (accion === 'desactivar') return confirm('¿Desactivar ' + n + ' celda(s)?\n\nQuedarán inactivas pero conservadas en la BD.');
    if (accion === 'activar')    return confirm('¿Activar ' + n + ' celda(s)?');
    if (accion === 'eliminar') {
        if (!confirm('⚠️ ¿ELIMINAR PERMANENTEMENTE ' + n + ' celda(s) de la base de datos?\n\nTambién se borrarán sus asignaciones. NO se puede deshacer.')) return false;
        return confirm('Confirma una segunda vez: ¿borrar ' + n + ' celda(s) para SIEMPRE?');
    }
    return false;
}
function spkAccionFila(accion, id, label) {
    var msg = '';
    if (accion === 'desactivar') msg = '¿Desactivar celda ' + label + '?';
    else if (accion === 'activar')    msg = '¿Activar celda ' + label + '?';
    else if (accion === 'eliminar')   msg = '⚠️ ¿ELIMINAR PERMANENTEMENTE la celda ' + label + '?\n\nTambién se borrarán sus asignaciones.';
    else return;
    if (!confirm(msg)) return;
    if (accion === 'eliminar' && !confirm('Confirma una segunda vez: ¿borrar ' + label + ' para SIEMPRE?')) return;

    var f = document.createElement('form');
    f.method = 'POST';
    f.action = window.SPK_BATCH_URL;
    f.innerHTML =
        '<input type="hidden" name="_csrf" value="' + window.SPK_CSRF + '">' +
        '<input type="hidden" name="accion" value="' + accion + '">' +
        '<input type="hidden" name="seleccion[]" value="' + id + '">' +
        '<input type="hidden" name="return_url" value="' + window.location.pathname + window.location.search + '">';
    document.body.appendChild(f);
    f.submit();
}
</script>

<script>
(function(){'use strict';
if(window.__SP_UI_INIT)return;window.__SP_UI_INIT=true;
function initTop(){
    if(document.getElementById('sp-back-to-top'))return;
    var s=document.createElement('style');
    s.textContent='#sp-back-to-top{position:fixed;bottom:20px;right:20px;width:46px;height:46px;border-radius:50%;background:#1e6cff;color:#fff;border:none;font-size:22px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);display:none;align-items:center;justify-content:center;z-index:9998;transition:transform .15s,opacity .15s;opacity:.85}#sp-back-to-top:hover{opacity:1;transform:scale(1.08)}@media(max-width:600px){#sp-back-to-top{bottom:80px;right:14px;width:42px;height:42px}}';
    document.head.appendChild(s);
    var b=document.createElement('button');
    b.id='sp-back-to-top';b.type='button';b.innerHTML='↑';b.title='Volver arriba';
    document.body.appendChild(b);
    b.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'})});
    function u(){b.style.display=(window.scrollY>300)?'flex':'none'}
    window.addEventListener('scroll',u,{passive:true});u();
}
function initMulti(){
    document.querySelectorAll('.sp-multi').forEach(function(mb){
        if(mb.__init)return;mb.__init=true;
        var btn=mb.querySelector('.sp-multi-btn');
        var panel=mb.querySelector('.sp-multi-panel');
        if(!btn||!panel)return;
        var label=mb.getAttribute('data-label')||'Filtro';
        var labelAll=mb.getAttribute('data-all')||('Todos: '+label);
        var cbs=panel.querySelectorAll('input[type="checkbox"]');
        if(!panel.querySelector('.sp-multi-actions')){
            var ac=document.createElement('div');
            ac.className='sp-multi-actions';
            ac.innerHTML='<button type="button" data-act="all">Todos</button><button type="button" data-act="none">Ninguno</button>';
            panel.appendChild(ac);
            ac.querySelector('[data-act=all]').addEventListener('click',function(e){e.stopPropagation();cbs.forEach(function(c){c.checked=true});upd()});
            ac.querySelector('[data-act=none]').addEventListener('click',function(e){e.stopPropagation();cbs.forEach(function(c){c.checked=false});upd()});
        }
        function upd(){
            var sel=Array.prototype.filter.call(cbs,function(c){return c.checked});
            if(sel.length===0||sel.length===cbs.length){btn.textContent=labelAll;btn.classList.remove('is-active')}
            else if(sel.length===1){btn.textContent=label+': '+sel[0].parentNode.textContent.trim();btn.classList.add('is-active')}
            else{btn.textContent=label+': '+sel.length+' seleccionados';btn.classList.add('is-active')}
        }
        cbs.forEach(function(c){c.addEventListener('change',upd)});
        upd();
        btn.addEventListener('click',function(e){
            e.stopPropagation();
            document.querySelectorAll('.sp-multi-panel.is-open').forEach(function(p){if(p!==panel)p.classList.remove('is-open')});
            panel.classList.toggle('is-open');
        });
        panel.addEventListener('click',function(e){e.stopPropagation()});
    });
    if(!window.__SP_CL){
        window.__SP_CL=true;
        document.addEventListener('click',function(){
            document.querySelectorAll('.sp-multi-panel.is-open').forEach(function(p){p.classList.remove('is-open')});
        });
    }
}
function init(){try{initTop()}catch(e){}try{initMulti()}catch(e){}}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
</script>

<!-- v3BH: helper de popover de aptos (link flotante con detalles) -->
<script src="<?= url('/public/js/apto_popover.js') ?>?v=3bh"></script>

<!-- v3BI: Modal para asignar/cambiar apto USUARIO de una celda ═══════════════ -->
<div id="spk-asig-modal" class="spk-asig-modal" style="display:none">
    <div class="spk-asig-backdrop" onclick="spkCerrarAsig()"></div>
    <div class="spk-asig-dialog" role="dialog" aria-modal="true">
        <button type="button" class="spk-asig-close" onclick="spkCerrarAsig()" title="Cerrar">×</button>

        <h3 id="spk-asig-titulo" style="margin:0 0 4px;font-size:17px;color:#5b21b6">
            🔑 Asignar uso de celda
        </h3>
        <div id="spk-asig-info" style="font-size:12px;color:#6b7280;margin-bottom:14px">Cargando...</div>

        <div id="spk-asig-actual" style="display:none;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:13px">
            <strong>Asignación actual:</strong>
            <div id="spk-asig-actual-info" style="margin-top:4px"></div>
        </div>

        <form id="spk-asig-form" onsubmit="return spkGuardarAsig(event, 'guardar')">
            <input type="hidden" id="spk-asig-celda-id" name="celda_id" value="">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <label class="field" style="display:block;margin-bottom:12px">
                <span style="display:block;font-weight:600;font-size:13px;color:#374151;margin-bottom:4px">
                    Apto usuario <span style="color:#dc2626">*</span>
                </span>
                <div style="position:relative">
                    <input type="text" id="spk-asig-apto" name="apto_usuario"
                           autocomplete="off" maxlength="20"
                           placeholder="Ej: 1502"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px"
                           oninput="spkAsigBuscar(this.value)"
                           onkeydown="spkAsigNav(event)">
                    <div id="spk-asig-sugerencias"
                         style="display:none;position:absolute;top:100%;left:0;right:0;
                                background:#fff;border:1px solid #d1d5db;border-radius:6px;
                                margin-top:2px;max-height:220px;overflow-y:auto;z-index:10;
                                box-shadow:0 4px 12px rgba(0,0,0,.1)"></div>
                </div>
                <small style="color:#6b7280;font-size:11px;margin-top:4px;display:block">
                    Escribe el número del apto. Se autocompleta desde 1 caracter.
                </small>
            </label>

            <label class="field" style="display:block;margin-bottom:14px">
                <span style="display:block;font-weight:600;font-size:13px;color:#374151;margin-bottom:4px">
                    Observación <small style="font-weight:400;color:#6b7280">(opcional)</small>
                </span>
                <textarea id="spk-asig-obs" name="observacion" rows="2" maxlength="500"
                          placeholder="Ej: préstamo temporal, hijos, etc."
                          style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical"></textarea>
            </label>

            <div id="spk-asig-msg" style="display:none;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-size:13px"></div>

            <div style="display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-top:6px">
                <button type="button" id="spk-asig-quitar" onclick="spkQuitarAsig()"
                        style="display:none;padding:9px 14px;background:#fef2f2;color:#991b1b;
                               border:1px solid #fecaca;border-radius:6px;font-size:13px;cursor:pointer">
                    ✕ Quitar asignación
                </button>
                <div style="display:flex;gap:8px;margin-left:auto">
                    <button type="button" onclick="spkCerrarAsig()"
                            style="padding:9px 16px;background:#f3f4f6;color:#374151;
                                   border:1px solid #d1d5db;border-radius:6px;font-size:13px;cursor:pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="spk-asig-btn-guardar"
                            style="padding:9px 18px;background:#7c3aed;color:#fff;border:none;
                                   border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">
                        💾 Guardar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.spk-asig-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}
.spk-asig-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.55);animation:spk-fade .12s ease-out}
.spk-asig-dialog{position:relative;background:#fff;border-radius:10px;padding:22px 24px;
    max-width:460px;width:100%;box-shadow:0 20px 40px rgba(0,0,0,.25);
    animation:spk-slide .16s ease-out;max-height:90vh;overflow-y:auto}
.spk-asig-close{position:absolute;top:10px;right:12px;background:none;border:none;
    font-size:26px;color:#6b7280;cursor:pointer;line-height:1;padding:2px 8px}
.spk-asig-close:hover{color:#dc2626}
@keyframes spk-fade{from{opacity:0}to{opacity:1}}
@keyframes spk-slide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.spk-sug-item{padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f4f6}
.spk-sug-item:hover,.spk-sug-item.is-selected{background:#f5f3ff;color:#5b21b6}
.spk-sug-item strong{font-family:monospace;font-size:14px}
.spk-sug-item small{color:#6b7280;margin-left:6px}
</style>

<script>
window.SPK_ASIG_URL = <?= json_encode(url('/parqueadero/asignar_uso')) ?>;

var spkAsigState = { celdaId:null, sugerencias:[], selectedIdx:-1, buscarTimer:null };

function spkAbrirAsig(celdaId, codigo) {
    spkAsigState.celdaId = celdaId;
    document.getElementById('spk-asig-celda-id').value = celdaId;
    document.getElementById('spk-asig-titulo').textContent = '🔑 Asignar uso de celda ' + codigo;
    document.getElementById('spk-asig-info').innerHTML = '<span style="color:#6b7280">⏳ Cargando datos...</span>';
    document.getElementById('spk-asig-actual').style.display = 'none';
    document.getElementById('spk-asig-apto').value = '';
    document.getElementById('spk-asig-obs').value = '';
    document.getElementById('spk-asig-sugerencias').style.display = 'none';
    document.getElementById('spk-asig-quitar').style.display = 'none';
    spkAsigMsg('', '');
    document.getElementById('spk-asig-modal').style.display = 'flex';

    // Cargar datos de la celda
    fetch(window.SPK_ASIG_URL + '?ver=1&celda=' + celdaId, { credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                document.getElementById('spk-asig-info').innerHTML = '<span style="color:#dc2626">❌ ' + (d.error || 'Error') + '</span>';
                return;
            }
            var c = d.celda;
            var info = '🅿️ Celda <strong>' + spkEsc(c.codigo) + '</strong>';
            if (c.nivel_codigo) info += ' · Nivel: <strong>' + spkEsc(c.nivel_codigo) + '</strong>';
            if (c.tipo) info += ' · Tipo: ' + spkEsc(c.tipo);
            if (c.apto_dueno) info += '<br>🏠 Apto dueño: <strong>' + spkEsc(c.apto_dueno) + '</strong>';
            else info += '<br><span style="color:#dc2626">⚠️ Sin apto dueño asignado</span>';
            document.getElementById('spk-asig-info').innerHTML = info;

            if (d.asignacion_actual) {
                var a = d.asignacion_actual;
                // v3BJ: etiqueta amigable en vez de 'prestamo_gratis'
                var tipoLabels = {
                    'uso_propio':      '✅ Uso propio',
                    'prestamo_gratis': '🤝 Autorizado',
                    'alquiler':        '💰 Alquiler'
                };
                var tipoLabel = tipoLabels[a.tipo] || a.tipo;
                var actualHtml = '👤 Apto usuario: <strong>' + spkEsc(a.apto_usuario) + '</strong>'
                              + ' (' + tipoLabel + ')';
                if (a.observacion) actualHtml += '<br>📝 ' + spkEsc(a.observacion);
                document.getElementById('spk-asig-actual-info').innerHTML = actualHtml;
                document.getElementById('spk-asig-actual').style.display = 'block';
                document.getElementById('spk-asig-apto').value = a.apto_usuario || '';
                document.getElementById('spk-asig-obs').value = a.observacion || '';
                document.getElementById('spk-asig-quitar').style.display = 'inline-block';
            }
            setTimeout(function(){ document.getElementById('spk-asig-apto').focus(); }, 100);
        })
        .catch(function(){
            document.getElementById('spk-asig-info').innerHTML = '<span style="color:#dc2626">❌ Error de red</span>';
        });
}

function spkCerrarAsig() {
    document.getElementById('spk-asig-modal').style.display = 'none';
    spkAsigState.celdaId = null;
}

function spkEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function spkAsigMsg(tipo, texto) {
    var el = document.getElementById('spk-asig-msg');
    if (!texto) { el.style.display = 'none'; return; }
    var colors = {
        ok:    {bg:'#dcfce7', fg:'#166534', border:'#86efac'},
        error: {bg:'#fee2e2', fg:'#991b1b', border:'#fca5a5'}
    };
    var c = colors[tipo] || colors.error;
    el.style.background = c.bg;
    el.style.color = c.fg;
    el.style.border = '1px solid ' + c.border;
    el.textContent = texto;
    el.style.display = 'block';
}

function spkAsigBuscar(q) {
    clearTimeout(spkAsigState.buscarTimer);
    spkAsigState.buscarTimer = setTimeout(function(){
        q = q.trim();
        var box = document.getElementById('spk-asig-sugerencias');
        if (q.length < 1) { box.style.display = 'none'; return; }
        fetch(window.SPK_ASIG_URL + '?buscar=' + encodeURIComponent(q) + '&celda=' + spkAsigState.celdaId,
              { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                var arr = d.aptos || [];
                spkAsigState.sugerencias = arr;
                spkAsigState.selectedIdx = -1;
                if (arr.length === 0) {
                    box.innerHTML = '<div class="spk-sug-item" style="color:#9ca3af;cursor:default">Sin coincidencias</div>';
                    box.style.display = 'block';
                    return;
                }
                var html = '';
                arr.forEach(function(a, i){
                    html += '<div class="spk-sug-item" onclick="spkAsigElegir(' + i + ')" data-idx="' + i + '">'
                         + '<strong>' + spkEsc(a.apto) + '</strong>'
                         + '<small>Torre ' + spkEsc(a.torre) + (a.piso ? ' · Piso ' + a.piso : '') + '</small>';
                    if (a.dueno) html += '<br><small style="margin-left:0">👤 ' + spkEsc(a.dueno) + '</small>';
                    html += '</div>';
                });
                box.innerHTML = html;
                box.style.display = 'block';
            })
            .catch(function(){
                box.innerHTML = '<div class="spk-sug-item" style="color:#dc2626;cursor:default">Error al buscar</div>';
                box.style.display = 'block';
            });
    }, 200);
}

function spkAsigElegir(idx) {
    var a = spkAsigState.sugerencias[idx];
    if (!a) return;
    document.getElementById('spk-asig-apto').value = a.apto;
    document.getElementById('spk-asig-sugerencias').style.display = 'none';
    document.getElementById('spk-asig-obs').focus();
}

function spkAsigNav(e) {
    var box = document.getElementById('spk-asig-sugerencias');
    var items = box.querySelectorAll('.spk-sug-item[data-idx]');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        spkAsigState.selectedIdx = Math.min(spkAsigState.selectedIdx + 1, items.length - 1);
        spkAsigMarcar(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        spkAsigState.selectedIdx = Math.max(spkAsigState.selectedIdx - 1, 0);
        spkAsigMarcar(items);
    } else if (e.key === 'Enter' && spkAsigState.selectedIdx >= 0) {
        e.preventDefault();
        spkAsigElegir(spkAsigState.selectedIdx);
    } else if (e.key === 'Escape') {
        box.style.display = 'none';
    }
}

function spkAsigMarcar(items) {
    items.forEach(function(it, i){
        it.classList.toggle('is-selected', i === spkAsigState.selectedIdx);
    });
    if (spkAsigState.selectedIdx >= 0 && items[spkAsigState.selectedIdx]) {
        items[spkAsigState.selectedIdx].scrollIntoView({block:'nearest'});
    }
}

function spkGuardarAsig(e, accion) {
    e.preventDefault();
    spkAsigMsg('', '');
    var btn = document.getElementById('spk-asig-btn-guardar');
    var txtOrig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Guardando...';

    var fd = new FormData();
    fd.append('_csrf', document.querySelector('input[name="_csrf"]').value);
    fd.append('celda_id', document.getElementById('spk-asig-celda-id').value);
    fd.append('accion', accion || 'guardar');
    fd.append('apto_usuario', document.getElementById('spk-asig-apto').value);
    fd.append('observacion', document.getElementById('spk-asig-obs').value);

    fetch(window.SPK_ASIG_URL, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false; btn.textContent = txtOrig;
            if (d.ok) {
                spkAsigMsg('ok', d.msg || '✅ Guardado');
                setTimeout(function(){ window.location.reload(); }, 700);
            } else {
                spkAsigMsg('error', d.error || 'Error al guardar');
            }
        })
        .catch(function(err){
            btn.disabled = false; btn.textContent = txtOrig;
            spkAsigMsg('error', 'Error de red');
        });
    return false;
}

function spkQuitarAsig() {
    if (!confirm('¿Quitar la asignación de uso?\n\nLa celda volverá a "Usada por el dueño".')) return;
    spkGuardarAsig({preventDefault:function(){}}, 'quitar');
}

// Cerrar con Escape
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && document.getElementById('spk-asig-modal').style.display === 'flex') {
        spkCerrarAsig();
    }
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
