-- Quick Setup for Polls Feature
-- Copy and paste this entire file into your database SQL console

-- Create polls table
CREATE TABLE IF NOT EXISTS polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    group_id INT NULL,
    recipient_id INT NULL,
    title VARCHAR(200) NOT NULL,
    end_date DATETIME NULL,
    end_responses INT NULL,
    ended_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_creator (creator_id),
    INDEX idx_group (group_id),
    INDEX idx_recipient (recipient_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create poll options table
CREATE TABLE IF NOT EXISTS poll_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_index INT NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    option_image VARCHAR(500) NULL,
    INDEX idx_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create poll votes table
CREATE TABLE IF NOT EXISTS poll_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_id INT NOT NULL,
    user_id INT NOT NULL,
    voted_at DATETIME NOT NULL,
    UNIQUE KEY unique_user_poll (poll_id, user_id),
    INDEX idx_poll (poll_id),
    INDEX idx_option (option_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify tables were created
SHOW TABLES LIKE 'poll%';

-- Check table structures
DESCRIBE polls;
DESCRIBE poll_options;
DESCRIBE poll_votes;
