<?php
// /home/myzonaco/smartpark.myzona360.com/diag_api_celda.php
// DIAGNÓSTICO — muestra qué devuelve la lógica de celdas del modal.
// Abrí:  https://smartpark.myzona360.com/diag_api_celda.php?apto=2403
// BORRALO cuando termines (es público).

define('SMARTPARK_BOOT', true);
require __DIR__ . '/config/app.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$aptoNum = trim($_GET['apto'] ?? '2403');
$pdo = db();
$conjuntoId = 1;

echo "═══ DIAGNÓSTICO API CELDAS — apto {$aptoNum} ═══\n\n";

// Resolver apto → id
$stA = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                       WHERE conjunto_id=:c AND numero_visible=:n LIMIT 1");
$stA->execute([':c'=>$conjuntoId, ':n'=>$aptoNum]);
$apto = $stA->fetch(PDO::FETCH_ASSOC);
if (!$apto) { echo "Apto no encontrado\n"; exit; }
$aptoId = (int)$apto['id'];
echo "Apto {$aptoNum} → id interno = {$aptoId}\n\n";

// La query EXACTA del modal (v7.9.1)
echo "── Ejecutando la query del modal ──\n";
try {
    $stC = $pdo->prepare(
        "SELECT c.id, c.nombre_visible AS codigo, c.tipo AS tipo_celda,
                np.codigo AS nivel_codigo,
                c.apto_dueno_id,
                ac.apto_usuario_id,
                ac.tipo AS tipo_asig,
                ad.numero_visible AS apto_dueno
           FROM celdas c
      LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
      LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
      LEFT JOIN asignaciones_celdas ac
             ON ac.celda_id = c.id
            AND ac.activa = 1
            AND ac.archivado_en IS NULL
          WHERE c.conjunto_id = :cc
            AND (c.apto_dueno_id = :ad OR ac.apto_usuario_id = :au)
       ORDER BY np.orden, c.nombre_visible
          LIMIT 20"
    );
    $stC->execute([':cc' => $conjuntoId, ':ad' => $aptoId, ':au' => $aptoId]);
    $filas = $stC->fetchAll(PDO::FETCH_ASSOC);
    echo "Filas devueltas: " . count($filas) . "\n\n";
    foreach ($filas as $f) {
        echo "  celda={$f['codigo']}  dueno_id={$f['apto_dueno_id']}  "
           . "usuario_id=" . ($f['apto_usuario_id'] ?? 'NULL') . "  "
           . "nivel={$f['nivel_codigo']}  asig=" . ($f['tipo_asig'] ?? '-') . "\n";
    }
    if (!$filas) {
        echo "  ⚠️ CERO filas. Verificando por qué...\n\n";
        // Sin el filtro de asignacion
        $t = $pdo->prepare("SELECT nombre_visible, apto_dueno_id, activa FROM celdas
                             WHERE conjunto_id=:c AND apto_dueno_id=:a");
        $t->execute([':c'=>$conjuntoId, ':a'=>$aptoId]);
        $cd = $t->fetchAll(PDO::FETCH_ASSOC);
        echo "  Celdas con apto_dueno_id={$aptoId}: " . count($cd) . "\n";
        foreach ($cd as $x) echo "     {$x['nombre_visible']} (activa={$x['activa']})\n";
    }
} catch (Exception $e) {
    echo "ERROR SQL: " . $e->getMessage() . "\n";
}

echo "\n⚠️ BORRÁ este archivo cuando termines.\n";
