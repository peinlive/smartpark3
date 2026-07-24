<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/index.php
// v3k: ordenamiento clickeable en cabeceras + columna "Registro" para auditoría +
//      checkboxes para selección masiva + botones individuales Archivar/Eliminar +
//      barra flotante de acciones masivas + CSS que limita el tamaño de las fotos
//      en móvil. Mantiene 100% la lógica UNION ALL del v3i (residentes + visitantes).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDES_PATH . '/upload_helpers.php';

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

// ───── Filtros ─────
$f_placa   = clean_string($_GET['placa']  ?? '', 15);
$f_apto    = clean_string($_GET['apto']   ?? '', 20);
$f_torre   = clean_int(   $_GET['torre']  ?? null, 1, 99);

// v3o: tipo y vinculo multi-select
$f_tipos = $_GET['tipo'] ?? '';
if (!is_array($f_tipos)) $f_tipos = ($f_tipos !== '' ? [$f_tipos] : []);
$f_tipos = array_values(array_intersect($f_tipos, ['carro','moto']));

$vinculosValidos = ['propietario','inquilino','familiar','otro','sin_asignar','visitante'];
$f_vinculos = $_GET['vinculo'] ?? '';
if (!is_array($f_vinculos)) $f_vinculos = ($f_vinculos !== '' ? [$f_vinculos] : []);
$f_vinculos = array_values(array_intersect($f_vinculos, $vinculosValidos));

$f_vista   = in_array($_GET['vista']   ?? '', ['activos','archivados','todos'], true) ? $_GET['vista'] : 'activos';

// ── Filtro de fecha de registro ──
$f_fecha_desde = '';
$f_fecha_hasta = '';
if (!empty($_GET['fecha_desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_desde'])) {
    $f_fecha_desde = $_GET['fecha_desde'];
}
if (!empty($_GET['fecha_hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_hasta'])) {
    $f_fecha_hasta = $_GET['fecha_hasta'];
}

// ───── Ordenamiento (whitelist anti-injection) ─────
$sortValidas = ['placa','tipo','apto_numero','torre_numero','usuario_nombre','vinculo','creado_en'];
$sortCol = in_array($_GET['sort'] ?? '', $sortValidas, true) ? $_GET['sort'] : 'creado_en';
$sortDir = (($_GET['dir'] ?? '') === 'asc') ? 'asc' : 'desc';

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

// ───── Decidir qué tablas incluir según filtro de vínculo ─────
$vinculosRes = ['propietario','inquilino','familiar','otro','sin_asignar'];
if (empty($f_vinculos)) {
    $incluirRes = true;
    $incluirVis = true;
} else {
    $incluirRes = (bool)array_intersect($f_vinculos, $vinculosRes);
    $incluirVis = in_array('visitante', $f_vinculos, true);
}

// ───── WHERE residentes (sufijo _R) ─────
$whereR  = ['v.conjunto_id = :cidR'];
$paramsR = [':cidR' => $conjuntoId];

if ($f_placa !== '') { $whereR[] = 'v.placa LIKE :placaR'; $paramsR[':placaR'] = '%' . normalizar_placa($f_placa) . '%'; }
if ($f_apto  !== '') { $whereR[] = 'a.numero_visible LIKE :aptoR'; $paramsR[':aptoR'] = '%' . $f_apto . '%'; }
if ($f_torre !== null) { $whereR[] = 't.numero = :torreR'; $paramsR[':torreR'] = $f_torre; }
if (!empty($f_tipos))  {
    $tlist = "'" . implode("','", $f_tipos) . "'";
    $whereR[] = "v.tipo IN ($tlist)";
}
// Vínculos para residentes (excluyendo 'visitante')
$vincRes = array_values(array_intersect($f_vinculos, $vinculosRes));
if (!empty($vincRes)) {
    $condsV = [];
    if (in_array('sin_asignar', $vincRes, true)) {
        $condsV[] = 'v.residente_id IS NULL';
    }
    $otrosV = array_values(array_diff($vincRes, ['sin_asignar']));
    if (!empty($otrosV)) {
        $vlist = "'" . implode("','", $otrosV) . "'";
        $condsV[] = "r.tipo IN ($vlist)";
    }
    if ($condsV) $whereR[] = '(' . implode(' OR ', $condsV) . ')';
}
if     ($f_vista === 'activos')    $whereR[] = 'v.archivado_en IS NULL';
elseif ($f_vista === 'archivados') $whereR[] = 'v.archivado_en IS NOT NULL';
if ($f_fecha_desde !== '') { $whereR[] = 'DATE(v.creado_en) >= :fdR'; $paramsR[':fdR'] = $f_fecha_desde; }
if ($f_fecha_hasta !== '') { $whereR[] = 'DATE(v.creado_en) <= :fhR'; $paramsR[':fhR'] = $f_fecha_hasta; }
$whereRsql = implode(' AND ', $whereR);

