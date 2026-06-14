-- Polls table (without foreign keys initially)
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

-- Poll options table (without foreign keys initially)
CREATE TABLE IF NOT EXISTS poll_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_index INT NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    option_image VARCHAR(500) NULL,
    INDEX idx_poll (poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Poll votes table (without foreign keys initially)
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

-- Add foreign keys after tables are created (optional - comment out if still causing issues)
-- ALTER TABLE polls ADD CONSTRAINT fk_polls_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE;
-- ALTER TABLE polls ADD CONSTRAINT fk_polls_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE;
-- ALTER TABLE polls ADD CONSTRAINT fk_polls_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE;
-- ALTER TABLE poll_options ADD CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE;
-- ALTER TABLE poll_votes ADD CONSTRAINT fk_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE;
-- ALTER TABLE poll_votes ADD CONSTRAINT fk_poll_votes_option FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE;
-- ALTER TABLE poll_votes ADD CONSTRAINT fk_poll_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
