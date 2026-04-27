-- schema.sql - Complete Database Schema for Consensus-AI / Council Engine
--
-- Author: Mikhail Deynekin <mikhail@deynekin.com>
-- Website: https://Deynekin.com
-- Version: 1.0.0
-- Since: 2026-04-27

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Table: skills - SKILL definitions for council engine
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS skills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    roles JSON NOT NULL,
    consensus ENUM('majority_vote', 'weighted_average', 'synthesis') NOT NULL,
    tags JSON NOT NULL,
    is_auto_generated TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_skills_name (name),
    INDEX idx_skills_is_active (is_active),
    INDEX idx_skills_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: council_sessions - high-level council session metadata
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS council_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skill_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
    query_text TEXT NOT NULL,
    final_answer LONGTEXT DEFAULT NULL,
    consensus_score DECIMAL(5,4) DEFAULT NULL,
    consensus_status VARCHAR(50) DEFAULT NULL,
    consensus_meta JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE RESTRICT,
    INDEX idx_council_sessions_skill (skill_id),
    INDEX idx_council_sessions_status (status),
    INDEX idx_council_sessions_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: council_steps - individual steps within a council session
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS council_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    step_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    order_index INT NOT NULL,
    summary TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES council_sessions(id) ON DELETE CASCADE,
    INDEX idx_council_steps_session (session_id),
    INDEX idx_council_steps_step_key (step_key),
    INDEX idx_council_steps_order (order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: council_step_responses - model responses per step and role
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS council_step_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    step_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(200) NOT NULL,
    role_name VARCHAR(150) NOT NULL,
    raw_response JSON NOT NULL,
    normalized_response JSON DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    latency_ms INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (step_id) REFERENCES council_steps(id) ON DELETE CASCADE,
    INDEX idx_council_step_responses_step (step_id),
    INDEX idx_council_step_responses_role (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: memory_chunks - application-level memory items
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS memory_chunks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED DEFAULT NULL,
    skill_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    chunk_type ENUM('text', 'argument', 'decision', 'context', 'fact') NOT NULL DEFAULT 'text',
    content_text LONGTEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES council_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE SET NULL,
    INDEX idx_memory_chunks_session (session_id),
    INDEX idx_memory_chunks_skill (skill_id),
    INDEX idx_memory_chunks_type (chunk_type),
    INDEX idx_memory_chunks_created_at (created_at),
    FULLTEXT INDEX idx_memory_chunks_content (content_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: memory_embeddings - embedding vectors for memory_chunks
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS memory_embeddings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chunk_id INT UNSIGNED NOT NULL,
    model VARCHAR(100) NOT NULL,
    vector JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chunk_id) REFERENCES memory_chunks(id) ON DELETE CASCADE,
    INDEX idx_memory_embeddings_chunk (chunk_id),
    INDEX idx_memory_embeddings_model (model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: vector_embeddings - generic vector storage (used by SkillSearch, etc.)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vector_embeddings (
    id INT NOT NULL,
    collection VARCHAR(50) NOT NULL,
    vector JSON NOT NULL,
    metadata JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, collection),
    INDEX idx_collection (collection)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
