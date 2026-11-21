-- Run ONCE if your database was created before the Guidance update (2026-11-18).
USE beacon;

CREATE TABLE IF NOT EXISTS intervention_updates (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    intervention_id  INT UNSIGNED NOT NULL,
    progress         ENUM('declining','no_change','improving','on_track','achieved') NOT NULL,
    note             TEXT         NOT NULL,
    created_by       INT UNSIGNED NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_iu_intervention (intervention_id, created_at),
    CONSTRAINT fk_iu_intervention FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE CASCADE,
    CONSTRAINT fk_iu_creator      FOREIGN KEY (created_by)      REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