// ───── WHERE visitantes (sufijo _V) ─────
$whereV  = ['vv.conjunto_id = :cidV'];
$paramsV = [':cidV' => $conjuntoId];

if ($f_placa !== '') { $whereV[] = 'vv.placa LIKE :placaV'; $paramsV[':placaV'] = '%' . normalizar_placa($f_placa) . '%'; }
if ($f_apto  !== '') { $whereV[] = 'a.numero_visible LIKE :aptoV'; $paramsV[':aptoV'] = '%' . $f_apto . '%'; }
if ($f_torre !== null) { $whereV[] = 't.numero = :torreV'; $paramsV[':torreV'] = $f_torre; }
if (!empty($f_tipos))  {
    $tlist = "'" . implode("','", $f_tipos) . "'";
    $whereV[] = "vv.tipo IN ($tlist)";
}
if     ($f_vista === 'activos')    $whereV[] = 'vv.archivado_en IS NULL';
elseif ($f_vista === 'archivados') $whereV[] = 'vv.archivado_en IS NOT NULL';
if ($f_fecha_desde !== '') { $whereV[] = 'DATE(vv.creado_en) >= :fdV'; $paramsV[':fdV'] = $f_fecha_desde; }
if ($f_fecha_hasta !== '') { $whereV[] = 'DATE(vv.creado_en) <= :fhV'; $paramsV[':fhV'] = $f_fecha_hasta; }
$whereVsql = implode(' AND ', $whereV);

