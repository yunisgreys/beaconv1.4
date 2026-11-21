# BEACON — Entity Relationship Diagram

Renders on GitHub, in VS Code (Markdown Preview Mermaid Support), or at https://mermaid.live.

```mermaid
erDiagram
    roles ||--o{ users : "has"
    users ||--o{ users : "created_by"

    users ||--o{ sections : "advises"
    sections ||--o{ section_teachers : ""
    users ||--o{ section_teachers : "teaches"
    sections ||--o{ students : "enrolls"

    users ||--o{ parent_student_links : "parent"
    students ||--o{ parent_student_links : ""
    users ||--o{ parent_student_links : "reviewed_by (admin)"

    students ||--o{ attendance : ""
    students ||--o{ grades : ""
    students ||--o{ commendations : ""

    students ||--o{ incident_reports : ""
    users ||--o{ incident_reports : "reported_by (teacher)"
    guidance_cases |o--o{ incident_reports : "linked to"

    students ||--o{ guidance_cases : ""
    users ||--o{ guidance_cases : "counselor"
    guidance_cases ||--o{ case_notes : ""
    guidance_cases ||--o{ interventions : ""
    guidance_cases ||--o{ consent_events : "discretion log"
    guidance_cases |o--o{ counseling_sessions : ""
    students ||--o{ counseling_sessions : ""

    students ||--o| medical_profiles : ""
    students ||--o{ clinic_passes : ""
    users ||--o{ clinic_passes : "issued_by (teacher)"
    clinic_passes |o--o| clinic_visits : "verified as"
    students ||--o{ clinic_visits : ""
    clinic_visits ||--o{ medications_administered : ""
    inventory_items |o--o{ medications_administered : "from stock"
    inventory_items ||--o{ inventory_movements : ""

    users {
        int id PK
        tinyint role_id FK
        varchar email UK
        varchar password_hash
        enum status "active | disabled"
        bool must_change_password
    }
    students {
        int id PK
        varchar student_no UK "Student ID parents enter"
        char lrn UK
        date birthdate "verifies parent link"
        int section_id FK
    }
    parent_student_links {
        int parent_id FK
        int student_id FK
        enum status "pending | approved | rejected | revoked"
    }
    guidance_cases {
        int id PK
        int student_id FK
        enum case_type
        bool is_mental_health
        bool is_parent_accessible "student discretion flag"
        enum risk_level
        varchar parent_summary
    }
    case_notes {
        int id PK
        int case_id FK
        bool is_confidential "encrypted, never shown to parents"
        text body
    }
    consent_events {
        int case_id FK
        bool granted
        enum consent_method
        int recorded_by FK
    }
    incident_reports {
        int id PK
        int student_id FK
        int reported_by FK
        enum category
        enum severity
        enum status "new -> acknowledged -> linked"
    }
    clinic_passes {
        int id PK
        char pass_code UK
        enum reason_type "medical | injury | psychological | other"
        enum status
    }
    clinic_visits {
        int id PK
        int pass_id FK "NULL = walk-in"
        datetime arrived_at
        datetime departed_at
        bool is_sensitive "parents see date/time only"
    }
    audit_logs {
        bigint id PK
        int actor_id
        varchar action
        varchar entity
        int student_id
        varchar changed_fields "names only, never values"
    }
```

`audit_logs` and `login_attempts` have no foreign keys on purpose: logs must outlive the records they describe.

## How the spec's entities map to tables

| Spec entity        | Tables |
|--------------------|--------|
| Users, Roles       | `users`, `roles`, `login_attempts` |
| Students           | `students`, `sections`, `section_teachers` |
| Parent_Links       | `parent_student_links` |
| Attendance         | `attendance` (+ `grades` for academic performance) |
| Guidance_Records   | `incident_reports` (teacher → guidance inbox), `guidance_cases`, `case_notes`, `interventions`, `counseling_sessions`, `consent_events` |
| Clinic_Records     | `clinic_passes`, `clinic_visits`, `medical_profiles`, `medications_administered`, `inventory_items`, `inventory_movements` |
| Commendations      | `commendations` |
| Audit trail        | `audit_logs` (append-only, enforced by triggers) |
| Early warning      | `v_wellbeing_signals` view (30-day counts per student) |

## Who can see what

| Data | Super Admin | Teacher | Guidance | Clinic | Parent |
|---|---|---|---|---|---|
| Accounts, parent-link approvals, system activity | ✅ | – | – | – | – |
| Attendance, grades | – | own sections | ✅ | – | (later module) |
| Incident reports | – | own submissions | ✅ | – | – |
| Guidance cases (non-mental-health) | – | – | ✅ | – | ✅ approved link |
| Guidance cases (mental health) | – | – | ✅ | – | only if `is_parent_accessible = 1`; otherwise a locked "private" card |
| Confidential case notes | – | – | ✅ (decrypted, each read logged) | – | never |
| Clinic visits | – | – | – | ✅ | ✅ (sensitive visits: date/time only) |
| Commendations | – | ✅ | ✅ | – | ✅ |
