<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Controllers/RegistrationController.php';

send_security_headers();
start_secure_session();
if (current_admin() !== null) {
    redirect('admin.php');
}

$state = RegistrationController::handle();
$error = $state['error'];
$success = $state['success'];
$input = $state['input'];
$turnstile = $state['turnstile'];
$fieldErrors = $state['field_errors'] ?? [];
$emailVerificationEnabled = $state['email_verification_enabled'] ?? false;
$turnstileReady = $state['turnstile_ready'] ?? false;

require __DIR__ . '/app/Views/auth/register.php';