// ───── Conteos ─────
$totalRes = 0;
if ($incluirRes) {
    $st = $pdo->prepare("SELECT COUNT(*)
                           FROM vehiculos v
                           JOIN apartamentos a ON a.id = v.apartamento_id
                           JOIN torres t       ON t.id = a.torre_id
                      LEFT JOIN residentes r   ON r.id = v.residente_id
                          WHERE $whereRsql");
    $st->execute($paramsR);
    $totalRes = (int)$st->fetchColumn();
}
$totalVis = 0;
if ($incluirVis) {
    $st = $pdo->prepare("SELECT COUNT(*)
                           FROM visitantes_vehiculos vv
                           JOIN apartamentos a ON a.id = vv.apartamento_id
                           JOIN torres t       ON t.id = a.torre_id
                          WHERE $whereVsql");
    $st->execute($paramsV);
    $totalVis = (int)$st->fetchColumn();
}
$total    = $totalRes + $totalVis;
$totalPag = max(1, (int)ceil($total / $porPagina));

// ───── SELECT con UNION ALL ─────
$selRes = "SELECT v.id AS rec_id, 'residente' AS origen,
                  v.placa, v.tipo, v.foto_principal, v.archivado_en, v.observaciones, v.creado_en,
                  v.apartamento_id AS apto_id, a.numero_visible AS apto_numero, a.piso,
                  t.numero AS torre_numero,
                  COALESCE(r.nombre, '') AS usuario_nombre,
                  COALESCE(r.tipo, 'sin_asignar') AS vinculo,
                  v.residente_id
             FROM vehiculos v
             JOIN apartamentos a ON a.id = v.apartamento_id
             JOIN torres t       ON t.id = a.torre_id
        LEFT JOIN residentes r   ON r.id = v.residente_id
            WHERE $whereRsql";

$selVis = "SELECT vv.id AS rec_id, 'visitante' AS origen,
                  vv.placa, vv.tipo, NULL AS foto_principal, vv.archivado_en, vv.observaciones, vv.creado_en,
                  vv.apartamento_id AS apto_id, a.numero_visible AS apto_numero, a.piso,
                  t.numero AS torre_numero,
                  COALESCE(vv.nombre_visitante, '') AS usuario_nombre,
                  'visitante' AS vinculo,
                  NULL AS residente_id
             FROM visitantes_vehiculos vv
             JOIN apartamentos a ON a.id = vv.apartamento_id
             JOIN torres t       ON t.id = a.torre_id
            WHERE $whereVsql";

$parts  = [];
$params = [];
if ($incluirRes) { $parts[] = "($selRes)"; $params = array_merge($params, $paramsR); }
if ($incluirVis) { $parts[] = "($selVis)"; $params = array_merge($params, $paramsV); }

$vehiculos = [];
if (!empty($parts)) {
    $unionSql = implode(' UNION ALL ', $parts);
    // $sortCol y $sortDir vienen de whitelist; seguro contra injection
    $mainSql  = "SELECT * FROM ($unionSql) AS combined
                  ORDER BY $sortCol $sortDir
                  LIMIT $porPagina OFFSET $offset";
    $st = $pdo->prepare($mainSql);
    $st->execute($params);
    $vehiculos = $st->fetchAll();
}

$torres = $pdo->prepare("SELECT id, numero FROM torres WHERE conjunto_id = :c AND activo = 1 ORDER BY numero");
$torres->execute([':c' => $conjuntoId]);
$torres = $torres->fetchAll();

// QS base para preservar filtros en links de orden
$qsBase = $_GET;
unset($qsBase['p']);

// Mensajes flash: NO los procesamos aquí porque header.php ya lo hace.
// (flash_get() consume y devuelve TODOS los flashes; si los leemos antes que header
// se quedan sin mostrar. Lo dejamos al header.)

$_pageTitle = 'Vehículos';
include INCLUDES_PATH . '/header.php';

// Helper: pintar el vínculo
function pintarVinculo($vinculo, $origen) {
    if ($origen === 'visitante') {
        return '<span class="pill" style="background:#fce7f3;color:#9d174d">👋 Visitante</span>';
    }
    $map = [
        'propietario' => ['👑 Propietario', 'background:#dbeafe;color:#1e3a8a'],
        'inquilino'   => ['🏠 Inquilino',   'background:#dcfce7;color:#166534'],
        'familiar'    => ['👨‍👩‍👧 Familiar',  'background:#f3f4f6;color:#374151'],
        'otro'        => ['👤 Otro',        'background:#f3f4f6;color:#374151'],
        'sin_asignar' => ['⚠️ Sin asignar', 'background:#fef3c7;color:#92400e'],
    ];
    $info = $map[$vinculo] ?? ['👤 ' . ucfirst($vinculo), 'background:#f3f4f6;color:#374151'];
    return '<span class="pill" style="' . $info[1] . '">' . $info[0] . '</span>';
}

// Helper: link de cabecera ordenable (anti-injection vía whitelist)
function sortLink($col, $label, $sortCol, $sortDir, $qsBase) {
    $newDir = ($sortCol === $col && $sortDir === 'asc') ? 'desc' : 'asc';
    $arrow = '↕';
    $cls = '';
    if ($sortCol === $col) {
        $arrow = $sortDir === 'asc' ? '↑' : '↓';
        $cls = ' is-active';
    }
    $qs = $qsBase;
    $qs['sort'] = $col;
    $qs['dir']  = $newDir;
    $url = url('/vehiculos') . '?' . http_build_query($qs);
    return '<a class="sort-link' . $cls . '" href="' . htmlspecialchars($url) . '">'
         . htmlspecialchars($label) . ' <span class="arrow">' . $arrow . '</span></a>';
}

// Helper: fecha de registro compacta (auditoría)
function fechaRegistro($f) {
    if (empty($f)) return '<span class="t-muted">—</span>';
    $ts = strtotime($f);
    if ($ts === false) return '<span class="t-muted">—</span>';
    return '<span class="fecha-registro">'
         . date('d/m/Y', $ts) . '<br><small>' . date('H:i', $ts) . '</small></span>';
}
?>

<style>
/* v3o.1: CSS multi-select inline (no depende del JS, así no se "riega" si el JS no carga) */
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

/* v3k: tamaño limitado de fotos (fix móvil) */
.row-thumb { width: 48px !important; height: 48px !important; object-fit: cover; border-radius: 4px; display: block; }
.row-thumb--empty { display: inline-flex; align-items: center; justify-content: center; background: #f3f4f6; font-size: 20px; }
@media (max-width: 768px) {
    .row-thumb { width: 36px !important; height: 36px !important; }
}

/* Cabeceras ordenables */
.sort-link { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.sort-link:hover { color: #2563eb; }
.sort-link .arrow { color: #9ca3af; font-size: 0.85em; font-weight: 400; }
.sort-link.is-active { color: #2563eb; font-weight: 600; }
.sort-link.is-active .arrow { color: #2563eb; }

/* Fecha de registro */
.fecha-registro { font-size: 12px; color: #6b7280; white-space: nowrap; line-height: 1.3; }
.fecha-registro small { color: #9ca3af; font-size: 11px; }

/* Barra masiva sticky */
.bulk-bar {
    position: sticky; bottom: 0;
    background: #1f2937; color: white;
    padding: 14px 20px; margin: 16px -16px 0;
    display: none; align-items: center; gap: 12px;
    box-shadow: 0 -3px 10px rgba(0,0,0,0.15);
    border-top: 3px solid #3b82f6; z-index: 100;
    flex-wrap: wrap;
}
.bulk-bar.visible { display: flex; }
.bulk-count { font-weight: 600; flex: 1; min-width: 140px; }
.bulk-bar button {
    padding: 8px 16px; border: none; border-radius: 5px;
    cursor: pointer; font-weight: 500; color: white;
}
.bulk-archivar { background: #f59e0b; }
.bulk-archivar:hover { background: #d97706; }
.bulk-restaurar { background: #10b981; }
.bulk-restaurar:hover { background: #059669; }
.bulk-eliminar { background: #dc2626; }
.bulk-eliminar:hover { background: #b91c1c; }

/* Botones individuales */
.acciones-fila { display: inline-flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
.acciones-fila .btn--sm { padding: 4px 8px; }
.acciones-fila .btn--archivar { background: #fef3c7; color: #92400e; }
.acciones-fila .btn--archivar:hover { background: #fde68a; }
.acciones-fila .btn--restaurar { background: #d1fae5; color: #065f46; }
.acciones-fila .btn--restaurar:hover { background: #a7f3d0; }
.acciones-fila .btn--eliminar { background: #fee2e2; color: #991b1b; }
.acciones-fila .btn--eliminar:hover { background: #fecaca; }

/* Flash */
.flash-msg { padding: 12px 16px; margin: 12px 0; border-radius: 6px; }
.flash-msg.flash-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
.flash-msg.flash-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

.col-check { width: 32px; text-align: center; }
</style>

<?php /* Los mensajes flash los pinta header.php automáticamente */ ?>

<div class="page-head">
    <h1 class="page-head__title">Vehículos</h1>
    <p class="page-head__sub">
        <?= $total ?> resultado<?= $total === 1 ? '' : 's' ?>
        <small style="color:#6b7280">— <?= $totalRes ?> residentes + <?= $totalVis ?> visitantes</small>
    </p>
</div>
<!-- v7.5: exportar a Excel/CSV -->
<?php /* v7.66: exportar también para ronda */ ?>
<div style="margin:-6px 0 14px">
  <a href="<?= url('/exportar?t=vehiculos') ?>" class="btn btn--sm"
     style="background:#065f46;color:#fff;text-decoration:none;display:inline-flex;
            align-items:center;gap:5px">
    📊 Exportar a Excel
  </a>
  <small style="color:#9ca3af;margin-left:7px">
    Descarga un CSV con todo. Sirve de respaldo y para revisar en Excel.
  </small>
</div>

<div class="toolbar">
    <?php /* v7.66: ronda puede crear vehículos */ ?>
    <a class="btn btn--primary" href="<?= url('/vehiculos/crear') ?>">+ Nuevo vehículo</a>
    <a class="btn" href="<?= url('/visitantes') ?>"><span>🚗</span> Visitantes</a>
    <?php /* v7.66: ronda puede registrar visitas */ ?>
    <a class="btn" href="<?= url('/visitantes/crear') ?>">+ Registrar visita</a>
    <?php if (!$esRonda): /* importar sigue solo para admin */ ?>
    <a class="btn" href="<?= url('/importaciones?tipo=vehiculos') ?>">📥 Importar Excel</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= url('/vehiculos') ?>" class="filters">
    <input type="text" name="placa" placeholder="Placa"        value="<?= e($f_placa) ?>" maxlength="15">
    <input type="text" name="apto"  placeholder="Apto (1024)"  value="<?= e($f_apto) ?>"  maxlength="20">
    <select name="torre">
        <option value="">Todas las torres</option>
        <?php foreach ($torres as $t): ?>
            <option value="<?= (int)$t['numero'] ?>" <?= $f_torre === (int)$t['numero'] ? 'selected' : '' ?>>
                Torre <?= (int)$t['numero'] ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="sp-multi" data-label="Tipos" data-all="Carro y moto">
        <button type="button" class="sp-multi-btn">Carro y moto</button>
        <div class="sp-multi-panel">
            <label><input type="checkbox" name="tipo[]" value="carro" <?= in_array('carro', $f_tipos, true) ? 'checked' : '' ?>> 🚗 Carro</label>
            <label><input type="checkbox" name="tipo[]" value="moto"  <?= in_array('moto', $f_tipos, true)  ? 'checked' : '' ?>> 🏍️ Moto</label>
        </div>
    </div>

    <div class="sp-multi" data-label="Vínculos" data-all="Todos los vínculos">
        <button type="button" class="sp-multi-btn">Todos los vínculos</button>
        <div class="sp-multi-panel">
            <?php foreach ([
                'propietario' => '👑 Propietario', 'inquilino' => '🏠 Inquilino',
                'familiar' => '👨‍👩‍👧 Familiar', 'otro' => '👤 Otro',
                'sin_asignar' => '⚠️ Sin asignar', 'visitante' => '👋 Visitante'
            ] as $k => $v): ?>
                <label>
                    <input type="checkbox" name="vinculo[]" value="<?= $k ?>" <?= in_array($k, $f_vinculos, true) ? 'checked' : '' ?>>
                    <?= $v ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <select name="vista">
        <option value="activos"    <?= $f_vista === 'activos'    ? 'selected' : '' ?>>✓ Activos</option>
        <option value="archivados" <?= $f_vista === 'archivados' ? 'selected' : '' ?>>📁 Archivados</option>
        <option value="todos"      <?= $f_vista === 'todos'      ? 'selected' : '' ?>>Todos</option>
    </select>
    <?php /* Preservar sort/dir actual cuando se filtra */ ?>
    <?php if (!empty($_GET['sort'])): ?>
        <input type="hidden" name="sort" value="<?= e($_GET['sort']) ?>">
        <input type="hidden" name="dir"  value="<?= e($_GET['dir'] ?? 'desc') ?>">
    <?php endif; ?>
    <div style="display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px solid #d1d5db;border-radius:6px;padding:4px 10px" title="Filtrar por fecha de registro">
        <span style="font-size:12px;color:#6b7280;white-space:nowrap">📅 Registro:</span>
        <input type="date" name="fecha_desde" value="<?= e($f_fecha_desde) ?>"
               style="border:none;background:transparent;font-size:13px;color:#374151;outline:none;width:130px"
               title="Desde">
        <span style="color:#9ca3af;font-size:12px">→</span>
        <input type="date" name="fecha_hasta" value="<?= e($f_fecha_hasta) ?>"
               style="border:none;background:transparent;font-size:13px;color:#374151;outline:none;width:130px"
               title="Hasta">
    </div>
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/vehiculos') ?>">Limpiar</a>
</form>

<?php if (empty($vehiculos)): ?>
    <div class="notice notice--info">No hay vehículos que coincidan con los filtros.</div>
<?php else: ?>
    <form id="bulk-form" method="POST" action="<?= url('/vehiculos/acciones_batch') ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '/vehiculos') ?>">

        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" onchange="spToggleAll(this)" title="Seleccionar todos"></th>
                    <th></th>
                    <th><?= sortLink('placa',          'Placa',         $sortCol, $sortDir, $qsBase) ?></th>
                    <th><?= sortLink('tipo',           'Tipo',          $sortCol, $sortDir, $qsBase) ?></th>
                    <th><?= sortLink('apto_numero',    'Apto',          $sortCol, $sortDir, $qsBase) ?></th>
                    <th><?= sortLink('torre_numero',   'Torre',         $sortCol, $sortDir, $qsBase) ?></th>
                    <th><?= sortLink('usuario_nombre', 'Usuario',       $sortCol, $sortDir, $qsBase) ?></th>
                    <th><?= sortLink('vinculo',        'Vínculo',       $sortCol, $sortDir, $qsBase) ?></th>
                    <th>Observaciones</th>
                    <th><?= sortLink('creado_en',      'Registro',      $sortCol, $sortDir, $qsBase) ?></th>
                    <th>Estado</th>
                    <th class="t-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vehiculos as $v):
                $origen   = $v['origen'];
                $recId    = (int)$v['rec_id'];
                $valorSel = $origen . ':' . $recId;
                $verUrl   = $origen === 'visitante'
                    ? url('/visitantes/ver?id=' . $recId)
                    : url('/vehiculos/ver?id=' . $recId);
                $editUrl  = $origen === 'visitante'
                    ? url('/visitantes/editar?id=' . $recId)
                    : url('/vehiculos/editar?id=' . $recId);
                $labelJs  = addslashes($v['placa'] . ' (apto ' . $v['apto_numero'] . ')');
            ?>
                <tr <?= $origen === 'visitante' ? 'style="background:#fdf2f8"' : '' ?>>
                    <td class="col-check">
                        <input type="checkbox" name="seleccion[]" value="<?= e($valorSel) ?>" onchange="spUpdateBulkBar()">
                    </td>
                    <td>
                        <?php if (!empty($v['foto_principal'])): ?>
                            <img src="<?= e(url_foto($v['foto_principal'])) ?>" alt="" class="row-thumb"
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <span class="row-thumb row-thumb--empty"><?= $v['tipo'] === 'moto' ? '🏍️' : '🚗' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($v['placa']) ?></strong></td>
                    <td><?= $v['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></td>
                    <td>
    <?php if ($v['apto_numero']): ?>
        <a class="apto-link" data-apto="<?= e($v['apto_numero']) ?>" href="<?= url('/consultas?apto=' . urlencode($v['apto_numero'])) ?>" title="Ver detalles del apto">
            <?= e($v['apto_numero']) ?>
        </a>
    <?php else: ?>
        <span class="t-muted">—</span>
    <?php endif; ?>
</td>
                    <td>T<?= (int)$v['torre_numero'] ?></td>
                    <td><?= e($v['usuario_nombre'] ?: '—') ?></td>
                    <td><?= pintarVinculo($v['vinculo'], $origen) ?></td>
                    <td>
                        <?php
                        $obs = trim((string)($v['observaciones'] ?? ''));
                        if ($obs !== ''):
                            $obsCorta = mb_strlen($obs) > 60 ? mb_substr($obs, 0, 57) . '…' : $obs;
                        ?>
                            <span title="<?= e($obs) ?>"><?= e($obsCorta) ?></span>
                        <?php else: ?>
                            <span class="t-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= fechaRegistro($v['creado_en']) ?></td>
                    <td>
                        <?php if ($v['archivado_en']): ?>
                            <span class="pill pill--muted">📁 Archivado</span>
                        <?php else: ?>
                            <span class="pill pill--ok">Activo</span>
                        <?php endif; ?>
                    </td>
                    <td class="t-right">
                        <div class="acciones-fila">
                            <a class="btn btn--sm" href="<?= e($verUrl) . '&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/vehiculos') ?>" title="Ver detalle">👁</a>
                            <?php /* v7.66: ronda puede editar y archivar */ ?>
                            <?php if (!$v['archivado_en']): ?>
                                <a class="btn btn--sm" href="<?= e($editUrl) . '&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/vehiculos') ?>" title="Editar">✏️</a>
                                <button type="button" class="btn btn--sm btn--archivar"
                                        onclick="spAccionFila('archivar', '<?= e($valorSel) ?>', '<?= $labelJs ?>')"
                                        title="Archivar">📁</button>
                            <?php else: ?>
                                <button type="button" class="btn btn--sm btn--restaurar"
                                        onclick="spAccionFila('restaurar', '<?= e($valorSel) ?>', '<?= $labelJs ?>')"
                                        title="Restaurar">↩️</button>
                            <?php endif; ?>
                            <?php if (!$esRonda): /* v7.66: ronda NO elimina */ ?>
                            <button type="button" class="btn btn--sm btn--eliminar"
                                    onclick="spAccionFila('eliminar', '<?= e($valorSel) ?>', '<?= $labelJs ?>')"
                                    title="Eliminar definitivamente">🗑️</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Barra masiva sticky -->
        <?php /* v7.66: ronda ve la barra (archivar/restaurar), sin eliminar */ ?>
        <div id="bulk-bar" class="bulk-bar">
            <span id="bulk-count" class="bulk-count">0 seleccionado(s)</span>
            <button type="submit" name="accion" value="archivar" class="bulk-archivar"
                    onclick="return spConfirmarMasivo('archivar')">📁 Archivar seleccionados</button>
            <?php if ($f_vista === 'archivados' || $f_vista === 'todos'): ?>
            <button type="submit" name="accion" value="restaurar" class="bulk-restaurar"
                    onclick="return spConfirmarMasivo('restaurar')">↩️ Restaurar seleccionados</button>
            <?php endif; ?>
            <?php if (!$esRonda): /* v7.66: ronda NO elimina */ ?>
            <button type="submit" name="accion" value="eliminar" class="bulk-eliminar"
                    onclick="return spConfirmarMasivo('eliminar')">🗑️ Eliminar seleccionados</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php
            $qs = $_GET; unset($qs['p']);
            $base = url('/vehiculos') . '?' . http_build_query($qs);
            $sep  = $qs ? '&' : '';
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
// v3k: helpers para selección masiva y acciones por fila
window.SP_CSRF = <?= json_encode(csrf_token()) ?>;
window.SP_ACCIONES_URL = <?= json_encode(url('/vehiculos/acciones_batch')) ?>;

function spToggleAll(cb) {
    document.querySelectorAll('input[name="seleccion[]"]').forEach(function (c) { c.checked = cb.checked; });
    spUpdateBulkBar();
}
function spUpdateBulkBar() {
    var checked = document.querySelectorAll('input[name="seleccion[]"]:checked');
    var bar = document.getElementById('bulk-bar');
    if (!bar) return;
    if (checked.length > 0) {
        bar.classList.add('visible');
        document.getElementById('bulk-count').textContent = checked.length + ' seleccionado(s)';
    } else {
        bar.classList.remove('visible');
    }
}
function spConfirmarMasivo(accion) {
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    if (n === 0) { alert('No seleccionaste ningún vehículo.'); return false; }
    if (accion === 'archivar') {
        return confirm('¿Archivar ' + n + ' vehículo(s)?\n\nQuedarán archivados pero conservados en la BD (se pueden restaurar después).');
    }
    if (accion === 'restaurar') {
        return confirm('¿Restaurar ' + n + ' vehículo(s) a estado activo?');
    }
    if (accion === 'eliminar') {
        if (!confirm('⚠️ ¿ELIMINAR PERMANENTEMENTE ' + n + ' vehículo(s) de la base de datos?\n\nEsta acción NO se puede deshacer.')) return false;
        return confirm('Confirma una segunda vez: ¿borrar ' + n + ' registro(s) para SIEMPRE?');
    }
    return false;
}
function spAccionFila(accion, valor, label) {
    var msg = '';
    if (accion === 'archivar') {
        msg = '¿Archivar vehículo ' + label + '?';
    } else if (accion === 'restaurar') {
        msg = '¿Restaurar vehículo ' + label + ' a estado activo?';
    } else if (accion === 'eliminar') {
        msg = '⚠️ ¿ELIMINAR PERMANENTEMENTE el vehículo ' + label + '?\n\nNo se puede deshacer.';
    } else {
        return;
    }
    if (!confirm(msg)) return;
    if (accion === 'eliminar') {
        if (!confirm('Confirma una segunda vez: ¿borrar ' + label + ' para SIEMPRE?')) return;
    }
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = window.SP_ACCIONES_URL;
    f.innerHTML =
        '<input type="hidden" name="csrf_token" value="' + window.SP_CSRF + '">' +
        '<input type="hidden" name="accion" value="' + accion + '">' +
        '<input type="hidden" name="seleccion[]" value="' + valor + '">' +
        '<input type="hidden" name="return_url" value="' + window.location.pathname + window.location.search + '">';
    document.body.appendChild(f);
    f.submit();
}
</script>


<!-- v3o.2: JS inline para botón flotante + multi-select (no depende de archivo externo) -->
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

<!-- Estilos base requeridos para el diseño del popover de apartamentos -->
<style>
.apto-link {
    color: #1e40af;
    text-decoration: none;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 1px dotted #93c5fd;
    padding-bottom: 1px;
}
.apto-link:hover {
    color: #1d4ed8;
    border-bottom-style: solid;
    background: #eff6ff;
}
</style>

<!-- Helper nativo del sistema que genera la tarjeta flotante completa -->
<script src="<?= url('/public/js/apto_popover.js') ?>?v=3bh"></script>

<!-- v3BH.3: Script de precisión estricta para inyectar la torre en las consultas flotantes -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.AptoPopover || document.querySelectorAll('.apto-link').length > 0) {
        document.body.addEventListener('click', function(e) {
            var link = e.target.closest('.apto-link');
            if (link) {
                var aptoNum = link.getAttribute('data-apto');
                var torreNum = link.getAttribute('data-torre');
                
                if (aptoNum && torreNum) {
                    window.CQ_APTO_INFO_URL = '<?= url("/consultas/api_apto_info") ?>?apto=' + encodeURIComponent(aptoNum) + '&torre=' + encodeURIComponent(torreNum);
                    link.setAttribute('href', window.CQ_APTO_INFO_URL);
                }
            }
        }, true);
    }
});
</script>

<!-- v3o.2: JS inline para botón flotante + multi-select (no depende de archivo externo) -->
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
else if(sel.length===1){btn.textContent=label+': '+sel.parentNode.textContent.trim();btn.classList.add('is-active')}
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

<?php include INCLUDES_PATH . '/footer.php'; ?>