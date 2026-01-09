<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    header("Location: home.html");
    exit();
}

$email = $_SESSION['user_email'];
$success_message = '';
$error_message = '';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Connection failed: " . ($conn->connect_error ?? "Database connection not established"));
}

// Get current user data
try {
    // First get user and profile data
    $user_query = "SELECT u.id, u.email, u.full_name, u.qualification, 
                   p.phone_number, p.experience_level
                   FROM users u 
                   LEFT JOIN user_profiles p ON u.id = p.user_id
                   WHERE u.email = ?";

    if (!($stmt = $conn->prepare($user_query))) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    if (!$stmt->bind_param("s", $email)) {
        throw new Exception("Failed to bind parameters: " . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User not found");
    }

    // Now get skills separately
    $skills_query = "SELECT skill_name, proficiency_level FROM skills WHERE user_id = ? ORDER BY skill_name";
    if (!($stmt = $conn->prepare($skills_query))) {
        throw new Exception("Failed to prepare skills statement: " . $conn->error);
    }

    if (!$stmt->bind_param("i", $user['id'])) {
        throw new Exception("Failed to bind skills parameters: " . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception("Failed to execute skills query: " . $stmt->error);
    }

    $skills_result = $stmt->get_result();
    $skills = array();
    while ($skill = $skills_result->fetch_assoc()) {
        $skills[] = $skill;
    }
    $stmt->close();

} catch (Exception $e) {
    die("Error loading user data: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user_id = $user['id'];
        
        // Update user details including qualification
        $update_user = "UPDATE users SET full_name = ?, qualification = ? WHERE id = ?";
        if (!($stmt = $conn->prepare($update_user))) {
            throw new Exception("Failed to prepare update statement: " . $conn->error);
        }
        
        $full_name = $_POST['full_name'];
        $qualification = $_POST['qualification'];
        
        if (!$stmt->bind_param("ssi", $full_name, $qualification, $user_id)) {
            throw new Exception("Failed to bind update parameters: " . $stmt->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute update: " . $stmt->error);
        }
        $stmt->close();

        // Update profile
        $update_profile = "INSERT INTO user_profiles (user_id, phone_number, experience_level) 
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE 
                          phone_number = VALUES(phone_number),
                          experience_level = VALUES(experience_level)";
        
        if (!($stmt = $conn->prepare($update_profile))) {
            throw new Exception("Failed to prepare profile statement");
        }
        
        $phone = $_POST['phone'];
        $experience_level = $_POST['experience_level'];
        
        if (!$stmt->bind_param("iss", $user_id, $phone, $experience_level)) {
            throw new Exception("Failed to bind profile parameters");
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update profile");
        }
        $stmt->close();

        // Handle skills
        if (isset($_POST['skill_names']) && isset($_POST['skill_levels'])) {
            // Delete existing skills
            $delete_skills = "DELETE FROM skills WHERE user_id = ?";
            if (!($stmt = $conn->prepare($delete_skills))) {
                throw new Exception("Failed to prepare delete statement");
            }
            
            if (!$stmt->bind_param("i", $user_id)) {
                throw new Exception("Failed to bind delete parameter");
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete old skills");
            }
            $stmt->close();

            // Insert new skills with their levels
            $insert_skill = "INSERT INTO skills (user_id, skill_name, proficiency_level) VALUES (?, ?, ?)";
            if (!($stmt = $conn->prepare($insert_skill))) {
                throw new Exception("Failed to prepare insert statement");
            }

            foreach ($_POST['skill_names'] as $index => $skill_name) {
                $skill_name = trim($skill_name);
                if (!empty($skill_name)) {
                    $proficiency_level = $_POST['skill_levels'][$index];
                    if (!$stmt->bind_param("iss", $user_id, $skill_name, $proficiency_level)) {
                        throw new Exception("Failed to bind skill parameters");
                    }
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert skill: " . $stmt->error);
                    }
                }
            }
            $stmt->close();
        }

        // Redirect back to profile page instead of edit page
        $success_message = "Profile updated successfully!";
        header("Location: profile.php");
        exit();
    } catch (Exception $e) {
        $error_message = "Error updating profile: " . $e->getMessage();
        error_log($error_message);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Expert.Xchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fb;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .edit-profile-form {
            background-color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .form-header h1 {
            font-size: 24px;
            color: #333;
        }

        .back-btn {
            background-color: #f0f4ff;
            color: #2a7de1;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background-color: #2a7de1;
            color: white;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #666;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #2a7de1;
        }

        .submit-btn {
            background-color: #2a7de1;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }

        .submit-btn:hover {
            background-color: #1c68c5;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .skills-container {
            margin-top: 20px;
        }
        
        .skill-entry {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .skill-entry input[type="text"] {
            flex: 2;
        }
        
        .skill-entry select {
            flex: 1;
        }
        
        .remove-skill {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .remove-skill:hover {
            background-color: #c82333;
        }
        
        .add-skill {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
            transition: background-color 0.3s;
        }
        
        .add-skill:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <form class="edit-profile-form" method="POST" action="" id="profileForm">
            <div class="form-header">
                <h1>Edit Profile</h1>
                <a href="profile.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Profile
                </a>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                <small style="color: #666;">Email cannot be changed</small>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="qualification">Qualification</label>
                <input type="text" id="qualification" name="qualification" value="<?php echo htmlspecialchars($user['qualification'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="experience_level">Experience Level</label>
                <select id="experience_level" name="experience_level" required>
                    <option value="Beginner" <?php echo ($user['experience_level'] ?? '') === 'Beginner' ? 'selected' : ''; ?>>Beginner</option>
                    <option value="Intermediate" <?php echo ($user['experience_level'] ?? '') === 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                    <option value="Advanced" <?php echo ($user['experience_level'] ?? '') === 'Advanced' ? 'selected' : ''; ?>>Advanced</option>
                    <option value="Expert" <?php echo ($user['experience_level'] ?? '') === 'Expert' ? 'selected' : ''; ?>>Expert</option>
                </select>
            </div>

            <div class="form-group">
                <label>Skills</label>
                <div class="skills-container" id="skillsContainer">
                    <?php foreach($skills as $skill): ?>
                    <div class="skill-entry">
                        <input type="text" name="skill_names[]" value="<?php echo htmlspecialchars($skill['skill_name']); ?>" placeholder="Skill name" required>
                        <select name="skill_levels[]" required>
                            <option value="Beginner" <?php echo $skill['proficiency_level'] === 'Beginner' ? 'selected' : ''; ?>>Beginner</option>
                            <option value="Intermediate" <?php echo $skill['proficiency_level'] === 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                            <option value="Advanced" <?php echo $skill['proficiency_level'] === 'Advanced' ? 'selected' : ''; ?>>Advanced</option>
                            <option value="Expert" <?php echo $skill['proficiency_level'] === 'Expert' ? 'selected' : ''; ?>>Expert</option>
                        </select>
                        <button type="button" class="remove-skill" onclick="removeSkill(this)">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-skill" onclick="addSkill()">Add Skill</button>
            </div>

            <button type="submit" class="submit-btn">Save Changes</button>
        </form>
    </div>

    <script>
        function addSkill() {
            const container = document.getElementById('skillsContainer');
            const newEntry = document.createElement('div');
            newEntry.className = 'skill-entry';
            newEntry.innerHTML = `
                <input type="text" name="skill_names[]" placeholder="Skill name" required>
                <select name="skill_levels[]" required>
                    <option value="Beginner">Beginner</option>
                    <option value="Intermediate">Intermediate</option>
                    <option value="Advanced">Advanced</option>
                    <option value="Expert">Expert</option>
                </select>
                <button type="button" class="remove-skill" onclick="removeSkill(this)">Remove</button>
            `;
            container.appendChild(newEntry);
        }

        function removeSkill(button) {
            const entry = button.parentElement;
            entry.remove();
        }
    </script>
</body>
</html> 