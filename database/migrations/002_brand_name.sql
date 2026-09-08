-- 002_brand_name.sql
--
-- Wordmark/lettermark/monogram styles need the actual name to set, and it
-- must be exact — an image model cannot infer it from a description.
ALTER TABLE briefs ADD COLUMN brand_name VARCHAR(128) NULL AFTER entity_type;
