-- Destructive migration: permanently deletes all shift schedule records.
-- Back up the target database before running this migration.
DROP TABLE IF EXISTS schedules;
