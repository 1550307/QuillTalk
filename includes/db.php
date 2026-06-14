<?php
declare(strict_types=1);
require_once __DIR__ . '/games.php';
// Disable display_errors for production - errors are logged instead
ini_set('display_errors', 0);
error_reporting(E_ALL);

$host = "127.0.0.1:3306";
$db   = "u813772999_quilltalkdb";
$user = "u813772999_quilltalkdb";
$pass = "Wazzup123123*";

function ensure_utf8mb4_tables(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.utf8mb4_messages_ready';

    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[UTF8MB4 SET NAMES] ' . $e->getMessage());
    }

    if (is_file($flagPath)) {
        return;
    }

    try {
        $tablesToCheck = ['messages', 'users'];
        $placeholders = implode(',', array_fill(0, count($tablesToCheck), '?'));

        $stmt = $pdo->prepare("
            SELECT TABLE_NAME, CHARACTER_SET_NAME, COLLATION_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ($placeholders)
              AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')
        ");
        $stmt->execute($tablesToCheck);

        $tablesNeedingUpgrade = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $charset = (string)($row['CHARACTER_SET_NAME'] ?? '');
            $collation = (string)($row['COLLATION_NAME'] ?? '');
            if ($charset !== 'utf8mb4' || !str_starts_with($collation, 'utf8mb4_')) {
                $tablesNeedingUpgrade[(string)$row['TABLE_NAME']] = true;
            }
        }

        foreach (array_keys($tablesNeedingUpgrade) as $tableName) {
            $safeTableName = str_replace('`', '``', $tableName);
            $pdo->exec("ALTER TABLE `{$safeTableName}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "utf8mb4 ready\n");
    } catch (Throwable $e) {
        error_log('[UTF8MB4 TABLE CHECK] ' . $e->getMessage());
    }
}

function ensure_user_identity_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.user_identity_ready_v3';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $columns = [];
        $columnStmt = $pdo->query("SHOW COLUMNS FROM users");
        while ($row = $columnStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string)($row['Field'] ?? '')] = true;
        }

        if (!isset($columns['display_name'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(255) NULL AFTER username");
        }

        if (!isset($columns['bio'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER profile_pic");
        }

        if (!isset($columns['created_at'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME NULL AFTER is_passkey_user");
        }

        if (!isset($columns['online'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN online TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_pic");
        }

        if (!isset($columns['last_seen_at'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER online");
        }

        $legacyUsersStmt = $pdo->query("
            SELECT id, username
            FROM users
            WHERE display_name IS NULL OR display_name = ''
        ");
        $legacyUsers = $legacyUsersStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($legacyUsers) {
            $migrateLegacyUserStmt = $pdo->prepare("
                UPDATE users
                SET display_name = ?, username = ?
                WHERE id = ?
            ");

            foreach ($legacyUsers as $legacyUser) {
                $legacyId = (int)($legacyUser['id'] ?? 0);
                $legacyUsername = trim((string)($legacyUser['username'] ?? ''));
                if ($legacyId <= 0) {
                    continue;
                }

                $legacyDisplayName = $legacyUsername !== '' ? $legacyUsername : 'User';
                $newUsername = generate_unique_username($pdo, $legacyDisplayName);
                $migrateLegacyUserStmt->execute([$legacyDisplayName, $newUsername, $legacyId]);
            }
        }

        $pdo->exec("UPDATE users SET bio = '' WHERE bio IS NULL");
        $pdo->exec("UPDATE users SET created_at = NOW() WHERE created_at IS NULL");
        $pdo->exec("UPDATE users SET online = 0 WHERE online IS NULL");
        $pdo->exec("UPDATE users SET last_seen_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE last_seen_at IS NULL");

        $hasUniqueUsernameIndex = false;
        $indexStmt = $pdo->query("SHOW INDEX FROM users WHERE Column_name = 'username'");
        while ($row = $indexStmt->fetch(PDO::FETCH_ASSOC)) {
            if ((int)($row['Non_unique'] ?? 1) === 0) {
                $hasUniqueUsernameIndex = true;
                break;
            }
        }

        if (!$hasUniqueUsernameIndex) {
            $pdo->exec("ALTER TABLE users ADD UNIQUE KEY users_username_unique (username)");
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "user identity ready\n");
    } catch (Throwable $e) {
        error_log('[USER IDENTITY CHECK] ' . $e->getMessage());
    }
}

function ensure_android_push_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.android_push_schema_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS android_push_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                fcm_token VARCHAR(255) NOT NULL,
                platform VARCHAR(32) NOT NULL DEFAULT 'android',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY android_push_tokens_token_unique (fcm_token),
                KEY android_push_tokens_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "android push schema ready\n");
    } catch (Throwable $e) {
        error_log('[ANDROID PUSH SCHEMA] ' . $e->getMessage());
    }
}

function ensure_user_block_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.user_blocks_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_blocks (
                blocker_id INT UNSIGNED NOT NULL,
                blocked_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (blocker_id, blocked_id),
                KEY user_blocks_blocked_idx (blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "user blocks ready\n");
    } catch (Throwable $e) {
        error_log('[USER BLOCK SCHEMA] ' . $e->getMessage());
    }
}

function ensure_chat_notification_preferences_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.chat_notification_preferences_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_notification_preferences (
                user_id INT UNSIGNED NOT NULL,
                chat_type VARCHAR(16) NOT NULL,
                chat_id INT UNSIGNED NOT NULL,
                notify_mode VARCHAR(16) NOT NULL DEFAULT 'all',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, chat_type, chat_id),
                KEY chat_notification_preferences_chat_idx (chat_type, chat_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            UPDATE chat_notification_preferences
            SET notify_mode = 'all'
            WHERE notify_mode IS NULL OR notify_mode = ''
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "chat notification preferences ready\n");
    } catch (Throwable $e) {
        error_log('[CHAT NOTIFICATION PREFERENCES SCHEMA] ' . $e->getMessage());
    }
}

function ensure_group_chat_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.group_chat_schema_ready_v3';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                icon_path VARCHAR(255) NULL,
                send_permission VARCHAR(16) NOT NULL DEFAULT 'all',
                add_members_permission VARCHAR(16) NOT NULL DEFAULT 'admins',
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                KEY chat_groups_created_by_idx (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_group_members (
                group_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'member',
                joined_at DATETIME NULL,
                last_seen_message_id INT UNSIGNED NOT NULL DEFAULT 0,
                muted_until DATETIME NULL,
                PRIMARY KEY (group_id, user_id),
                KEY chat_group_members_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS group_messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_id INT UNSIGNED NOT NULL,
                sender_id INT UNSIGNED NOT NULL,
                message TEXT NOT NULL,
                created_at DATETIME NULL,
                KEY group_messages_group_idx (group_id, id),
                KEY group_messages_sender_idx (sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $missingTables = [];
        foreach (['chat_groups', 'chat_group_members', 'group_messages'] as $tableName) {
            $lookup = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
            if (!$lookup || !$lookup->fetchColumn()) {
                $missingTables[] = $tableName;
            }
        }

        if ($missingTables) {
            throw new RuntimeException('Missing group chat tables after bootstrap: ' . implode(', ', $missingTables));
        }

        $groupColumns = [];
        $groupColumnStmt = $pdo->query("SHOW COLUMNS FROM chat_groups");
        while ($row = $groupColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $groupColumns[(string)($row['Field'] ?? '')] = true;
        }
        if (!isset($groupColumns['send_permission'])) {
            $pdo->exec("ALTER TABLE chat_groups ADD COLUMN send_permission VARCHAR(16) NOT NULL DEFAULT 'all' AFTER icon_path");
        }
        if (!isset($groupColumns['add_members_permission'])) {
            $pdo->exec("ALTER TABLE chat_groups ADD COLUMN add_members_permission VARCHAR(16) NOT NULL DEFAULT 'admins' AFTER send_permission");
        }

        $memberColumns = [];
        $memberColumnStmt = $pdo->query("SHOW COLUMNS FROM chat_group_members");
        while ($row = $memberColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $memberColumns[(string)($row['Field'] ?? '')] = true;
        }
        if (!isset($memberColumns['role'])) {
            $pdo->exec("ALTER TABLE chat_group_members ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'member' AFTER user_id");
        }
        if (!isset($memberColumns['muted_until'])) {
            $pdo->exec("ALTER TABLE chat_group_members ADD COLUMN muted_until DATETIME NULL AFTER last_seen_message_id");
        }

        $pdo->exec("
            UPDATE chat_groups
            SET send_permission = 'all'
            WHERE send_permission IS NULL OR send_permission = ''
        ");
        $pdo->exec("
            UPDATE chat_groups
            SET add_members_permission = 'admins'
            WHERE add_members_permission IS NULL OR add_members_permission = ''
        ");
        $pdo->exec("
            UPDATE chat_group_members
            SET role = 'member'
            WHERE role IS NULL OR role = ''
        ");
        $pdo->exec("
            UPDATE chat_group_members cgm
            JOIN chat_groups cg
              ON cg.id = cgm.group_id
            SET cgm.role = 'owner'
            WHERE cgm.user_id = cg.created_by
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "group chat schema ready\n");
    } catch (Throwable $e) {
        error_log('[GROUP CHAT SCHEMA] ' . $e->getMessage());
    }
}

function ensure_group_call_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.group_call_schema_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS group_calls (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_id INT UNSIGNED NOT NULL,
                initiator_id INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'ringing',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                KEY group_calls_group_idx (group_id, status, created_at),
                KEY group_calls_initiator_idx (initiator_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS group_call_members (
                call_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                invite_status VARCHAR(16) NOT NULL DEFAULT 'pending',
                joined_at DATETIME NULL,
                left_at DATETIME NULL,
                PRIMARY KEY (call_id, user_id),
                KEY group_call_members_user_idx (user_id, invite_status),
                KEY group_call_members_call_status_idx (call_id, invite_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $callColumns = [];
        $callColumnStmt = $pdo->query("SHOW COLUMNS FROM group_calls");
        while ($row = $callColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $callColumns[(string)($row['Field'] ?? '')] = true;
        }
        if (!isset($callColumns['status'])) {
            $pdo->exec("ALTER TABLE group_calls ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'ringing' AFTER initiator_id");
        }
        if (!isset($callColumns['ended_at'])) {
            $pdo->exec("ALTER TABLE group_calls ADD COLUMN ended_at DATETIME NULL AFTER created_at");
        }

        $memberColumns = [];
        $memberColumnStmt = $pdo->query("SHOW COLUMNS FROM group_call_members");
        while ($row = $memberColumnStmt->fetch(PDO::FETCH_ASSOC)) {
            $memberColumns[(string)($row['Field'] ?? '')] = true;
        }
        if (!isset($memberColumns['invite_status'])) {
            $pdo->exec("ALTER TABLE group_call_members ADD COLUMN invite_status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER user_id");
        }
        if (!isset($memberColumns['joined_at'])) {
            $pdo->exec("ALTER TABLE group_call_members ADD COLUMN joined_at DATETIME NULL AFTER invite_status");
        }
        if (!isset($memberColumns['left_at'])) {
            $pdo->exec("ALTER TABLE group_call_members ADD COLUMN left_at DATETIME NULL AFTER joined_at");
        }

        $pdo->exec("
            UPDATE group_calls
            SET status = 'ringing'
            WHERE status IS NULL OR status = ''
        ");
        $pdo->exec("
            UPDATE group_call_members
            SET invite_status = 'pending'
            WHERE invite_status IS NULL OR invite_status = ''
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "group call schema ready\n");
    } catch (Throwable $e) {
        error_log('[GROUP CALL SCHEMA] ' . $e->getMessage());
    }
}

function ensure_message_metadata_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';

    try {
        foreach (['messages', 'group_messages'] as $table) {
            $columns = [];
            $columnStmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            while ($row = $columnStmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[(string)($row['Field'] ?? '')] = true;
            }

            if (!isset($columns['reply_to_id'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN reply_to_id INT UNSIGNED NULL DEFAULT NULL");
            }
            if (!isset($columns['forward_from_user_id'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN forward_from_user_id INT UNSIGNED NULL DEFAULT NULL");
            }
            if (!isset($columns['forward_from_display_name'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN forward_from_display_name VARCHAR(255) NULL DEFAULT NULL");
            }
            if (!isset($columns['is_ai_response'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN is_ai_response TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!isset($columns['ai_sender_display_name'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN ai_sender_display_name VARCHAR(255) NULL DEFAULT NULL");
            }
            if ($table === 'messages' && !isset($columns['ai_origin_user_id'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN ai_origin_user_id INT UNSIGNED NULL DEFAULT NULL");
            }
            if (!isset($columns['ai_origin_message_id'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN ai_origin_message_id INT UNSIGNED NULL DEFAULT NULL");
            }
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagDir . DIRECTORY_SEPARATOR . '.message_metadata_ready_v1', "message metadata ready\n");
    } catch (Throwable $e) {
        error_log('[MESSAGE METADATA SCHEMA] ' . $e->getMessage());
    }
}

function ensure_message_visibility_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.message_visibility_ready_v1';

    $tableExists = false;
    $columns = [];

    try {
        $tableStmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('message_visibility'));
        $tableExists = (bool)($tableStmt && $tableStmt->fetchColumn());

        if ($tableExists) {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM message_visibility");
            while ($row = $columnStmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[(string)($row['Field'] ?? '')] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[MESSAGE VISIBILITY SCHEMA] verification error: ' . $e->getMessage());
    }

    if (
        $tableExists
        && isset($columns['user_id'], $columns['message_type'], $columns['message_id'], $columns['hidden_at'])
    ) {
        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "message visibility ready\n");
        return;
    }

    try {
        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS message_visibility (
                    user_id INT UNSIGNED NOT NULL,
                    message_type VARCHAR(16) NOT NULL,
                    message_id INT UNSIGNED NOT NULL,
                    hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, message_type, message_id),
                    KEY message_visibility_message_idx (message_type, message_id),
                    KEY message_visibility_user_idx (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $columns = [];
        $columnStmt = $pdo->query("SHOW COLUMNS FROM message_visibility");
        while ($row = $columnStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string)($row['Field'] ?? '')] = true;
        }

        if (!isset($columns['hidden_at'])) {
            $pdo->exec("ALTER TABLE message_visibility ADD COLUMN hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER message_id");
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "message visibility ready\n");
    } catch (Throwable $e) {
        error_log('[MESSAGE VISIBILITY SCHEMA] ' . $e->getMessage());
    }
}

function ensure_message_reactions_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.message_reactions_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS message_reactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_type ENUM('direct','group','ai') NOT NULL,
                message_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_reaction (message_type, message_id, user_id, emoji),
                KEY idx_message (message_type, message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        } catch (Throwable $e) {
            // Already updated.
        }

        try {
            $pdo->exec("ALTER TABLE message_reactions MODIFY COLUMN message_type ENUM('direct','group','ai') NOT NULL");
        } catch (Throwable $e) {
            // Already updated.
        }

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "message reactions ready\n");
    } catch (Throwable $e) {
        error_log('[MESSAGE REACTIONS SCHEMA] ' . $e->getMessage());
    }
}

function ensure_scrolls_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.scrolls_schema_ready_v4';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scrolls (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                video_path VARCHAR(500) NOT NULL,
                title VARCHAR(160) NULL,
                caption TEXT NULL,
                mime_type VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY scrolls_user_idx (user_id, created_at),
                KEY scrolls_active_idx (is_active, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $scrollColumns = [];
        $scrollColumnStmt = $pdo->query("SHOW COLUMNS FROM scrolls");
        while ($row = $scrollColumnStmt ? $scrollColumnStmt->fetch(PDO::FETCH_ASSOC) : false) {
            $scrollColumns[(string)($row['Field'] ?? '')] = true;
        }

        if (!isset($scrollColumns['title'])) {
            $pdo->exec("ALTER TABLE scrolls ADD COLUMN title VARCHAR(160) NULL AFTER video_path");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scroll_reactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scroll_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reaction_type ENUM('like','dislike') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY scroll_reaction_user_idx (scroll_id, user_id),
                KEY scroll_reaction_type_idx (reaction_type, scroll_id),
                KEY scroll_reaction_user_lookup_idx (user_id, scroll_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scroll_follows (
                follower_user_id INT UNSIGNED NOT NULL,
                target_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (follower_user_id, target_user_id),
                KEY scroll_follow_target_idx (target_user_id, follower_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scroll_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scroll_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                comment_text TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY scroll_comment_scroll_idx (scroll_id, created_at, id),
                KEY scroll_comment_user_idx (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $commentColumns = [];
        $commentColumnsStmt = $pdo->query("SHOW COLUMNS FROM scroll_comments");
        if ($commentColumnsStmt) {
            $commentColumns = array_map('strtolower', $commentColumnsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        if (!in_array('reply_to_comment_id', $commentColumns, true)) {
            $pdo->exec("
                ALTER TABLE scroll_comments
                ADD COLUMN reply_to_comment_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_id
            ");
        }

        $replyIndexStmt = $pdo->query("SHOW INDEX FROM scroll_comments WHERE Key_name = 'scroll_comment_reply_idx'");
        $hasReplyIndex = (bool)($replyIndexStmt && $replyIndexStmt->fetch(PDO::FETCH_ASSOC));
        if (!$hasReplyIndex) {
            $pdo->exec("
                ALTER TABLE scroll_comments
                ADD KEY scroll_comment_reply_idx (scroll_id, reply_to_comment_id, created_at, id)
            ");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scroll_comment_reactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reaction_type ENUM('like', 'dislike') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY scroll_comment_reaction_unique (comment_id, user_id),
                KEY scroll_comment_reaction_type_idx (comment_id, reaction_type),
                KEY scroll_comment_reaction_user_idx (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "scrolls schema ready\n");
    } catch (Throwable $e) {
        error_log('[SCROLLS SCHEMA] ' . $e->getMessage());
    }
}

function ensure_user_nicknames_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.user_nicknames_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_nicknames (
                user_id INT UNSIGNED NOT NULL,
                target_user_id INT UNSIGNED NOT NULL,
                nickname VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, target_user_id),
                KEY user_nicknames_target_idx (target_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "user nicknames ready\n");
    } catch (Throwable $e) {
        error_log('[USER NICKNAMES SCHEMA] ' . $e->getMessage());
    }
}

function ensure_chat_user_nicknames_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.chat_user_nicknames_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_user_nicknames (
                user_id INT UNSIGNED NOT NULL,
                chat_type VARCHAR(16) NOT NULL,
                chat_id INT UNSIGNED NOT NULL,
                nickname VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, chat_type, chat_id),
                KEY chat_user_nicknames_chat_idx (chat_type, chat_id),
                KEY chat_user_nicknames_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "chat user nicknames ready\n");
    } catch (Throwable $e) {
        error_log('[CHAT USER NICKNAMES SCHEMA] ' . $e->getMessage());
    }
}

function ensure_history_events_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.history_events_ready_v1';

    if (is_file($flagPath)) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_history_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_user_id INT UNSIGNED NOT NULL,
                subject_user_id INT UNSIGNED NULL,
                chat_type VARCHAR(16) NULL,
                chat_id INT UNSIGNED NULL,
                event_type VARCHAR(32) NOT NULL,
                event_value VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY user_history_events_actor_idx (actor_user_id, created_at),
                KEY user_history_events_subject_idx (subject_user_id, created_at),
                KEY user_history_events_chat_idx (chat_type, chat_id, created_at),
                KEY user_history_events_type_idx (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "history events ready\n");
    } catch (Throwable $e) {
        error_log('[HISTORY EVENTS SCHEMA] ' . $e->getMessage());
    }
}

function ensure_group_typing_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $flagPath = $flagDir . DIRECTORY_SEPARATOR . '.group_typing_schema_applied';
    if (file_exists($flagPath)) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS group_typing (
                group_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                last_typing_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (group_id, user_id),
                INDEX idx_group_id (group_id),
                INDEX idx_last_typing_at (last_typing_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if (!is_dir($flagDir)) {
            @mkdir($flagDir, 0775, true);
        }
        @file_put_contents($flagPath, "group typing ready\n");
    } catch (Throwable $e) {
        error_log('[GROUP TYPING SCHEMA] ' . $e->getMessage());
    }
}

function ensure_email_verification_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($flagDir)) {
        mkdir($flagDir, 0755, true);
    }
    $flagFile = $flagDir . DIRECTORY_SEPARATOR . '.email_verification_schema_applied';
    if (file_exists($flagFile)) {
        return;
    }

    try {
        // Check if columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_code'");
        $codeExists = $stmt->rowCount() > 0;
        
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verification_expires'");
        $expiresExists = $stmt->rowCount() > 0;
        
        if (!$codeExists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_code VARCHAR(6) DEFAULT NULL");
        }
        
        if (!$expiresExists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_verification_expires DATETIME DEFAULT NULL");
        }
        
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        error_log('ensure_email_verification_schema error: ' . $e->getMessage());
    }
}

function ensure_tutorial_schema(PDO $pdo): void
{
    $flagDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($flagDir)) {
        mkdir($flagDir, 0755, true);
    }
    $flagFile = $flagDir . DIRECTORY_SEPARATOR . '.tutorial_schema_applied';
    if (file_exists($flagFile)) {
        return;
    }

    try {
        // Check if columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tutorial_completed'");
        $completedExists = $stmt->rowCount() > 0;
        
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tutorial_skipped'");
        $skippedExists = $stmt->rowCount() > 0;
        
        if (!$completedExists) {
            // Default to 0 (false) so all existing users will see the tutorial
            $pdo->exec("ALTER TABLE users ADD COLUMN tutorial_completed TINYINT(1) DEFAULT 0");
        }
        
        if (!$skippedExists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN tutorial_skipped TINYINT(1) DEFAULT 0");
        }
        
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        error_log('ensure_tutorial_schema error: ' . $e->getMessage());
    }
}

function qt_identity_substr(string $value, int $length): string
{
    if ($length <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string)mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function build_username_base(string $displayName): string
{
    $normalized = trim((string)(preg_replace('/\s+/u', ' ', $displayName) ?? ''));
    $base = (string)(preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '');

    if ($base === '') {
        $base = 'User';
    }

    return qt_identity_substr($base, 24);
}

function generate_unique_username(PDO $pdo, string $displayName): string
{
    $base = build_username_base($displayName);
    $lookup = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");

    for ($attempt = 0; $attempt < 200; $attempt++) {
        $suffixLength = $attempt < 150 ? 3 : 4;
        $max = $suffixLength === 3 ? 999 : 9999;
        $suffix = str_pad((string)random_int(0, $max), $suffixLength, '0', STR_PAD_LEFT);
        $candidate = qt_identity_substr($base, 255 - $suffixLength) . $suffix;

        $lookup->execute([$candidate]);
        if (!$lookup->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique username.');
}

try {
    $dsn = "mysql:host=$host;dbname=$db;port=3306;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);
    ensure_utf8mb4_tables($pdo);
    ensure_user_identity_schema($pdo);
    ensure_android_push_schema($pdo);
    ensure_user_block_schema($pdo);
    ensure_chat_notification_preferences_schema($pdo);
    ensure_group_chat_schema($pdo);
    ensure_group_call_schema($pdo);
    ensure_message_metadata_schema($pdo);
    ensure_message_visibility_schema($pdo);
    ensure_message_reactions_schema($pdo);
    ensure_game_schema($pdo);
    ensure_user_nicknames_schema($pdo);
    ensure_chat_user_nicknames_schema($pdo);
    ensure_history_events_schema($pdo);
    ensure_scrolls_schema($pdo);
    ensure_group_typing_schema($pdo);
    ensure_email_verification_schema($pdo);
    ensure_tutorial_schema($pdo);
} catch (Throwable $e) {
    die("Database error: " . $e->getMessage());
}
