CREATE TABLE events_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    ts DATETIME NOT NULL,
    type VARCHAR(50) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    details JSON NOT NULL,
    PRIMARY KEY (id, ts),
    KEY idx_ts (ts),
    KEY idx_type (type),
    KEY idx_ip (ip),
    KEY idx_type_ts (type, ts)
) ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
PARTITION BY RANGE (YEAR(ts)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION pmax VALUES LESS THAN MAXVALUE
);

DELIMITER $$

CREATE PROCEDURE rotate_event_partitions()
BEGIN
    DECLARE next_year INT;
    DECLARE partition_name VARCHAR(10);
    DECLARE partition_exists INT DEFAULT 0;

    SET next_year = YEAR(CURDATE()) + 1;
    SET partition_name = CONCAT('p', next_year);

    SELECT COUNT(*) INTO partition_exists
    FROM information_schema.partitions
    WHERE table_schema = DATABASE()
      AND table_name = 'events_logs'
      AND partition_name = partition_name;

    IF partition_exists = 0 THEN
        SET @sql = CONCAT(
            'ALTER TABLE events_logs ADD PARTITION (PARTITION ',
            partition_name,
            ' VALUES LESS THAN (',
            next_year + 1,
            '))'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CREATE EVENT auto_rotate_partitions
ON SCHEDULE EVERY 1 YEAR
STARTS '2026-01-01 00:00:00'
DO CALL rotate_event_partitions();