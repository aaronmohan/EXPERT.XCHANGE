<?php
session_start();
require_once 'config.php';

// Check if it's an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

try {
    if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
        // Expire the current session token
        $expire_session = $conn->prepare("UPDATE user_sessions SET expired = 1, expired_at = NOW() WHERE user_id = ? AND session_token = ? AND expired = 0");
        $expire_session->bind_param("is", $_SESSION['user_id'], $_SESSION['session_token']);
        $expire_session->execute();
    }

    // Clear all session variables
    $_SESSION = array();

    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }

    // Destroy the session
    session_destroy();

    if ($isAjax) {
        // If it's an AJAX request, return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Logged out successfully',
            'redirect' => 'home.html'
        ]);
    } else {
        // If it's a regular request, redirect to home page
        header('Location: home.html');
    }
    exit();
} catch (Exception $e) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        // If something goes wrong, still try to redirect
        header('Location: home.html');
    }
    exit();
}
?> 