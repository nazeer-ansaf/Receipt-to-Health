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
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user ? (int)$user['id'] : null;
}

function login_user(array $user): void
{
    start_app_session();
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
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
    $statement = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

function register_user(string $name, string $email, string $password): array
{
    $statement = db()->prepare(
        'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    return [
        'id' => (int)db()->lastInsertId(),
        'name' => $name,
        'email' => $email,
    ];
}

