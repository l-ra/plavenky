<?php
/**
 * Statistics Receiver Endpoint
 * Receives analytics events and stores them in JSON Lines format
 * One file per month: stats-YYYY-MM.jsonl
 */

// CORS Configuration
$allowedOrigins = [
    'https://l-ra.github.io',
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1',
    'http://127.0.0.1:8000'
];

// Get the origin of the request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if origin is allowed
$isAllowed = false;
foreach ($allowedOrigins as $allowedOrigin) {
    if ($origin === $allowedOrigin || strpos($origin, $allowedOrigin) === 0) {
        $isAllowed = true;
        break;
    }
}

// Set CORS headers if origin is allowed
if ($isAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 86400"); // 24 hours
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON payload
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate data
if (!$data || !isset($data['instanceId']) || !isset($data['event']) || !isset($data['timestamp'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data format']);
    exit;
}

// Validate timestamp format
$timestamp = $data['timestamp'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid timestamp format']);
    exit;
}

// Extract year and month from timestamp
$dateTime = new DateTime($timestamp);
$yearMonth = $dateTime->format('Y-m');

// Get stats directory from environment variable or use default
$statsDir = getenv('STATS_DIR');
if ($statsDir === false || $statsDir === '') {
    $statsDir = '/working/plavenky-stats/';
}

// Remove trailing slash if present and add it back for consistency
$statsDir = rtrim($statsDir, '/');

// Create stats directory if it doesn't exist
if (!is_dir($statsDir)) {
    if (!mkdir($statsDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create stats directory']);
        exit;
    }
}

// Sanitize data - only allow non-sensitive aggregate fields
function sanitizeEventData($data) {
    if (!is_array($data) || empty($data)) {
        return null;
    }
    
    // Whitelist of allowed fields (only aggregate/non-sensitive data)
    $allowedFields = [
        'count',        // Generic count
        'skipped',      // Number of skipped items
        'rowCount',     // Number of rows
        'chipsCount',   // Number of chips (not identifiers)
        'chips',        // Count field
        'employees',    // Count field
        'borrowings'    // Count field
    ];
    
    $sanitized = [];
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && (is_int($data[$field]) || is_numeric($data[$field]))) {
            $sanitized[$field] = (int)$data[$field];
        }
    }
    
    return !empty($sanitized) ? $sanitized : null;
}

// Determine filename based on month
$filename = $statsDir . '/stats-' . $yearMonth . '.jsonl';

// Sanitize event data to remove any sensitive information
$sanitizedData = isset($data['data']) ? sanitizeEventData($data['data']) : null;

// Prepare log entry
$logEntry = [
    'instanceId' => $data['instanceId'],
    'event' => $data['event'],
    'timestamp' => $timestamp,
    'data' => $sanitizedData,
    'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    'received' => date('c')
];

// Append to file (JSON Lines format - one JSON object per line)
$success = file_put_contents($filename, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

if ($success === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save data']);
    exit;
}

// Return success
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Event recorded']);
