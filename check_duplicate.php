<?php
// /home/myzonaco/smartpark.myzona360.com/api/check_duplicate.php
// v3m: endpoint AJAX que verifica si ya existe un residente (por celular)
//      o un vehículo (por placa) en el conjunto del usuario actual.
//      Lo consume el JS sp_duplicate_check.js cuando el usuario escribe
//      en los formularios /residentes/crear y /vehiculos/crear.
//      Devuelve JSON. NO escribe a la BD. Solo lectura.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// Blindaje JSON: cualquier cosa que se imprima fuera se envuelve en JSON
ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out === false || $out === '') return;
    json_decode($out);
    if (json_last_error() === JSON_ERROR_NONE) { echo $out; return; }
    if (!headers_sent()) {
        header_remove('Content-Type');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['exists' => false, 'error' => 'Error del servidor.']);
});

header('Content-Type: application/json; charset=utf-8');

auth_require_role('super_admin','admin','supervisor','porteria');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$tipo = $_GET['tipo'] ?? '';
$out  = ['exists' => false, 'matches' => []];

try {
    // ───────────────────────────────────────────────────────────────
    // RESIDENTE: chequea por celular en todo el conjunto
    //            (opcionalmente filtra también por nombre+apto)
    // ───────────────────────────────────────────────────────────────
    if ($tipo === 'residente') {
        $celular = preg_replace('/[^0-9]/', '', $_GET['celular'] ?? '');
        $nombre  = trim($_GET['nombre'] ?? '');
        $aptoId  = (int)($_GET['apto_id'] ?? 0);

        // Si no hay datos suficientes, devolvemos vacío sin error
        if (strlen($celular) < 7 && ($nombre === '' || $aptoId === 0)) {
            echo json_encode($out); exit;
        }

        $conds  = [];
        $params = [':cid' => $conjuntoId];

        if (strlen($celular) >= 7) {
            // Búsqueda flexible: el celular puede tener guiones/espacios en BD
            $conds[] = "REPLACE(REPLACE(REPLACE(REPLACE(r.celular,' ',''),'-',''),'(',''),')','') LIKE :cel";
            $params[':cel'] = '%' . $celular . '%';
        }
        if ($nombre !== '' && $aptoId > 0) {
            $conds[] = "(r.nombre LIKE :nom AND r.apartamento_id = :aid)";
            $params[':nom'] = '%' . $nombre . '%';
            $params[':aid'] = $aptoId;
        }

        if (empty($conds)) { echo json_encode($out); exit; }
        $whereExtra = '(' . implode(' OR ', $conds) . ')';

        $sql = "SELECT r.id, r.nombre, r.celular, r.tipo, r.archivado_en,
                       a.numero_visible AS apto_numero, t.numero AS torre_numero
                  FROM residentes r
                  JOIN apartamentos a ON a.id = r.apartamento_id
                  JOIN torres t       ON t.id = a.torre_id
                 WHERE a.conjunto_id = :cid
                   AND $whereExtra
                 ORDER BY r.archivado_en ASC, r.creado_en DESC
                 LIMIT 5";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        if (!empty($rows)) {
            $out['exists'] = true;
            foreach ($rows as $r) {
                $out['matches'][] = [
                    'id'         => (int)$r['id'],
                    'nombre'     => $r['nombre'],
                    'celular'    => $r['celular'],
                    'tipo'       => $r['tipo'],
                    'apto'       => $r['apto_numero'],
                    'torre'      => (int)$r['torre_numero'],
                    'archivado'  => !empty($r['archivado_en']),
                    'url_ver'    => url('/residentes/ver?id=' . (int)$r['id']),
                    'url_editar' => !empty($r['archivado_en']) ? null : url('/residentes/editar?id=' . (int)$r['id']),
                ];
            }
        }
    }
    // ───────────────────────────────────────────────────────────────
    // VEHÍCULO: chequea por placa en TODO el conjunto
    //           (tanto en vehiculos residentes como visitantes_vehiculos)
    // ───────────────────────────────────────────────────────────────
    elseif ($tipo === 'vehiculo') {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['placa'] ?? '')));
        if (strlen($placa) < 4) { echo json_encode($out); exit; }

        $rows = [];

        // 1) Tabla vehiculos (residentes)
        $sql1 = "SELECT v.id, v.placa, v.tipo, v.archivado_en,
                        a.numero_visible AS apto_numero, t.numero AS torre_numero,
                        COALESCE(r.nombre, '') AS nombre,
                        'residente' AS origen
                   FROM vehiculos v
                   JOIN apartamentos a ON a.id = v.apartamento_id
                   JOIN torres t       ON t.id = a.torre_id
              LEFT JOIN residentes r   ON r.id = v.residente_id
                  WHERE v.conjunto_id = :cid
                    AND v.placa = :placa
                  LIMIT 3";
        $st1 = $pdo->prepare($sql1);
        $st1->execute([':cid' => $conjuntoId, ':placa' => $placa]);
        foreach ($st1->fetchAll() as $r) $rows[] = $r;

        // 2) Tabla visitantes_vehiculos
        $sql2 = "SELECT vv.id, vv.placa, vv.tipo, vv.archivado_en,
                        a.numero_visible AS apto_numero, t.numero AS torre_numero,
                        COALESCE(vv.nombre_visitante, '') AS nombre,
                        'visitante' AS origen
                   FROM visitantes_vehiculos vv
                   JOIN apartamentos a ON a.id = vv.apartamento_id
                   JOIN torres t       ON t.id = a.torre_id
                  WHERE vv.conjunto_id = :cid
                    AND vv.placa = :placa
                  LIMIT 3";
        $st2 = $pdo->prepare($sql2);
        $st2->execute([':cid' => $conjuntoId, ':placa' => $placa]);
        foreach ($st2->fetchAll() as $r) $rows[] = $r;

        if (!empty($rows)) {
            $out['exists'] = true;
            foreach ($rows as $v) {
                $verUrl  = $v['origen'] === 'visitante'
                    ? url('/visitantes/ver?id=' . (int)$v['id'])
                    : url('/vehiculos/ver?id=' . (int)$v['id']);
                $editUrl = null;
                if (empty($v['archivado_en'])) {
                    $editUrl = $v['origen'] === 'visitante'
                        ? url('/visitantes/editar?id=' . (int)$v['id'])
                        : url('/vehiculos/editar?id=' . (int)$v['id']);
                }
                $out['matches'][] = [
                    'id'         => (int)$v['id'],
                    'placa'      => $v['placa'],
                    'tipo'       => $v['tipo'],
                    'origen'     => $v['origen'],
                    'nombre'     => $v['nombre'],
                    'apto'       => $v['apto_numero'],
                    'torre'      => (int)$v['torre_numero'],
                    'archivado'  => !empty($v['archivado_en']),
                    'url_ver'    => $verUrl,
                    'url_editar' => $editUrl,
                ];
            }
        }
    }
    else {
        echo json_encode(['exists' => false, 'error' => 'tipo no válido']);
        exit;
    }
}
catch (Throwable $e) {
    $msg = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'consulta fallida';
    echo json_encode(['exists' => false, 'error' => $msg]);
    exit;
}

echo json_encode($out);
