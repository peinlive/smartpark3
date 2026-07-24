<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/confirmar.php
// v3j2: agrega INSERT en visitantes_vehiculos cuando destino corresponde.
//       Preserva 100% la lógica de residentes y vehículos sin vínculo.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDES_PATH . '/csv_helpers.php';

auth_require_role('super_admin','admin','supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/importaciones');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

if (session_status() === PHP_SESSION_NONE) session_start();
$imp = $_SESSION['import'] ?? null;
if (!$imp || empty($imp['analisis'])) {
    flash_set('error', 'No hay datos de importación.');
    redirect('/importaciones');
}

$tipo = $imp['tipo'];
$filas = $imp['analisis']['filas'];
$modoVinculo = !empty($imp['analisis']['modo_vinculo']);
// v7.8: las decisiones ahora llegan empaquetadas en UN campo JSON (dup_json).
// Si viene (flujo nuevo) lo decodificamos. Si no (compatibilidad con un form
// viejo cacheado), caemos al array dup[] de siempre. La logica de abajo
// ($decisiones[$idx]) NO cambia en absoluto.
$decisiones = [];
if (!empty($_POST['dup_json'])) {
    $tmp = json_decode((string)$_POST['dup_json'], true);
    if (is_array($tmp)) $decisiones = $tmp;
} else {
    $decisiones = (array)($_POST['dup'] ?? []);
}

$contador = ['ok' => 0, 'duplicado' => 0, 'error' => 0, 'actualizados' => 0, 'ok_visitantes' => 0];
$logErrores = [];

