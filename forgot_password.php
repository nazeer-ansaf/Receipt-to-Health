<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    header('Location: ' . role_home_href(current_user()));
    exit;
}

$message = '';
$error = '';
$developmentResetUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Security token expired. Please refresh the page and try again.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $message = 'If an account exists for that email, a password reset link has been sent.';

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = find_user_by_email($email);
            if ($user) {
                $token = create_password_reset_token((int)$user['id']);
                $resetUrl = APP_URL . '/reset_password.php?token=' . rawurlencode($token);
                $subject = APP_NAME . ' password reset';
                $body = "Hi {$user['name']},\n\nUse this link to choose a new password (valid for 1 hour):\n{$resetUrl}\n\nIf you did not request this, you can ignore this email.";
                $headers = 'From: ' . MAIL_FROM . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
                $sent = @mail($email, $subject, $body, $headers);

                if (APP_ENV !== 'production' && !$sent) {
                    $developmentResetUrl = $resetUrl;
                }
            }
        }
    }
}

render_page_start('Forgot password', 'account');
?>
<section class="auth-experience">
    <div class="auth-stage">
        <aside class="auth-story">
            <a class="auth-brand" href="login.php">
                <span class="auth-brand-mark">R2H</span>
                <span><strong><?= e(APP_NAME) ?></strong><small>AI Household Nutrition Intelligence</small></span>
            </a>
            <div class="auth-copy">
                <p class="auth-kicker">Account recovery</p>
                <h1>Get back to your health workspace.</h1>
                <p>We’ll help you securely create a new password and return to your receipt history, profiles, and insights.</p>
            </div>
        </aside>

        <section class="auth-console" aria-label="Password recovery">
            <div class="auth-toolbar"><a href="login.php">Login</a><a href="register.php">Register</a></div>
            <article class="auth-glass auth-login-card">
                <div class="auth-card-header">
                    <span>Secure recovery</span>
                    <h2>Forgot password?</h2>
                    <p>Enter the email address linked to your account.</p>
                </div>
                <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>
                <?php if ($message): ?><p class="success-text"><?= e($message) ?></p><?php endif; ?>
                <?php if ($developmentResetUrl): ?>
                    <p class="warning-text">Local mail is not configured. Use this development reset link:</p>
                    <p><a class="auth-register-link" href="<?= e($developmentResetUrl) ?>">Open password reset</a></p>
                <?php endif; ?>
                <form method="post" class="auth-form">
                    <?= csrf_field() ?>
                    <label><span>Email address</span><input type="email" name="email" placeholder="you@example.com" autocomplete="email" required></label>
                    <button class="button primary auth-submit" type="submit">Send reset link</button>
                </form>
                <a class="auth-register-link" href="login.php">Back to login</a>
            </article>
        </section>
    </div>
</section>
<?php render_page_end(); ?>
