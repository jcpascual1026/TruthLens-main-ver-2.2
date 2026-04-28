<?php
/**
 * Database Helper Functions for TruthLens
 * Handles all database operations (CRUD)
 */

require_once 'config.php';

/**
 * Save article to database
 * @param string $url - Article URL
 * @param string $text - Article text content
 * @param string $image_url - URL to the image (preferred method)
 * @param binary $image_blob - Image binary data (optional)
 * @return array - Result array with status and message
 */
function saveArticle($url, $text, $image_url = null, $image_blob = null) {
    global $conn;
    
    // Check current count and clean up if needed
    $count_result = $conn->query("SELECT COUNT(*) as count FROM articles");
    if ($count_result) {
        $count = $count_result->fetch_assoc()['count'];
        if ($count >= 50) {
            // Delete oldest articles to keep only 49 (to make room for the new one)
            $conn->query("DELETE FROM articles ORDER BY created_at ASC LIMIT " . ($count - 49));
        }
    }
    
    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("INSERT INTO articles (url, text_content, image_url, image_blob) VALUES (?, ?, ?, ?)");
    
    if (!$stmt) {
        return ['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error];
    }
    
    // Bind parameters (s = string, b = blob)
    if ($image_blob) {
        $stmt->bind_param('ssbs', $url, $text, $image_url, $image_blob);
    } else {
        $stmt->bind_param('ssss', $url, $text, $image_url, $image_blob);
    }
    
    if ($stmt->execute()) {
        $insert_id = $conn->insert_id;
        $stmt->close();
        return ['status' => 'success', 'message' => 'Article saved successfully', 'id' => $insert_id];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => 'Execute failed: ' . $error];
    }
}

/**
 * Get article by ID
 * @param int $id - Article ID
 * @return array - Article data or null
 */
function getArticleById($id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $article = $result->fetch_assoc();
    
    $stmt->close();
    return $article;
}

/**
 * Get all articles
 * @return array - Array of all articles
 */
function getAllArticles() {
    global $conn;
    
    $result = $conn->query("SELECT id, url, text_content, image_url, created_at FROM articles ORDER BY created_at DESC LIMIT 50");
    
    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

/**
 * Update article
 * @param int $id - Article ID
 * @param string $url - Article URL
 * @param string $text - Article text content
 * @param string $image_url - URL to the image
 * @return array - Result array
 */
function updateArticle($id, $url, $text, $image_url = null) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE articles SET url = ?, text_content = ?, image_url = ? WHERE id = ?");
    
    if (!$stmt) {
        return ['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error];
    }
    
    $stmt->bind_param('sssi', $url, $text, $image_url, $id);
    
    if ($stmt->execute()) {
        return ['status' => 'success', 'message' => 'Article updated successfully'];
    } else {
        return ['status' => 'error', 'message' => 'Execute failed: ' . $stmt->error];
    }
    
    $stmt->close();
}

/**
 * Delete article by ID
 * @param int $id - Article ID
 * @return array - Result array
 */
function deleteArticle($id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM articles WHERE id = ?");
    
    if (!$stmt) {
        return ['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error];
    }
    
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        return ['status' => 'success', 'message' => 'Article deleted successfully'];
    } else {
        return ['status' => 'error', 'message' => 'Execute failed: ' . $stmt->error];
    }
    
    $stmt->close();
}

/**
 * Search articles by keyword
 * @param string $keyword - Search keyword
 * @return array - Array of matching articles
 */
function searchArticles($keyword) {
    global $conn;
    
    $search_term = '%' . $keyword . '%';
    $stmt = $conn->prepare("SELECT * FROM articles WHERE url LIKE ? OR text_content LIKE ? ORDER BY created_at DESC");
    $stmt->bind_param('ss', $search_term, $search_term);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $articles = [];
    
    while ($row = $result->fetch_assoc()) {
        $articles[] = $row;
    }
    
    $stmt->close();
    return $articles;
}

/**
 * Upload and save image file
 * @param file $_FILES array for image
 * @param int $article_id - Article ID to associate with image
 * @return array - Result array with image path or blob
 */
function uploadImage($file_input_name, $article_id) {
    global $conn;
    
    if (!isset($_FILES[$file_input_name])) {
        return ['status' => 'error', 'message' => 'No file uploaded'];
    }
    
    $file = $_FILES[$file_input_name];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        return ['status' => 'error', 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP allowed'];
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'File size exceeds 5MB limit'];
    }
    
    // Create images directory if it doesn't exist
    if (!is_dir('uploads')) {
        mkdir('uploads', 0755, true);
    }
    
    // Generate unique filename
    $filename = 'uploads/' . uniqid() . '_' . basename($file['name']);
    
    if (move_uploaded_file($file['tmp_name'], $filename)) {
        // Update article with image URL
        $stmt = $conn->prepare("UPDATE articles SET image_url = ? WHERE id = ?");
        $stmt->bind_param('si', $filename, $article_id);
        
        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'Image uploaded successfully', 'path' => $filename];
        } else {
            return ['status' => 'error', 'message' => 'Failed to update database'];
        }
        
        $stmt->close();
    } else {
        return ['status' => 'error', 'message' => 'Failed to move uploaded file'];
    }
}
?>
