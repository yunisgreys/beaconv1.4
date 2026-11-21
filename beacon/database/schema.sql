-- =====================================================================
-- BEACON — Student Well-being Monitoring System
-- Database schema · MySQL 8.0+ / MariaDB 10.4+ (XAMPP compatible)
--
-- Import order:  schema.sql  ->  seed.sql
--
-- Design rules baked into this schema
--   * Users are never hard-deleted (status = 'disabled'), so every record
--     keeps a valid author and the audit trail stays meaningful.
--   * Mental-health guidance cases are hidden from parents unless
--     guidance_cases.is_parent_accessible = 1, and every change to that
--     flag is recorded in consent_events.
--   * Confidential counselling notes are stored encrypted by the app
--     (case_notes.body holds ciphertext when is_confidential = 1) and are
--     never shown to parents, whatever the case flag says.
--   * audit_logs is append-only: triggers at the bottom reject UPDATE and
--     DELETE. It records WHICH fields changed, never their values, so the
--     log itself does not become a second copy of sensitive data.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS beacon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE beacon;

-- ---------------------------------------------------------------------
-- 1. Identity & access
-- ---------------------------------------------------------------------

CREATE TABLE roles (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL,
    label       VARCHAR(50)  NOT NULL,
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_id              TINYINT UNSIGNED NOT NULL,
    email                VARCHAR(190) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    first_name           VARCHAR(80)  NOT NULL,
    last_name            VARCHAR(80)  NOT NULL,
    title                VARCHAR(100) NULL,
    phone                VARCHAR(30)  NULL,
    status               ENUM('active','disabled') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at        DATETIME     NULL,
    last_seen_at         DATETIME     NULL,        -- last request while logged in; NULL after logout (online monitor)
    created_by           INT UNSIGNED NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role       FOREIGN KEY (role_id)    REFERENCES roles(id),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force throttling (per e-mail and per IP).
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_email_time (email, attempted_at),
    KEY idx_login_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. School structure
-- ---------------------------------------------------------------------

CREATE TABLE sections (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grade_level  TINYINT UNSIGNED NOT NULL,
    name         VARCHAR(60)  NOT NULL,
    school_year  CHAR(9)      NOT NULL,            -- e.g. 2026-2027
    adviser_id   INT UNSIGNED NULL,
    UNIQUE KEY uq_sections (grade_level, name, school_year),
    CONSTRAINT fk_sections_adviser FOREIGN KEY (adviser_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject teachers (the adviser is on sections.adviser_id).
CREATE TABLE section_teachers (
    section_id   INT UNSIGNED NOT NULL,
    teacher_id   INT UNSIGNED NOT NULL,
    subject      VARCHAR(80)  NOT NULL,
    PRIMARY KEY (section_id, teacher_id, subject),
    CONSTRAINT fk_st_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_st_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_no        VARCHAR(20)  NOT NULL,       -- the "Student ID" parents enter, e.g. SV-2026-0841
    lrn               CHAR(12)     NULL,
    first_name        VARCHAR(80)  NOT NULL,
    middle_name       VARCHAR(80)  NULL,
    last_name         VARCHAR(80)  NOT NULL,
    birthdate         DATE         NOT NULL,       -- also used to verify parent link requests
    sex               ENUM('F','M') NOT NULL,
    section_id        INT UNSIGNED NULL,
    address           VARCHAR(255) NULL,
    guardian_name     VARCHAR(120) NULL,
    guardian_relation VARCHAR(40)  NULL,
    guardian_contact  VARCHAR(30)  NULL,
    status            ENUM('enrolled','transferred','graduated','inactive') NOT NULL DEFAULT 'enrolled',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_no (student_no),
    UNIQUE KEY uq_students_lrn (lrn),
    KEY idx_students_section (section_id),
    KEY idx_students_name (last_name, first_name),
    CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parent <-> student link. Parents self-register; the link stays 'pending'
-- until the Super Admin approves it, and parents see nothing until then.
CREATE TABLE parent_student_links (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id     INT UNSIGNED NOT NULL,
    student_id    INT UNSIGNED NOT NULL,
    relationship  VARCHAR(30)  NOT NULL,
    status        ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME     NULL,
    review_note   VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parent_student (parent_id, student_id),
    KEY idx_psl_status (status),
    CONSTRAINT fk_psl_parent   FOREIGN KEY (parent_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_psl_student  FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_psl_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Teacher module: attendance, grades, commendations
-- ---------------------------------------------------------------------

CREATE TABLE attendance (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id   INT UNSIGNED NOT NULL,
    section_id   INT UNSIGNED NOT NULL,
    att_date     DATE         NOT NULL,
    status       ENUM('present','late','absent','excused') NOT NULL,
    remarks      VARCHAR(255) NULL,
    recorded_by  INT UNSIGNED NOT NULL,
    recorded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_day (student_id, att_date),
    KEY idx_attendance_section_day (section_id, att_date),
    CONSTRAINT fk_att_student  FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_section  FOREIGN KEY (section_id)  REFERENCES sections(id),
    CONSTRAINT fk_att_recorder FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grades (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id   INT UNSIGNED NOT NULL,
    subject      VARCHAR(80)  NOT NULL,
    school_year  CHAR(9)      NOT NULL,
    quarter      TINYINT UNSIGNED NOT NULL,
    score        DECIMAL(5,2) NOT NULL,
    recorded_by  INT UNSIGNED NOT NULL,
    recorded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grade (student_id, subject, school_year, quarter),
    CONSTRAINT chk_grade_quarter CHECK (quarter BETWEEN 1 AND 4),
    CONSTRAINT chk_grade_score   CHECK (score BETWEEN 0 AND 100),
    CONSTRAINT fk_grade_student  FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_grade_recorder FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commendations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id   INT UNSIGNED NOT NULL,
    issued_by    INT UNSIGNED NOT NULL,
    category     ENUM('conduct','academic','leadership','service','kindness','other') NOT NULL,
    title        VARCHAR(120) NOT NULL,
    description  TEXT         NULL,
    awarded_on   DATE         NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_comm_student (student_id, awarded_on),
    CONSTRAINT fk_comm_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_comm_issuer  FOREIGN KEY (issued_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Guidance module (mental health & counselling hub)
-- ---------------------------------------------------------------------

CREATE TABLE guidance_cases (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id            INT UNSIGNED NOT NULL,
    opened_by             INT UNSIGNED NOT NULL,
    counselor_id          INT UNSIGNED NULL,
    case_type             ENUM('academic','behavioral','mental_health','family','peer','career','other') NOT NULL,
    is_mental_health      TINYINT(1)   NOT NULL DEFAULT 0,
    -- Student-discretion flag. Only meaningful when is_mental_health = 1;
    -- change it only through the consent flow (writes consent_events).
    is_parent_accessible  TINYINT(1)   NOT NULL DEFAULT 0,
    risk_level            ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low',
    status                ENUM('open','monitoring','closed') NOT NULL DEFAULT 'open',
    title                 VARCHAR(150) NOT NULL,
    parent_summary        VARCHAR(255) NULL,       -- wording written for families, shown only when visible
    opened_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at             DATETIME     NULL,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_gc_student (student_id, status),
    KEY idx_gc_counselor (counselor_id, status),
    CONSTRAINT fk_gc_student   FOREIGN KEY (student_id)   REFERENCES students(id),
    CONSTRAINT fk_gc_opened_by FOREIGN KEY (opened_by)    REFERENCES users(id),
    CONSTRAINT fk_gc_counselor FOREIGN KEY (counselor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teacher observations: behavioural conduct, physical state, or mental/emotional
-- state. Each report lands in the inbox of the office in routed_to (status 'new');
-- guidance may attach it to a case.
CREATE TABLE incident_reports (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED NOT NULL,
    reported_by   INT UNSIGNED NOT NULL,
    record_type   ENUM('conduct','physical','mental_emotional') NOT NULL DEFAULT 'conduct',
    category      ENUM('behavioral','emotional','mental_health','physical','academic','peer_conflict','attendance','other') NOT NULL,
    severity      ENUM('low','moderate','high','urgent') NOT NULL DEFAULT 'low',
    routed_to     ENUM('guidance','clinic') NOT NULL DEFAULT 'guidance',   -- which office's inbox receives it
    title         VARCHAR(150) NOT NULL,
    description   TEXT         NOT NULL,
    action_taken  TEXT         NULL,
    observed_at   DATETIME     NOT NULL,
    status        ENUM('new','acknowledged','linked','closed') NOT NULL DEFAULT 'new',
    case_id       INT UNSIGNED NULL,
    acknowledged_by INT UNSIGNED NULL,
    acknowledged_at DATETIME   NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ir_status (status, created_at),
    KEY idx_ir_route (routed_to, status, created_at),
    KEY idx_ir_student (student_id, observed_at),
    CONSTRAINT fk_ir_student  FOREIGN KEY (student_id)      REFERENCES students(id),
    CONSTRAINT fk_ir_reporter FOREIGN KEY (reported_by)     REFERENCES users(id),
    CONSTRAINT fk_ir_case     FOREIGN KEY (case_id)         REFERENCES guidance_cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_ir_ack      FOREIGN KEY (acknowledged_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Progress notes, session notes, remarks. Confidential notes are encrypted
-- by the app and never reach the parent portal.
CREATE TABLE case_notes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    case_id         INT UNSIGNED NOT NULL,
    author_id       INT UNSIGNED NOT NULL,
    note_type       ENUM('progress','session','remark','referral') NOT NULL DEFAULT 'progress',
    is_confidential TINYINT(1)   NOT NULL DEFAULT 1,
    body            TEXT         NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cn_case (case_id, created_at),
    CONSTRAINT fk_cn_case   FOREIGN KEY (case_id)   REFERENCES guidance_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_cn_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intervention plans (counselling plan, BIP, referral, parent conference...).
CREATE TABLE interventions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    case_id       INT UNSIGNED NOT NULL,
    plan_type     ENUM('counseling','bip','referral','parent_conference','peer_support','monitoring','other') NOT NULL,
    goals         TEXT         NOT NULL,
    strategies    TEXT         NULL,
    start_date    DATE         NOT NULL,
    review_date   DATE         NULL,
    status        ENUM('planned','active','completed','discontinued') NOT NULL DEFAULT 'planned',
    created_by    INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_int_case (case_id, status),
    CONSTRAINT fk_int_case    FOREIGN KEY (case_id)    REFERENCES guidance_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_int_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Progress check-ins on an intervention plan (milestones). Guidance-only.
CREATE TABLE intervention_updates (
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

-- Every grant/withdrawal of parent visibility, with how consent was given.
CREATE TABLE consent_events (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    case_id        INT UNSIGNED NOT NULL,
    student_id     INT UNSIGNED NOT NULL,
    granted        TINYINT(1)   NOT NULL,          -- 1 = parents may see, 0 = withdrawn
    consent_method ENUM('verbal_in_session','written_form','student_portal') NOT NULL,
    recorded_by    INT UNSIGNED NOT NULL,
    notes          VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ce_case (case_id, created_at),
    CONSTRAINT fk_ce_case     FOREIGN KEY (case_id)     REFERENCES guidance_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_student  FOREIGN KEY (student_id)  REFERENCES students(id),
    CONSTRAINT fk_ce_recorder FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counseling_sessions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    case_id         INT UNSIGNED NULL,
    counselor_id    INT UNSIGNED NOT NULL,
    session_type    ENUM('check_in','individual','group','crisis','follow_up','parent_conference') NOT NULL,
    priority        ENUM('routine','priority','urgent') NOT NULL DEFAULT 'routine',
    scheduled_start DATETIME     NOT NULL,
    scheduled_end   DATETIME     NOT NULL,
    location        VARCHAR(80)  NULL,
    status          ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cs_counselor_time (counselor_id, scheduled_start),
    KEY idx_cs_student (student_id, scheduled_start),
    CONSTRAINT chk_cs_times     CHECK (scheduled_end > scheduled_start),
    CONSTRAINT fk_cs_student    FOREIGN KEY (student_id)   REFERENCES students(id),
    CONSTRAINT fk_cs_case       FOREIGN KEY (case_id)      REFERENCES guidance_cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_cs_counselor  FOREIGN KEY (counselor_id) REFERENCES users(id),
    CONSTRAINT fk_cs_creator    FOREIGN KEY (created_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Clinic module (medical & triage hub)
-- ---------------------------------------------------------------------

CREATE TABLE medical_profiles (
    student_id          INT UNSIGNED NOT NULL PRIMARY KEY,
    blood_type          VARCHAR(5)   NULL,
    allergies           TEXT         NULL,
    conditions          TEXT         NULL,
    regular_medications TEXT         NULL,
    emergency_notes     TEXT         NULL,
    updated_by          INT UNSIGNED NULL,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mp_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinic_passes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pass_code    CHAR(8)      NOT NULL,            -- shown on the digital pass, checked by the clinic
    student_id   INT UNSIGNED NOT NULL,
    issued_by    INT UNSIGNED NOT NULL,
    reason_type  ENUM('medical','injury','psychological','other') NOT NULL,
    reason       VARCHAR(255) NOT NULL,
    urgency      ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
    status       ENUM('issued','arrived','completed','expired','cancelled') NOT NULL DEFAULT 'issued',
    issued_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_pass_code (pass_code),
    KEY idx_pass_status (status, issued_at),
    KEY idx_pass_student (student_id, issued_at),
    CONSTRAINT fk_pass_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_pass_issuer  FOREIGN KEY (issued_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinic_visits (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id       INT UNSIGNED NOT NULL,
    pass_id          INT UNSIGNED NULL,            -- NULL for walk-ins
    attended_by      INT UNSIGNED NOT NULL,
    arrived_at       DATETIME     NOT NULL,
    departed_at      DATETIME     NULL,
    complaint        VARCHAR(255) NOT NULL,
    assessment       TEXT         NULL,
    temperature_c    DECIMAL(4,1) NULL,
    blood_pressure   VARCHAR(10)  NULL,
    pulse_bpm        SMALLINT UNSIGNED NULL,
    disposition      ENUM('returned_to_class','observation','sent_home','referred_guidance','referred_hospital') NULL,
    -- Live status tag shown in the clinic queue (disposition is kept in sync for reports).
    case_status      ENUM('under_observation','guardian_contacted','sent_home','cleared_to_class','referred_guidance','referred_hospital') NOT NULL DEFAULT 'under_observation',
    medication_notes TEXT         NULL,            -- exact medication given, as written by the nurse
    referral_report_id INT UNSIGNED NULL,          -- report sent to Guidance when the student is referred
    -- Psychological triage / guidance referral: parents see that a visit
    -- happened, but not the complaint or assessment.
    is_sensitive     TINYINT(1)   NOT NULL DEFAULT 0,
    parent_notified  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_visit_pass (pass_id),
    KEY idx_visit_student (student_id, arrived_at),
    KEY idx_visit_open (departed_at),
    CONSTRAINT chk_visit_times  CHECK (departed_at IS NULL OR departed_at >= arrived_at),
    CONSTRAINT fk_visit_student FOREIGN KEY (student_id)  REFERENCES students(id),
    CONSTRAINT fk_visit_pass    FOREIGN KEY (pass_id)     REFERENCES clinic_passes(id),
    CONSTRAINT fk_visit_staff   FOREIGN KEY (attended_by) REFERENCES users(id),
    CONSTRAINT fk_visit_referral FOREIGN KEY (referral_report_id) REFERENCES incident_reports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    category       ENUM('medicine','supply','equipment') NOT NULL,
    unit           VARCHAR(20)  NOT NULL,          -- tablet, pc, bottle, box...
    quantity       INT          NOT NULL DEFAULT 0,
    reorder_level  INT          NOT NULL DEFAULT 0,
    status_override ENUM('in_stock','low_stock','out_of_stock') NULL,   -- NULL = automatic from quantity
    requested_qty  INT          NULL,             -- quantity requested/ordered and not yet received
    request_note   VARCHAR(255) NULL,
    requested_at   DATETIME     NULL,
    expiry_date    DATE         NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_name (name),
    CONSTRAINT chk_item_qty CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medications_administered (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id        INT UNSIGNED NOT NULL,
    item_id         INT UNSIGNED NULL,             -- NULL if not from clinic stock
    medication_name VARCHAR(120) NOT NULL,
    dose            VARCHAR(50)  NOT NULL,
    route           ENUM('oral','topical','inhaled','other') NOT NULL DEFAULT 'oral',
    quantity_used   INT          NOT NULL DEFAULT 1,
    administered_at DATETIME     NOT NULL,
    administered_by INT UNSIGNED NOT NULL,
    KEY idx_med_visit (visit_id),
    CONSTRAINT fk_med_visit FOREIGN KEY (visit_id)        REFERENCES clinic_visits(id) ON DELETE CASCADE,
    CONSTRAINT fk_med_item  FOREIGN KEY (item_id)         REFERENCES inventory_items(id),
    CONSTRAINT fk_med_staff FOREIGN KEY (administered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id       INT UNSIGNED NOT NULL,
    change_qty    INT          NOT NULL,           -- +restock / -used
    reason        ENUM('restock','administered','expired','damaged','adjustment') NOT NULL,
    medication_id INT UNSIGNED NULL,
    performed_by  INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mov_item (item_id, created_at),
    CONSTRAINT fk_mov_item  FOREIGN KEY (item_id)       REFERENCES inventory_items(id),
    CONSTRAINT fk_mov_med   FOREIGN KEY (medication_id) REFERENCES medications_administered(id) ON DELETE SET NULL,
    CONSTRAINT fk_mov_staff FOREIGN KEY (performed_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5b. Family notices (school announcements shown in the Family Portal)
-- ---------------------------------------------------------------------

CREATE TABLE notices (
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

CREATE TABLE notice_acknowledgements (
    notice_id        INT UNSIGNED NOT NULL,
    parent_id        INT UNSIGNED NOT NULL,
    acknowledged_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, parent_id),
    CONSTRAINT fk_ack_notice FOREIGN KEY (notice_id) REFERENCES notices(id) ON DELETE CASCADE,
    CONSTRAINT fk_ack_parent FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Audit trail (append-only)
-- ---------------------------------------------------------------------

CREATE TABLE audit_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id       INT UNSIGNED NULL,              -- no FK on purpose: logs outlive everything
    actor_role     VARCHAR(20)  NULL,
    action         VARCHAR(40)  NOT NULL,          -- login, login_failed, view, create, update, consent_grant...
    entity         VARCHAR(40)  NULL,              -- table name
    entity_id      BIGINT UNSIGNED NULL,
    student_id     INT UNSIGNED NULL,              -- lets guidance answer "who looked at this child's file?"
    changed_fields VARCHAR(500) NULL,              -- comma-separated field NAMES only, never values
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_student (student_id, created_at),
    KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Early-warning view (feeds teacher & guidance dashboard flags)
--    Counts the last 30 days of signals per enrolled student.
-- ---------------------------------------------------------------------

CREATE VIEW v_wellbeing_signals AS
SELECT
    s.id AS student_id,
    s.section_id,
    (SELECT COUNT(*) FROM incident_reports ir
      WHERE ir.student_id = s.id
        AND ir.category IN ('emotional','mental_health','behavioral','peer_conflict')
        AND ir.observed_at >= CURRENT_DATE - INTERVAL 30 DAY)          AS incidents_30d,
    (SELECT COUNT(*) FROM clinic_visits cv
      WHERE cv.student_id = s.id
        AND cv.arrived_at >= CURRENT_DATE - INTERVAL 30 DAY)           AS clinic_visits_30d,
    (SELECT COUNT(*) FROM clinic_visits cv
      WHERE cv.student_id = s.id AND cv.is_sensitive = 1
        AND cv.arrived_at >= CURRENT_DATE - INTERVAL 30 DAY)           AS sensitive_visits_30d,
    (SELECT COUNT(*) FROM attendance a
      WHERE a.student_id = s.id AND a.status = 'absent'
        AND a.att_date >= CURRENT_DATE - INTERVAL 30 DAY)              AS absences_30d,
    (SELECT COUNT(*) FROM guidance_cases gc
      WHERE gc.student_id = s.id AND gc.status <> 'closed')            AS open_cases
FROM students s
WHERE s.status = 'enrolled';

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 8. Make audit_logs append-only
-- ---------------------------------------------------------------------

DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
DELIMITER ;
