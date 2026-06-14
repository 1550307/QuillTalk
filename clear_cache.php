<?php
/**
 * Clear PHP OPcache
 * 
 * Access this file once to clear the cache: http://your-domain/clear_cache.php
 */

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache cleared successfully!\n";
} else {
    echo "OPcache is not enabled.\n";
}

if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "✓ APCu cache cleared successfully!\n";
}

echo "\nYou can now test again with test_ai_context_v2.php\n";
