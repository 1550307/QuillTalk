-- Call invites table
CREATE TABLE IF NOT EXISTS call_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inviter_id INT NOT NULL,
    invited_user_id INT NOT NULL,
    call_type ENUM('direct', 'group') NOT NULL DEFAULT 'direct',
    group_call_id INT NULL,
    current_participants TEXT NULL COMMENT 'JSON array of current call participants',
    status ENUM('pending', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    INDEX idx_invited_user (invited_user_id, status, created_at),
    INDEX idx_inviter (inviter_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Call invite rejections table (for notifying inviters)
CREATE TABLE IF NOT EXISTS call_invite_rejections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inviter_id INT NOT NULL,
    rejected_by_user_id INT NOT NULL,
    rejected_by_display_name VARCHAR(255) NOT NULL,
    invite_id INT NOT NULL,
    notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_inviter_notified (inviter_id, notified, created_at),
    INDEX idx_invite (invite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
