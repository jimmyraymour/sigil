-- ============================================================
-- Sigil schema — MySQL 8
-- Apply with:  mysql -u <user> -p < database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS sigil CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE sigil;

-- API keys (hashed; plaintext shown once at creation)
CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,
    key_hash      CHAR(64)     NOT NULL,
    key_prefix    CHAR(8)      NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uniq_key_hash (key_hash),
    KEY idx_prefix (key_prefix)
) ENGINE=InnoDB;

-- Briefs form a linked list: the root brief holds the raw description,
-- each child brief is one chat iteration (feedback -> refined brief).
CREATE TABLE IF NOT EXISTS briefs (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    parent_brief_id CHAR(36)     NULL,
    raw_description TEXT         NULL,
    logo_description TEXT        NULL,
    entity_type     VARCHAR(32)  NOT NULL DEFAULT 'company',
    brand_name      VARCHAR(128) NULL,
    feedback        TEXT         NULL,
    refined_brief   TEXT         NOT NULL,
    style           VARCHAR(32)  NOT NULL DEFAULT 'auto',
    palette_hint    VARCHAR(255) NULL,
    model_used      VARCHAR(64)  NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parent (parent_brief_id),
    KEY idx_created (created_at),
    CONSTRAINT fk_brief_parent FOREIGN KEY (parent_brief_id) REFERENCES briefs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One row per generated asset. status transitions: queued -> rendering -> succeeded|failed
CREATE TABLE IF NOT EXISTS renders (
    id              CHAR(36)        NOT NULL PRIMARY KEY,
    brief_id        CHAR(36)        NOT NULL,
    batch_id        CHAR(36)        NULL,
    variant_index   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    api_key_id      INT UNSIGNED    NULL,
    provider        VARCHAR(32)     NOT NULL,
    provider_job_id VARCHAR(128)    NULL,
    prompt_sent     TEXT            NOT NULL,
    output_format   VARCHAR(8)      NOT NULL,
    output_path     VARCHAR(512)    NULL,
    output_url      VARCHAR(512)    NULL,
    width_px        INT UNSIGNED    NULL,
    height_px       INT UNSIGNED    NULL,
    cost_usd        DECIMAL(6,4)    NULL,
    status          ENUM('queued','rendering','succeeded','failed') NOT NULL DEFAULT 'queued',
    error_message   TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME        NULL,
    KEY idx_brief (brief_id),
    KEY idx_batch (batch_id),
    KEY idx_status (status),
    KEY idx_key (api_key_id),
    CONSTRAINT fk_render_brief FOREIGN KEY (brief_id) REFERENCES briefs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-key per-minute rate limiting (bucket = unix minute)
CREATE TABLE IF NOT EXISTS rate_limits (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    key_hash   CHAR(64)        NOT NULL,
    bucket     INT UNSIGNED    NOT NULL,
    count      INT UNSIGNED    NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_bucket (key_hash, bucket),
    KEY idx_key (key_hash)
) ENGINE=InnoDB;
