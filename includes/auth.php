<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function current_user(): ?array
{
    start_app_session();

    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return null;
    }

    return refresh_session_user($_SESSION['user']);
}

function refresh_session_user(array $sessionUser): array
{
    static $refreshedUsers = [];

    if (!empty($sessionUser['is_guest'])) {
        return $sessionUser;
    }

    $userId = (int)($sessionUser['id'] ?? 0);

    if ($userId <= 0) {
        return $sessionUser;
    }

    if (isset($refreshedUsers[$userId])) {
        return $refreshedUsers[$userId];
    }

    try {
        ensure_user_auth_columns();

        $statement = db()->prepare('SELECT id, name, email, role, auth_provider FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();

        if (is_array($user)) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => (string)$user['name'],
                'email' => (string)$user['email'],
                'role' => normalize_user_role((string)($user['role'] ?? 'user')),
                'auth_provider' => (string)($user['auth_provider'] ?? 'local'),
                'is_guest' => false,
            ];

            return $refreshedUsers[$userId] = $_SESSION['user'];
        }
    } catch (Throwable $exception) {
        // Keep the existing session if the database is temporarily unavailable.
    }

    return $refreshedUsers[$userId] = $sessionUser;
}

function current_user_id(): ?int
{
    $user = current_user();
    if (!$user || !empty($user['is_guest'])) {
        return null;
    }

    return (int)$user['id'];
}

function login_user(array $user): void
{
    start_app_session();
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'role' => normalize_user_role((string)($user['role'] ?? 'user')),
        'auth_provider' => (string)($user['auth_provider'] ?? 'local'),
        'is_guest' => false,
    ];
}

function login_guest(): void
{
    start_app_session();
    $_SESSION['user'] = [
        'id' => 0,
        'name' => 'Guest Visitor',
        'email' => 'guest@local.demo',
        'role' => 'guest',
        'auth_provider' => 'guest',
        'is_guest' => true,
    ];
}

function logout_user(): void
{
    start_app_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function find_user_by_email(string $email): ?array
{
    ensure_user_auth_columns();
    $statement = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

function find_user_by_login_identifier(string $identifier): ?array
{
    $identifier = trim($identifier);

    if ($identifier === '') {
        return null;
    }

    ensure_user_auth_columns();

    $statement = db()->prepare(
        'SELECT * FROM users
         WHERE email = :email OR name = :name
         ORDER BY CASE WHEN email = :email_order THEN 0 ELSE 1 END, id ASC
         LIMIT 1'
    );
    $statement->execute([
        ':email' => $identifier,
        ':name' => $identifier,
        ':email_order' => $identifier,
    ]);

    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

function list_registered_users(int $limit = 50): array
{
    ensure_user_auth_columns();
    $limit = max(1, min(200, $limit));
    $statement = db()->query(
        'SELECT id, name, email, role, auth_provider, created_at
         FROM users
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit
    );

    return $statement->fetchAll() ?: [];
}

function register_user(string $name, string $email, string $password, string $role = 'user'): array
{
    ensure_user_auth_columns();
    $role = normalize_account_role($role);
    $statement = db()->prepare(
        'INSERT INTO users (name, email, password_hash, role, auth_provider) VALUES (:name, :email, :password_hash, :role, :auth_provider)'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $role,
        ':auth_provider' => 'local',
    ]);

    return [
        'id' => (int)db()->lastInsertId(),
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'auth_provider' => 'local',
    ];
}

function login_or_create_social_user(string $provider): array
{
    ensure_user_auth_columns();

    $provider = normalize_social_provider($provider);
    $email = $provider . '.demo@receipt-to-health.local';
    $name = ucfirst($provider) . ' Demo User';
    $user = find_user_by_email($email);

    if ($user) {
        return $user;
    }

    $statement = db()->prepare(
        'INSERT INTO users (name, email, password_hash, role, auth_provider) VALUES (:name, :email, :password_hash, :role, :auth_provider)'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ':role' => 'user',
        ':auth_provider' => $provider,
    ]);

    return [
        'id' => (int)db()->lastInsertId(),
        'name' => $name,
        'email' => $email,
        'role' => 'user',
        'auth_provider' => $provider,
    ];
}

function normalize_user_role(string $role): string
{
    $role = strtolower(preg_replace('/[^a-zA-Z_-]/', '', $role) ?: 'user');
    return in_array($role, ['admin', 'user', 'guest'], true) ? $role : 'user';
}

function normalize_account_role(string $role): string
{
    $role = normalize_user_role($role);
    return in_array($role, ['admin', 'user'], true) ? $role : 'user';
}

function normalize_social_provider(string $provider): string
{
    $provider = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $provider) ?: 'google');
    return in_array($provider, ['google', 'github', 'microsoft'], true) ? $provider : 'google';
}

function current_user_role(): string
{
    $user = current_user();
    return $user ? normalize_user_role((string)($user['role'] ?? 'user')) : 'visitor';
}

function is_admin_user(): bool
{
    return current_user_role() === 'admin';
}

function is_guest_user(): bool
{
    $user = current_user();
    return $user ? !empty($user['is_guest']) || current_user_role() === 'guest' : false;
}

function has_app_access(): bool
{
    return current_user() !== null;
}

function ensure_user_auth_columns(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        db()->exec("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER password_hash");
    } catch (Throwable $exception) {
        // Column already exists or database privileges are limited.
    }

    try {
        db()->exec("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(40) NOT NULL DEFAULT 'local' AFTER role");
    } catch (Throwable $exception) {
        // Column already exists or database privileges are limited.
    }
}
