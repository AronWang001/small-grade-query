<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

send_security_headers();
start_secure_session();
$admin = current_admin();
if ($admin === null) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    audit_log('admin_logout', 'admin_user', (int) $admin['id']);
    destroy_session();
    redirect('login.php');
}
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>退出登录</title><link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/app.css') ?>"></head>
<body>
<div class="decoration"></div><div class="decoration bottom"></div>
<main class="container">
    <header class="header"><h1>退出登录</h1></header>
    <section class="card">
        <p class="subtitle">确认结束当前管理员会话？</p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="btn" type="submit">确认退出</button>
        </form>
    </section>
    <footer class="footer">
        GitHub开源：
        <a href="https://github.com/AronWang001/grade-query-system"
           target="_blank"
           rel="noopener noreferrer">AronWang001/grade-query-system</a>
    </footer>
</main>
</body>
</html>
