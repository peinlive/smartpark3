<?php
// /home/myzonaco/smartpark.myzona360.com/includes/audit_helpers.php
// v1.0 (3AJ): Helper para escribir en la tabla audit_log de forma segura.
//
// Uso básico:
//   audit_log('accion', 'entidad', $entidad_id, 'descripción');
//
// Uso completo con diff automático:
//   $antes = $stmt->fetch(); // fila actual antes de update
//   // ... hacer el UPDATE ...
//   $despues = $stmt->fetch(); // fila después
//   audit_log('update_vehiculo', 'vehiculos', $vehId,
//             'Cambió placa y color', $antes, $despues);
//
// El helper NO propaga excepciones — si escribir en audit_log falla,
// se registra en error_log pero la operación principal sigue igual.

if (!function_exists('audit_log')) {

    /**
     * Registra una acción en la tabla audit_log.
     *
     * @param string      $accion       Slug de la acción (ej: 'update_observacion', 'delete_evidencia', 'login')
     * @param string|null $entidad      Nombre de la entidad afectada (ej: 'observaciones_vehiculo', 'vehiculos')
     * @param int|null    $entidad_id   ID del registro afectado
     * @param string|null $descripcion  Descripción legible (máx 500 chars)
     * @param mixed       $antes        Array/objeto con estado previo (se guarda como JSON) — opcional
     * @param mixed       $despues      Array/objeto con estado nuevo (se guarda como JSON) — opcional
     * @param int|null    $conjunto_id  Fuerza un conjunto_id (por defecto usa el del usuario autenticado)
     * @param int|null    $usuario_id   Fuerza un usuario_id (por defecto usa el del usuario autenticado)
     *
     * @return bool  true si se escribió, false si falló (nunca lanza excepción)
     */
    function audit_log(
        string $accion,
        ?string $entidad = null,
        $entidad_id = null,
        ?string $descripcion = null,
        $antes = null,
        $despues = null,
        ?int $conjunto_id = null,
        ?int $usuario_id = null
    ): bool {
        try {
            if (!function_exists('db')) return false;
            $pdo = db();
            if (!$pdo) return false;

            // Resolver conjunto y usuario desde la sesión si no se pasaron
            if ($conjunto_id === null || $usuario_id === null) {
                if (function_exists('auth_user')) {
                    $u = auth_user();
                    if ($conjunto_id === null && !empty($u['conjunto_id'])) $conjunto_id = (int)$u['conjunto_id'];
                    if ($usuario_id === null && !empty($u['id']))          $usuario_id  = (int)$u['id'];
                }
            }

            // Sanitizar tipos
            $entidad     = $entidad !== null ? mb_substr($entidad, 0, 80) : null;
            $accion      = mb_substr($accion, 0, 80);
            $descripcion = $descripcion !== null ? mb_substr($descripcion, 0, 500) : null;
            $entidad_id  = ($entidad_id !== null && is_numeric($entidad_id)) ? (int)$entidad_id : null;

            // Convertir antes/después a JSON válido (o null)
            $antesJson   = _audit_to_json($antes);
            $despuesJson = _audit_to_json($despues);

            // Datos de contexto
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if ($ip !== null) $ip = mb_substr($ip, 0, 45);
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if ($ua !== null) $ua = mb_substr($ua, 0, 255);

            $sql = "INSERT INTO audit_log
                        (conjunto_id, usuario_id, accion, entidad, entidad_id,
                         descripcion, datos_antes, datos_despues, ip, user_agent)
                    VALUES
                        (:conj, :usr, :ac, :en, :eid,
                         :ds, :da, :dd, :ip, :ua)";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':conj' => $conjunto_id,
                ':usr'  => $usuario_id,
                ':ac'   => $accion,
                ':en'   => $entidad,
                ':eid'  => $entidad_id,
                ':ds'   => $descripcion,
                ':da'   => $antesJson,
                ':dd'   => $despuesJson,
                ':ip'   => $ip,
                ':ua'   => $ua,
            ]);
            return true;
        } catch (Throwable $ex) {
            // Nunca romper el flujo por un fallo de auditoría
            @error_log('audit_log() failed: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Helper interno: convierte cualquier valor a JSON válido o null.
     * Filtra campos sensibles (password, contrasena, token) por si acaso.
     */
    function _audit_to_json($v): ?string {
        if ($v === null) return null;
        if (is_string($v) && $v === '') return null;
        try {
            // Si es objeto PDO/otro, convertir a array
            if (is_object($v)) $v = (array)$v;
            if (is_array($v)) {
                foreach (array_keys($v) as $k) {
                    $lk = strtolower((string)$k);
                    if (strpos($lk, 'password') !== false ||
                        strpos($lk, 'contrasena') !== false ||
                        strpos($lk, 'contraseña') !== false ||
                        strpos($lk, 'token')  !== false ||
                        strpos($lk, 'secret') !== false) {
                        $v[$k] = '***REDACTED***';
                    }
                }
            }
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($j === false) return null;
            // Truncar si es enorme
            if (strlen($j) > 60000) $j = substr($j, 0, 60000) . '...(TRUNCADO)';
            return $j;
        } catch (Throwable $ex) {
            return null;
        }
    }

    /**
     * Helper de conveniencia: calcula el diff entre dos arrays y genera
     * arrays "antes" y "despues" solo con los campos que cambiaron.
     * Útil para no llenar el JSON con todos los campos si solo cambió uno.
     *
     * @return array [antes_diff, despues_diff]  — ambos con solo los campos cambiados
     */
    function audit_diff(array $antes, array $despues): array {
        $diffA = [];
        $diffD = [];
        $keys = array_unique(array_merge(array_keys($antes), array_keys($despues)));
        foreach ($keys as $k) {
            $a = $antes[$k]   ?? null;
            $d = $despues[$k] ?? null;
            if ($a != $d) { // comparación loose (evita false positives por tipos)
                $diffA[$k] = $a;
                $diffD[$k] = $d;
            }
        }
        return [$diffA, $diffD];
    }
}
