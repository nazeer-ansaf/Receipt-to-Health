<?php
require_once __DIR__ . '/includes/layout.php';

$error = '';

if (current_user()) {
    header('Location: profile_setup.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'login');

    if ($action === 'guest') {
        login_guest();
        header('Location: profile_setup.php?first=1');
        exit;
    }

    if ($action === 'social') {
        try {
            $user = login_or_create_social_user((string)($_POST['provider'] ?? 'google'));
            login_user($user);
            header('Location: profile_setup.php?first=1');
            exit;
        } catch (Throwable $exception) {
            $error = 'Social demo login could not start: ' . $exception->getMessage();
        }
    }

    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = $email !== '' ? find_user_by_email($email) : null;

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            $error = 'Invalid email or password.';
        } else {
            login_user($user);
            header('Location: profile_setup.php?first=1');
            exit;
        }
    }
}

render_page_start('Login', 'account');
?>
<section class="auth-experience">
    <div class="auth-ambient" aria-hidden="true">
        <span class="auth-line line-a"></span>
        <span class="auth-line line-b"></span>
        <span class="auth-line line-c"></span>
        <span class="auth-code code-a">OCR  NLP  SCORE  GRAPH</span>
        <span class="auth-code code-b">SUGAR DOWN  SODIUM DOWN  FIBER UP</span>
    </div>

    <div class="auth-stage">
        <aside class="auth-story">
            <a class="auth-brand" href="login.php">
                <span class="auth-brand-mark">R2H</span>
                <span>
                    <strong><?= e(APP_NAME) ?></strong>
                    <small>AI Household Nutrition Intelligence</small>
                </span>
            </a>

            <div class="auth-copy">
                <p class="auth-kicker">Nutrition cockpit</p>
                <h1>Turn every receipt into a living health profile.</h1>
                <p>Sign in, continue as a guest, or launch a social demo account before building your personalized analysis workspace.</p>
            </div>

            <div class="auth-dashboard-preview" aria-hidden="true">
                <div class="auth-mini-card receipt-scan">
                    <div class="scan-head">
                        <span>Receipt stream</span>
                        <b>LIVE</b>
                    </div>
                    <div class="scan-lines">
                        <i style="--w: 82%"></i>
                        <i style="--w: 64%"></i>
                        <i style="--w: 91%"></i>
                        <i style="--w: 48%"></i>
                        <i style="--w: 76%"></i>
                    </div>
                    <span class="scan-beam"></span>
                </div>

                <div class="auth-mini-card health-score">
                    <span>Health score</span>
                    <strong>82</strong>
                    <small>Strong profile match</small>
                    <div class="mini-bars">
                        <i style="--h: 74%"></i>
                        <i style="--h: 46%"></i>
                        <i style="--h: 88%"></i>
                        <i style="--h: 58%"></i>
                        <i style="--h: 69%"></i>
                    </div>
                </div>

                <div class="auth-pipeline">
                    <span>OCR</span>
                    <span>NLP</span>
                    <span>Risk</span>
                    <span>Advice</span>
                </div>
            </div>

            <div class="auth-stats">
                <div><strong>7</strong><span>AI layers</span></div>
                <div><strong>24h</strong><span>trend memory</span></div>
                <div><strong>4</strong><span>access modes</span></div>
            </div>
        </aside>

        <section class="auth-console" aria-label="Login options">
            <div class="auth-toolbar">
                <a href="login.php" class="active">Login</a>
                <a href="register.php">Register</a>
            </div>

            <article class="auth-glass auth-login-card">
                <div class="auth-card-header">
                    <span>Secure access</span>
                    <h2>Welcome back</h2>
                    <p>Use your account to continue your receipt-to-health workspace.</p>
                </div>

                <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>

                <form method="post" class="auth-form">
                    <input type="hidden" name="action" value="login">
                    <label>
                        <span>Email address</span>
                        <input type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
                    </label>
                    <label>
                        <span>Password</span>
                        <input type="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                    </label>
                    <button class="button primary auth-submit" type="submit">Login</button>
                </form>
            </article>

            <div class="auth-option-grid">
                <article class="auth-glass auth-social-card">
                    <div class="auth-card-header compact">
                        <span>Fast entry</span>
                        
                    </div>
                    <div class="social-buttons auth-social-buttons">
                        <?php foreach (['google' => 'Google', 'github' => 'GitHub', 'microsoft' => 'Microsoft'] as $provider => $label): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="social">
                                <input type="hidden" name="provider" value="<?= e($provider) ?>">
                                <button class="button social <?= e($provider) ?>" type="submit"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="auth-glass auth-guest-card">
                    <div class="auth-card-header compact">
                        <span>No account</span>
                        <h2>Guest mode</h2>
                    </div>
                    <form method="post" class="guest-form">
                        <input type="hidden" name="action" value="guest">
                        <button class="button ghost auth-guest-button" type="submit">Continue as guest</button>
                    </form>
                    <a class="auth-register-link" href="register.php">Create role account</a>
                </article>
            </div>
        </section>
    </div>
</section>

<?php render_page_end(); ?>
