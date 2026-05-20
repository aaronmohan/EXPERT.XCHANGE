<?php
require_once 'session_config.php';
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Get and validate input data
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
        throw new Exception('All fields are required');
    }

    $name = trim($data['name']);
    $email = trim($data['email']);
    $password = $data['password'];

    // Validate name (only letters, spaces, and basic punctuation)
    if (!preg_match('/^[a-zA-Z\s\'-]+$/', $name)) {
        throw new Exception('Name can only contain letters, spaces, hyphens, and apostrophes');
    }

    // Start transaction
    $conn->begin_transaction();

    // Check if email already exists
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    
    if ($check_email->get_result()->num_rows > 0) {
        throw new Exception('Email already registered');
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $insert_user = $conn->prepare("INSERT INTO users (full_name, email, password, created_at) VALUES (?, ?, ?, NOW())");
    $insert_user->bind_param("sss", $name, $email, $hashed_password);
    
    if (!$insert_user->execute()) {
        throw new Exception('Failed to create user account');
    }

    $user_id = $conn->insert_id;

    // Add initial credits for new user
    $insert_credits = $conn->prepare("INSERT INTO user_credits (user_id, credits, transaction_type, description) VALUES (?, 500, 'EARNED', 'Initial signup bonus')");
    $insert_credits->bind_param("i", $user_id);
    
    if (!$insert_credits->execute()) {
        throw new Exception('Failed to add initial credits');
    }

    // Set session variables
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $name;
    $_SESSION['last_activity'] = time();

    // Commit transaction
    $conn->commit();

    // Return success response with redirect to profile setup
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'redirect' => 'profile-setup.html'
    ]);

} catch (Exception $e) {
    // Rollback transaction if active
    if ($conn && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Close prepared statements if they exist
if (isset($check_email)) $check_email->close();
if (isset($insert_user)) $insert_user->close();
if (isset($insert_credits)) $insert_credits->close();

// Close database connection
$conn->close();
?> 