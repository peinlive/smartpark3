<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/api_observacion.php
// v1.0 (3AG): Devuelve JSON con detalle de una observación (para el modal Ver).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { echo json_encode(['error'=>'ID inválido']); exit; }

$labelsTipo = [
    'mal_parqueo'  => 'Mal parqueo',
    'advertencia'  => 'Advertencia',
    'reincidencia' => 'Reincidencia',
    'queja'        => 'Queja',
    'otro'         => 'Otro',
];

// Cargar observación (validando conjunto)
$st = $pdo->prepare("SELECT o.id, o.tipo, o.gravedad, o.descripcion, o.evidencia_url, o.creado_en,
                            v.placa, v.tipo AS veh_tipo,
                            a.numero_visible AS apto, t.numero AS torre,
                            up.nombre_completo AS registrado_por
                       FROM observaciones_vehiculo o
                       JOIN vehiculos v      ON v.id = o.vehiculo_id
                  LEFT JOIN apartamentos a   ON a.id = v.apartamento_id
                  LEFT JOIN torres t         ON t.id = a.torre_id
                  LEFT JOIN usuarios up      ON up.id = o.usuario_registra
                      WHERE o.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$o = $st->fetch();
if (!$o) { echo json_encode(['error'=>'Observación no encontrada']); exit; }

// Evidencias adicionales
$evidencias = [];
try {
    $stE = $pdo->prepare("SELECT id, tipo, archivo_url, creado_en FROM observaciones_evidencias
                           WHERE observacion_id = :o ORDER BY creado_en ASC");
    $stE->execute([':o' => $id]);
    foreach ($stE->fetchAll() as $e) {
        $evidencias[] = [
            'id'   => (int)$e['id'],
            'tipo' => $e['tipo'],
            'url'  => strpos($e['archivo_url'],'http')===0 ? $e['archivo_url'] : '/uploads/' . ltrim($e['archivo_url'], '/'),
        ];
    }
} catch (Exception $ex) { /* tabla no existe todavía */ }

echo json_encode([
    'id'             => (int)$o['id'],
    'tipo'           => $o['tipo'],
    'tipo_label'     => $labelsTipo[$o['tipo']] ?? $o['tipo'],
    'gravedad'       => $o['gravedad'],
    'descripcion'    => (string)$o['descripcion'],
    'evidencia'      => $o['evidencia_url'] ? (strpos($o['evidencia_url'],'http')===0 ? $o['evidencia_url'] : '/uploads/' . ltrim($o['evidencia_url'],'/')) : null,
    'evidencias'     => $evidencias,
    'placa'          => $o['placa'],
    'veh_tipo'       => $o['veh_tipo'],
    'apto'           => $o['apto'],
    'torre'          => $o['torre'],
    'registrado_por' => $o['registrado_por'],
    'fecha'          => date('d/m/Y H:i:s', strtotime($o['creado_en'])),
]);
