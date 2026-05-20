<?php
session_start();
require_once 'config.php';

// Determine if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

try {
    if (!isset($_SESSION['user_email'])) {
        throw new Exception('Not logged in');
    }

    // Get user ID from email
    $email = $_SESSION['user_email'];
    $user_query = "SELECT id FROM users WHERE email = ?";
    $stmt = $conn->prepare($user_query);
    if (!$stmt) {
        throw new Exception("Failed to prepare user query: " . $conn->error);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User not found");
    }

    $user_id = $user['id'];

    // Start transaction
    $conn->begin_transaction();

    // Update user profile
    $update_profile = "INSERT INTO user_profiles (user_id, phone_number, experience_level) 
                      VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE 
                      phone_number = VALUES(phone_number),
                      experience_level = VALUES(experience_level)";
    
    $stmt = $conn->prepare($update_profile);
    if (!$stmt) {
        throw new Exception("Failed to prepare profile update: " . $conn->error);
    }

    $phone_number = $_POST['phone_number'];
    $experience_level = $_POST['experience_level'];
    
    $stmt->bind_param("iss", $user_id, $phone_number, $experience_level);
    $stmt->execute();
    $stmt->close();

    // Update qualification in users table
    $update_qualification = "UPDATE users SET qualification = ? WHERE id = ?";
    $stmt = $conn->prepare($update_qualification);
    if (!$stmt) {
        throw new Exception("Failed to prepare qualification update: " . $conn->error);
    }

    $qualification = $_POST['qualification'];
    $stmt->bind_param("si", $qualification, $user_id);
    $stmt->execute();
    $stmt->close();

    // Handle skills with proficiency levels
    if (isset($_POST['skill_names']) && isset($_POST['skill_levels']) && 
        is_array($_POST['skill_names']) && is_array($_POST['skill_levels'])) {
        
        // Delete existing skills
        $delete_skills = "DELETE FROM skills WHERE user_id = ?";
        $stmt = $conn->prepare($delete_skills);
        if (!$stmt) {
            throw new Exception("Failed to prepare delete skills query: " . $conn->error);
        }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to delete existing skills: " . $stmt->error);
        }
        $stmt->close();

        // Insert new skills with proficiency levels
        $insert_skill = "INSERT INTO skills (user_id, skill_name, proficiency_level) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert_skill);
        if (!$stmt) {
            throw new Exception("Failed to prepare insert skills query: " . $conn->error);
        }

        foreach ($_POST['skill_names'] as $index => $skill_name) {
            $skill_name = trim($skill_name);
            if (!empty($skill_name) && isset($_POST['skill_levels'][$index])) {
                $proficiency_level = $_POST['skill_levels'][$index];
                
                if (!$stmt->bind_param("iss", $user_id, $skill_name, $proficiency_level)) {
                    throw new Exception("Failed to bind skill parameters: " . $stmt->error);
                }
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert skill: " . $stmt->error);
                }
            }
        }
        $stmt->close();
    }

    // Commit transaction
    $conn->commit();

    if ($isAjax) {
        // Return JSON response for AJAX requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Profile saved successfully',
            'redirect' => 'profile.php'
        ]);
    } else {
        // Regular form submission - redirect directly
        header('Location: profile.php');
    }
    exit();

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
    }
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        $_SESSION['error'] = $e->getMessage();
        header('Location: profile-setup.html');
    }
    exit();
}
?> 