<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function google_authenticate_id_token(string $credential): array
{
    if (GOOGLE_CLIENT_ID === '') {
        throw new RuntimeException('Google Login is not configured yet.');
    }

    $autoload = ROOT_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Google Login dependencies are not installed.');
    }

    require_once $autoload;

    if (!class_exists('Google\\Client')) {
        throw new RuntimeException('Google Login dependencies are incomplete.');
    }

    $client = new Google\Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($credential);

    if (!is_array($payload)) {
        throw new RuntimeException('Google credential could not be verified.');
    }

    $issuer = (string)($payload['iss'] ?? '');
    $audience = (string)($payload['aud'] ?? '');
    $expiresAt = (int)($payload['exp'] ?? 0);
    $email = strtolower(trim((string)($payload['email'] ?? '')));

    if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
        || !hash_equals(GOOGLE_CLIENT_ID, $audience)
        || $expiresAt <= time()
        || empty($payload['email_verified'])
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || trim((string)($payload['sub'] ?? '')) === '') {
        throw new RuntimeException('Google credential claims were not accepted.');
    }

    ensure_user_auth_columns();
    $googleId = trim((string)$payload['sub']);
    $statement = db()->prepare('SELECT * FROM users WHERE google_id = :google_id LIMIT 1');
    $statement->execute([':google_id' => $googleId]);
    $user = $statement->fetch();

    if (!$user) {
        $user = find_user_by_email($email);
    }

    $name = trim((string)($payload['name'] ?? '')) ?: trim((string)($payload['given_name'] ?? '')) ?: 'Google User';
    $picture = trim((string)($payload['picture'] ?? ''));

    if ($user) {
        if (!empty($user['google_id']) && !hash_equals((string)$user['google_id'], $googleId)) {
            throw new RuntimeException('This Google account cannot be linked to the existing email account.');
        }

        $update = db()->prepare('UPDATE users SET google_id = :google_id, profile_picture = :profile_picture WHERE id = :id');
        $update->execute([':google_id' => $googleId, ':profile_picture' => $picture !== '' ? $picture : ($user['profile_picture'] ?? null), ':id' => (int)$user['id']]);
        $user['google_id'] = $googleId;
        $user['profile_picture'] = $picture !== '' ? $picture : ($user['profile_picture'] ?? '');
        return $user;
    }

    $insert = db()->prepare(
        'INSERT INTO users (name, email, google_id, password_hash, role, auth_provider, profile_picture)
         VALUES (:name, :email, :google_id, NULL, :role, :auth_provider, :profile_picture)'
    );
    $insert->execute([
        ':name' => $name,
        ':email' => $email,
        ':google_id' => $googleId,
        ':role' => 'user',
        ':auth_provider' => 'google',
        ':profile_picture' => $picture !== '' ? $picture : null,
    ]);

    return [
        'id' => (int)db()->lastInsertId(),
        'name' => $name,
        'email' => $email,
        'role' => 'user',
        'auth_provider' => 'google',
        'profile_picture' => $picture,
    ];
}
