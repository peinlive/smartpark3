<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_registrar_vehiculo.php
// v2.0 (3AO): Registro rápido de vehículo durante revista.
//   Recibe: placa, tipo (carro/moto), apto (número), tipo_registro (residente/visitante),
//           residente_id (opcional, solo para tipo_registro=residente)
//   - Si tipo_registro=residente → INSERT en vehiculos
//   - Si tipo_registro=visitante → INSERT en visitantes_vehiculos
//   - residente_id: si el apto tiene varios residentes, permite asignar a uno específico

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit;
}
if (!csrf_check()) {
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
}

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$placa       = strtoupper(preg_replace('/[^A-Z0-9]/i', '', clean_string($_POST['placa'] ?? '', 15)));
$tipo        = in_array($_POST['tipo'] ?? '', ['carro','moto'], true) ? $_POST['tipo'] : 'carro';
$aptoNum     = clean_string($_POST['apto'] ?? '', 20);
// v7.69: ahora se puede elegir propietario / inquilino / visitante
//   propietario|inquilino → vehículo de residente, asociado al residente de ese tipo
//   residente             → compatibilidad con lo anterior (usa residente_id)
//   visitante             → va a visitantes_vehiculos
$tipoReg     = in_array($_POST['tipo_registro'] ?? '', ['residente','visitante','propietario','inquilino'], true)
                ? $_POST['tipo_registro'] : 'residente';
$residenteId = (int)($_POST['residente_id'] ?? 0);

if ($placa === '') { echo json_encode(['ok' => false, 'error' => 'Placa vacía']); exit; }

