<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Controllers/AuthController.php';

send_security_headers();
start_secure_session();
if (current_admin() !== null) {
    redirect('admin.php');
}

$state = AuthController::handle();
$error = $state['error'];
$identifier = $state['identifier'];
$turnstile = $state['turnstile'];
$flash = consume_flash();
if ($error === null && is_array($flash)) {
    $error = (string) ($flash['message'] ?? '');
}

require __DIR__ . '/app/Views/auth/login.php';
