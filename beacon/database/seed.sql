-- =====================================================================
-- BEACON demo data. Import AFTER schema.sql.
-- Every demo account uses the password:  Beacon@2026
-- The super admin is forced to change it on first login.
-- Remove or disable these accounts before real use.
-- =====================================================================
USE beacon;

INSERT INTO roles (id, code, label) VALUES
 (1, 'super_admin', 'Super Admin'),
 (2, 'teacher',     'Teacher'),
 (3, 'guidance',    'Guidance Office'),
 (4, 'clinic',      'Clinic'),
 (5, 'parent',      'Parent / Guardian');

INSERT INTO users (id, role_id, email, password_hash, first_name, last_name, title, phone, must_change_password, created_by) VALUES
 (1, 1, 'admin@beacon.edu',    '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'System',  'Admin',  'Super Administrator', NULL, 1, NULL),
 (2, 2, 'm.reyes@beacon.edu',  '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Mariel',  'Reyes',  'Teacher · Adviser',   NULL, 0, 1),
 (3, 3, 'g.santos@beacon.edu', '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Gabriel', 'Santos', 'Guidance Counselor',  NULL, 0, 1),
 (4, 4, 'n.flores@beacon.edu', '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Nina',    'Flores', 'School Nurse',        NULL, 0, 1),
 (5, 5, 'elena.cruz@mail.com', '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Elena',   'Cruz',   NULL,                  '0917 555 0123', 0, NULL),
 (6, 2, 'p.lim@beacon.edu',    '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Paolo',   'Lim',    'Teacher',             NULL, 0, 1);

INSERT INTO sections (id, grade_level, name, school_year, adviser_id) VALUES
 (1, 8, 'Mabini', '2026-2027', 2),
 (2, 8, 'Rizal',  '2026-2027', 6);

INSERT INTO section_teachers (section_id, teacher_id, subject) VALUES
 (1, 2, 'English'),
 (2, 6, 'Mathematics');

INSERT INTO students (id, student_no, lrn, first_name, last_name, birthdate, sex, section_id, address, guardian_name, guardian_relation, guardian_contact) VALUES
 (1, 'SV-2026-0841', '136102930188', 'Andrea',  'Cruz',    '2012-04-12', 'F', 1, 'Brgy. Poblacion, Makati City',  'Elena Cruz',    'Mother', '0917 555 0123'),
 (2, 'SV-2026-0914', '136102930192', 'Joaquin', 'Mendoza', '2012-05-14', 'M', 1, 'Purok 4, Makati City',          'Ramon Mendoza', 'Father', '0918 442 1093'),
 (3, 'SV-2026-1032', '136102930205', 'Leila',   'Santos',  '2012-09-08', 'F', 1, 'Salcedo Village, Makati City',  'Clara Santos',  'Mother', '0919 888 3412'),
 (4, 'SV-2026-0755', '136102930174', 'Nathan',  'Ramos',   '2012-01-20', 'M', 1, 'Legaspi Village, Makati City',  'Jose Ramos',    'Father', '0922 111 4567'),
 (5, 'SV-2026-1101', '136102930221', 'Bea',     'Villanueva', '2012-07-02', 'F', 2, 'San Antonio, Makati City', 'Rosa Villanueva', 'Mother', '0927 300 1188');

INSERT INTO parent_student_links (parent_id, student_id, relationship, status, reviewed_by, reviewed_at) VALUES
 (5, 1, 'Mother', 'approved', 1, '2026-11-10 09:00:00');

INSERT INTO medical_profiles (student_id, blood_type, allergies, conditions, regular_medications, updated_by) VALUES
 (2, 'O+', NULL,      'Asthma (mild, exercise-induced)', 'Salbutamol inhaler as needed', 4),
 (4, 'A+', 'Peanuts', NULL, NULL, 4);

INSERT INTO attendance (student_id, section_id, att_date, status, recorded_by) VALUES
 (1, 1, '2026-11-16', 'present', 2),
 (2, 1, '2026-11-16', 'late',    2),
 (3, 1, '2026-11-16', 'present', 2),
 (4, 1, '2026-11-16', 'absent',  2);

INSERT INTO commendations (student_id, issued_by, category, title, description, awarded_on) VALUES
 (1, 2, 'kindness', 'Peer Helper of the Week', 'Stayed after class to help a classmate catch up on the reading.', '2026-11-13'),
 (3, 2, 'academic', 'Top Scorer — English Quiz', 'Perfect score on the Unit 3 vocabulary quiz.', '2026-11-10'),
 (2, 2, 'leadership', 'Group Leader', 'Organized his group and kept everyone on task during the science project.', '2026-11-06');

INSERT INTO guidance_cases (id, student_id, opened_by, counselor_id, case_type, is_mental_health, is_parent_accessible, risk_level, status, title, parent_summary, opened_at) VALUES
 (1, 1, 3, 3, 'mental_health', 1, 0, 'moderate', 'open',       'Stress and sleep concerns',  'Andrea is meeting with the Guidance Office for regular well-being check-ins.', '2026-11-05 10:00:00'),
 (2, 1, 3, 3, 'academic',      0, 0, 'low',      'monitoring', 'Study habits support',       'Weekly study-planning sessions to help with workload in English and Science.', '2026-10-20 13:30:00');

INSERT INTO incident_reports (student_id, reported_by, record_type, category, severity, title, description, observed_at, status, case_id, acknowledged_by, acknowledged_at) VALUES
 (1, 2, 'mental_emotional', 'emotional', 'moderate', 'Withdrawn during class', 'Seemed tired and tearful during the reading activity; said she has not been sleeping well.', '2026-11-04 09:15:00', 'linked', 1, 3, '2026-11-04 11:00:00');

INSERT INTO incident_reports (student_id, reported_by, record_type, category, severity, routed_to, title, description, action_taken, observed_at, status) VALUES
 (3, 2, 'mental_emotional', 'emotional', 'moderate', 'guidance', 'Upset after group work', 'Left the room during group work and said her groupmates were ignoring her.', 'Talked with her briefly at break.', '2026-11-16 10:20:00', 'new'),
 (4, 2, 'conduct', 'attendance', 'low', 'guidance', 'Third absence this month', 'Absent again today with no excuse letter. Classmates say he has been helping at the family store.', NULL, '2026-11-16 08:00:00', 'new');

INSERT INTO case_notes (case_id, author_id, note_type, is_confidential, body, created_at) VALUES
 (2, 3, 'remark', 0, 'Andrea now uses a weekly planner and has submitted all English requirements this month.', '2026-11-12 14:00:00');

INSERT INTO interventions (case_id, plan_type, goals, strategies, start_date, review_date, status, created_by) VALUES
 (2, 'counseling', 'Keep up with weekly requirements', 'Weekly 20-minute planning session; adviser check-in on Fridays', '2026-10-21', '2026-12-02', 'active', 3);

INSERT INTO intervention_updates (intervention_id, progress, note, created_by, created_at) VALUES
 (1, 'improving', 'Using the planner daily; one late requirement this week (Science).', 3, '2026-11-12 14:10:00');

INSERT INTO counseling_sessions (student_id, case_id, counselor_id, session_type, priority, scheduled_start, scheduled_end, location, created_by) VALUES
 (1, 1, 3, 'check_in', 'priority', '2026-11-19 10:00:00', '2026-11-19 10:30:00', 'Guidance Room 2', 3);

INSERT INTO inventory_items (name, category, unit, quantity, reorder_level, expiry_date) VALUES
 ('Paracetamol 500mg',  'medicine', 'tablet', 180, 50, '2027-08-31'),
 ('Salbutamol nebule',  'medicine', 'nebule',   8, 10, '2027-03-31'),
 ('Adhesive bandage',   'supply',   'pc',     240, 60, NULL),
 ('Antiseptic solution','supply',   'bottle',   3,  4, '2027-06-30'),
 ('Digital thermometer','equipment','pc',       4,  2, NULL);

INSERT INTO clinic_visits (student_id, attended_by, arrived_at, departed_at, complaint, assessment, temperature_c, disposition, case_status, medication_notes, parent_notified) VALUES
 (2, 4, '2026-11-16 09:48:00', '2026-11-16 10:30:00', 'Shortness of breath after PE', 'Mild wheeze, settled after own inhaler and rest.', 36.8, 'returned_to_class', 'cleared_to_class', 'Own salbutamol inhaler, 2 puffs at 9:55 AM (student''s own supply).', 1),
 (4, 4, '2026-11-13 13:10:00', '2026-11-13 14:05:00', 'Headache and fever', 'Temp 38.1°C. Guardian picked up.', 38.1, 'sent_home', 'sent_home', 'Paracetamol 500mg, 1 tablet orally at 1:20 PM.', 1);

INSERT INTO medications_administered (visit_id, item_id, medication_name, dose, route, quantity_used, administered_at, administered_by) VALUES
 (2, 1, 'Paracetamol 500mg', '1 tablet', 'oral', 1, '2026-11-13 13:20:00', 4);

INSERT INTO inventory_movements (item_id, change_qty, reason, medication_id, performed_by, created_at) VALUES
 (1, -1, 'administered', 1, 4, '2026-11-13 13:20:00'),
 (3, 100, 'restock', NULL, 4, '2026-11-10 08:00:00');

UPDATE inventory_items SET requested_qty = 20, request_note = 'Ordered from supplier, arriving next week', requested_at = '2026-11-15 09:00:00' WHERE name = 'Salbutamol nebule';

INSERT INTO users (id, role_id, email, password_hash, first_name, last_name, phone, must_change_password, created_at) VALUES
 (7, 5, 'ramon.mendoza@mail.com', '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Ramon', 'Mendoza', '0918 442 1093', 0, '2026-11-17 19:40:00'),
 (8, 5, 'j.reyes.parent@mail.com', '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu', 'Josefina', 'Reyes', NULL, 0, '2026-11-17 21:05:00');

INSERT INTO parent_student_links (parent_id, student_id, relationship, status, created_at) VALUES
 (7, 2, 'Father', 'pending', '2026-11-17 19:41:00'),
 (8, 4, 'Grandparent', 'pending', '2026-11-17 21:06:00');

INSERT INTO notices (title, body, tag, audience, grade_level, section_id, requires_ack, posted_by, published_at) VALUES
 ('Parent–Adviser Conference this Friday', 'We are inviting all Grade 8 – Mabini families to a short conference on Friday, Nov 20, 3:00–5:00 PM in Room 204. We will go over first-quarter progress and well-being plans. Please confirm you have read this notice.', 'important', 'section', NULL, 1, 1, 2, '2026-11-16 15:00:00'),
 ('Rainy-season health reminders', 'Please pack an extra shirt and an umbrella. Students with asthma should bring their own inhaler, labelled with their name. The clinic is open 7:00 AM – 5:00 PM.', 'clinic', 'all', NULL, NULL, 0, 4, '2026-11-14 08:30:00'),
 ('Mental Health Awareness Week', 'Next week the Guidance Office hosts short, optional activities on stress and sleep. Any student can drop by the Guidance Office — no appointment needed. Talking with your child about how they feel at school helps a lot.', 'guidance', 'all', NULL, NULL, 0, 3, '2026-11-12 10:00:00'),
 ('Grade 8 Science Fair schedule', 'The Grade 8 Science Fair is on Dec 4. Projects are due to advisers by Nov 27.', 'announcement', 'grade', 8, NULL, 0, 1, '2026-11-10 09:00:00');
