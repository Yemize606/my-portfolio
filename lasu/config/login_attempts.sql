-- ============================================================
--  login_attempts table
--  Tracks failed/successful login attempts for brute-force lockout.
--  Run this once against your database (phpMyAdmin > SQL tab,
--  or `mysql -u root -p lasu_health_center < config/login_attempts.sql`).
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `matric_number`  VARCHAR(20)  NOT NULL,
  `ip_address`     VARCHAR(45)  NOT NULL,
  `success`        TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attempt_id`),
  INDEX `idx_matric_time` (`matric_number`, `attempted_at`),
  INDEX `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
