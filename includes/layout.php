<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function nav_items(): array
{
    $items = [
        'upload' => ['label' => 'Analyze', 'href' => 'index.php'],
        'profile' => ['label' => 'Profile', 'href' => 'profile_setup.php'],
        'dashboard' => ['label' => 'Results', 'href' => 'dashboard.php'],
        'analytics' => ['label' => 'Trends', 'href' => 'analytics.php'],
        'history' => ['label' => 'History', 'href' => 'history.php'],
        'ocr' => ['label' => 'Fix Items', 'href' => 'ocr_review.php'],
        'foods' => ['label' => 'Foods', 'href' => 'food_database.php'],
        'simulator' => ['label' => 'Simulator', 'href' => 'simulator.php'],
        'graph' => ['label' => 'Graph', 'href' => 'knowledge_graph.php'],
        'family' => ['label' => 'Family', 'href' => 'family.php'],
        'account' => ['label' => 'Account', 'href' => 'account.php'],
        'reports' => ['label' => 'Report', 'href' => 'reports.php'],
        'admin' => ['label' => 'Admin', 'href' => 'admin.php'],
        'method' => ['label' => 'Methodology', 'href' => 'methodology.php'],
        'setup' => ['label' => 'Setup', 'href' => 'setup_check.php'],
    ];

    if (!is_admin_user()) {
        unset($items['admin']);
    }

    return $items;
}

function primary_nav_items(): array
{
    $primaryKeys = ['upload', 'profile', 'dashboard', 'analytics', 'history', 'reports'];
    return array_intersect_key(nav_items(), array_flip($primaryKeys));
}

function secondary_nav_items(): array
{
    return array_diff_key(nav_items(), primary_nav_items());
}

function quick_actions(): array
{
    $actions = [
        ['label' => 'Start new analysis', 'href' => 'index.php'],
        ['label' => 'Try demo report', 'href' => 'api/demo_mode.php?mode=final'],
        ['label' => 'Try correction flow', 'href' => 'api/demo_mode.php?mode=review'],
        ['label' => 'Edit health profile', 'href' => 'profile_setup.php'],
        ['label' => 'Upload Medical Record', 'href' => 'profile_setup.php#medical-records'],
        ['label' => 'Fix detected items', 'href' => 'ocr_review.php'],
        ['label' => 'Open latest result', 'href' => 'dashboard.php'],
        ['label' => 'Print/PDF report', 'href' => 'reports.php'],
        ['label' => 'System Check', 'href' => 'setup_check.php'],
    ];

    if (is_admin_user()) {
        $actions[] = ['label' => 'Admin Console', 'href' => 'admin.php'];
    }

    return $actions;
}

function render_page_start(string $title, string $active = 'dashboard'): void
{
    $currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $publicPages = ['login.php', 'register.php', 'setup_check.php'];

    if (!has_app_access() && !in_array($currentPage, $publicPages, true)) {
        header('Location: login.php');
        exit;
    }

    $user = current_user();
    $isAuthPage = in_array($currentPage, ['login.php', 'register.php'], true);
    $htmlClass = $isAuthPage ? ' class="auth-page-root"' : '';
    $bodyClass = $isAuthPage ? ' class="auth-page' . ($currentPage === 'register.php' ? ' register-page' : '') . '"' : '';
    ?>
    <!doctype html>
    <html lang="en"<?= $htmlClass ?>>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
        <?php $assetVersion = (string)time(); ?>
        <link rel="stylesheet" href="assets/css/styles.css?v=<?= e($assetVersion) ?>">
        <link rel="stylesheet" href="assets/css/ui.css?v=<?= e($assetVersion) ?>">
    </head>
    <body<?= $bodyClass ?>>
        <header class="topbar">
            <div class="topbar-row">
                <a class="brand" href="index.php">
                    <span class="brand-mark">R2H</span>
                    <span>
                        <strong><?= e(APP_NAME) ?></strong>
                        <small>AI Household Nutrition Intelligence</small>
                    </span>
                </a>

                <?php if ($user): ?>
                    <button class="nav-toggle" type="button" aria-controls="main-nav" aria-expanded="false">
                        Menu
                    </button>

                    <form class="nav-search" action="search.php" method="get" role="search">
                        <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search reports, foods, modules">
                    </form>
                <?php endif; ?>

                <div class="topbar-tools">
                    <?php if ($user): ?>
                        <details class="quick-menu">
                            <summary>Actions</summary>
                            <div class="quick-panel">
                                <?php foreach (quick_actions() as $action): ?>
                                    <a href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <a class="status-chip system-chip" href="setup_check.php"><span></span> Ready</a>
                        <a class="status-chip user-chip account-chip" href="account.php">
                            <?= e($user['name']) ?> · <?= e(ucfirst((string)($user['role'] ?? 'user'))) ?>
                        </a>
                        <a class="status-chip logout-chip" href="logout.php">Logout</a>
                    <?php else: ?>
                        <a class="status-chip user-chip" href="login.php">Login</a>
                        <a class="status-chip user-chip" href="register.php">Register</a>
                    <?php endif; ?>
                    <button class="theme-toggle" type="button" data-theme-toggle>Mode</button>
                </div>
            </div>

            <?php if ($user): ?>
            <nav id="main-nav" class="main-nav" aria-label="Main navigation">
            <?php foreach (primary_nav_items() as $key => $item): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
                <?php $secondaryItems = secondary_nav_items(); ?>
                <details class="nav-more <?= array_key_exists($active, $secondaryItems) ? 'active' : '' ?>">
                    <summary>More</summary>
                    <div class="nav-more-panel">
                        <?php foreach ($secondaryItems as $key => $item): ?>
                            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                                <?= e($item['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            </nav>
            <?php endif; ?>
        </header>
        <main class="shell">
    <?php
}

function render_page_end(): void
{
    ?>
        </main>
        <script src="assets/js/app.js?v=<?= e((string)time()) ?>"></script>
    </body>
    </html>
    <?php
}

function page_hero(string $eyebrow, string $title, string $lede = '', string $actionHtml = ''): void
{
    ?>
    <section class="hero-panel">
        <div>
            <p class="eyebrow"><?= e($eyebrow) ?></p>
            <h1><?= e($title) ?></h1>
            <?php if ($lede !== ''): ?>
                <p class="lede"><?= e($lede) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actionHtml !== ''): ?>
            <div class="hero-actions"><?= $actionHtml ?></div>
        <?php endif; ?>
    </section>
    <?php
}

function page_steps(array $steps): void
{
    ?>
    <section class="ux-stepper" aria-label="Page steps">
        <?php foreach ($steps as $index => $step): ?>
            <article>
                <span><?= e((string)($index + 1)) ?></span>
                <strong><?= e($step['title'] ?? '') ?></strong>
                <small><?= e($step['text'] ?? '') ?></small>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}
