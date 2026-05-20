<?php
require_once 'session_config.php';
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

try {
    // Log the start of login attempt
    error_log("Login attempt started");
    
    // Get request data (handle both JSON and form data)
    if ($_SERVER['CONTENT_TYPE'] === 'application/json') {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid request format');
        }
    } else {
        $data = $_POST;
    }
    
    if (!isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Email and password are required');
    }

    $email = trim($data['email']);
    $password = $data['password'];
    
    error_log("Attempting login for email: " . $email);

    // Check database connection
    if ($conn->connect_error) {
        throw new Exception('Database connection failed');
    }

    // Get user details with a more detailed query
    $stmt = $conn->prepare("
        SELECT u.id, u.email, u.password, u.full_name, 
               COALESCE(uc.credits, 0) as credits,
               u.created_at
        FROM users u 
        LEFT JOIN user_credits uc ON u.id = uc.user_id 
        WHERE u.email = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Database error occurred');
    }

    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        throw new Exception('Database error occurred');
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Invalid email or password');
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Verify password with support for both old and new formats
    $passwordValid = password_verify($password, $user['password']);
    
    // If password verification fails, try the old format
    if (!$passwordValid) {
        // For old accounts, update to new password format
        $oldHash = hash('sha256', $password); // This matches the old format
        if ($oldHash === $user['password']) {
            // Password is valid in old format, update to new format
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newHash, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
            $passwordValid = true;
        }
    }
    
    if (!$passwordValid) {
        error_log("Password verification failed for user: " . $email);
        throw new Exception('Invalid email or password');
    }

    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['credits'] = $user['credits'];
    $_SESSION['last_activity'] = time();

    error_log("Login successful for user: " . $email);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => 'profile.php'
    ]);

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 