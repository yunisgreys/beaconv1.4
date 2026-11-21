-- Run ONCE if your database was created before the Clinic update (2026-11-18).
USE beacon;

ALTER TABLE clinic_visits
    ADD COLUMN case_status ENUM('under_observation','guardian_contacted','sent_home','cleared_to_class','referred_guidance','referred_hospital')
        NOT NULL DEFAULT 'under_observation' AFTER disposition,
    ADD COLUMN medication_notes TEXT NULL AFTER assessment,
    ADD COLUMN referral_report_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_visit_referral FOREIGN KEY (referral_report_id) REFERENCES incident_reports(id) ON DELETE SET NULL;

UPDATE clinic_visits SET case_status = CASE disposition
    WHEN 'returned_to_class' THEN 'cleared_to_class'
    WHEN 'sent_home' THEN 'sent_home'
    WHEN 'referred_guidance' THEN 'referred_guidance'
    WHEN 'referred_hospital' THEN 'referred_hospital'
    ELSE 'under_observation' END;

ALTER TABLE inventory_items
    ADD COLUMN status_override ENUM('in_stock','low_stock','out_of_stock') NULL AFTER reorder_level,
    ADD COLUMN requested_qty INT NULL AFTER status_override,
    ADD COLUMN request_note VARCHAR(255) NULL AFTER requested_qty,
    ADD COLUMN requested_at DATETIME NULL AFTER request_note;
