<?php
// /home/myzonaco/smartpark.myzona360.com/includes/auth.php
// ──────────────────────────────────────────────────────────────
// Autenticación y autorización.
//
// Funciones expuestas:
//   - auth_login($username, $password): bool
//   - auth_logout(): void
//   - auth_user(): ?array          (datos del usuario logueado o null)
//   - auth_check(): bool           (¿hay sesión activa?)
//   - auth_require(): void         (exige sesión, redirige a /login si no)
//   - auth_has_role(...$codes): bool
//   - auth_require_role(...$codes): void
// ──────────────────────────────────────────────────────────────

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

function _session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// ───── Iniciar sesión ─────
function auth_login(string $username, string $password): bool
{
    _session_start();

    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, nombre_completo, conjunto_id, activo,
                intentos_fallidos, bloqueado_hasta
           FROM usuarios
          WHERE username = :u
          LIMIT 1"
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }
    if ((int)$user['activo'] !== 1) {
        return false;
    }
    if (!empty($user['bloqueado_hasta']) && strtotime($user['bloqueado_hasta']) > time()) {
        return false;
    }
    if (!password_verify($password, $user['password_hash'])) {
        // incrementar intentos
        $pdo->prepare(
            "UPDATE usuarios
                SET intentos_fallidos = intentos_fallidos + 1,
                    bloqueado_hasta   = CASE WHEN intentos_fallidos >= 4
                                              THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                              ELSE bloqueado_hasta END
              WHERE id = :id"
        )->execute([':id' => $user['id']]);
        return false;
    }

    // Cargar roles
    $rolesStmt = $pdo->prepare(
        "SELECT r.codigo, r.nombre, r.nivel
           FROM usuario_roles ur
           JOIN roles r ON r.id = ur.rol_id
          WHERE ur.usuario_id = :id"
    );
    $rolesStmt->execute([':id' => $user['id']]);
    $roles = $rolesStmt->fetchAll();

    // Regenerar ID de sesión (evita fixation)
    session_regenerate_id(true);

    // Si conjunto_id es NULL (ej: super_admin global), usar 1 como default
    // para que las queries del sistema siempre tengan un conjunto válido.
    $conjuntoSesion = $user['conjunto_id'] ? (int)$user['conjunto_id'] : 1;

    $_SESSION['user'] = [
        'id'              => (int)$user['id'],
        'username'        => $user['username'],
        'nombre_completo' => $user['nombre_completo'],
        'conjunto_id'     => $conjuntoSesion,
        'roles'           => array_column($roles, 'codigo'),
        'nivel_max'       => $roles ? max(array_column($roles, 'nivel')) : 0,
        'login_at'        => time(),
    ];

    // Reset intentos
    $pdo->prepare(
        "UPDATE usuarios
            SET intentos_fallidos = 0,
                bloqueado_hasta   = NULL,
                ultimo_login      = NOW()
          WHERE id = :id"
    )->execute([':id' => $user['id']]);

    return true;
}

// ───── Cerrar sesión ─────
function auth_logout(): void
{
    _session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// ───── Estado de sesión ─────
function auth_user(): ?array
{
    _session_start();
    $u = $_SESSION['user'] ?? null;
    if ($u === null) return null;

    // Sesión corrupta: usuario con ID pero sin roles → invalidar, fuerza re-login
    if (!empty($u['id']) && empty($u['roles'])) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    // Sesión vieja: conjunto_id null (antes del fix) → corregir en caliente sin re-login
    if (array_key_exists('conjunto_id', $u) && $u['conjunto_id'] === null) {
        $_SESSION['user']['conjunto_id'] = 1;
        return $_SESSION['user'];
    }

    return $u;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function auth_require(): void
{
    if (!auth_check()) {
        flash_set('warn', 'Debes iniciar sesión para continuar.');
        redirect('/login');
    }
}

// ───── Roles ─────
function auth_has_role(string ...$codigos): bool
{
    $u = auth_user();
    if (!$u) return false;
    foreach ($codigos as $c) {
        if (in_array($c, $u['roles'], true)) return true;
    }
    return false;
}

function auth_require_role(string ...$codigos): void
{
    auth_require();
    if (!auth_has_role(...$codigos)) {
        http_response_code(403);
        flash_set('error', 'No tienes permiso para acceder a esa sección.');
        redirect('/dashboard');
    }
}