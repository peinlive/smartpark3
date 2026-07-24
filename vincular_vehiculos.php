<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/vincular_vehiculos.php
// v7.10 — Vinculación masiva de vehículos "Sin asignar" a un residente del apto.
//
// REGLA (definida con el cliente):
//   - Solo se tocan vehículos con residente_id NULL (los "Sin asignar") y activos.
//   - Se vincula al residente ACTIVO del mismo apartamento, con prioridad:
//         1) inquilino   2) propietario   3) cualquier otro tipo
//   - Si el apartamento NO tiene ningún residente activo, el vehículo se DEJA
//     como está (sin asignar) y se reporta aparte. NUNCA se inventan datos.
//
// SEGURIDAD:
//   - Previsualización (GET) antes de aplicar (POST con CSRF).
//   - Al aplicar: transacción + try/catch por fila. Un error no tumba el lote.
//   - Jamás pisa un residente_id ya existente (el WHERE exige residente_id IS NULL).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin', 'admin', 'supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

// ───────────────────────────────────────────────────────────────
// Consulta base: vehículos SIN asignar (residente NULL, activos) y,
// para cada uno, el mejor residente candidato de su apartamento.
// El candidato se elige por prioridad de tipo con un ORDER BY dentro
// de una subconsulta correlacionada.
// ───────────────────────────────────────────────────────────────
function sp_vincular_analizar(PDO $pdo, int $conjuntoId): array
{
    $sql = "
        SELECT v.id            AS veh_id,
               v.placa         AS placa,
               v.tipo          AS veh_tipo,
               a.id            AS apto_id,
               a.numero_visible AS apto_num,
               t.numero        AS torre,
               (SELECT r.id   FROM residentes r
                 WHERE r.apartamento_id = a.id
                   AND r.activo = 1 AND r.archivado_en IS NULL
                 ORDER BY CASE r.tipo
                              WHEN 'inquilino'   THEN 1
                              WHEN 'propietario' THEN 2
                              ELSE 3 END,
                          r.id
                 LIMIT 1)      AS cand_id,
               (SELECT r.tipo FROM residentes r
                 WHERE r.apartamento_id = a.id
                   AND r.activo = 1 AND r.archivado_en IS NULL
                 ORDER BY CASE r.tipo
                              WHEN 'inquilino'   THEN 1
                              WHEN 'propietario' THEN 2
                              ELSE 3 END,
                          r.id
                 LIMIT 1)      AS cand_tipo,
               (SELECT r.nombre FROM residentes r
                 WHERE r.apartamento_id = a.id
                   AND r.activo = 1 AND r.archivado_en IS NULL
                 ORDER BY CASE r.tipo
                              WHEN 'inquilino'   THEN 1
                              WHEN 'propietario' THEN 2
                              ELSE 3 END,
                          r.id
                 LIMIT 1)      AS cand_nombre
          FROM vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
     LEFT JOIN torres t       ON t.id = a.torre_id
         WHERE v.conjunto_id = :c
           AND v.residente_id IS NULL
           AND v.archivado_en IS NULL
      ORDER BY a.numero_visible, v.placa";

    $st = $pdo->prepare($sql);
    $st->execute([':c' => $conjuntoId]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);

    $conCandidato = [];
    $sinCandidato = [];
    foreach ($filas as $f) {
        if (!empty($f['cand_id'])) $conCandidato[] = $f;
        else                        $sinCandidato[] = $f;
    }
    return [$conCandidato, $sinCandidato];
}

