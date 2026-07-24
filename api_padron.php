<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_padron.php
// v4a (OFFLINE): Exporta el padron de vehiculos del conjunto para cachearlo en IndexedDB.
//
// Lo consume /assets/js/sp_padron.js cuando hay red, para que la ronda pueda
// verificar placas SIN conexion (hoy verificarPlaca() depende de api_placa_lookup,
// que muere sin señal).
//
// NO modifica nada. Solo lee.
//
// Respuesta:
//   { ok:true, version:<unix>, total:<n>, vehiculos:[ {p,t,a,i}, ... ] }
//     p = placa | t = tipo ('carro'|'moto') | a = apto visible | i = vehiculo_id
//   Claves cortas a proposito: con 2000 vehiculos ahorra ~40% de payload.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin', 'admin', 'supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo        = db();
$u          = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

try {
    // Vehiculos activos del conjunto (mismo criterio que api_placa_lookup)
    $st = $pdo->prepare(
        "SELECT v.id, v.placa, v.tipo, a.numero_visible AS apto,
                a.estado_morosidad, a.meses_mora
           FROM vehiculos v
      LEFT JOIN apartamentos a ON a.id = v.apartamento_id
          WHERE v.conjunto_id = :cj
            AND v.archivado_en IS NULL
            AND v.placa <> ''
       ORDER BY v.placa"
    );
    $st->execute([':cj' => $conjuntoId]);

    $out = [];
    while ($r = $st->fetch()) {
        $mora   = (int)($r['meses_mora'] ?? 0);
        $estado = $r['estado_morosidad'] ?? 'al_dia';
        if (!$estado || $estado === '') $estado = 'al_dia';
        $out[] = [
            'p'  => strtoupper((string)$r['placa']),
            't'  => (string)($r['tipo'] ?? ''),
            'a'  => (string)($r['apto'] ?? ''),
            'i'  => (int)$r['id'],
            'ep' => $estado,   // estado_pago: 'al_dia' | 'moroso' | ...
            'm'  => $mora,     // meses_mora
        ];
    }

    // Vehiculos de visitantes (tabla separada, puede no existir en todos los despliegues)
    // Try/catch defensivo: si la tabla no esta, seguimos sin romper.
    try {
        $sv = $pdo->prepare(
            "SELECT vv.id, vv.placa, vv.tipo
               FROM visitantes_vehiculos vv
              WHERE vv.conjunto_id = :cj2
                AND vv.placa <> ''
           ORDER BY vv.placa"
        );
        $sv->execute([':cj2' => $conjuntoId]);
        while ($r = $sv->fetch()) {
            $out[] = [
                'p' => strtoupper((string)$r['placa']),
                't' => (string)($r['tipo'] ?? ''),
                'a' => '',          // el visitante no tiene apto fijo
                'i' => 0,           // 0 = no es vehiculo residente
                'v' => 1,           // marca: es visitante
            ];
        }
    } catch (Throwable $e) {
        // tabla ausente o sin columna conjunto_id -> se ignora, el padron igual sirve
    }

    echo json_encode([
        'ok'        => true,
        'version'   => time(),
        'total'     => count($out),
        'vehiculos' => $out,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo generar el padron']);
}
