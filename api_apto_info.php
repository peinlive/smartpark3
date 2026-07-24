<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/api_apto_info.php
// v1.0 (3BH): devuelve JSON con info del apartamento (para el popover flotante).
//   Params: ?apto=1502
//   Response: { ok, apto, torre, piso, propietario, celular,
//               estado_morosidad, meses_mora, bloqueo_comunes,
//               residentes:[{nombre,tipo,celular}],
//               vehiculos:[{placa,tipo,marca,color}],
//               celdas_dueno:[{codigo,nivel}],
//               celdas_usadas:[{codigo,nivel,tipo_asig}] }

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

// Acepta ?id=<interno> (como manda el modal) o ?apto=<numero_visible>.
$aptoId  = (int)($_GET['id'] ?? 0);
$aptoNum = trim((string)($_GET['apto'] ?? ''));
if ($aptoId <= 0 && $aptoNum === '') {
    echo json_encode(['ok'=>false,'error'=>'Falta id o apto']); exit;
}

try {
    // Datos del apto
    $stA = $pdo->prepare("SELECT a.id, a.numero_visible AS apto, a.piso,
                                 a.estado_morosidad, a.meses_mora, a.bloqueo_comunes,
                                 a.propietario_nombre, a.propietario_celular,
                                 t.numero AS torre, t.nombre AS torre_nombre
                            FROM apartamentos a
                            JOIN torres t ON t.id = a.torre_id
                           WHERE a.conjunto_id = :c
                             AND (a.id = :aid OR a.numero_visible = :n)
                           LIMIT 1");
    $stA->execute([':c' => $conjuntoId, ':aid' => $aptoId, ':n' => $aptoNum]);
    $apto = $stA->fetch();

    if (!$apto) {
        echo json_encode(['ok'=>false,'error'=>'Apto no encontrado','apto_num'=>$aptoNum]);
        exit;
    }

    $aptoId = (int)$apto['id'];

    // Residentes activos
    $stR = $pdo->prepare("SELECT r.id, r.nombre, r.tipo, r.celular
                            FROM residentes r
                           WHERE r.apartamento_id = :a
                             AND r.archivado_en IS NULL AND r.activo = 1
                        ORDER BY r.tipo, r.nombre LIMIT 10");
    $stR->execute([':a' => $aptoId]);
    $residentes = $stR->fetchAll(PDO::FETCH_ASSOC);

    // Vehículos activos
    $stV = $pdo->prepare("SELECT v.id, v.placa, v.tipo, v.marca, v.color
                            FROM vehiculos v
                           WHERE v.apartamento_id = :a
                             AND v.archivado_en IS NULL
                        ORDER BY v.tipo, v.placa LIMIT 10");
    $stV->execute([':a' => $aptoId]);
    $vehiculos = $stV->fetchAll(PDO::FETCH_ASSOC);

    // ── UNA sola consulta: trae las celdas del apto directo de la BD ──
    // Recibe el id del apto ya resuelto ($aptoId) y busca por las DOS vias
    // con UNION: como DUENO (celdas.apto_dueno_id) y como USUARIO autorizado
    // (asignaciones_celdas.apto_usuario_id). Es lo mismo que muestra la
    // busqueda por celda y el modo offline. Comentarios FUERA del SQL.
    $celdasDueno  = [];
    $celdasUsadas = [];
    try {
        $stC = $pdo->prepare(
            "SELECT c.nombre_visible AS codigo, c.tipo AS tipo_celda,
                    np.codigo AS nivel_codigo, 'dueno' AS relacion,
                    acd.tipo AS tipo_asig, ad.numero_visible AS apto_dueno,
                    au.numero_visible AS apto_usuario
               FROM celdas c
          LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
          LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
          LEFT JOIN asignaciones_celdas acd ON acd.celda_id = c.id AND acd.activa = 1 AND acd.archivado_en IS NULL
          LEFT JOIN apartamentos au ON au.id = acd.apto_usuario_id
              WHERE c.apto_dueno_id = :ad
             UNION
             SELECT c.nombre_visible AS codigo, c.tipo AS tipo_celda,
                    np.codigo AS nivel_codigo, 'usuario' AS relacion,
                    ac.tipo AS tipo_asig, ad.numero_visible AS apto_dueno,
                    NULL AS apto_usuario
               FROM asignaciones_celdas ac
               JOIN celdas c ON c.id = ac.celda_id
          LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
          LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
              WHERE ac.apto_usuario_id = :au
                AND ac.activa = 1
                AND ac.archivado_en IS NULL
           ORDER BY codigo"
        );
        $stC->execute([':ad' => $aptoId, ':au' => $aptoId]);
        foreach ($stC as $r) {
            if ($r['relacion'] === 'dueno') {
                $celdasDueno[] = $r;
            } else {
                $celdasUsadas[] = $r;
            }
        }
    } catch (Exception $e) { /* defensivo */ }

    // v7.59: cuartos útiles del apto (dueño + usuario), misma lógica que celdas
    $cuartosDueno  = [];
    $cuartosUsadas = [];
    try {
        $stCu = $pdo->prepare(
            "SELECT cu.codigo AS codigo, np.codigo AS nivel_codigo, 'dueno' AS relacion,
                    acd.tipo AS tipo_asig, ad.numero_visible AS apto_dueno,
                    au.numero_visible AS apto_usuario
               FROM cuartos_utiles cu
          LEFT JOIN niveles_parqueadero np ON np.id = cu.nivel_id
          LEFT JOIN apartamentos ad ON ad.id = cu.apto_dueno_id
          LEFT JOIN asignaciones_cuartos acd ON acd.cuarto_id = cu.id AND acd.activa = 1 AND acd.archivado_en IS NULL
          LEFT JOIN apartamentos au ON au.id = acd.apto_usuario_id
              WHERE cu.apto_dueno_id = :ad AND cu.activo = 1
             UNION
             SELECT cu.codigo AS codigo, np.codigo AS nivel_codigo, 'usuario' AS relacion,
                    acu.tipo AS tipo_asig, ad.numero_visible AS apto_dueno,
                    NULL AS apto_usuario
               FROM asignaciones_cuartos acu
               JOIN cuartos_utiles cu ON cu.id = acu.cuarto_id
          LEFT JOIN niveles_parqueadero np ON np.id = cu.nivel_id
          LEFT JOIN apartamentos ad ON ad.id = cu.apto_dueno_id
              WHERE acu.apto_usuario_id = :au
                AND acu.activa = 1
                AND acu.archivado_en IS NULL
           ORDER BY codigo"
        );
        $stCu->execute([':ad' => $aptoId, ':au' => $aptoId]);
        foreach ($stCu as $r) {
            if ($r['relacion'] === 'dueno') {
                $cuartosDueno[] = $r;
            } else {
                $cuartosUsadas[] = $r;
            }
        }
    } catch (Exception $e) { /* tabla puede no existir: defensivo */ }

    echo json_encode([
        'ok' => true,
        'apto'                => $apto['apto'],
        'torre'               => (int)$apto['torre'],
        'torre_nombre'        => $apto['torre_nombre'],
        'piso'                => (int)$apto['piso'],
        'propietario'         => $apto['propietario_nombre'] ?: '',
        'propietario_celular' => $esRonda ? '' : ($apto['propietario_celular'] ?: ''),
        'estado_morosidad'    => $apto['estado_morosidad'],
        'meses_mora'          => (int)$apto['meses_mora'],
        'bloqueo_comunes'     => (int)$apto['bloqueo_comunes'],
        'residentes'          => $esRonda
            ? array_map(function($r){ $r['celular'] = ''; return $r; }, $residentes)
            : $residentes,
        'vehiculos'           => $vehiculos,
        'celdas_dueno'        => $celdasDueno,
        'celdas_usadas'       => $celdasUsadas,
        'cuartos_dueno'       => $cuartosDueno,
        'cuartos_usados'      => $cuartosUsadas,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $ex) {
    echo json_encode([
        'ok' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al consultar',
    ]);
}
