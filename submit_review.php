<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Debug logging
error_log("Review submission started");
error_log("Session data: " . print_r($_SESSION, true));

if (!isset($_SESSION['user_email'])) {
    error_log("User not logged in");
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get the POST data
    $input = file_get_contents('php://input');
    error_log("Received input: " . $input);
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (!isset($data['rated_user_id']) || !isset($data['rating'])) {
        throw new Exception('Missing required parameters');
    }

    // Get current user's ID
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $_SESSION['user_email']);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_user = $result->fetch_assoc();
    $stmt->close();

    if (!$current_user) {
        throw new Exception('Current user not found');
    }

    $rated_user_id = intval($data['rated_user_id']);
    $rating = intval($data['rating']);
    $rater_user_id = $current_user['id'];

    error_log("Processing rating: User {$rater_user_id} rating user {$rated_user_id} with {$rating} stars");

    // Validate rating
    if ($rating < 1 || $rating > 5) {
        throw new Exception('Invalid rating value (must be between 1 and 5)');
    }

    // Start transaction
    $conn->begin_transaction();

    // Check if user has already rated this exchange recently
    $check_existing = "SELECT id FROM user_ratings 
                      WHERE rated_user_id = ? AND rater_user_id = ? 
                      AND created_at > NOW() - INTERVAL 1 HOUR";
    $stmt = $conn->prepare($check_existing);
    $stmt->bind_param("ii", $rated_user_id, $rater_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        throw new Exception("You have already submitted a rating for this user recently");
    }

    // Insert the rating
    $insert_query = "INSERT INTO user_ratings (rated_user_id, rater_user_id, stars) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($insert_query);
    if (!$stmt) {
        throw new Exception("Failed to prepare rating insertion: " . $conn->error);
    }
    
    $stmt->bind_param("iii", $rated_user_id, $rater_user_id, $rating);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert rating: " . $stmt->error);
    }
    $stmt->close();

    // Update user's average rating
    $update_avg = "UPDATE users u 
                   SET rating = (
                       SELECT AVG(stars) 
                       FROM user_ratings 
                       WHERE rated_user_id = u.id
                   )
                   WHERE id = ?";
    $stmt = $conn->prepare($update_avg);
    $stmt->bind_param("i", $rated_user_id);
    $stmt->execute();
    $stmt->close();

    // Get rater's name for notification
    $get_rater = "SELECT full_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($get_rater);
    $stmt->bind_param("i", $rater_user_id);
    $stmt->execute();
    $rater = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Create notification for rated user
    $notification_message = "{$rater['full_name']} has rated your skill exchange {$rating} stars!";
    $create_notification = "INSERT INTO notifications (user_id, message, type, is_read) 
                          VALUES (?, ?, 'new_rating', 0)";
    $stmt = $conn->prepare($create_notification);
    $stmt->bind_param("is", $rated_user_id, $notification_message);
    $stmt->execute();
    $stmt->close();

    // Commit transaction
    $conn->commit();
    
    error_log("Rating submitted successfully");
    echo json_encode([
        'success' => true,
        'message' => 'Rating submitted successfully'
    ]);

} catch (Exception $e) {
    error_log("Error submitting rating: " . $e->getMessage());
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 