try {
    $pdo->beginTransaction();

    if ($tipo === 'residentes') {
        // ════════════ RESIDENTES (sin cambios) ════════════
        $ins = $pdo->prepare("
            INSERT INTO residentes (apartamento_id, nombre, celular, tipo, vive_en_apto, activo)
            VALUES (:a, :n, :ce, :t, 1, 1)
        ");
        foreach ($filas as $f) {
            if ($f['estado'] === 'error') {
                $contador['error']++;
                $logErrores[] = ['linea'=>$f['linea'],'apto'=>$f['apto'],'nombre'=>$f['nombre'],
                                 'motivo'=>implode(' | ', $f['errores'])];
                continue;
            }
            if ($f['estado'] === 'duplicado') { $contador['duplicado']++; continue; }
            $ins->execute([
                ':a'  => (int)$f['apto_id'], ':n'  => $f['nombre'],
                ':ce' => $f['celular'] !== '' ? $f['celular'] : null,
                ':t'  => $f['tipo'],
            ]);
            $contador['ok']++;
        }

    } else {
        // ════════════ VEHÍCULOS ════════════
        // Prepares: tres queries distintas con placeholders únicos cada una
        $insVeh = $pdo->prepare("
            INSERT INTO vehiculos
                (conjunto_id, apartamento_id, residente_id, placa, tipo, observaciones, activo)
            VALUES (:c, :a, :r, :p, :t, :ob, 1)
        ");
        $updVeh = $pdo->prepare("
            UPDATE vehiculos SET
                apartamento_id = :a, observaciones = :ob, tipo = :t
            WHERE id = :id AND conjunto_id = :c
        ");
        $insVis = $pdo->prepare("
            INSERT INTO visitantes_vehiculos
                (conjunto_id, apartamento_id, placa, tipo, nombre_visitante, observaciones, visitas_count, recurrente)
            VALUES (:c, :a, :p, :t, :n, :ob, 1, 0)
        ");
        $updVis = $pdo->prepare("
            UPDATE visitantes_vehiculos SET
                apartamento_id = :a, tipo = :t, nombre_visitante = :n, observaciones = :ob
            WHERE id = :id AND conjunto_id = :c
        ");

        foreach ($filas as $idx => $f) {
            if ($f['estado'] === 'error') {
                $contador['error']++;
                $logErrores[] = ['linea'=>$f['linea'],'apto'=>$f['apto'],'placa'=>$f['placa'],
                                 'motivo'=>implode(' | ', $f['errores'])];
                continue;
            }

            $destino = $f['destino'] ?? 'vehiculos';

            // ── Caso 1: VISITANTE ──
            if ($destino === 'visitantes_vehiculos') {
                if ($f['estado'] === 'nuevo') {
                    try {
                        $insVis->execute([
                            ':c'  => $conjuntoId,
                            ':a'  => (int)$f['apto_id'],
                            ':p'  => $f['placa'],
                            ':t'  => $f['tipo'] ?: 'carro',
                            ':n'  => $f['usuario'] !== '' ? $f['usuario'] : null,
                            ':ob' => $f['observacion'] !== '' ? $f['observacion'] : null,
                        ]);
                        $contador['ok']++;
                        $contador['ok_visitantes']++;
                    } catch (PDOException $e) {
                        $contador['error']++;
                        $logErrores[] = [
                            'linea'  => $f['linea'],
                            'apto'   => $f['apto'],
                            'nombre' => $f['placa'],
                            'motivo' => substr($e->getMessage(), 0, 140),
                        ];
                    }
                } elseif ($f['estado'] === 'duplicado') {
                    $decision = $decisiones[$idx] ?? 'skip';
                    if ($decision === 'skip') {
                        $contador['duplicado']++;
                        continue;
                    }
                    $updVis->execute([
                        ':a'  => (int)$f['apto_id'],
                        ':t'  => $f['tipo'] ?: 'carro',
                        ':n'  => $f['usuario'] !== '' ? $f['usuario'] : null,
                        ':ob' => $f['observacion'] !== '' ? $f['observacion'] : null,
                        ':id' => (int)$f['duplicada']['id'],
                        ':c'  => $conjuntoId,
                    ]);
                    $contador['actualizados']++;
                }
                continue;
            }

            // ── Caso 2: VEHÍCULO DE RESIDENTE ──
            // Resolver residente_id
            $residenteId = null;
            if (!empty($f['usuario_match'])) {
                $residenteId = (int)$f['usuario_match']['id'];
            } elseif (!$modoVinculo && !empty($f['usuario'])) {
                // Compatibilidad: modo viejo busca por nombre como antes
                $residenteId = _buscar_residente_por_nombre($pdo, (int)$f['apto_id'], $f['usuario']);
            }

            // Observación: en modo viejo añadimos nota si usuario no se vincula
            $obsFinal = trim((string)($f['observacion'] ?? ''));
            if (!$modoVinculo && $residenteId === null && !empty($f['usuario'])) {
                $obsFinal = trim($obsFinal . ' [usuario sin vincular: ' . $f['usuario'] . ']');
            }

            if ($f['estado'] === 'nuevo') {
                // v7.6 BLINDAJE: try/catch POR FILA.
                // Antes, si UNA placa chocaba con el indice unico, la excepcion
                // subia y hacia ROLLBACK de TODO el lote: no se guardaba nada,
                // ni siquiera las filas correctas. Ahora la fila mala se registra
                // como error y el resto sigue.
                try {
                    $insVeh->execute([
                        ':c'  => $conjuntoId,
                        ':a'  => (int)$f['apto_id'],
                        ':r'  => $residenteId,
                        ':p'  => $f['placa'],
                        ':t'  => $f['tipo'] ?: 'carro',
                        ':ob' => $obsFinal !== '' ? $obsFinal : null,
                    ]);
                    if ($residenteId !== null) {
                        $vehId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE vehiculos SET residente_id = :rr WHERE id = :iid")
                            ->execute([':rr' => $residenteId, ':iid' => $vehId]);
                    }
                    $contador['ok']++;
                } catch (PDOException $e) {
                    // 23000 = violacion de constraint (placa duplicada, FK, etc)
                    $contador['error']++;
                    $motivo = (strpos($e->getMessage(), '1062') !== false)
                        ? 'La placa ya existe en la BD (puede estar ARCHIVADA). '
                          . 'Restaurala desde Vehiculos en vez de importarla.'
                        : substr($e->getMessage(), 0, 140);
                    $logErrores[] = [
                        'linea'  => $f['linea'],
                        'apto'   => $f['apto'],
                        'nombre' => $f['placa'],
                        'motivo' => $motivo,
                    ];
                }

            } elseif ($f['estado'] === 'duplicado') {
                $decision = $decisiones[$idx] ?? 'skip';
                if ($decision === 'skip') {
                    $contador['duplicado']++;
                    continue;
                }
                try {
                    $updVeh->execute([
                        ':a'  => (int)$f['apto_id'],
                        ':t'  => $f['tipo'] ?: 'carro',
                        ':ob' => $obsFinal !== '' ? $obsFinal : null,
                        ':id' => (int)$f['duplicada']['id'],
                        ':c'  => $conjuntoId,
                    ]);
                    $contador['actualizados']++;
                } catch (PDOException $e) {
                    $contador['error']++;
                    $logErrores[] = [
                        'linea'  => $f['linea'],
                        'apto'   => $f['apto'],
                        'nombre' => $f['placa'],
                        'motivo' => substr($e->getMessage(), 0, 140),
                    ];
                }
            }
        }
    }

    // Log
    $totalFilas = count($filas);
    $pdo->prepare("
        INSERT INTO importaciones_log
            (conjunto_id, usuario_id, tipo, archivo_nombre, total_filas, filas_ok, filas_error, detalle_json)
        VALUES (:c, :u, :tp, :f, :tt, :ok, :er, :dj)
    ")->execute([
        ':c'  => $conjuntoId, ':u' => $u['id'], ':tp' => $tipo,
        ':f'  => $imp['archivo_orig'] ?? null,
        ':tt' => $totalFilas,
        ':ok' => $contador['ok'] + $contador['actualizados'],
        ':er' => $contador['error'],
        ':dj' => json_encode([
            'archivo' => $imp['archivo_orig'] ?? '',
            'totales' => $contador,
            'errores' => $logErrores,
            'modo_vinculo' => $modoVinculo,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $logId = (int)$pdo->lastInsertId();

    $pdo->commit();

    $tmp = UPLOADS_PATH . '/imports/' . $imp['token'] . '.' . $imp['ext'];
    if (is_file($tmp)) @unlink($tmp);

    $_SESSION['import_result'] = [
        'log_id' => $logId, 'tipo' => $tipo,
        'totales' => $contador, 'errores' => $logErrores,
        'total' => $totalFilas,
    ];
    unset($_SESSION['import']);
    redirect('/importaciones/resultado');

} catch (Exception $ex) {
    $pdo->rollBack();
    flash_set('error', APP_DEBUG ? $ex->getMessage() : 'Error al importar. No se guardó nada.');
    redirect('/importaciones/preview');
}

function _buscar_residente_por_nombre(PDO $pdo, int $aptoId, string $usuarioRaw): ?int
{
    $usuarioRaw = trim($usuarioRaw);
    if ($usuarioRaw === '') return null;
    $busc = normalizar_nombre($usuarioRaw);
    if ($busc === '') return null;

    $st = $pdo->prepare("SELECT id, nombre FROM residentes
                          WHERE apartamento_id = :a AND archivado_en IS NULL");
    $st->execute([':a' => $aptoId]);
    $candidatos = $st->fetchAll();

    foreach ($candidatos as $c) {
        if (normalizar_nombre($c['nombre']) === $busc) return (int)$c['id'];
    }
    foreach ($candidatos as $c) {
        $cn = normalizar_nombre($c['nombre']);
        if (str_contains($cn, $busc) || str_contains($busc, $cn)) return (int)$c['id'];
    }
    return null;
}