// ───────────────────────────────────────────────────────────────
// APLICAR (POST) — vincula solo los que tienen candidato.
// ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    [$conCandidato, $sinCandidato] = sp_vincular_analizar($pdo, $conjuntoId);

    $ok = 0; $err = 0; $errores = [];
    $upd = $pdo->prepare(
        "UPDATE vehiculos
            SET residente_id = :rid
          WHERE id = :vid
            AND conjunto_id = :c
            AND residente_id IS NULL"   // <- doble seguro: no pisa asignaciones
    );

    $pdo->beginTransaction();
    try {
        foreach ($conCandidato as $f) {
            try {
                $upd->execute([
                    ':rid' => (int)$f['cand_id'],
                    ':vid' => (int)$f['veh_id'],
                    ':c'   => $conjuntoId,
                ]);
                if ($upd->rowCount() > 0) $ok++;
            } catch (Exception $e) {
                $err++;
                $errores[] = $f['placa'] . ': ' . $e->getMessage();
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('error', 'Error al aplicar: ' . $e->getMessage() . ' — no se guardó nada.');
        redirect('/importaciones/vincular_vehiculos');
    }

    // Auditoría (si existe la función/tabla)
    if (function_exists('audit_log')) {
        audit_log('vehiculos', 'vinculacion_masiva',
            "Vinculación masiva: {$ok} vehículos asignados a residente, {$err} errores.");
    }

    $msg = "Se vincularon {$ok} vehículos.";
    if ($err > 0) $msg .= " {$err} con error.";
    if (!empty($sinCandidato)) $msg .= " " . count($sinCandidato) . " quedaron sin asignar (apto sin residente).";
    flash_set('success', $msg);
    redirect('/importaciones/vincular_vehiculos');
}

// ───────────────────────────────────────────────────────────────
// PREVISUALIZACIÓN (GET)
// ───────────────────────────────────────────────────────────────
[$conCandidato, $sinCandidato] = sp_vincular_analizar($pdo, $conjuntoId);

// Conteos por tipo de vínculo que se asignaría
$porTipo = ['inquilino' => 0, 'propietario' => 0, 'otro' => 0];
foreach ($conCandidato as $f) {
    $t = $f['cand_tipo'];
    if ($t === 'inquilino') $porTipo['inquilino']++;
    elseif ($t === 'propietario') $porTipo['propietario']++;
    else $porTipo['otro']++;
}

$_pageTitle = 'Vincular vehículos sin asignar';
include INCLUDES_PATH . '/header.php';
?>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn" href="<?= url('/importaciones') ?>">📥 Importaciones</a>
</div>

<h1 style="margin:8px 0 4px">🔗 Vincular vehículos sin asignar</h1>
<p style="color:#6b7280;margin:0 0 18px">
    Asigna un vínculo a los vehículos que hoy aparecen como
    <b>“Sin asignar”</b>. Se usa el residente del mismo apartamento,
    prefiriendo <b>inquilino</b> y luego <b>propietario</b>.
    Los vehículos cuyo apartamento no tiene ningún residente registrado
    se dejan como están.
</p>

<div class="cards" style="margin-bottom:20px">
    <div class="card card--accent">
        <div class="card__label">✅ Se pueden vincular</div>
        <div class="card__value" style="color:#166534"><?= count($conCandidato) ?></div>
    </div>
    <div class="card">
        <div class="card__label">🏠 Como inquilino</div>
        <div class="card__value"><?= $porTipo['inquilino'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">👔 Como propietario</div>
        <div class="card__value"><?= $porTipo['propietario'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">⚠️ Quedan sin asignar</div>
        <div class="card__value" style="color:#b45309"><?= count($sinCandidato) ?></div>
    </div>
</div>

<?php if (!empty($conCandidato)): ?>
    <form method="post" action="<?= url('/importaciones/vincular_vehiculos') ?>"
          onsubmit="return confirm('Se vincularán <?= count($conCandidato) ?> vehículos. Esta acción modifica la base de datos. ¿Continuar?');"
          style="margin-bottom:24px">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary" style="font-size:15px;padding:10px 20px">
            ✅ Aplicar vinculación a <?= count($conCandidato) ?> vehículos
        </button>
        <span style="color:#6b7280;font-size:13px;margin-left:10px">
            Solo se tocan los que están sin asignar. No se modifica nada más.
        </span>
    </form>
<?php else: ?>
    <div class="card" style="background:#f0fdf4;margin-bottom:24px">
        <b style="color:#166534">✓ No hay vehículos pendientes de vincular con datos disponibles.</b>
    </div>
<?php endif; ?>

<h3 style="margin:18px 0 8px">Vista previa — se vincularían así (<?= count($conCandidato) ?>)</h3>

<!-- Búsqueda y filtros (filtran la tabla al instante, sin recargar) -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;align-items:center">
    <input type="text" id="fBuscar" placeholder="🔍 Buscar placa, apto o nombre..."
           style="flex:1;min-width:220px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
    <select id="fTorre" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <option value="">Todas las torres</option>
        <?php
        $torresPrev = [];
        foreach ($conCandidato as $f) { if ($f['torre'] !== null) $torresPrev[(string)$f['torre']] = true; }
        ksort($torresPrev, SORT_NATURAL);
        foreach (array_keys($torresPrev) as $tr): ?>
            <option value="<?= e($tr) ?>">Torre <?= e($tr) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="fVinculo" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <option value="">Todos los vínculos</option>
        <option value="inquilino">Inquilino</option>
        <option value="propietario">Propietario</option>
    </select>
    <span id="fContador" style="font-size:13px;color:#6b7280;white-space:nowrap"></span>
</div>

<div style="overflow:auto;max-height:420px;border:1px solid #e5e7eb;border-radius:8px">
<table class="table" style="margin:0">
    <thead style="position:sticky;top:0;background:#f9fafb">
        <tr>
            <th>Placa</th><th>Tipo</th><th>Apto</th><th>Torre</th>
            <th>Se vincularía a</th><th>Vínculo</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($conCandidato as $f):
        $badge = $f['cand_tipo'] === 'inquilino'
                    ? '<span class="pill" style="background:#dcfce7;color:#166534">inquilino</span>'
                    : ($f['cand_tipo'] === 'propietario'
                        ? '<span class="pill" style="background:#dbeafe;color:#1e40af">propietario</span>'
                        : '<span class="pill">' . e($f['cand_tipo']) . '</span>');
        $rowSearch = strtolower($f['placa'] . ' ' . $f['apto_num'] . ' ' . $f['cand_nombre']);
    ?>
        <tr class="fila-veh"
            data-buscar="<?= e($rowSearch) ?>"
            data-torre="<?= e((string)$f['torre']) ?>"
            data-vinculo="<?= e($f['cand_tipo']) ?>">
            <td style="font-family:ui-monospace,monospace;font-weight:600"><?= e($f['placa']) ?></td>
            <td><?= e($f['veh_tipo']) ?></td>
            <td><?= e($f['apto_num']) ?></td>
            <td><?= e($f['torre']) ?></td>
            <td><?= e($f['cand_nombre']) ?></td>
            <td><?= $badge ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if (!empty($sinCandidato)): ?>
    <h3 style="margin:26px 0 8px;color:#b45309">
        ⚠️ Quedan sin asignar — su apartamento no tiene residente registrado (<?= count($sinCandidato) ?>)
    </h3>
    <p style="color:#6b7280;font-size:13px;margin:0 0 8px">
        Para vincular estos, primero hay que registrar un residente (inquilino o
        propietario) en el apartamento correspondiente.
    </p>
    <div style="overflow:auto;max-height:320px;border:1px solid #fed7aa;border-radius:8px">
    <table class="table" style="margin:0">
        <thead style="position:sticky;top:0;background:#fff7ed">
            <tr><th>Placa</th><th>Tipo</th><th>Apto</th><th>Torre</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sinCandidato as $f): ?>
            <tr>
                <td style="font-family:ui-monospace,monospace;font-weight:600"><?= e($f['placa']) ?></td>
                <td><?= e($f['veh_tipo']) ?></td>
                <td><?= e($f['apto_num']) ?></td>
                <td><?= e($f['torre']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<script>
(function () {
    var buscar   = document.getElementById('fBuscar');
    var selTorre = document.getElementById('fTorre');
    var selVinc  = document.getElementById('fVinculo');
    var contador = document.getElementById('fContador');
    var filas    = Array.prototype.slice.call(document.querySelectorAll('.fila-veh'));
    if (!filas.length) return;

    function aplicar() {
        var q  = (buscar.value || '').trim().toLowerCase();
        var tr = selTorre.value;
        var vi = selVinc.value;
        var visibles = 0;
        filas.forEach(function (row) {
            var okQ  = !q  || row.getAttribute('data-buscar').indexOf(q) !== -1;
            var okTr = !tr || row.getAttribute('data-torre') === tr;
            var okVi = !vi || row.getAttribute('data-vinculo') === vi;
            var mostrar = okQ && okTr && okVi;
            row.style.display = mostrar ? '' : 'none';
            if (mostrar) visibles++;
        });
        contador.textContent = 'Mostrando ' + visibles + ' de ' + filas.length;
    }

    buscar.addEventListener('input', aplicar);
    selTorre.addEventListener('change', aplicar);
    selVinc.addEventListener('change', aplicar);
    aplicar();
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
