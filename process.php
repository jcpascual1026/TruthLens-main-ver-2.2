<?php
/**
 * Process API Endpoint for TruthLens
 * Handles analyze requests from frontend (URL, text, image)
 * Returns JSON responses
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'error' => 'PHP Error: ' . $errstr
    ]);
    exit;
});

require_once 'db_helper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Accept both 'type' and 'input_type' parameter names
    $type = $_POST['type'] ?? $_POST['input_type'] ?? null;
    
    if (!$type) {
        http_response_code(400);
        echo json_encode(['error' => 'Type parameter required']);
        exit;
    }

    // Normalize type
    $type = strtolower($type);

    // Handle URL analysis
    if ($type === 'url') {
        $url = $_POST['url'] ?? $_POST['input_data'] ?? null;
        
        if (!$url) {
            http_response_code(400);
            echo json_encode(['error' => 'URL required']);
            exit;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL format']);
            exit;
        }

        $analysis = performAnalysis($url, 'url');
        // Store analysis data as JSON
        $analysis_json = json_encode($analysis);
        $result = saveArticle($url, $analysis_json, null);
        
        http_response_code(200);
        echo json_encode(formatResponse($analysis, $result));
    }

    // Handle text analysis
    elseif ($type === 'text') {
        $text = $_POST['text'] ?? $_POST['input_data'] ?? null;
        
        if (!$text) {
            http_response_code(400);
            echo json_encode(['error' => 'Text required']);
            exit;
        }

        if (strlen($text) < 10) {
            http_response_code(400);
            echo json_encode(['error' => 'Text too short (minimum 10 characters)']);
            exit;
        }

        $analysis = performAnalysis($text, 'text');
        // Store analysis data as JSON
        $analysis_json = json_encode($analysis);
        $result = saveArticle('text-analysis', $analysis_json, null);
        
        http_response_code(200);
        echo json_encode(formatResponse($analysis, $result));
    }

    // Handle image analysis
    elseif ($type === 'image') {
        $imageData = $_POST['image_data'] ?? null;
        
        if (!$imageData && !isset($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Image file or image data required']);
            exit;
        }

        $filename = null;
        
        // Handle base64 image data from frontend
        if ($imageData && strpos($imageData, 'data:image') === 0) {
            if (!is_dir('uploads')) {
                mkdir('uploads', 0755, true);
            }
            
            $data = explode(',', $imageData);
            $binary = base64_decode($data[1]);
            $filename = 'uploads/' . uniqid() . '.png';
            
            if (!file_put_contents($filename, $binary)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save image']);
                exit;
            }
        }
        // Handle file upload
        elseif (isset($_FILES['image'])) {
            $file = $_FILES['image'];
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Image upload error']);
                exit;
            }

            if (!in_array($file['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid image type']);
                exit;
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'Image size exceeds 5MB']);
                exit;
            }

            if (!is_dir('uploads')) {
                mkdir('uploads', 0755, true);
            }

            $filename = 'uploads/' . uniqid() . '_' . basename($file['name']);
            
            if (!move_uploaded_file($file['tmp_name'], $filename)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to upload image']);
                exit;
            }
        }

        if (!$filename) {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to process image']);
            exit;
        }

        $analysis = performAnalysis($filename, 'image');
        // Store analysis data as JSON
        $analysis_json = json_encode($analysis);
        $result = saveArticle('image-analysis', $analysis_json, $filename);
        
        http_response_code(200);
        echo json_encode(formatResponse($analysis, $result));
    }

    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

function loadDomainConfig() {
    $configPath = __DIR__ . '/trusted_domains.json';
    if (!file_exists($configPath)) {
        return ['trusted' => [], 'suspicious' => []];
    }
    $json = file_get_contents($configPath);
    $config = json_decode($json, true);
    return is_array($config) ? $config : ['trusted' => [], 'suspicious' => []];
}

function checkDomain($url) {
    $config = loadDomainConfig();
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $host = preg_replace('/^www\./', '', $host);
    
    if (in_array($host, $config['trusted'] ?? [], true)) {
        return ['status' => 'trusted', 'reason' => 'This domain is from a well-known and trusted news source.'];
    }
    if (in_array($host, $config['suspicious'] ?? [], true)) {
        return ['status' => 'suspicious', 'reason' => 'This domain is known for publishing unreliable or fake news.'];
    }
    return ['status' => 'unknown', 'reason' => 'This domain is not in our trusted or suspicious lists. Exercise caution.'];
}

/**
 * Perform analysis on content
 * TODO: Replace with your AI analysis engine
 */
function performAnalysis($content, $type) {
    $verdict = ['real', 'fake', 'unclear'][mt_rand(0, 2)];
    $score = mt_rand(40, 95);
    $domainInfo = null;
    
    if ($type === 'url') {
        $domainInfo = checkDomain($content);
        if ($domainInfo['status'] === 'trusted') {
            $score = min(95, $score + 15);
            $verdict = 'real';
        } elseif ($domainInfo['status'] === 'suspicious') {
            $score = max(40, $score - 20);
            $verdict = 'fake';
        }
    }
    
    return [
        'verdict' => $verdict,
        'score' => $score,
        'confidence' => $score,
        'summary' => 'Analysis pending - integrate with AI engine',
        'details' => [
            'type' => $type,
            'analyzed_at' => date('Y-m-d H:i:s'),
            'confidence_percent' => $score . '%'
        ],
        'domain_info' => $domainInfo
    ];
}

/**
 * Format response for frontend
 */
function formatResponse($analysis, $db_result) {
    $response = [
        'result' => $analysis['verdict'] ?? 'unclear',
        'score' => $analysis['score'] ?? 0,
        'confidence' => $analysis['confidence'] ?? 0,
        'label' => ucfirst($analysis['verdict'] ?? 'Unknown'),
        'credibility_score' => $analysis['score'] ?? 0,
        'analysis' => $analysis,
        'saved' => $db_result['status'] === 'success',
        'id' => $db_result['id'] ?? null
    ];
    
    if (!empty($analysis['domain_info'])) {
        $response['domain_status'] = $analysis['domain_info']['status'];
        $response['domain_reason'] = $analysis['domain_info']['reason'];
    }
    
    return $response;
}
