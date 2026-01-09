<?php
require_once 'config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS user_ratings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rated_user_id INT NOT NULL,
        rater_user_id INT NOT NULL,
        stars INT NOT NULL CHECK (stars >= 1 AND stars <= 5),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rated_user_id) REFERENCES users(id),
        FOREIGN KEY (rater_user_id) REFERENCES users(id)
    )";

    if ($conn->query($sql) === TRUE) {
        echo "Table user_ratings created successfully";
    } else {
        echo "Error creating table: " . $conn->error;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

$conn->close();
?> 