-- Run this ONCE if you already imported schema.sql before 2026-11-18.
-- (Fresh installs: schema.sql already includes these columns.)
USE beacon;

ALTER TABLE incident_reports
    MODIFY category ENUM('behavioral','emotional','mental_health','physical','academic','peer_conflict','attendance','other') NOT NULL,
    ADD COLUMN record_type ENUM('conduct','physical','mental_emotional') NOT NULL DEFAULT 'conduct' AFTER reported_by,
    ADD COLUMN routed_to   ENUM('guidance','clinic') NOT NULL DEFAULT 'guidance' AFTER severity,
    ADD KEY idx_ir_route (routed_to, status, created_at);

UPDATE incident_reports SET record_type = 'mental_emotional' WHERE category IN ('emotional','mental_health');
