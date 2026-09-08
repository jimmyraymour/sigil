-- 001_logo_redirect.sql
--
-- Redirects Sigil from "one description -> one paid render" to
-- "two descriptions -> a batch of variants from a free or premium model".
--
-- 1. providers/styles/formats become open VARCHARs so adding a model is a
--    config edit rather than a schema migration.
-- 2. briefs gain the second input field (logo_description) and entity_type.
-- 3. renders gain batch grouping so one run can produce several variants.

ALTER TABLE briefs
    MODIFY COLUMN style VARCHAR(32) NOT NULL DEFAULT 'auto',
    ADD COLUMN logo_description TEXT NULL AFTER raw_description,
    ADD COLUMN entity_type VARCHAR(32) NOT NULL DEFAULT 'company' AFTER logo_description;

ALTER TABLE renders
    MODIFY COLUMN provider VARCHAR(32) NOT NULL,
    MODIFY COLUMN output_format VARCHAR(8) NOT NULL,
    ADD COLUMN batch_id CHAR(36) NULL AFTER brief_id,
    ADD COLUMN variant_index TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER batch_id,
    ADD KEY idx_batch (batch_id);

-- The stub provider is gone: it produced placeholder images that were
-- indistinguishable from real output in the library. Mark the old rows.
UPDATE renders SET status = 'failed', error_message = 'stub provider removed (placeholder, never a real render)'
    WHERE provider = 'stub';
