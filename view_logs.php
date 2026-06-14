<?php
/**
 * Simple log viewer for debugging AI responses
 */

echo "<h2>AI Debug Logs</h2>\n";

$logFiles = [
    'push_debug.log' => 'Send Message Debug Log',
    'ai_debug.log' => 'AI Debug Log'
];

foreach ($logFiles as $file => $title) {
    $path = __DIR__ . '/' . $file;
    echo "<h3>$title ($file)</h3>\n";
    
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if ($content) {
            // Show last 50 lines
            $lines = explode("\n", $content);
            $lastLines = array_slice($lines, -50);
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow-y: scroll;'>";
            echo htmlspecialchars(implode("\n", $lastLines));
            echo "</pre>\n";
        } else {
            echo "<p>Log file is empty.</p>\n";
        }
    } else {
        echo "<p>Log file does not exist.</p>\n";
    }
    echo "<hr>\n";
}

echo "<p><a href='view_logs.php'>Refresh</a></p>\n";
?>