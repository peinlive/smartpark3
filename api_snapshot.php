<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/api_snapshot.php
// v4d (OFFLINE): Exporta TODO lo que la ronda necesita para consultar sin señal.
//
// Reemplaza al padron minimo de v4a (que solo traia placa/tipo/apto).
// Ahora trae: vehiculos + visitantes + apartamentos + residentes + celdas,
// con los mismos campos que muestran las 4 busquedas de /consultas.
//
// SOLO LEE. No modifica nada.
//
// Cada bloque va en su propio try/catch: si una tabla no existe en este
// despliegue, el resto del snapshot igual se entrega.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo        = db();
$u          = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

$out = [
    'ok'            => true,
    'version'       => time(),
    'vehiculos'     => [],
    'visitantes'    => [],
    'apartamentos'  => [],
    'residentes'    => [],
    'celdas'        => [],
    'cuartos'       => [],
    'observaciones' => [],
    'revistas'      => [],
    'niveles'       => [],
    'avisos'        => [],
];

/* ─────────── VEHICULOS (residentes) ─────────── */
try {
    $st = $pdo->prepare("
        SELECT v.id, v.placa, v.tipo, v.marca, v.color, v.observaciones, v.archivado_en,
               a.id AS apto_id, a.numero_visible AS apto, a.piso,
               a.estado_morosidad, a.meses_mora, a.bloqueo_comunes,
               t.numero AS torre,
               r.nombre AS residente_nombre, r.celular AS residente_celular, r.tipo AS residente_tipo
          FROM vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
          JOIN torres t       ON t.id = a.torre_id
     LEFT JOIN residentes r   ON r.id = v.residente_id
         WHERE v.conjunto_id = :cv
      ORDER BY v.placa");
    $st->execute([':cv' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['vehiculos'][] = [
            'id'        => (int)$r['id'],
            'placa'     => strtoupper((string)$r['placa']),
            'tipo'      => (string)($r['tipo'] ?? ''),
            'marca'     => (string)($r['marca'] ?? ''),
            'color'     => (string)($r['color'] ?? ''),
            'obs'       => (string)($r['observaciones'] ?? ''),
            'archivado' => !empty($r['archivado_en']) ? 1 : 0,
            'apto_id'   => (int)($r['apto_id'] ?? 0),
            'apto'      => (string)($r['apto'] ?? ''),
            'piso'      => (string)($r['piso'] ?? ''),
            'torre'     => (string)($r['torre'] ?? ''),
            'moroso'    => (string)($r['estado_morosidad'] ?? ''),
            'meses'     => (int)($r['meses_mora'] ?? 0),
            'bloqueo'   => !empty($r['bloqueo_comunes']) ? 1 : 0,
            'res_nom'   => (string)($r['residente_nombre'] ?? ''),
            'res_cel'   => $esRonda ? '' : (string)($r['residente_celular'] ?? ''),
            'res_tipo'  => (string)($r['residente_tipo'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'vehiculos: ' . $e->getMessage();
}

/* ─────────── VISITANTES (tabla separada) ─────────── */
try {
    $st = $pdo->prepare("
        SELECT vv.id, vv.placa, vv.tipo, vv.nombre_visitante, vv.parentesco,
               vv.recurrente, vv.visitas_count, vv.primera_visita, vv.ultima_visita,
               vv.archivado_en,
               a.id AS apto_id, a.numero_visible AS apto, t.numero AS torre
          FROM visitantes_vehiculos vv
          JOIN apartamentos a ON a.id = vv.apartamento_id
          JOIN torres t       ON t.id = a.torre_id
         WHERE vv.conjunto_id = :cvv
      ORDER BY vv.placa");
    $st->execute([':cvv' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['visitantes'][] = [
            'id'         => (int)$r['id'],
            'placa'      => strtoupper((string)$r['placa']),
            'tipo'       => (string)($r['tipo'] ?? ''),
            'nombre'     => (string)($r['nombre_visitante'] ?? ''),
            'parentesco' => (string)($r['parentesco'] ?? ''),
            'recurrente' => !empty($r['recurrente']) ? 1 : 0,
            'visitas'    => (int)($r['visitas_count'] ?? 0),
            'primera'    => (string)($r['primera_visita'] ?? ''),
            'ultima'     => (string)($r['ultima_visita'] ?? ''),
            'archivado'  => !empty($r['archivado_en']) ? 1 : 0,
            'apto_id'    => (int)($r['apto_id'] ?? 0),
            'apto'       => (string)($r['apto'] ?? ''),
            'torre'      => (string)($r['torre'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'visitantes: tabla no disponible';
}

/* ─────────── APARTAMENTOS (con conteos) ─────────── */
try {
    $st = $pdo->prepare("
        SELECT a.id, a.numero_visible AS apto, a.piso, a.estado_morosidad, a.meses_mora,
               a.bloqueo_comunes, a.propietario_nombre, a.propietario_celular,
               t.numero AS torre, t.nombre AS torre_nombre,
               (SELECT COUNT(*) FROM residentes r2
                 WHERE r2.apartamento_id = a.id AND r2.activo = 1) AS n_res,
               (SELECT COUNT(*) FROM vehiculos v2
                 WHERE v2.apartamento_id = a.id AND v2.tipo = 'carro'
                   AND v2.archivado_en IS NULL) AS n_carros,
               (SELECT COUNT(*) FROM vehiculos v3
                 WHERE v3.apartamento_id = a.id AND v3.tipo = 'moto'
                   AND v3.archivado_en IS NULL) AS n_motos
          FROM apartamentos a
          JOIN torres t ON t.id = a.torre_id
         WHERE a.conjunto_id = :ca
      ORDER BY t.numero, a.numero_visible");
    $st->execute([':ca' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['apartamentos'][] = [
            'id'        => (int)$r['id'],
            'apto'      => (string)($r['apto'] ?? ''),
            'piso'      => (string)($r['piso'] ?? ''),
            'torre'     => (string)($r['torre'] ?? ''),
            'torre_nom' => (string)($r['torre_nombre'] ?? ''),
            'moroso'    => (string)($r['estado_morosidad'] ?? ''),
            'meses'     => (int)($r['meses_mora'] ?? 0),
            'bloqueo'   => !empty($r['bloqueo_comunes']) ? 1 : 0,
            'prop_nom'  => (string)($r['propietario_nombre'] ?? ''),
            'prop_cel'  => $esRonda ? '' : (string)($r['propietario_celular'] ?? ''),
            'n_res'     => (int)($r['n_res'] ?? 0),
            'n_carros'  => (int)($r['n_carros'] ?? 0),
            'n_motos'   => (int)($r['n_motos'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'apartamentos: ' . $e->getMessage();
}

/* ─────────── RESIDENTES ───────────
   OJO: residentes NO tiene conjunto_id. Se valida por a.conjunto_id. */
try {
    $st = $pdo->prepare("
        SELECT r.id, r.nombre, r.celular, r.tipo, r.activo, r.vive_en_apto,
               a.id AS apto_id, a.numero_visible AS apto, t.numero AS torre
          FROM residentes r
          JOIN apartamentos a ON a.id = r.apartamento_id
          JOIN torres t       ON t.id = a.torre_id
         WHERE a.conjunto_id = :cr AND r.archivado_en IS NULL
      ORDER BY r.nombre");
    $st->execute([':cr' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['residentes'][] = [
            'id'      => (int)$r['id'],
            'nombre'  => (string)($r['nombre'] ?? ''),
            'celular' => $esRonda ? '' : (string)($r['celular'] ?? ''),
            'tipo'    => (string)($r['tipo'] ?? ''),
            'activo'  => !empty($r['activo']) ? 1 : 0,
            'vive'    => !empty($r['vive_en_apto']) ? 1 : 0,
            'apto_id' => (int)($r['apto_id'] ?? 0),
            'apto'    => (string)($r['apto'] ?? ''),
            'torre'   => (string)($r['torre'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'residentes: ' . $e->getMessage();
}

/* ─────────── CELDAS (con asignacion activa) ───────────
   Campos correctos confirmados: celdas.apto_dueno_id (NO apartamento_dueno_id);
   asignaciones_celdas.observacion (NO notas). */
try {
    $st = $pdo->prepare("
        SELECT c.id, c.nombre_visible AS celda, c.tipo, c.nivel_id, c.numero_orden,
               c.apto_dueno_id,
               ac.apto_usuario_id,
               ad.numero_visible AS apto_dueno,
               ad.estado_morosidad AS dueno_moroso,
               au.numero_visible AS apto_usuario,
               au.estado_morosidad AS usuario_moroso,
               ac.tipo AS asig_tipo, ac.valor_mensual, ac.observacion
          FROM celdas c
     LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
     LEFT JOIN asignaciones_celdas ac
            ON ac.celda_id = c.id AND ac.activa = 1 AND ac.archivado_en IS NULL
     LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id
         WHERE c.conjunto_id = :cc AND c.activa = 1
      ORDER BY c.numero_orden ASC, c.nombre_visible ASC");
    $st->execute([':cc' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['celdas'][] = [
            'id'        => (int)$r['id'],
            'celda'     => (string)($r['celda'] ?? ''),
            'tipo'      => (string)($r['tipo'] ?? ''),
            'nivel_id'  => (int)($r['nivel_id'] ?? 0),
            'orden'     => (int)($r['numero_orden'] ?? 0),
            'dueno_id'  => (int)($r['apto_dueno_id'] ?? 0),
            'uso_id'    => (int)($r['apto_usuario_id'] ?? 0),
            'dueno'     => (string)($r['apto_dueno'] ?? ''),
            'usuario'   => (string)($r['apto_usuario'] ?? ''),
            'dueno_moroso'   => (string)($r['dueno_moroso'] ?? ''),
            'usuario_moroso' => (string)($r['usuario_moroso'] ?? ''),
            'asig'      => (string)($r['asig_tipo'] ?? ''),
            'valor'     => (float)($r['valor_mensual'] ?? 0),
            'obs'       => (string)($r['observacion'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'celdas: ' . $e->getMessage();
}

// ── v7.27: Cuartos útiles para offline (espejo de celdas) ──
try {
    $stq = $pdo->prepare("
        SELECT c.id, c.codigo AS cuarto, c.nivel_id,
               c.apto_dueno_id,
               ac.apto_usuario_id,
               ad.numero_visible AS apto_dueno,
               au.numero_visible AS apto_usuario,
               ac.tipo AS asig_tipo, ac.valor_mensual, ac.observacion
          FROM cuartos_utiles c
     LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
     LEFT JOIN asignaciones_cuartos ac
            ON ac.cuarto_id = c.id AND ac.activa = 1 AND ac.archivado_en IS NULL
     LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id
         WHERE c.conjunto_id = :cc AND c.activo = 1
      ORDER BY c.codigo ASC");
    $stq->execute([':cc' => $conjuntoId]);
    while ($r = $stq->fetch()) {
        $out['cuartos'][] = [
            'id'        => (int)$r['id'],
            'cuarto'    => (string)($r['cuarto'] ?? ''),
            'nivel_id'  => (int)($r['nivel_id'] ?? 0),
            'dueno_id'  => (int)($r['apto_dueno_id'] ?? 0),
            'uso_id'    => (int)($r['apto_usuario_id'] ?? 0),
            'dueno'     => (string)($r['apto_dueno'] ?? ''),
            'usuario'   => (string)($r['apto_usuario'] ?? ''),
            'asig'      => (string)($r['asig_tipo'] ?? ''),
            'valor'     => (float)($r['valor_mensual'] ?? 0),
            'obs'       => (string)($r['observacion'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'cuartos: ' . $e->getMessage();
}

/* ─────────── OBSERVACIONES (historial de novedades) ───────────
   Campos correctos: o.tipo (NO o.tipo_obs), o.usuario_registra (NO o.creado_por),
   sin o.conjunto_id -> se valida por JOIN con vehiculos. */
try {
    // o.* -> traemos todo, asi no importa si alguna columna se llama distinto
    $st = $pdo->prepare("
        SELECT o.*,
               v.placa, v.tipo AS veh_tipo,
               a.numero_visible AS apto,
               u.nombre_completo AS usuario_nombre
          FROM observaciones_vehiculo o
          JOIN vehiculos v      ON v.id = o.vehiculo_id
     LEFT JOIN apartamentos a   ON a.id = v.apartamento_id
     LEFT JOIN usuarios u       ON u.id = o.usuario_registra
         WHERE v.conjunto_id = :co
      ORDER BY o.creado_en DESC
         LIMIT 800");
    $st->execute([':co' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['observaciones'][] = [
            'id'       => (int)$r['id'],
            'veh_id'   => (int)$r['vehiculo_id'],
            'placa'    => strtoupper((string)($r['placa'] ?? '')),
            'apto'     => (string)($r['apto'] ?? ''),
            'tipo'     => (string)($r['tipo'] ?? ''),
            'gravedad' => (string)($r['gravedad'] ?? ''),
            'desc'     => (string)($r['descripcion'] ?? ''),
            'estado'   => (string)($r['estado'] ?? ''),
            'creado'   => (string)($r['creado_en'] ?? ''),
            'usuario'  => (string)($r['usuario_nombre'] ?? ''),
            'evid'     => (string)($r['evidencia_url'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'observaciones: ' . $e->getMessage();
}

/* ─────────── REVISTAS (lista + celdas de las en curso) ─────────── */
try {
    $st = $pdo->prepare("
        SELECT r.id, r.nombre, r.estado, r.creado_en, r.nivel_id,
               r.celdas_revisadas, r.celdas_ocupadas, r.celdas_vacias,
               u.nombre_completo AS usuario
          FROM revistas r
     LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE r.conjunto_id = :crv
      ORDER BY r.creado_en DESC
         LIMIT 30");
    $st->execute([':crv' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['revistas'][] = [
            'id'        => (int)$r['id'],
            'nombre'    => (string)($r['nombre'] ?? ''),
            'estado'    => (string)($r['estado'] ?? ''),
            'creado'    => (string)($r['creado_en'] ?? ''),
            'nivel_id'  => (int)($r['nivel_id'] ?? 0),
            'revisadas' => (int)($r['celdas_revisadas'] ?? 0),
            'ocupadas'  => (int)($r['celdas_ocupadas'] ?? 0),
            'vacias'    => (int)($r['celdas_vacias'] ?? 0),
            'usuario'   => (string)($r['usuario'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'revistas: ' . $e->getMessage();
}

/* ─────────── NIVELES (para crear revistas offline) ─────────── */
try {
    // Tabla REAL: niveles_parqueadero (id, codigo, nombre).
    // revistas.nivel guarda el CODIGO (string), no el id.
    $st = $pdo->prepare("
        SELECT n.id, n.codigo, n.nombre,
               (SELECT COUNT(*) FROM celdas c
                 WHERE c.nivel_id = n.id AND c.activa = 1) AS total_celdas
          FROM niveles_parqueadero n
         WHERE n.conjunto_id = :cn
      ORDER BY n.codigo");
    $st->execute([':cn' => $conjuntoId]);
    while ($r = $st->fetch()) {
        $out['niveles'][] = [
            'id'     => (int)$r['id'],
            'codigo' => (string)$r['codigo'],
            'nombre' => (string)$r['nombre'],
            'total'  => (int)$r['total_celdas'],
        ];
    }
} catch (Throwable $e) {
    $out['avisos'][] = 'niveles: ' . $e->getMessage();
}

$out['totales'] = [
    'vehiculos'    => count($out['vehiculos']),
    'visitantes'   => count($out['visitantes']),
    'apartamentos' => count($out['apartamentos']),
    'residentes'   => count($out['residentes']),
    'celdas'        => count($out['celdas']),
    'cuartos'       => count($out['cuartos']),
    'observaciones' => count($out['observaciones']),
    'revistas'      => count($out['revistas']),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);
