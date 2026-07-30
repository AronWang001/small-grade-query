<?php declare(strict_types=1);
// Shared auth page layout - used by both login and register
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(APP_ROOT . '/assets/app.css') ?>">
    <?php if ($turnstile['enabled']): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" defer></script>
    <?php endif; ?>
    <script src="assets/app.js?v=<?= (int) filemtime(APP_ROOT . '/assets/app.js') ?>" defer></script>
</head>
<body class="auth-page <?= $pageClass ?? '' ?>">
<div class="decoration"></div><div class="decoration bottom"></div>
<?php require __DIR__ . '/components/auth-toast.php'; ?>
<main class="container">
    <header class="header">
        <h1><?= e($pageTitle) ?></h1>
        <p class="subtitle"><?= e($pageSubtitle) ?></p>
    </header>
    <section class="card auth-card">
        <?= $content ?>
    </section>
    <footer class="footer">
        GitHub开源：
        <a href="https://github.com/AronWang001/small-grade-query"
           target="_blank"
           rel="noopener noreferrer">AronWang001/small-grade-query</a>
    </footer>
</main>
</body>
</html>
