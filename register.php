<?php
require_once __DIR__ . '/includes/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || strlen($password) < 6) {
        $error = 'Enter a name, valid email, and password with at least 6 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (find_user_by_email($email)) {
        $error = 'An account already exists for this email.';
    } else {
        $user = register_user($name, $email, $password);
        login_user($user);
        header('Location: account.php');
        exit;
    }
}

render_page_start('Register', 'account');
page_hero(
    'Account setup',
    'Create Household Account',
    'Register a local demo account so receipt analyses can be linked to a user record in MySQL.'
);
?>

<section class="auth-shell">
    <article class="panel">
        <h2>Register</h2>
        <?php if ($error): ?><p class="warning-text"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
            <label>
                <span>Name</span>
                <input type="text" name="name" required>
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" required>
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" minlength="6" required>
            </label>
            <button class="button primary" type="submit">Create account</button>
        </form>
        <p class="muted">Already registered? <a href="login.php">Login here</a>.</p>
    </article>
</section>

<?php render_page_end(); ?>

