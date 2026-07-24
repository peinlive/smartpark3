<?php
// /home/myzonaco/smartpark.myzona360.com/api/sync_lote.php
// v6 (OFFLINE): Recibe TODO lo que la ronda creo sin señal, en un solo POST.
//
// EL PROBLEMA QUE RESUELVE:
//   Sin señal, el celular no puede pedirle un ID al servidor (AUTO_INCREMENT).
//   Entonces genera IDs LOCALES ("loc-a3f9") y los usa para relacionar cosas:
//     revista loc-a3f9  ->  celda C98102, celda C98103, ...
//   Este endpoint crea los registros reales EN ORDEN y devuelve el mapeo
//   { "loc-a3f9": 42 } para que el celular sepa que su revista es la 42.
//
// ORDEN OBLIGATORIO (hay dependencias):
//   1. revistas nuevas      -> generan IDs reales
//   2. vehiculos/visitantes -> generan IDs reales
//   3. pasos de revista     -> ya pueden referenciar la revista real
//   4. novedades            -> ya pueden referenciar el vehiculo real
//   5. cierres de revista
//
// IDEMPOTENTE: cada item lleva un uid. Si ya se proceso, se ignora.
// Reenviar el mismo lote dos veces NO duplica nada.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

ob_start();
register_shutdown_function(function () {
    $o = ob_get_clean();
    if ($o === false || $o === '') return;
    json_decode($o);
    if (json_last_error() === JSON_ERROR_NONE) { echo $o; return; }
    if (!headers_sent()) { header_remove('Content-Type'); header('Content-Type: application/json'); }
    echo json_encode(['ok'=>false, 'error'=>'Error servidor: '.trim(strip_tags(substr($o,0,200)))]);
});

header('Content-Type: application/json; charset=utf-8');
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Método no permitido']); exit;
}
if (!csrf_check()) {
    echo json_encode(['ok'=>false,'error'=>'CSRF inválido']); exit;
}

$pdo        = db();
$u          = auth_user();
$uid        = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$raw  = file_get_contents('php://input');
$lote = json_decode($raw, true);
if (!is_array($lote)) {
    // fallback: viene por POST normal (con fotos en $_FILES)
    $lote = json_decode($_POST['lote'] ?? '[]', true);
}
if (!is_array($lote)) $lote = [];

$mapa    = [];   // id_local => id_real
$hechos  = [];   // uids procesados
$errores = [];

$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../uploads';

/* ─── helper: guardar una foto dataURL y devolver el path relativo ─── */
function guardar_foto($dataUrl, $dir, $nombre) {
    if (!$dataUrl || strpos($dataUrl, 'data:image/') !== 0) return null;
    $p = explode(',', $dataUrl, 2);
    if (count($p) !== 2) return null;
    $bin = base64_decode($p[1]);
    if ($bin === false) return null;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $full = $dir . '/' . $nombre;
    return (file_put_contents($full, $bin) !== false) ? $nombre : null;
}

/* ─── ya procesado? (idempotencia) ─── */
/* v7.73 FIX CRÍTICO — "ya hecho" no alcanzaba.
   Antes bastaba con que el uid estuviera en sync_procesados para que el
   celular BORRARA el registro de su cola. Si en ese intento el dato no
   llegó a guardarse (p.ej. la revista no se resolvió y el paso se
   descartó), quedaba marcado como hecho SIN EXISTIR: el celular lo
   borraba, decía "no hay pendientes" y el trabajo se perdía.
   Ahora, además de la marca, se comprueba que el registro EXISTA. */
