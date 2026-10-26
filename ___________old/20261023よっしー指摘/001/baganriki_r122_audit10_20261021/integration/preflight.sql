-- Read-only checks. Execute against the isolated verification database.
-- If the application uses a table prefix, add it to every table name below.
-- Transactions and row locking require InnoDB on the participating tables.
SELECT TABLE_NAME, ENGINE
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'baganriki_%'
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('baganriki_snapshots','baganriki_ai_attempts',
                    'baganriki_validation_results','baganriki_publications','baganriki_audit_events',
                    'baganriki_r122_leases','baganriki_r122_jobs')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Existing UNIQUE(snapshot_id,role,attempt_no) is required for send slots.
-- Snapshot uniqueness must include race_id and generation as in the original contract.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('baganriki_snapshots','baganriki_ai_attempts','baganriki_publication_intents')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE;
