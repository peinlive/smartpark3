<?php
// /home/myzonaco/smartpark.myzona360.com/cron/backup_auto.php
// v7.3 — Backup automático. Se ejecuta desde el cron de cPanel.
//
// CONFIGURAR EN cPanel → Cron Jobs:
//     /usr/local/bin/php /home/myzonaco/smartpark.myzona360.com/cron/backup_auto.php
//
// Frecuencia sugerida:
//     0 3 * * *      cada día a las 3 AM
//     0 3 * * 0      cada domingo a las 3 AM
//     0 3 1,16 * *   los días 1 y 16 de cada mes
//
// ROTACIÓN: conserva los últimos 10 automáticos. Los más viejos se borran
// solos, así el disco no se llena. Los MANUALES nunca se tocan.

// Este script corre por CLI, no por web. Bloquear el acceso HTTP.
if (PHP_SAPI !== 'cli' && empty($_GET['token'])) {
    http_response_code(403);
    exit('Solo por CLI');
}

$BASE = dirname(__DIR__);

// Bootstrap mínimo: solo necesitamos la conexión a la BD.
define('SMARTPARK_BOOT', true);
require_once $BASE . '/config/config.php';

$MAX_AUTO = 10;                      // cuántos automáticos conservar
$DIR      = $BASE . '/storage/backups';

if (!is_dir($DIR)) @mkdir($DIR, 0700, true);
if (!is_dir($DIR) || !is_writable($DIR)) {
    fwrite(STDERR, "ERROR: no se puede escribir en {$DIR}\n");
    exit(1);
}

try {
    $pdo = db();

    // ── generar el dump ──
    $nombre = 'smartpark_' . date('Ymd_His') . '_auto.sql';
    $ruta   = $DIR . '/' . $nombre;
    $fh     = fopen($ruta, 'w');
    if (!$fh) throw new RuntimeException('No se pudo crear el archivo');

    fwrite($fh, "-- SmartPark backup AUTOMATICO\n");
    fwrite($fh, "-- Fecha: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET NAMES utf8mb4;\n\n");

    $tablas = [];
    $st = $pdo->query("SHOW TABLES");
    while ($r = $st->fetch(PDO::FETCH_NUM)) $tablas[] = $r[0];

    $filas = 0;
    foreach ($tablas as $t) {
        $c = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "\n-- {$t}\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n");
        fwrite($fh, $c[1] . ";\n\n");

        $q   = $pdo->query("SELECT * FROM `{$t}`");
        $buf = [];
        $cols = null;
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $vals = [];
            foreach ($row as $v) {
                if ($v === null)    $vals[] = 'NULL';
                elseif (is_int($v)) $vals[] = (string)$v;
                elseif (is_numeric($v) && !preg_match('/^0\d/', (string)$v)) $vals[] = $v;
                else                $vals[] = $pdo->quote((string)$v);
            }
            $buf[] = '(' . implode(',', $vals) . ')';
            $filas++;
            if (count($buf) >= 200) {
                fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $buf) . ";\n");
                $buf = [];
            }
        }
        if ($buf && $cols) {
            fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $buf) . ";\n");
        }
    }
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    // ── comprimir ──
    if (function_exists('gzencode')) {
        $gz = gzencode(file_get_contents($ruta), 9);
        if ($gz !== false && file_put_contents($ruta . '.gz', $gz) !== false) {
            @unlink($ruta);
            $ruta   = $ruta . '.gz';
            $nombre = $nombre . '.gz';
        }
    }

    $peso = filesize($ruta);

    // ── ROTAR: borrar los automáticos viejos ──
    // Los MANUALES y los PREVIO-RESTAURACION nunca se borran.
    $autos = glob($DIR . '/smartpark_*_auto.sql*') ?: [];
    usort($autos, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $borrados = 0;
    foreach (array_slice($autos, $MAX_AUTO) as $viejo) {
        if (@unlink($viejo)) $borrados++;
    }

    $linea = sprintf(
        "[%s] OK · %s · %s KB · %d tablas · %d filas · %d viejos borrados\n",
        date('Y-m-d H:i:s'), $nombre, number_format($peso / 1024, 0),
        count($tablas), $filas, $borrados
    );
    @file_put_contents($DIR . '/backup.log', $linea, FILE_APPEND);
    echo $linea;
    exit(0);

} catch (Throwable $e) {
    $linea = sprintf("[%s] ERROR · %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    @file_put_contents($DIR . '/backup.log', $linea, FILE_APPEND);
    fwrite(STDERR, $linea);
    exit(1);
}
