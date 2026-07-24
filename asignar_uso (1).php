<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/asignar_uso.php
// v7.23: Asignar apto USUARIO a un CUARTO ÚTIL. Espejo de parqueadero/asignar_uso.php.
//
//   Modos:
//     GET  ?buscar=X&celda=YY → autocomplete: devuelve JSON con aptos que coinciden
//     GET  ?ver=1&celda=YY    → devuelve JSON con datos actuales de la celda + asignación
//     POST                    → guarda / actualiza / quita la asignación
//
//   Schema (verificado):
//     asignaciones_cuartos: id, cuarto_id, apto_dueno_id, apto_usuario_id,
//       tipo enum('uso_propio','prestamo_gratis','alquiler') NOT NULL DEFAULT 'uso_propio',
//       valor_mensual, fecha_inicio (NOT NULL), fecha_fin,
//       activa DEFAULT 1, observacion, creado_por, creado_en, archivado_en

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ═════════════════════════════════════════════════════════════
// MODO 1: GET ?buscar=X&celda=Y → autocomplete de aptos
// ═════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['buscar'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)$_GET['buscar']);
    if ($q === '' || strlen($q) < 1) { echo json_encode(['aptos'=>[]]); exit; }
    try {
        $stB = $pdo->prepare("SELECT a.id, a.numero_visible AS apto, a.piso,
                                     t.numero AS torre,
                                     a.propietario_nombre AS dueno
                                FROM apartamentos a
                                JOIN torres t ON t.id = a.torre_id
                               WHERE a.conjunto_id = :c
                                 AND a.numero_visible LIKE :q
                            ORDER BY a.numero_visible LIMIT 15");
        $stB->execute([':c' => $conjuntoId, ':q' => $q . '%']);
        echo json_encode(['aptos' => $stB->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['aptos'=>[], 'error'=>(defined('APP_DEBUG')&&APP_DEBUG)?$e->getMessage():'error']);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════
// MODO 2: GET ?ver=1&celda=Y → datos actuales para pre-cargar modal
// ═════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ver'])) {
    header('Content-Type: application/json; charset=utf-8');
    $celdaId = (int)($_GET['cuarto'] ?? 0);
    if ($celdaId < 1) { echo json_encode(['ok'=>false,'error'=>'Cuarto inválido']); exit; }
    try {
        $stC = $pdo->prepare("SELECT c.id, c.codigo AS codigo, 'cuarto' AS tipo,
                                     c.apto_dueno_id,
                                     ad.numero_visible AS apto_dueno,
                                     np.codigo AS nivel_codigo, np.nombre AS nivel_nombre
                                FROM cuartos_utiles c
                           LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
                           LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
                               WHERE c.id = :id AND c.conjunto_id = :c LIMIT 1");
        $stC->execute([':id' => $celdaId, ':c' => $conjuntoId]);
        $cel = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$cel) { echo json_encode(['ok'=>false,'error'=>'Cuarto no encontrado']); exit; }

        // Asignación activa (si existe)
        $stA = $pdo->prepare("SELECT ac.id, ac.tipo, ac.observacion,
                                     au.numero_visible AS apto_usuario
                                FROM asignaciones_cuartos ac
                           LEFT JOIN apartamentos au ON au.id = ac.apto_usuario_id
                               WHERE ac.cuarto_id = :cid AND ac.activa = 1 AND ac.archivado_en IS NULL
                            ORDER BY ac.id DESC LIMIT 1");
        $stA->execute([':cid' => $celdaId]);
        $asig = $stA->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'    => true,
            'celda' => $cel,
            'asignacion_actual' => $asig ?: null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $ex) {
        echo json_encode([
            'ok' => false,
            'error' => (defined('APP_DEBUG')&&APP_DEBUG) ? $ex->getMessage() : 'Error',
        ]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════
// MODO 3: POST → guardar / actualizar / quitar
// ═════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_require();

    $celdaId  = (int)($_POST['cuarto_id'] ?? 0);
    $accion   = (string)($_POST['accion'] ?? 'guardar'); // guardar | quitar
    $aptoNum  = trim((string)($_POST['apto_usuario'] ?? ''));
    $obs      = trim((string)($_POST['observacion'] ?? ''));

    if ($celdaId < 1) { echo json_encode(['ok'=>false,'error'=>'Cuarto inválido']); exit; }

    try {
        // Verificar que el cuarto pertenece al conjunto
        $stC = $pdo->prepare("SELECT c.id, c.codigo AS nombre_visible, c.apto_dueno_id
                                FROM cuartos_utiles c
                               WHERE c.id = :id AND c.conjunto_id = :c LIMIT 1");
        $stC->execute([':id' => $celdaId, ':c' => $conjuntoId]);
        $celda = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$celda) { echo json_encode(['ok'=>false,'error'=>'Cuarto no encontrado']); exit; }

        $pdo->beginTransaction();

        // Archivar cualquier asignación activa previa (siempre, en ambos modos)
        $pdo->prepare("UPDATE asignaciones_cuartos
                          SET activa = 0, archivado_en = NOW()
                        WHERE cuarto_id = :cid AND activa = 1 AND archivado_en IS NULL")
            ->execute([':cid' => $celdaId]);

        if ($accion === 'quitar') {
            // Solo archivar y listo
            if (function_exists('audit_log')) {
                audit_log('quitar_asignacion_uso', 'cuartos', $celdaId,
                          "Se quitó la asignación de uso del cuarto {$celda['nombre_visible']} (queda usada por el dueño)",
                          null, null);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true, 'msg'=>'Asignación quitada. El cuarto queda usado por el dueño.']);
            exit;
        }

        // GUARDAR: validar apto usuario
        if ($aptoNum === '') { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Debes indicar el apto usuario.']); exit; }

        $stAU = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                                WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $stAU->execute([':c' => $conjuntoId, ':n' => $aptoNum]);
        $aptoUsr = $stAU->fetch(PDO::FETCH_ASSOC);
        if (!$aptoUsr) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>"El apto '{$aptoNum}' no existe."]); exit; }

        // Determinar tipo automático:
        //   uso_propio si es el mismo dueño de la celda, prestamo_gratis en caso contrario
        $aptoDuenoId = (int)$celda['apto_dueno_id'];
        $aptoUsrId   = (int)$aptoUsr['id'];
        $tipoAuto = ($aptoUsrId === $aptoDuenoId) ? 'uso_propio' : 'prestamo_gratis';

        // Si el cuarto no tiene apto_dueno_id definido, usar el mismo apto usuario como dueño
        //   (evita NOT NULL en apto_dueno_id)
        $aptoDuenoInsert = $aptoDuenoId > 0 ? $aptoDuenoId : $aptoUsrId;

        // INSERT nueva asignación
        $ins = $pdo->prepare("INSERT INTO asignaciones_cuartos
                (cuarto_id, apto_dueno_id, apto_usuario_id, tipo,
                 fecha_inicio, activa, observacion, creado_por, creado_en)
              VALUES
                (:cid, :ad, :au, :t, CURDATE(), 1, :o, :cp, NOW())");
        $ins->execute([
            ':cid' => $celdaId,
            ':ad'  => $aptoDuenoInsert,
            ':au'  => $aptoUsrId,
            ':t'   => $tipoAuto,
            ':o'   => $obs !== '' ? $obs : null,
            ':cp'  => (int)$u['id'],
        ]);
        $nuevoId = (int)$pdo->lastInsertId();

        if (function_exists('audit_log')) {
            audit_log('asignar_uso_cuarto', 'cuartos', $celdaId,
                      "Cuarto {$celda['nombre_visible']} asignado a apto {$aptoUsr['numero_visible']} ({$tipoAuto})",
                      null,
                      ['asignacion_id' => $nuevoId, 'apto_usuario' => $aptoUsr['numero_visible'], 'tipo' => $tipoAuto]);
        }

        $pdo->commit();
        // v3BJ: etiqueta amigable en el mensaje
        $etiqueta = $tipoAuto === 'uso_propio' ? 'Uso propio'
                  : ($tipoAuto === 'prestamo_gratis' ? 'Autorizado' : ucfirst($tipoAuto));
        echo json_encode([
            'ok'  => true,
            'msg' => "✅ Cuarto asignado a apto {$aptoUsr['numero_visible']} ({$etiqueta}).",
            'asignacion' => [
                'id'           => $nuevoId,
                'apto_usuario' => $aptoUsr['numero_visible'],
                'tipo'         => $tipoAuto,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'ok' => false,
            'error' => (defined('APP_DEBUG')&&APP_DEBUG) ? $ex->getMessage() : 'Error al guardar',
        ]);
        exit;
    }
}

// Otros métodos: 405
http_response_code(405);
echo 'Method not allowed';
