<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function nav_items(): array
{
    return [
        'upload' => ['label' => 'Upload', 'href' => 'index.php'],
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
        'analytics' => ['label' => 'Analytics', 'href' => 'analytics.php'],
        'history' => ['label' => 'History', 'href' => 'history.php'],
        'ocr' => ['label' => 'OCR Review', 'href' => 'ocr_review.php'],
        'foods' => ['label' => 'Food Database', 'href' => 'food_database.php'],
        'simulator' => ['label' => 'Simulator', 'href' => 'simulator.php'],
        'graph' => ['label' => 'Knowledge Graph', 'href' => 'knowledge_graph.php'],
        'family' => ['label' => 'Family Profile', 'href' => 'family.php'],
        'account' => ['label' => 'Account', 'href' => 'account.php'],
        'reports' => ['label' => 'Reports', 'href' => 'reports.php'],
        'admin' => ['label' => 'Admin', 'href' => 'admin.php'],
        'method' => ['label' => 'Methodology', 'href' => 'methodology.php'],
        'setup' => ['label' => 'Setup Check', 'href' => 'setup_check.php'],
    ];
}

function primary_nav_items(): array
{
    $primaryKeys = ['upload', 'dashboard', 'analytics', 'history', 'family', 'reports'];
    return array_intersect_key(nav_items(), array_flip($primaryKeys));
}

function secondary_nav_items(): array
{
    return array_diff_key(nav_items(), primary_nav_items());
}

function quick_actions(): array
{
    return [
        ['label' => 'Analyze Receipt', 'href' => 'index.php'],
        ['label' => 'Correct OCR Text', 'href' => 'ocr_review.php'],
        ['label' => 'Open Latest Report', 'href' => 'dashboard.php'],
        ['label' => 'Print Report', 'href' => 'reports.php'],
        ['label' => 'Export JSON', 'href' => 'api/export_report.php?format=json'],
        ['label' => 'Export CSV', 'href' => 'api/export_report.php?format=csv'],
        ['label' => 'System Check', 'href' => 'setup_check.php'],
    ];
}

function render_page_start(string $title, string $active = 'dashboard'): void
{
    $user = current_user();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
        <?php $assetVersion = (string)time(); ?>
        <link rel="stylesheet" href="assets/css/styles.css?v=<?= e($assetVersion) ?>">
        <link rel="stylesheet" href="assets/css/ui.css?v=<?= e($assetVersion) ?>">
    </head>
    <body>
        <header class="topbar">
            <div class="topbar-row">
                <a class="brand" href="index.php">
                    <span class="brand-mark">R2H</span>
                    <span>
                        <strong><?= e(APP_NAME) ?></strong>
                        <small>AI Household Nutrition Intelligence</small>
                    </span>
                </a>

                <button class="nav-toggle" type="button" aria-controls="main-nav" aria-expanded="false">
                    Menu
                </button>

                <form class="nav-search" action="search.php" method="get" role="search">
                    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search reports, foods, modules">
                </form>

                <div class="topbar-tools">
                    <details class="quick-menu">
                        <summary>Actions</summary>
                        <div class="quick-panel">
                            <?php foreach (quick_actions() as $action): ?>
                                <a href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <a class="status-chip" href="setup_check.php"><span></span> OK</a>
                    <?php if ($user): ?>
                        <a class="status-chip user-chip" href="account.php"><?= e($user['name']) ?></a>
                    <?php else: ?>
                        <a class="status-chip user-chip" href="login.php">Login</a>
                    <?php endif; ?>
                    <button class="theme-toggle" type="button" data-theme-toggle>Mode</button>
                </div>
            </div>

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
