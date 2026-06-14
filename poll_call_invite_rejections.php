<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/includes/db.php';

$token = $_GET['token'] ?? '';
$lastCheckTime = $_GET['last_check'] ?? null;

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Missing token']);
    exit;
}

// Validate session
$stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ?");
$stmt->execute([$token]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'error' => 'Invalid session']);
    exit;
}

$userId = $session['user_id'];

try {
    // Get recent rejections
    $query = "
        SELECT 
            id,
            rejected_by_user_id,
            rejected_by_display_name,
            created_at
        FROM call_invite_rejections
        WHERE inviter_id = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ";
    
    $params = [$userId];
    
    if ($lastCheckTime) {
        $query .= " AND created_at > ?";
        $params[] = $lastCheckTime;
    }
    
    $query .= " ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rejections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark as notified
    if (!empty($rejections)) {
        $rejectionIds = array_column($rejections, 'id');
        $placeholders = implode(',', array_fill(0, count($rejectionIds), '?'));
        $stmt = $pdo->prepare("
            UPDATE call_invite_rejections 
            SET notified = 1 
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($rejectionIds);
    }

    echo json_encode([
        'success' => true,
        'rejections' => $rejections
    ]);
} catch (Exception $e) {
    error_log("Poll call invite rejections error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch rejections']);
}
