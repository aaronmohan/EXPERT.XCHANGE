<?php
// Only configure session if it hasn't started yet
if (session_status() === PHP_SESSION_NONE) {
    // Session security configuration
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
    ini_set('session.cookie_lifetime', 86400); // 24 hours

    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set to true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Lax'  // Changed from Strict to Lax for better compatibility
    ]);

    // Start the session
    session_start();
}

// Set last activity time if not set
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Generate CSRF token if not exists
if (!isset($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}
?> 