-- Run ONCE if your database was created before the Family Portal update (2026-11-18).
USE beacon;

CREATE TABLE IF NOT EXISTS notices (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(150) NOT NULL,
    body          TEXT         NOT NULL,
    tag           ENUM('important','announcement','clinic','guidance') NOT NULL DEFAULT 'announcement',
    audience      ENUM('all','grade','section') NOT NULL DEFAULT 'all',
    grade_level   TINYINT UNSIGNED NULL,
    section_id    INT UNSIGNED NULL,
    requires_ack  TINYINT(1)   NOT NULL DEFAULT 0,
    posted_by     INT UNSIGNED NOT NULL,
    published_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notice_pub (published_at),
    CONSTRAINT fk_notice_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_notice_author  FOREIGN KEY (posted_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notice_acknowledgements (
    notice_id        INT UNSIGNED NOT NULL,
    parent_id        INT UNSIGNED NOT NULL,
    acknowledged_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, parent_id),
    CONSTRAINT fk_ack_notice FOREIGN KEY (notice_id) REFERENCES notices(id) ON DELETE CASCADE,
    CONSTRAINT fk_ack_parent FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
