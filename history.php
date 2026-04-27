<?php
/**
 * History API Endpoint for TruthLens
 * Returns analysis history from database as JSON
 */

header('Content-Type: application/json');

require_once 'db_helper.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

    // Get all articles (history)
    if ($action === 'list') {
        $articles = getAllArticles();
        
        // Format for frontend with input_type, input_data, result, confidence
        $history = array_map(function($article) {
            // Determine type and data from stored values
            $input_type = 'text'; // default
            $input_data = $article['text_content'];
            
            if (strpos($article['url'], 'http') === 0) {
                $input_type = 'url';
                $input_data = $article['url'];
            } elseif ($article['image_url']) {
                $input_type = 'image';
                $input_data = basename($article['image_url']);
            }
            
            // Extract analysis data - parse if it's stored as JSON
            $analysis_data = [];
            if (!empty($article['text_content']) && json_decode($article['text_content'], true)) {
                $analysis_data = json_decode($article['text_content'], true);
            }
            
            return [
                'id' => $article['id'],
                'input_type' => $input_type,
                'input_data' => substr($input_data, 0, 100), // Truncate long text
                'url' => $article['url'],
                'text' => substr($article['text_content'], 0, 200),
                'image' => $article['image_url'],
                'result' => $analysis_data['verdict'] ?? 'unclear',
                'confidence' => $analysis_data['confidence'] ?? 0,
                'score' => $analysis_data['score'] ?? 0,
                'credibility_score' => $analysis_data['score'] ?? 0,
                'created_at' => $article['created_at']
            ];
        }, $articles);

        http_response_code(200);
        echo json_encode($history);
    }

    // Get specific article
    elseif ($action === 'get') {
        $id = $_GET['id'] ?? $_POST['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }

        $article = getArticleById($id);
        
        if (!$article) {
            http_response_code(404);
            echo json_encode(['error' => 'Article not found']);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => $article
        ]);
    }

    // Delete article
    elseif ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }

        $result = deleteArticle($id);
        
        http_response_code($result['status'] === 'success' ? 200 : 500);
        echo json_encode($result);
    }

    // Clear history
    elseif ($action === 'clear') {
        $articles = getAllArticles();
        $deleted = 0;
        
        foreach ($articles as $article) {
            $result = deleteArticle($article['id']);
            if ($result['status'] === 'success') {
                $deleted++;
            }
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'History cleared',
            'deleted' => $deleted
        ]);
    }

    // Search
    elseif ($action === 'search') {
        $keyword = $_GET['q'] ?? $_POST['q'] ?? null;
        
        if (!$keyword) {
            http_response_code(400);
            echo json_encode(['error' => 'Search query required']);
            exit;
        }

        $results = searchArticles($keyword);
        
        // Format results
        $formatted = array_map(function($article) {
            $input_type = 'text';
            $input_data = $article['text_content'];
            
            if (strpos($article['url'], 'http') === 0) {
                $input_type = 'url';
                $input_data = $article['url'];
            } elseif ($article['image_url']) {
                $input_type = 'image';
                $input_data = basename($article['image_url']);
            }
            
            return [
                'id' => $article['id'],
                'input_type' => $input_type,
                'input_data' => substr($input_data, 0, 100),
                'created_at' => $article['created_at']
            ];
        }, $results);
        
        http_response_code(200);
        echo json_encode($formatted);
    }

    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
