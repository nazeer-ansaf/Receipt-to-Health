<?php
require_once __DIR__ . '/includes/layout.php';

$error = '';

if (current_user()) {
    header('Location: profile_setup.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'register');

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

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = normalize_user_role((string)($_POST['role'] ?? 'user'));

    if ($action === 'register' && ($name === '' || $email === '' || strlen($password) < 6)) {
        $error = 'Enter a name, valid email, and password with at least 6 characters.';
    } elseif ($action === 'register' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($action === 'register' && find_user_by_email($email)) {
        $error = 'An account already exists for this email.';
    } elseif ($action === 'register') {
        $user = register_user($name, $email, $password, $role);
        login_user($user);
        header('Location: profile_setup.php?first=1');
        exit;
    }
}

render_page_start('Register', 'account');
?>
<section class="auth-experience register-experience">
    <div class="auth-ambient" aria-hidden="true">
        <span class="auth-line line-a"></span>
        <span class="auth-line line-b"></span>
        <span class="auth-line line-c"></span>
        <span class="auth-code code-a">USER  NUTRITIONIST  ADMIN</span>
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
                <p class="auth-kicker">Role-based workspace</p>
                <h1>Create your nutrition intelligence account.</h1>
                <p>Choose a role, build a health profile, and connect receipt analysis to a secure personalized workspace.</p>
            </div>

            <div class="role-orbit" aria-hidden="true">
                <div class="role-orbit-core">
                    <span>AI</span>
                    <strong>Profile Engine</strong>
                </div>
                <div class="role-node role-user"><strong>User</strong><span>Personal reports</span></div>
                <div class="role-node role-nutritionist"><strong>Nutritionist</strong><span>Guided review</span></div>
                <div class="role-node role-admin"><strong>Admin</strong><span>System console</span></div>
            </div>

            <div class="auth-stats">
                <div><strong>3</strong><span>role types</span></div>
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
                    <input type="hidden" name="action" value="register">

                    <div class="auth-field-grid">
                        <label>
                            <span>Name</span>
                            <input type="text" name="name" placeholder="Your name" autocomplete="name" required>
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

                    <label>
                        <span>Account role</span>
                        <select name="role" class="auth-role-select">
                            <option value="user">User</option>
                            <option value="nutritionist">Nutritionist</option>
                            <option value="admin">Admin</option>
                        </select>
                    </label>

                    <div class="auth-role-strip" aria-hidden="true">
                        <div><strong>User</strong><span>Personal profile</span></div>
                        <div><strong>Nutritionist</strong><span>Review flow</span></div>
                        <div><strong>Admin</strong><span>Data console</span></div>
                    </div>

                    <button class="button primary auth-submit" type="submit">Create account</button>
                </form>
            </article>

            <div class="auth-option-grid">
                <article class="auth-glass auth-social-card">
                    <div class="auth-card-header compact">
                        <span>Fast entry</span>
                        <h2>Social demo</h2>
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
                        <span>No setup</span>
                        <h2>Guest mode</h2>
                    </div>
                    <form method="post" class="guest-form">
                        <input type="hidden" name="action" value="guest">
                        <button class="button ghost auth-guest-button" type="submit">Continue as guest</button>
                    </form>
                    <a class="auth-register-link" href="login.php">Already have account</a>
                </article>
            </div>
        </section>
    </div>
</section>

<?php render_page_end(); ?>
