<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/terminar.php
// v3.0 (3AS): Cierra la revista de UN nivel.
//   - AUTO-MARCA como VACÍAS todas las celdas del nivel sin registro
//   - Recalcula conteos finales
//   - Redirige a /revistas/continuar para ofrecer agregar otro nivel

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/revistas');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/revistas');

// Obtener la revista y su nivel
$stR = $pdo->prepare("SELECT id, nivel, estado, total_celdas
                        FROM revistas
                       WHERE id = :id AND conjunto_id = :c LIMIT 1");
$stR->execute([':id' => $id, ':c' => $conjuntoId]);
$rev = $stR->fetch();

if (!$rev) { flash_set('error', 'Revista no encontrada.'); redirect('/revistas'); }
if ($rev['estado'] !== 'en_curso') {
    flash_set('warn', 'Esta revista ya no está en curso.');
    redirect('/revistas/ver?id=' . $id);
}

try {
    $autoVacias = 0;

    // ── AUTO-MARCAR CELDAS SIN REGISTRO COMO VACÍAS ──
    // 1) Ubicar el nivel_id a partir del código de nivel de la revista
    $stN = $pdo->prepare("SELECT id FROM niveles_parqueadero
                           WHERE codigo = :nv AND conjunto_id = :c AND activo = 1
                           LIMIT 1");
    $stN->execute([':nv' => $rev['nivel'], ':c' => $conjuntoId]);
    $nivelId = (int)$stN->fetchColumn();

    if ($nivelId > 0) {
        // 2) Celdas activas del nivel SIN registro en revistas_detalle
        $stPend = $pdo->prepare("
            SELECT c.id
              FROM celdas c
             WHERE c.nivel_id = :nv
               AND c.activa = 1
               AND c.id NOT IN (
                   SELECT rd.celda_id FROM revistas_detalle rd
                    WHERE rd.revista_id = :r
               )");
        $stPend->execute([':nv' => $nivelId, ':r' => $id]);
        $celdasPendientes = $stPend->fetchAll(PDO::FETCH_COLUMN);

        // 3) INSERT masivo con estado='vacia'
        if (!empty($celdasPendientes)) {
            $stIns = $pdo->prepare("INSERT INTO revistas_detalle
                    (revista_id, celda_id, estado, placa_detectada, vehiculo_id, foto_path)
                VALUES (:r, :cd, 'vacia', NULL, NULL, NULL)");
            foreach ($celdasPendientes as $cId) {
                $stIns->execute([':r' => $id, ':cd' => (int)$cId]);
                $autoVacias++;
            }
        }
    }

    // ── RECALCULAR CONTEOS FINALES ──
    $stC = $pdo->prepare("SELECT
            SUM(CASE WHEN estado = 'ocupada' THEN 1 ELSE 0 END) AS oc,
            SUM(CASE WHEN estado = 'vacia' THEN 1 ELSE 0 END) AS vc,
            COUNT(*) AS rv
        FROM revistas_detalle WHERE revista_id = :r");
    $stC->execute([':r' => $id]);
    $c = $stC->fetch();

    $pdo->prepare("UPDATE revistas SET
            celdas_revisadas = :rv, celdas_ocupadas = :oc, celdas_vacias = :vc,
            estado = 'terminada', terminado_en = NOW()
        WHERE id = :id AND conjunto_id = :cn AND estado = 'en_curso'")
        ->execute([
            ':rv' => (int)$c['rv'], ':oc' => (int)$c['oc'], ':vc' => (int)$c['vc'],
            ':id' => $id, ':cn' => $conjuntoId,
        ]);

    // audit_log si está disponible
    if (function_exists('audit_log')) {
        audit_log('terminar_revista', 'revistas', $id,
                  "Terminó revista nivel {$rev['nivel']} (auto-vacías: {$autoVacias})",
                  null, ['auto_vacias' => $autoVacias, 'ocupadas' => (int)$c['oc'], 'vacias' => (int)$c['vc']]);
    }

    $msg = "✅ Nivel {$rev['nivel']} terminado. Revisadas: " . (int)$c['rv']
         . " (Ocupadas: " . (int)$c['oc'] . ", Vacías: " . (int)$c['vc'] . ")";
    if ($autoVacias > 0) $msg .= " · Auto-marcadas como vacías: {$autoVacias}";
    flash_set('ok', $msg);

    // v3AS: pantalla intermedia (NO redirect directo a /ver)
    redirect('/revistas/continuar?id=' . $id);

} catch (Exception $ex) {
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al terminar.');
    redirect('/revistas/ejecutar?id=' . $id);
}
