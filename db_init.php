<?php
// Storage initialization with MySQL/PDO fallback to JSON.
// The application stores analysis history in SQL when available.

function getHistoryFilePath() {
    return __DIR__ . '/results.json';
}

function initSqlDatabase() {
    $dbName = 'fake_news_db';
    $charset = 'utf8mb4';

    $mysqliCreated = false;
    $pdoCreated = false;

    // Try mysqli first — connect without DB to create it
    if (class_exists('mysqli')) {
        $mysqli = @new mysqli('localhost', 'root', '');
        if (!$mysqli->connect_error) {
            $mysqli->set_charset($charset);
            $mysqli->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
            $mysqli->select_db($dbName);
            $mysqli->query("CREATE TABLE IF NOT EXISTS results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                input_type VARCHAR(50) NOT NULL,
                input_data TEXT NOT NULL,
                result VARCHAR(20) NOT NULL,
                confidence DECIMAL(5,2) NOT NULL,
                image LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE {$charset}_unicode_ci");
            $mysqliCreated = true;
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            return $mysqli;
        }
    }

    // Fallback to PDO — connect without DB to create it
    if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
            $pdo->exec("USE `$dbName`");
            $pdo->exec("CREATE TABLE IF NOT EXISTS results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                input_type VARCHAR(50) NOT NULL,
                input_data TEXT NOT NULL,
                result VARCHAR(20) NOT NULL,
                confidence DECIMAL(5,2) NOT NULL,
                image LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE {$charset}_unicode_ci");
            $pdoCreated = true;
            return $pdo;
        } catch (PDOException $e) {
            error_log('initSqlDatabase (PDO) failed: ' . $e->getMessage());
            return null;
        }
    }

    return null;
}

function isSqlAvailable() {
    $conn = getSqlConnection();
    if ($conn instanceof mysqli) {
        $conn->close();
        return true;
    }
    if ($conn instanceof PDO) {
        $conn = null;
        return true;
    }
    return false;
}

function getSqlConnection() {
    $dbName = 'fake_news_db';
    $charset = 'utf8mb4';

    if (class_exists('mysqli')) {
        $mysqli = @new mysqli('localhost', 'root', '', $dbName);
        if (!$mysqli->connect_error) {
            $mysqli->set_charset($charset);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            return $mysqli;
        }
        error_log('getSqlConnection (mysqli) failed: ' . $mysqli->connect_error . '. Attempting auto-initialization...');
    }

    if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=$dbName;charset=$charset", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            error_log('getSqlConnection (PDO) failed: ' . $e->getMessage() . '. Attempting auto-initialization...');
        }
    }

    // Auto-initialize database and table if normal connection failed
    $initialized = initSqlDatabase();
    if ($initialized) {
        return $initialized;
    }

    return null;
}

function ensureHistoryStorage() {
    $filePath = getHistoryFilePath();
    if (!file_exists($filePath)) {
        $initial = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @file_put_contents($filePath, $initial, LOCK_EX);
    }
}

function readHistoryFromJson() {
    ensureHistoryStorage();
    $filePath = getHistoryFilePath();
    $content = @file_get_contents($filePath);
    $items = json_decode($content, true);
    return is_array($items) ? $items : [];
}

function writeHistory(array $items) {
    $filePath = getHistoryFilePath();
    @file_put_contents($filePath, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function saveHistoryItemJson(array $item) {
    $items = readHistoryFromJson();
    $items[] = $item;
    $filePath = getHistoryFilePath();
    return @file_put_contents($filePath, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function readHistoryFromSql() {
    $conn = getSqlConnection();
    if (!$conn) {
        return null;
    }

    if ($conn instanceof mysqli) {
        $rows = [];
        $query = 'SELECT id, input_type, input_data, result, confidence, created_at FROM results ORDER BY created_at DESC LIMIT 50';
        if ($result = $conn->query($query)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $conn->close();
        return $rows;
    }

    if ($conn instanceof PDO) {
        $stmt = $conn->query('SELECT id, input_type, input_data, result, confidence, created_at FROM results ORDER BY created_at DESC LIMIT 50');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $conn = null;
        return $rows;
    }

    return null;
}

function saveHistoryItemSql(array $item) {
    $conn = getSqlConnection();
    if (!$conn) {
        return false;
    }

    $image = isset($item['image']) ? $item['image'] : null;

    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare('INSERT INTO results (input_type, input_data, result, confidence, image) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) {
            error_log('saveHistoryItemSql (mysqli) prepare failed: ' . $conn->error);
            $conn->close();
            return false;
        }
        $stmt->bind_param('sssds', $item['input_type'], $item['input_data'], $item['result'], $item['confidence'], $image);
        $success = $stmt->execute();
        if (!$success) {
            error_log('saveHistoryItemSql (mysqli) execute failed: ' . $stmt->error);
        }
        $stmt->close();
        $conn->close();
        return (bool)$success;
    }

    if ($conn instanceof PDO) {
        try {
            $stmt = $conn->prepare('INSERT INTO results (input_type, input_data, result, confidence, image) VALUES (?, ?, ?, ?, ?)');
            $success = $stmt->execute([ $item['input_type'], $item['input_data'], $item['result'], $item['confidence'], $image ]);
            if (!$success) {
                error_log('saveHistoryItemSql (PDO) execute failed.');
            }
            $conn = null;
            return (bool)$success;
        } catch (PDOException $e) {
            error_log('saveHistoryItemSql (PDO) failed: ' . $e->getMessage());
            $conn = null;
            return false;
        }
    }

    return false;
}

function getNextHistoryId() {
    if (isSqlAvailable()) {
        $conn = getSqlConnection();
        if ($conn instanceof mysqli) {
            $result = $conn->query('SELECT MAX(id) AS max_id FROM results');
            if ($result) {
                $row = $result->fetch_assoc();
                $result->free();
                $conn->close();
                return intval($row['max_id'] ?? 0) + 1;
            }
            $conn->close();
        }

        if ($conn instanceof PDO) {
            $stmt = $conn->query('SELECT MAX(id) AS max_id FROM results');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $conn = null;
            return intval($row['max_id'] ?? 0) + 1;
        }
    }

    $items = readHistoryFromJson();
    $maxId = 0;
    foreach ($items as $entry) {
        if (isset($entry['id'])) {
            $maxId = max($maxId, intval($entry['id']));
        }
    }
    return $maxId + 1;
}

function addHistoryItem(array $item) {
    if (isSqlAvailable()) {
        if (saveHistoryItemSql($item)) {
            return $item;
        }

        error_log('addHistoryItem: SQL available but saveHistoryItemSql failed. Item was not persisted to SQL.');
        return false;
    }

    if (saveHistoryItemJson($item)) {
        return $item;
    }

    error_log('addHistoryItem: Failed to save to SQL and JSON fallback. Item was not persisted.');
    return false;
}

function clearHistory() {
    if (isSqlAvailable()) {
        $conn = getSqlConnection();
        if ($conn instanceof mysqli) {
            $success = $conn->query('TRUNCATE TABLE results');
            $conn->close();
            return (bool)$success;
        }
        if ($conn instanceof PDO) {
            $success = $conn->exec('TRUNCATE TABLE results');
            $conn = null;
            return $success !== false;
        }
    }

    $items = [];
    return writeHistory($items) !== false;
}

function readHistory() {
    if (isSqlAvailable()) {
        $rows = readHistoryFromSql();
        if (is_array($rows)) {
            return $rows;
        }
    }
    return readHistoryFromJson();
}
?>
