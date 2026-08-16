<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/google_auth.php';

$error = '';
if (current_user()) {
    header('Location: ' . role_home_href(current_user()));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'register');

    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Security token expired. Please refresh the page and try again.';
    } else {
        if ($action === 'guest') {
            login_guest();
            header('Location: profile_setup.php?first=1');
            exit;
        }

        if ($action === 'google') {
            try {
                $user = google_authenticate_id_token((string)($_POST['credential'] ?? ''));
                login_user($user);
                header('Location: ' . post_login_redirect_url($user));
                exit;
            } catch (Throwable $exception) {
                $error = 'Google Login could not be completed. Please try again.';
            }
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = 'user';
        $matchingLogin = $name !== '' ? find_user_by_login_identifier($name) : null;

        if ($action === 'register' && ($name === '' || $email === '' || strlen($password) < 6)) {
            $error = 'Enter a name, valid email, and password with at least 6 characters.';
        } elseif ($action === 'register' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif ($action === 'register' && find_user_by_email($email)) {
            $error = 'An account already exists for this email.';
        } elseif ($action === 'register' && $matchingLogin && strcasecmp((string)($matchingLogin['name'] ?? ''), $name) === 0) {
            $error = 'An account already exists for this username.';
        } elseif ($action === 'register') {
            $user = register_user($name, $email, $password, $role);
            login_user($user);
            header('Location: profile_setup.php?first=1');
            exit;
        }
    }
}

render_page_start('Register', 'account');
?>
<section class="auth-experience register-experience">
    <div class="auth-ambient" aria-hidden="true">
        <span class="auth-line line-a"></span>
        <span class="auth-line line-b"></span>
        <span class="auth-line line-c"></span>
        <span class="auth-code code-a">PROFILE  RECEIPTS</span>
        <span class="auth-code code-b">PROFILE  RECEIPTS  INSIGHTS</span>
    </div>

    <div class="auth-stage register-stage">
        <aside class="auth-story">
            <a class="auth-brand" href="login.php">
                <span class="auth-brand-mark">R2H</span>
                <span>
                    <strong><?= e(APP_NAME) ?></strong>
                    <small>AI Household Nutrition Intelligence</small>
                </span>
            </a>

            <div class="auth-copy">
                <p class="auth-kicker">Personal workspace</p>
                <h1>Create your nutrition account.</h1>
                <p>Build your health profile and connect receipt analysis to a secure personalized workspace.</p>
            </div>

            <div class="role-orbit" aria-hidden="true">
                <div class="role-orbit-core">
                    <span>AI</span>
                    <strong>Profile Engine</strong>
                </div>
                <div class="role-node role-user"><strong>Profile</strong><span>Health notes</span></div>
                <div class="role-node role-report"><strong>Reports</strong><span>Receipt insights</span></div>
            </div>

            <div class="auth-stats">
                <div><strong>1</strong><span>user workspace</span></div>
                <div><strong>1st</strong><span>profile setup</span></div>
                <div><strong>AI</strong><span>analysis ready</span></div>
            </div>
        </aside>

        <section class="auth-console" aria-label="Register options">
            <div class="auth-toolbar">
                <a href="login.php">Login</a>
                <a href="register.php" class="active">Register</a>
            </div>

            <article class="auth-glass auth-login-card register-card">
                <div class="auth-card-header">
                    <span>New workspace</span>
                    <h2>Create account</h2>
                    <p>Register locally and continue directly to your first health profile.</p>
                </div>

                <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>

                <form method="post" class="auth-form register-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="register">

                    <div class="auth-field-grid">
                        <label>
                            <span>Username</span>
                            <input type="text" name="name" placeholder="user1" autocomplete="username" required>
                        </label>
                        <label>
                            <span>Email</span>
                            <input type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
                        </label>
                    </div>

                    <label>
                        <span>Password</span>
                        <input type="password" name="password" minlength="6" placeholder="Minimum 6 characters" autocomplete="new-password" required>
                    </label>

                    <button class="button primary auth-submit" type="submit">Create account</button>
                </form>
            </article>

            <div class="auth-option-grid">
                <article class="auth-glass auth-social-card">
                    <div class="auth-card-header compact">
                        <span>Quick start</span>
                        <h2>Try a demo workspace</h2>
                    </div>
                    <div class="social-buttons auth-social-buttons">
                        <div class="google-login-widget">
                            <?php if (GOOGLE_CLIENT_ID !== ''): ?>
                                <div id="g_id_onload" data-client_id="<?= e(GOOGLE_CLIENT_ID) ?>" data-callback="handleGoogleCredential" data-auto_prompt="false"></div>
                                <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline" data-text="continue_with" data-shape="rectangular" data-logo_alignment="left" data-width="220"></div>
                                <form id="google-auth-form" method="post" hidden>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="google">
                                    <input type="hidden" name="credential" id="google-credential">
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php foreach (['github' => 'GitHub', 'microsoft' => 'Microsoft'] as $provider => $label): ?>
                            <form method="post">
                                <?= csrf_field() ?>
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
                        <h2>Guest access</h2>
                    </div>
                    <form method="post" class="guest-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="guest">
                        <button class="button ghost auth-guest-button" type="submit">Continue as guest</button>
                    </form>
                    <a class="auth-register-link" href="login.php">Already have account</a>
                </article>
            </div>
        </section>
    </div>
</section>

<script src="assets/js/google-auth.js"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>

<?php render_page_end(); ?>