// Buscar apto (obligatorio ahora para saber a qué apto pertenece)
$aptoId = null;
if ($aptoNum !== '') {
    $stA = $pdo->prepare("SELECT id FROM apartamentos WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
    $stA->execute([':n' => $aptoNum, ':c' => $conjuntoId]);
    $aptoId = (int)$stA->fetchColumn();
    if (!$aptoId) {
        echo json_encode(['ok' => false, 'error' => "Apto {$aptoNum} no existe"]); exit;
    }
}

// Validar residente_id si viene
// v7.69 FIX: la tabla residentes NO tiene conjunto_id; se valida por el apartamento.
if ($residenteId > 0) {
    $stR = $pdo->prepare("SELECT r.id FROM residentes r
                            JOIN apartamentos a ON a.id = r.apartamento_id
                           WHERE r.id = :r AND r.apartamento_id = :a AND a.conjunto_id = :c LIMIT 1");
    $stR->execute([':r' => $residenteId, ':a' => $aptoId, ':c' => $conjuntoId]);
    if (!$stR->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Residente inválido para ese apto']); exit;
    }
}

// v7.69: si eligieron propietario/inquilino, buscar ese residente en el apto
if (($tipoReg === 'propietario' || $tipoReg === 'inquilino') && $aptoId && $residenteId === 0) {
    $buscaTipo = ($tipoReg === 'propietario') ? 'propietario' : 'inquilino';
    try {
        $stT = $pdo->prepare("SELECT r.id FROM residentes r
                                JOIN apartamentos a ON a.id = r.apartamento_id
                               WHERE r.apartamento_id = :a AND a.conjunto_id = :c
                                 AND r.tipo = :t AND r.archivado_en IS NULL AND r.activo = 1
                            ORDER BY r.id LIMIT 1");
        $stT->execute([':a' => $aptoId, ':c' => $conjuntoId, ':t' => $buscaTipo]);
        $encontrado = (int)$stT->fetchColumn();
        if ($encontrado > 0) {
            $residenteId = $encontrado;
        }
        // Si no hay residente de ese tipo, el vehículo queda ligado solo al apto
        // (no se corta el registro: lo importante es dejar el vehículo cargado).
    } catch (Throwable $e) { /* defensivo */ }
}

try {
    if ($tipoReg === 'visitante') {
        // ── VISITANTE ──
        if (!$aptoId) {
            echo json_encode(['ok' => false, 'error' => 'Apto obligatorio para visitante']); exit;
        }
        // ¿Ya existe visitante con esa placa activo?
        $stE = $pdo->prepare("SELECT id FROM visitantes_vehiculos
                                WHERE placa = :p AND conjunto_id = :c AND archivado_en IS NULL LIMIT 1");
        $stE->execute([':p' => $placa, ':c' => $conjuntoId]);
        if ($stE->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'La placa ya está registrada como visitante activo']); exit;
        }

        // Detectar columnas disponibles para no depender de un esquema exacto
        $cols = ['conjunto_id', 'apartamento_id', 'placa', 'tipo'];
        $vals = [':c' => $conjuntoId, ':a' => $aptoId, ':p' => $placa, ':t' => $tipo];
        $ph   = [':c', ':a', ':p', ':t'];

        $ins = $pdo->prepare("INSERT INTO visitantes_vehiculos (" . implode(', ', $cols) . ")
                              VALUES (" . implode(', ', $ph) . ")");
        $ins->execute($vals);
        $newId = (int)$pdo->lastInsertId();

        // audit_log (si helper disponible)
        if (function_exists('audit_log')) {
            audit_log('create_visitante_vehiculo', 'visitantes_vehiculos', $newId,
                      'Registró visitante desde revista placa=' . $placa . ' apto=' . $aptoNum,
                      null, ['placa'=>$placa,'tipo'=>$tipo,'apartamento_id'=>$aptoId]);
        }

        echo json_encode([
            'ok' => true,
            'tipo_registro' => 'visitante',
            'visitante_id'  => $newId,
            'placa' => $placa,
        ]);

    } else {
        // ── RESIDENTE ──
        // v7.69: distinguir si la placa existe ACTIVA o ARCHIVADA.
        //   - activa    → error (ya está registrada)
        //   - archivada → se RESTAURA y se actualiza al apto indicado
        $stE = $pdo->prepare("SELECT id, apartamento_id, archivado_en
                                FROM vehiculos
                               WHERE placa = :p AND conjunto_id = :c LIMIT 1");
        $stE->execute([':p' => $placa, ':c' => $conjuntoId]);
        $yaExiste = $stE->fetch();

        if ($yaExiste && empty($yaExiste['archivado_en'])) {
            echo json_encode(['ok' => false, 'error' => 'La placa ya está registrada']); exit;
        }

        if ($yaExiste && !empty($yaExiste['archivado_en'])) {
            // Restaurar el vehículo archivado y reasignarlo al apto/residente indicado
            $upd = $pdo->prepare("UPDATE vehiculos
                                     SET archivado_en = NULL,
                                         archivado_motivo = NULL,
                                         apartamento_id = :a,
                                         residente_id = :r,
                                         tipo = :t
                                   WHERE id = :id AND conjunto_id = :c");
            $upd->execute([
                ':a'  => $aptoId,
                ':r'  => ($residenteId > 0 ? $residenteId : null),
                ':t'  => $tipo,
                ':id' => (int)$yaExiste['id'],
                ':c'  => $conjuntoId,
            ]);
            $newId = (int)$yaExiste['id'];
            if (function_exists('audit_log')) {
                audit_log('restore_vehiculo', 'vehiculos', $newId,
                          'Restauró vehículo archivado desde revista placa=' . $placa . ' apto=' . $aptoNum,
                          null, ['placa'=>$placa,'apartamento_id'=>$aptoId]);
            }
            echo json_encode([
                'ok'            => true,
                'restaurado'    => true,
                'vehiculo_id'   => $newId,
                'placa'         => $placa,
                'tipo_registro' => 'residente',
                'msg'           => '♻️ La placa estaba archivada. Se restauró y quedó en el apto ' . $aptoNum . '.',
            ]);
            exit;
        }

        // INSERT con o sin residente_id (según venga)
        if ($residenteId > 0) {
            $ins = $pdo->prepare("INSERT INTO vehiculos
                                    (conjunto_id, apartamento_id, residente_id, placa, tipo)
                                  VALUES (:c, :a, :r, :p, :t)");
            $ins->execute([
                ':c' => $conjuntoId, ':a' => $aptoId, ':r' => $residenteId,
                ':p' => $placa, ':t' => $tipo,
            ]);
        } else {
            $ins = $pdo->prepare("INSERT INTO vehiculos (conjunto_id, apartamento_id, placa, tipo)
                                  VALUES (:c, :a, :p, :t)");
            $ins->execute([
                ':c' => $conjuntoId, ':a' => $aptoId,
                ':p' => $placa, ':t' => $tipo,
            ]);
        }
        $newId = (int)$pdo->lastInsertId();

        // audit_log
        if (function_exists('audit_log')) {
            audit_log('create_vehiculo', 'vehiculos', $newId,
                      'Registró residente desde revista placa=' . $placa . ' apto=' . $aptoNum
                      . ($residenteId > 0 ? ' res_id=' . $residenteId : ''),
                      null,
                      ['placa'=>$placa,'tipo'=>$tipo,'apartamento_id'=>$aptoId,'residente_id'=>$residenteId ?: null]);
        }

        echo json_encode([
            'ok' => true,
            'tipo_registro' => 'residente',
            'vehiculo_id'   => $newId,
            'placa' => $placa,
        ]);
    }
} catch (Exception $ex) {
    echo json_encode([
        'ok' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al registrar',
    ]);
}
