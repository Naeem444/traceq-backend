-- ===================== SESSION =====================
SET SQL_MODE='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===================== TABLES =====================

-- users
CREATE TABLE IF NOT EXISTS user_data (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  custom_id     VARCHAR(16) UNIQUE,
  firebase_uid  VARCHAR(128) NOT NULL UNIQUE,
  email         VARCHAR(190) UNIQUE,
  full_name     VARCHAR(120),
  phone         VARCHAR(40),
  student_id    VARCHAR(40),
  department    VARCHAR(80),
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  photo         TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_user_role ON user_data(role);

-- lost reports
CREATE TABLE IF NOT EXISTS lost_reports (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  custom_id         VARCHAR(16) UNIQUE,
  user_id           INT NOT NULL,
  item_type         VARCHAR(80),
  item_model        VARCHAR(120),
  title             VARCHAR(160),
  description       TEXT,
  location          VARCHAR(160),
  location_details  VARCHAR(240),
  lost_date         DATE,
  lost_time         TIME,
  secret_hint       VARCHAR(160),
  secret_hash       VARCHAR(255),
  report_time       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lost_user
    FOREIGN KEY (user_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lost_user       ON lost_reports(user_id);
CREATE INDEX idx_lost_datetime   ON lost_reports(lost_date, lost_time);
CREATE INDEX idx_lost_report_ts  ON lost_reports(report_time);

-- found reports
CREATE TABLE IF NOT EXISTS found_reports (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  custom_id         VARCHAR(16) UNIQUE,
  user_id           INT NOT NULL,
  item_type         VARCHAR(80),
  item_model        VARCHAR(120),
  title             VARCHAR(160),
  description       TEXT,
  location          VARCHAR(160),
  location_details  VARCHAR(240),
  found_date        DATE,
  found_time        TIME,
  report_time       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  admin_dropoff     TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_found_user
    FOREIGN KEY (user_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_found_user      ON found_reports(user_id);
CREATE INDEX idx_found_datetime  ON found_reports(found_date, found_time);
CREATE INDEX idx_found_report_ts ON found_reports(report_time);
CREATE INDEX idx_found_dropoff   ON found_reports(admin_dropoff);

-- found photos (text only; join by custom_id)
CREATE TABLE IF NOT EXISTS found_item_photos (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  found_custom_id    VARCHAR(16) NOT NULL,
  photo              TEXT NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_found_photo_custom
    FOREIGN KEY (found_custom_id) REFERENCES found_reports(custom_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_found_photo_custom ON found_item_photos(found_custom_id);

-- lost photos (text only; join by custom_id)
CREATE TABLE IF NOT EXISTS lost_item_photos (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  lost_custom_id     VARCHAR(16) NOT NULL,
  photo              TEXT NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lost_photo_custom
    FOREIGN KEY (lost_custom_id) REFERENCES lost_reports(custom_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_lost_photo_custom ON lost_item_photos(lost_custom_id);

-- claims
CREATE TABLE IF NOT EXISTS claims (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  custom_id     VARCHAR(16) UNIQUE,
  user_id       INT NOT NULL,
  report_type   ENUM('lost','found') NOT NULL,
  report_id     INT NOT NULL,
  claim_status  ENUM('pending','approved','rejected','verified','handovered') NOT NULL DEFAULT 'pending',
  secret_match  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_claims_user
    FOREIGN KEY (user_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_claims_user        ON claims(user_id);
CREATE INDEX idx_claims_status      ON claims(claim_status);
CREATE INDEX idx_claims_report_poly ON claims(report_type, report_id);

-- handover logs
CREATE TABLE IF NOT EXISTS handover_logs (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  custom_id          VARCHAR(16) UNIQUE,
  claim_id           INT NOT NULL,
  admin_id           INT NOT NULL,
  handed_to_user_id  INT NOT NULL,
  remarks            VARCHAR(240),
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_handover_claim
    FOREIGN KEY (claim_id) REFERENCES claims(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_handover_admin
    FOREIGN KEY (admin_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_handover_user
    FOREIGN KEY (handed_to_user_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_handover_claim ON handover_logs(claim_id);

-- notifications
CREATE TABLE IF NOT EXISTS notifications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  message     VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user
    FOREIGN KEY (user_id) REFERENCES user_data(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_notif_user_time ON notifications(user_id, created_at);

-- ===================== SEQUENCE TABLE =====================
CREATE TABLE IF NOT EXISTS app_sequences (
  name    VARCHAR(64) PRIMARY KEY,
  next_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO app_sequences (name, next_id) VALUES
  ('user_data',0),
  ('lost_reports',0),
  ('found_reports',0),
  ('claims',0),
  ('handover_logs',0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ===================== ID GENERATION TRIGGERS =====================
DELIMITER $$

DROP TRIGGER IF EXISTS bi_user_customid $$
CREATE TRIGGER bi_user_customid
BEFORE INSERT ON user_data
FOR EACH ROW
BEGIN
  IF NEW.custom_id IS NULL OR NEW.custom_id = '' THEN
    UPDATE app_sequences SET next_id = LAST_INSERT_ID(next_id + 1) WHERE name = 'user_data';
    SET NEW.custom_id = CONCAT('U', DATE_FORMAT(UTC_TIMESTAMP(), '%y%m'), UPPER(HEX(LAST_INSERT_ID())));
  END IF;
END $$

DROP TRIGGER IF EXISTS bi_lost_customid $$
CREATE TRIGGER bi_lost_customid
BEFORE INSERT ON lost_reports
FOR EACH ROW
BEGIN
  IF NEW.custom_id IS NULL OR NEW.custom_id = '' THEN
    UPDATE app_sequences SET next_id = LAST_INSERT_ID(next_id + 1) WHERE name = 'lost_reports';
    SET NEW.custom_id = CONCAT('L', DATE_FORMAT(UTC_TIMESTAMP(), '%y%m'), UPPER(HEX(LAST_INSERT_ID())));
  END IF;
END $$

DROP TRIGGER IF EXISTS bi_found_customid $$
CREATE TRIGGER bi_found_customid
BEFORE INSERT ON found_reports
FOR EACH ROW
BEGIN
  IF NEW.custom_id IS NULL OR NEW.custom_id = '' THEN
    UPDATE app_sequences SET next_id = LAST_INSERT_ID(next_id + 1) WHERE name = 'found_reports';
    SET NEW.custom_id = CONCAT('F', DATE_FORMAT(UTC_TIMESTAMP(), '%y%m'), UPPER(HEX(LAST_INSERT_ID())));
  END IF;
END $$

DROP TRIGGER IF EXISTS bi_claims_customid $$
CREATE TRIGGER bi_claims_customid
BEFORE INSERT ON claims
FOR EACH ROW
BEGIN
  IF NEW.custom_id IS NULL OR NEW.custom_id = '' THEN
    UPDATE app_sequences SET next_id = LAST_INSERT_ID(next_id + 1) WHERE name = 'claims';
    SET NEW.custom_id = CONCAT('C', DATE_FORMAT(UTC_TIMESTAMP(), '%y%m'), UPPER(HEX(LAST_INSERT_ID())));
  END IF;
END $$

DROP TRIGGER IF EXISTS bi_handover_customid $$
CREATE TRIGGER bi_handover_customid
BEFORE INSERT ON handover_logs
FOR EACH ROW
BEGIN
  IF NEW.custom_id IS NULL OR NEW.custom_id = '' THEN
    UPDATE app_sequences SET next_id = LAST_INSERT_ID(next_id + 1) WHERE name = 'handover_logs';
    SET NEW.custom_id = CONCAT('H', DATE_FORMAT(UTC_TIMESTAMP(), '%y%m'), UPPER(HEX(LAST_INSERT_ID())));
  END IF;
END $$

DELIMITER ;
