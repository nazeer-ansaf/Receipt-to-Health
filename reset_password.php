<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    header('Location: ' . role_home_href(current_user()));
    exit;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$reset = $token !== '' ? find_password_reset_token($token) : null;
$error = $reset ? '' : 'This reset link is invalid or has expired. Please request a new one.';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset && is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (strlen($password) < 6) {
        $error = 'Your new password must be at least 6 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        reset_user_password((int)$reset['user_id'], (int)$reset['token_id'], $password);
        $success = true;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$reset) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security token expired. Please refresh the page and try again.';
}

render_page_start('Reset password', 'account');
?>
<section class="auth-experience">
    <div class="auth-stage">
        <aside class="auth-story">
            <a class="auth-brand" href="login.php"><span class="auth-brand-mark">R2H</span><span><strong><?= e(APP_NAME) ?></strong><small>AI Household Nutrition Intelligence</small></span></a>
            <div class="auth-copy"><p class="auth-kicker">Account recovery</p><h1>Set a fresh password.</h1><p>Your reset link is single-use and expires after one hour.</p></div>
        </aside>
        <section class="auth-console" aria-label="Reset password">
            <div class="auth-toolbar"><a href="login.php">Login</a><a href="register.php">Register</a></div>
            <article class="auth-glass auth-login-card">
                <div class="auth-card-header"><span>New credentials</span><h2>Reset password</h2><p>Choose a password with at least 6 characters.</p></div>
                <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>
                <?php if ($success): ?>
                    <p class="success-text">Your password has been updated. You can now sign in.</p>
                    <a class="button primary auth-submit" href="login.php">Continue to login</a>
                <?php elseif ($reset): ?>
                    <form method="post" class="auth-form">
                        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
                        <label><span>New password</span><input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
                        <label><span>Confirm new password</span><input type="password" name="password_confirmation" minlength="6" autocomplete="new-password" required></label>
                        <button class="button primary auth-submit" type="submit">Update password</button>
                    </form>
                <?php else: ?>
                    <a class="auth-register-link" href="forgot_password.php">Request a new reset link</a>
                <?php endif; ?>
            </article>
        </section>
    </div>
</section>
<?php render_page_end(); ?>
