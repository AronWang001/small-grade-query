<?php

$adminPageView = match ($tab) {
    'dashboard' => __DIR__ . '/dashboard.php',
    'projects' => __DIR__ . '/projects.php',
    'records' => __DIR__ . '/records.php',
    'imports' => __DIR__ . '/imports.php',
    'new_query' => __DIR__ . '/new-query.php',
    'logs' => __DIR__ . '/logs.php',
    'security' => __DIR__ . '/security.php',
    'admin_users' => $isSuperAdmin ? dirname(__DIR__) . '/admin-users.php' : null,
    'login_security' => $isSuperAdmin ? dirname(__DIR__) . '/login-security.php' : null,
    'system_settings' => $isSuperAdmin ? dirname(__DIR__) . '/system-settings.php' : null,
    default => null,
};

if (is_string($adminPageView)) {
    require $adminPageView;
}
