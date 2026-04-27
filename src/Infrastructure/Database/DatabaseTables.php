<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * DatabaseTables class provides SQL schema definitions for all application tables.
 * Use this class to create or migrate database tables.
 */
final class DatabaseTables
{
    /**
     * Create all tables in the database.
     */
    public static function createAll(\PDO $pdo): void
    {
        $pdo->exec(self::getUsersTable());
        $pdo->exec(self::getTopicsTable());
        $pdo->exec(self::getSessionsTable());
        $pdo->exec(self::getParticipantsTable());
        $pdo->exec(self::getArgumentsTable());
        $pdo->exec(self::getMemoryItemsTable());
        $pdo->exec(self::getSessionTopicsTable());
    }

    /**
     * Get SQL for the users table.
     */
    public static function getUsersTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    role ENUM('admin', 'moderator', 'participant') DEFAULT 'participant',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_users_email (email),
    INDEX idx_users_username (username),
    INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the topics table.
     */
    public static function getTopicsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS topics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    status ENUM('open', 'closed', 'archived') DEFAULT 'open',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_topics_status (status),
    INDEX idx_topics_category (category),
    FULLTEXT INDEX idx_topics_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the sessions table.
     */
    public static function getSessionsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('active', 'paused', 'completed', 'cancelled') DEFAULT 'active',
    settings JSON DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sessions_status (status),
    INDEX idx_sessions_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the participants table.
     */
    public static function getParticipantsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) DEFAULT NULL,
    role ENUM('moderator', 'participant', 'observer') DEFAULT 'participant',
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_session_participant (session_id, user_id),
    INDEX idx_participants_session (session_id),
    INDEX idx_participants_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the arguments table.
     */
    public static function getArgumentsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS arguments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED DEFAULT NULL,
    parent_id INT UNSIGNED DEFAULT NULL,
    content TEXT NOT NULL,
    type ENUM('claim', 'evidence', 'question', 'counter_argument', 'summary') DEFAULT 'claim',
    stance ENUM('for', 'against', 'neutral') DEFAULT 'neutral',
    status ENUM('pending', 'approved', 'rejected', 'flagged') DEFAULT 'pending',
    vote_count INT DEFAULT 0,
    depth INT DEFAULT 0,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES arguments(id) ON DELETE SET NULL,
    INDEX idx_arguments_session (session_id),
    INDEX idx_arguments_participant (participant_id),
    INDEX idx_arguments_topic (topic_id),
    INDEX idx_arguments_parent (parent_id),
    INDEX idx_arguments_status (status),
    INDEX idx_arguments_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the memory_items table.
     */
    public static function getMemoryItemsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS memory_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    type ENUM('text', 'argument', 'decision', 'context', 'fact') DEFAULT 'text',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    INDEX idx_memory_session (session_id),
    INDEX idx_memory_type (type),
    INDEX idx_memory_created_at (created_at),
    FULLTEXT INDEX idx_memory_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    /**
     * Get SQL for the session_topics junction table.
     */
    public static function getSessionTopicsTable(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS session_topics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    topic_id INT UNSIGNED NOT NULL,
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_topic (session_id, topic_id),
    INDEX idx_session_topics_session (session_id),
    INDEX idx_session_topics_topic (topic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }
}
