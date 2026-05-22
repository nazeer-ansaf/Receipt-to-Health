<?php
require_once __DIR__ . '/includes/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $user = $email !== '' ? find_user_by_email($email) : null;

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        $error = 'Invalid email or password.';
    } else {
        login_user($user);
        header('Location: account.php');
        exit;
    }
}

render_page_start('Login', 'account');
page_hero(
    'Account access',
    'Login to Household Account',
    'Use the local account layer to show authentication and user-linked receipt storage.'
);
?>

<section class="auth-shell">
    <article class="panel">
        <h2>Login</h2>
        <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
            <label>
                <span>Email</span>
                <input type="email" name="email" required>
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" required>
            </label>
            <button class="button primary" type="submit">Login</button>
        </form>
        <p class="muted">Need an account? <a href="register.php">Register here</a>.</p>
    </article>
</section>

<?php render_page_end(); ?>