function ya_hecho(PDO $pdo, $uidItem, $tipo = '', $idRealEsperado = 0) {
    try {
        $s = $pdo->prepare("SELECT id_real, tipo FROM sync_procesados WHERE uid = :u LIMIT 1");
        $s->execute([':u' => $uidItem]);
        $row = $s->fetch();
        if (!$row) return false;

        $idReal = (int)($row['id_real'] ?? 0);
        $t      = (string)($row['tipo'] ?? '');

        // Verificar CONTRA LA TABLA REAL según el tipo
        if ($t === 'revista_paso' && $idReal > 0) {
            $v = $pdo->prepare("SELECT 1 FROM revistas_detalle WHERE revista_id = :r LIMIT 1");
            $v->execute([':r' => $idReal]);
            if (!$v->fetchColumn()) return false;   // la revista quedó sin celdas: NO está hecho
        } elseif (($t === 'revista' || $t === 'revista_local') && $idReal > 0) {
            $v = $pdo->prepare("SELECT 1 FROM revistas WHERE id = :r LIMIT 1");
            $v->execute([':r' => $idReal]);
            if (!$v->fetchColumn()) return false;   // la revista no existe: NO está hecho
        } elseif ($t === 'vehiculo_nuevo' && $idReal > 0) {
            $v = $pdo->prepare("SELECT 1 FROM vehiculos WHERE id = :r LIMIT 1");
            $v->execute([':r' => $idReal]);
            if (!$v->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable $e) { return false; }
}
function marcar_hecho(PDO $pdo, $uidItem, $tipo, $idReal) {
    try {
        $s = $pdo->prepare("INSERT IGNORE INTO sync_procesados (uid, tipo, id_real, creado_en)
                            VALUES (:u, :t, :i, NOW())");
        $s->execute([':u'=>$uidItem, ':t'=>$tipo, ':i'=>$idReal]);
    } catch (Throwable $e) { /* tabla ausente: seguimos igual */ }
}

// v7.52: guardar el mapeo id_local(revista) -> id_real, para que los PASOS
// (que referencian la revista por su id_local) puedan resolverla aunque el
// revista_nueva ya se haya subido y borrado de la cola en otra sincronización.
function marcar_revista_local(PDO $pdo, $idLocal, $idReal) {
    if (!$idLocal || !$idReal) return;
    try {
        $s = $pdo->prepare("INSERT IGNORE INTO sync_procesados (uid, tipo, id_real, creado_en)
                            VALUES (:u, 'revista_local', :i, NOW())");
        $s->execute([':u'=>(string)$idLocal, ':i'=>(int)$idReal]);
    } catch (Throwable $e) { /* tabla ausente */ }
}

// v7.34: resolver el id REAL de una revista a partir de su id LOCAL, aunque
// el "revista_nueva" haya venido en OTRA tanda. Busca en el mapa de la tanda
// y, si no está, en sync_procesados (donde quedó al crearla). Esto arregla
// el bug "revista no resuelta" cuando la revista y sus pasos se parten en
// tandas distintas (>12 items).
function resolver_revista(PDO $pdo, $rv, array $mapa, $conjuntoId = 0, $celdaId = 0) {
    if (is_string($rv) && isset($mapa[$rv])) return (int)$mapa[$rv];
    if (is_numeric($rv) && (int)$rv > 0)      return (int)$rv;
    // buscar en sync_procesados por el id_local de la revista (lo que traen los pasos)
    if (is_string($rv) && $rv !== '') {
        try {
            $s = $pdo->prepare("SELECT id_real FROM sync_procesados
                                 WHERE uid = :u AND tipo IN ('revista_local','revista')
                                   AND id_real IS NOT NULL
                              ORDER BY creado_en DESC LIMIT 1");
            $s->execute([':u' => $rv]);
            $id = (int)$s->fetchColumn();
            if ($id > 0) return $id;
        } catch (Throwable $e) { /* tabla ausente */ }
    }
    // v7.52: último recurso — por la celda, buscar la revista más reciente de
    // su mismo nivel (para pasos rezagados cuya revista ya se subió/cerró).
    if ($conjuntoId > 0 && $celdaId > 0) {
        try {
            $s = $pdo->prepare("SELECT r.id
                                  FROM celdas c
                                  JOIN niveles_parqueadero np ON np.id = c.nivel_id
                                  JOIN revistas r ON r.nivel = np.codigo AND r.conjunto_id = :c
                                 WHERE c.id = :cd
                              ORDER BY r.id DESC LIMIT 1");
            $s->execute([':c' => $conjuntoId, ':cd' => (int)$celdaId]);
            $id = (int)$s->fetchColumn();
            if ($id > 0) return $id;
        } catch (Throwable $e) { /* ignorar */ }
    }
    return 0;
}

/* ══════════ 1) REVISTAS NUEVAS ══════════ */
foreach ($lote as $it) {
    if (($it['tipo'] ?? '') !== 'revista_nueva') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '') continue;

    if (ya_hecho($pdo, $iu)) {
        try {
            $s = $pdo->prepare("SELECT id_real FROM sync_procesados WHERE uid=:u LIMIT 1");
            $s->execute([':u'=>$iu]);
            $ridr = (int)$s->fetchColumn();
            $mapa[$it['id_local']] = $ridr;
            marcar_revista_local($pdo, $it['id_local'] ?? '', $ridr);
        } catch (Throwable $e) {}
        $hechos[] = $iu;
        continue;
    }

    try {
        // Columnas REALES de `revistas`: nivel (codigo), total_celdas, iniciado_en.
        // NO existe 'nombre' ni 'nivel_id' ni 'creado_en'.
        $nivel  = clean_string($it['nivel'] ?? '', 30);
        if ($nivel === '') { $errores[]=['uid'=>$iu,'error'=>'nivel vacío']; continue; }
        $creado = $it['creado_en'] ?? date('Y-m-d H:i:s');

        // total de celdas activas del nivel. celdas.nivel_id -> niveles_parqueadero.id
        // pero revistas.nivel guarda el CODIGO. Hay que resolver el id primero.
        $tot = (int)($it['total_celdas'] ?? 0);
        try {
            $sn = $pdo->prepare("SELECT id FROM niveles_parqueadero
                                  WHERE conjunto_id=:c AND codigo=:cod LIMIT 1");
            $sn->execute([':c'=>$conjuntoId, ':cod'=>$nivel]);
            $nid = (int)$sn->fetchColumn();
            if ($nid) {
                $sc = $pdo->prepare("SELECT COUNT(*) FROM celdas
                                      WHERE nivel_id=:n AND conjunto_id=:c2 AND activa=1");
                $sc->execute([':n'=>$nid, ':c2'=>$conjuntoId]);
                $tot = (int)$sc->fetchColumn();
            }
        } catch (Throwable $e) { /* usamos el total que mando el celular */ }

        // ¿ya hay una en curso en ese nivel? -> reutilizarla, no crear otra
        $se = $pdo->prepare("SELECT id FROM revistas
                              WHERE conjunto_id=:c AND nivel=:nv AND estado='en_curso'
                           ORDER BY id DESC LIMIT 1");
        $se->execute([':c'=>$conjuntoId, ':nv'=>$nivel]);
        $ex = (int)$se->fetchColumn();
        if ($ex > 0) {
            $mapa[$it['id_local']] = $ex;
            marcar_hecho($pdo, $iu, 'revista', $ex);
            marcar_revista_local($pdo, $it['id_local'] ?? '', $ex);
            $hechos[] = $iu;
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO revistas
                (conjunto_id, nivel, usuario_id, total_celdas, estado, iniciado_en)
             VALUES (:c, :nv, :u, :tc, 'en_curso', :ini)");
        $ins->execute([
            ':c'=>$conjuntoId, ':nv'=>$nivel, ':u'=>$uid,
            ':tc'=>$tot, ':ini'=>$creado
        ]);
        $idReal = (int)$pdo->lastInsertId();
        $mapa[$it['id_local']] = $idReal;
        marcar_hecho($pdo, $iu, 'revista', $idReal);
        marcar_revista_local($pdo, $it['id_local'] ?? '', $idReal);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>'revista: '.$e->getMessage()];
    }
}

/* ══════════ 2) VEHICULOS / VISITANTES NUEVOS ══════════ */
foreach ($lote as $it) {
    $t = $it['tipo'] ?? '';
    if ($t !== 'vehiculo_nuevo' && $t !== 'visitante_nuevo') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '' || ya_hecho($pdo, $iu)) { if ($iu) $hechos[]=$iu; continue; }

    try {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/i','', (string)($it['placa'] ?? '')));
        if ($placa === '') { $errores[]=['uid'=>$iu,'error'=>'placa vacía']; continue; }
        $aptoId = (int)($it['apartamento_id'] ?? 0);
        $tipoV  = in_array(($it['tipo_vehiculo'] ?? ''), ['carro','moto'], true)
                    ? $it['tipo_vehiculo'] : 'carro';

        if ($t === 'vehiculo_nuevo') {
            // ¿ya existe esa placa? -> no duplicar
            $s = $pdo->prepare("SELECT id FROM vehiculos
                                 WHERE placa=:p AND conjunto_id=:c LIMIT 1");
            $s->execute([':p'=>$placa, ':c'=>$conjuntoId]);
            $ex = (int)$s->fetchColumn();
            if ($ex) {
                $mapa[$it['id_local']] = $ex;
                marcar_hecho($pdo, $iu, 'vehiculo', $ex);
                $hechos[] = $iu;
                continue;
            }
            // v7.76: si viene el rol (propietario|inquilino), buscar ese
            // residente en el apto para dejar el vehículo bien asignado.
            $rol = (string)($it['rol'] ?? '');
            $resId = null;
            if ($aptoId > 0 && ($rol === 'propietario' || $rol === 'inquilino')) {
                try {
                    $sr = $pdo->prepare("SELECT r.id FROM residentes r
                                           JOIN apartamentos a ON a.id = r.apartamento_id
                                          WHERE r.apartamento_id = :a AND a.conjunto_id = :c
                                            AND r.tipo = :t AND r.archivado_en IS NULL
                                            AND r.activo = 1
                                       ORDER BY r.id LIMIT 1");
                    $sr->execute([':a'=>$aptoId, ':c'=>$conjuntoId, ':t'=>$rol]);
                    $rid = (int)$sr->fetchColumn();
                    if ($rid > 0) $resId = $rid;
                } catch (Throwable $e) { /* defensivo */ }
            }

            $ins = $pdo->prepare("INSERT INTO vehiculos
                    (conjunto_id, apartamento_id, residente_id, placa, tipo, marca, color, creado_en)
                 VALUES (:c, :a, :r, :p, :t, :m, :co, NOW())");
            $ins->execute([
                ':c'=>$conjuntoId, ':a'=>($aptoId ?: null), ':r'=>$resId,
                ':p'=>$placa, ':t'=>$tipoV,
                ':m'=>clean_string($it['marca'] ?? '', 40),
                ':co'=>clean_string($it['color'] ?? '', 30),
            ]);
            $idReal = (int)$pdo->lastInsertId();
        } else {
            $ins = $pdo->prepare("INSERT INTO visitantes_vehiculos
                    (conjunto_id, apartamento_id, placa, tipo, nombre_visitante,
                     parentesco, visitas_count, primera_visita, ultima_visita)
                 VALUES (:c, :a, :p, :t, :nv, :pa, 1, NOW(), NOW())");
            $ins->execute([
                ':c'=>$conjuntoId, ':a'=>($aptoId ?: null), ':p'=>$placa, ':t'=>$tipoV,
                ':nv'=>clean_string($it['nombre_visitante'] ?? '', 80),
                ':pa'=>clean_string($it['parentesco'] ?? '', 40),
            ]);
            $idReal = (int)$pdo->lastInsertId();
        }
        $mapa[$it['id_local']] = $idReal;
        marcar_hecho($pdo, $iu, $t, $idReal);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>$t.': '.$e->getMessage()];
    }
}

/* ══════════ 3) PASOS DE REVISTA (con foto) ══════════ */
foreach ($lote as $it) {
    if (($it['tipo'] ?? '') !== 'revista_paso') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '' || ya_hecho($pdo, $iu)) { if ($iu) $hechos[]=$iu; continue; }

    try {
        // resolver la revista: puede ser un id local ("loc-...") o uno real
        $cd     = (int)($it['celda_id'] ?? 0);
        $rv = resolver_revista($pdo, $it['revista_id'] ?? 0, $mapa, $conjuntoId, $cd);
        if (!$rv) { $errores[]=['uid'=>$iu,'error'=>'revista no resuelta']; continue; }
        $estado = $it['estado'] ?? '';
        if (!in_array($estado, ['ocupada','vacia','pendiente'], true)) {
            $errores[]=['uid'=>$iu,'error'=>'estado inválido']; continue;
        }
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/i','', (string)($it['placa'] ?? '')));

        // vehiculo_id: puede venir como id local de un vehiculo recien creado
        $vh = $it['vehiculo_id'] ?? null;
        if (is_string($vh) && isset($mapa[$vh])) $vh = $mapa[$vh];
        $vh = $vh ? (int)$vh : null;
        if (!$vh && $placa !== '') {
            $s = $pdo->prepare("SELECT id FROM vehiculos
                                 WHERE placa=:p AND conjunto_id=:c AND archivado_en IS NULL LIMIT 1");
            $s->execute([':p'=>$placa, ':c'=>$conjuntoId]);
            $vh = ((int)$s->fetchColumn()) ?: null;
        }

        // foto (ya viene comprimida desde el celular)
        $fotoPath = null;
        if (!empty($it['foto'])) {
            $dir = $baseUploads . '/revistas/' . $rv;
            $nom = 'celda_' . $cd . '_' . time() . '_' . substr(md5($iu), 0, 6) . '.jpg';
            $g = guardar_foto($it['foto'], $dir, $nom);
            if ($g) $fotoPath = $rv . '/' . $g;
        }

        // UPSERT (igual que api_guardar_paso: idempotente)
        $s = $pdo->prepare("SELECT id, foto_path FROM revistas_detalle
                             WHERE revista_id=:r AND celda_id=:cd LIMIT 1");
        $s->execute([':r'=>$rv, ':cd'=>$cd]);
        $ex = $s->fetch();

        if ($ex) {
            $sql = "UPDATE revistas_detalle SET estado=:st, placa_detectada=:p, vehiculo_id=:v"
                 . ($fotoPath ? ", foto_path=:fp" : "")
                 . " WHERE id=:id";
            $par = [':st'=>$estado, ':p'=>($placa ?: null), ':v'=>$vh, ':id'=>(int)$ex['id']];
            if ($fotoPath) $par[':fp'] = $fotoPath;
            $pdo->prepare($sql)->execute($par);
        } else {
            $pdo->prepare("INSERT INTO revistas_detalle
                    (revista_id, celda_id, estado, placa_detectada, vehiculo_id, foto_path)
                 VALUES (:r, :cd, :st, :p, :v, :fp)")
                ->execute([':r'=>$rv, ':cd'=>$cd, ':st'=>$estado,
                           ':p'=>($placa ?: null), ':v'=>$vh, ':fp'=>$fotoPath]);
        }
        marcar_hecho($pdo, $iu, 'revista_paso', $rv);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>'paso: '.$e->getMessage()];
    }
}

/* ══════════ 4) NOVEDADES (con foto) ══════════ */
foreach ($lote as $it) {
    if (($it['tipo'] ?? '') !== 'novedad') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '' || ya_hecho($pdo, $iu)) { if ($iu) $hechos[]=$iu; continue; }

    try {
        $vh = $it['vehiculo_id'] ?? null;
        if (is_string($vh) && isset($mapa[$vh])) $vh = $mapa[$vh];
        $vh = $vh ? (int)$vh : null;

        if (!$vh) {
            $placa = strtoupper(preg_replace('/[^A-Z0-9]/i','', (string)($it['placa'] ?? '')));
            if ($placa !== '') {
                $s = $pdo->prepare("SELECT id FROM vehiculos
                                     WHERE placa=:p AND conjunto_id=:c LIMIT 1");
                $s->execute([':p'=>$placa, ':c'=>$conjuntoId]);
                $vh = ((int)$s->fetchColumn()) ?: null;
            }
        }
        if (!$vh) { $errores[]=['uid'=>$iu,'error'=>'vehículo no encontrado']; continue; }

        // Columnas REALES: o.tipo, o.gravedad, o.evidencia_url, o.usuario_registra.
        $ins = $pdo->prepare("INSERT INTO observaciones_vehiculo
                (vehiculo_id, tipo, gravedad, descripcion, evidencia_url, usuario_registra)
             VALUES (:v, :t, :g, :d, :ev, :u)");
        $ins->execute([
            ':v'  => $vh,
            // v6.6: validar contra el enum REAL. Si no, MySQL degrada al
            // primer valor del enum en silencio (el mismo bug que hacia que
            // las revistas quedaran como 'cancelada').
            ':t'  => in_array($it['tipo_obs'] ?? '', ['mal_parqueo','advertencia','reincidencia','queja','otro'], true)
                       ? $it['tipo_obs'] : 'otro',
            ':g'  => in_array($it['gravedad'] ?? '', ['ninguna','leve','media','grave'], true)
                       ? $it['gravedad'] : 'ninguna',
            ':d'  => clean_string($it['descripcion'] ?? '', 500),
            ':ev' => null,
            ':u'  => $uid,
        ]);
        $obsId = (int)$pdo->lastInsertId();

        // evidencias: fotos YA COMPRIMIDAS desde el celular (1280px / q0.75 ~80 KB)
        if (!empty($it['fotos']) && is_array($it['fotos'])) {
            $dir = $baseUploads . '/observaciones/' . $obsId;
            $n = 0;
            foreach ($it['fotos'] as $f) {
                $n++;
                $nom = 'ev_' . $n . '_' . substr(md5($iu . $n), 0, 6) . '.jpg';
                $g = guardar_foto($f, $dir, $nom);
                if (!$g) continue;
                $rel  = 'observaciones/' . $obsId . '/' . $g;
                $full = $dir . '/' . $g;
                $sz   = is_file($full) ? filesize($full) : 0;
                try {
                    $pdo->prepare("INSERT INTO observaciones_evidencias
                            (observacion_id, tipo, archivo_url, mime, tamano_bytes, subido_por)
                         VALUES (:o, 'foto', :url, 'image/jpeg', :sz, :us)")
                        ->execute([':o'=>$obsId, ':url'=>$rel, ':sz'=>$sz, ':us'=>($uid ?: null)]);
                } catch (Throwable $e) {
                    $errores[] = ['uid'=>$iu, 'error'=>'evidencia: '.$e->getMessage()];
                }
            }
        }
        marcar_hecho($pdo, $iu, 'novedad', $obsId);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>'novedad: '.$e->getMessage()];
    }
}

/* ══════════ 5) CIERRE DE REVISTAS ══════════ */
foreach ($lote as $it) {
    if (($it['tipo'] ?? '') !== 'revista_cerrar') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '' || ya_hecho($pdo, $iu)) { if ($iu) $hechos[]=$iu; continue; }

    try {
        $rv = resolver_revista($pdo, $it['revista_id'] ?? 0, $mapa);
        if (!$rv) { $errores[]=['uid'=>$iu,'error'=>'revista no resuelta']; continue; }

        // El enum REAL de revistas.estado es:  en_curso | terminada | cancelada
        // Antes usaba 'completada', que NO existe -> MySQL lo degradaba al primer
        // valor del enum y la revista terminaba apareciendo como CANCELADA.
        // Se replica exactamente lo que hace /revistas/terminar.php:
        $c = $pdo->prepare("SELECT
                SUM(estado='ocupada') AS oc, SUM(estado='vacia') AS vc, COUNT(*) AS rv
              FROM revistas_detalle WHERE revista_id = :r");
        $c->execute([':r' => $rv]);
        $x = $c->fetch() ?: ['oc'=>0, 'vc'=>0, 'rv'=>0];

        $pdo->prepare("UPDATE revistas SET
                celdas_revisadas = :rv, celdas_ocupadas = :oc, celdas_vacias = :vc,
                estado = 'terminada', terminado_en = NOW()
              WHERE id = :id AND conjunto_id = :cn AND estado = 'en_curso'")
            ->execute([
                ':rv' => (int)$x['rv'], ':oc' => (int)$x['oc'], ':vc' => (int)$x['vc'],
                ':id' => $rv, ':cn' => $conjuntoId,
            ]);
        marcar_hecho($pdo, $iu, 'revista_cerrar', $rv);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>'cierre: '.$e->getMessage()];
    }
}

/* ══════════ 5b) CANCELACIÓN DE REVISTAS (v7.58) ══════════ */
foreach ($lote as $it) {
    if (($it['tipo'] ?? '') !== 'revista_cancelar') continue;
    $iu = (string)($it['uid'] ?? '');
    if ($iu === '' || ya_hecho($pdo, $iu)) { if ($iu) $hechos[]=$iu; continue; }

    try {
        $rv = resolver_revista($pdo, $it['revista_id'] ?? 0, $mapa);
        if (!$rv) { $errores[]=['uid'=>$iu,'error'=>'revista no resuelta']; continue; }

        $pdo->prepare("UPDATE revistas SET
                estado = 'cancelada', terminado_en = NOW()
              WHERE id = :id AND conjunto_id = :cn AND estado = 'en_curso'")
            ->execute([':id' => $rv, ':cn' => $conjuntoId]);
        marcar_hecho($pdo, $iu, 'revista_cancelar', $rv);
        $hechos[] = $iu;
    } catch (Throwable $e) {
        $errores[] = ['uid'=>$iu, 'error'=>'cancelar: '.$e->getMessage()];
    }
}

/* ── recalcular conteos de las revistas tocadas ── */
try {
    $rvs = array_unique(array_values($mapa));
    foreach ($lote as $it) {
        if (($it['tipo'] ?? '') === 'revista_paso') {
            $r = $it['revista_id'] ?? 0;
            if (is_string($r) && isset($mapa[$r])) $r = $mapa[$r];
            if ((int)$r) $rvs[] = (int)$r;
        }
    }
    foreach (array_unique($rvs) as $r) {
        if (!$r) continue;
        $c = $pdo->prepare("SELECT
                SUM(estado='ocupada') oc, SUM(estado='vacia') vc, COUNT(*) rv
              FROM revistas_detalle WHERE revista_id=:r");
        $c->execute([':r'=>$r]);
        $x = $c->fetch();
        if ($x) {
            $pdo->prepare("UPDATE revistas SET celdas_revisadas=:rv,
                            celdas_ocupadas=:oc, celdas_vacias=:vc WHERE id=:id")
                ->execute([':rv'=>(int)$x['rv'], ':oc'=>(int)$x['oc'],
                           ':vc'=>(int)$x['vc'], ':id'=>$r]);
        }
    }
} catch (Throwable $e) { /* no critico */ }

echo json_encode([
    'ok'        => true,
    'procesados'=> count($hechos),
    'hechos'    => $hechos,     // uids -> el celular los borra de la cola
    'mapa'      => $mapa,       // id_local => id_real
    'errores'   => $errores,
], JSON_UNESCAPED_UNICODE);
