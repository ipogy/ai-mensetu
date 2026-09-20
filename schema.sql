-- すでにDBがある場合は以下のALTER文を実行してください:
-- ALTER TABLE candidates MODIFY COLUMN interview_status ENUM('applied', 'ready', 'in_progress', 'completed', 'es_failed') DEFAULT 'applied';

CREATE TABLE IF NOT EXISTS candidates (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    entry_sheet_data JSON NOT NULL,
    interview_status ENUM('applied', 'ready', 'in_progress', 'completed', 'es_failed') DEFAULT 'applied',
    final_decision ENUM('unreviewed', 'pass', 'fail', 'hold') DEFAULT 'unreviewed',
    reviewer_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interview_sessions (
    id CHAR(36) PRIMARY KEY,
    candidate_id CHAR(36) NOT NULL,
    current_step INT DEFAULT 1,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    evaluation_summary JSON NULL,
    INDEX idx_candidate_id (candidate_id),
    CONSTRAINT fk_sessions_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interview_turns (
    id CHAR(36) PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    turn_number INT NOT NULL,
    question_type ENUM('main', 'follow_up') NOT NULL,
    question_text TEXT NOT NULL,
    question_audio_filename VARCHAR(255) NULL,
    answer_text TEXT NULL,
    answer_audio_filename VARCHAR(255) NULL,
    turn_score JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session_id (session_id),
    CONSTRAINT fk_turns_session FOREIGN KEY (session_id) REFERENCES interview_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;