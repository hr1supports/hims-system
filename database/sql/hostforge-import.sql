
-- ===== schema.sql =====

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('HR1', 'HR2', 'HR3', 'HR4', 'ADMIN', 'EMPLOYEE', 'TRAINEE', 'PATIENT') NOT NULL DEFAULT 'EMPLOYEE',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_code VARCHAR(50) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NULL,
  department VARCHAR(100) NULL,
  position VARCHAR(100) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_code (employee_code),
  UNIQUE KEY uq_employees_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_leave_types_name (name)
) ENGINE=InnoDB;

INSERT IGNORE INTO leave_types (id, name, description) VALUES
  (1, 'Vacation Leave', 'Planned time away from work'),
  (2, 'Sick Leave', 'Leave for illness or medical care'),
  (3, 'Emergency Leave', 'Urgent personal or family matters');

CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attendance_employee_date (employee_id, work_date),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_attendance_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS overtime_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  work_date DATE NULL,
  date DATE NULL,
  hours DECIMAL(5,2) NOT NULL,
  notes TEXT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_overtime_employee_date (employee_id, work_date),
  KEY idx_overtime_user_date (user_id, date),
  CONSTRAINT fk_overtime_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_overtime_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_overtime_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  notes TEXT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leave_requests_employee_date (employee_id, start_date),
  CONSTRAINT fk_leave_requests_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_leave_requests_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (id),
  CONSTRAINT fk_leave_requests_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leaves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  notes TEXT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leaves_user_date (user_id, start_date),
  CONSTRAINT fk_leaves_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_leaves_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  description TEXT NULL,
  receipt_path VARCHAR(255) NULL,
  status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claims_employee (employee_id),
  KEY idx_claims_user (user_id),
  CONSTRAINT fk_claims_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_claims_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_claims_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rest_days (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,
  rest_date DATE NOT NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rest_days_employee_date (employee_id, rest_date),
  CONSTRAINT fk_rest_days_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
  CONSTRAINT fk_rest_days_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_created (user_id, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB;

-- ===== 001_hiring.sql =====

-- Batch 1: hiring tables (vacancies, applications). Select the target database before running.

CREATE TABLE IF NOT EXISTS vacancies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  department VARCHAR(100) NOT NULL,
  employment_type ENUM('Full-time', 'Part-time', 'Contract', 'Trainee') NOT NULL DEFAULT 'Full-time',
  slots INT UNSIGNED NOT NULL DEFAULT 1,
  deadline DATE NULL,
  description TEXT NULL,
  status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vacancies_status (status),
  CONSTRAINT fk_vacancies_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vacancy_id INT UNSIGNED NULL,
  position_title VARCHAR(150) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(50) NULL,
  note TEXT NULL,
  status ENUM('Applied', 'Screening', 'Interview', 'Hired', 'Rejected') NOT NULL DEFAULT 'Applied',
  resume_original_name VARCHAR(255) NULL,
  resume_stored_name VARCHAR(80) NULL,
  resume_size INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applications_vacancy (vacancy_id),
  KEY idx_applications_email (email),
  KEY idx_applications_ip_time (ip, created_at),
  CONSTRAINT fk_applications_vacancy FOREIGN KEY (vacancy_id) REFERENCES vacancies (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Starting vacancies (only inserted when the table is empty).
INSERT INTO vacancies (title, department, employment_type, slots, description, status)
SELECT * FROM (
  SELECT 'Nurse' AS title, 'Nursing' AS department, 'Full-time' AS employment_type, 5 AS slots,
         'Licensed nurse with a valid PRC ID. Ward, ER or ICU experience preferred. Rotating shifts.' AS description, 'Open' AS status
  UNION ALL SELECT 'Doctor', 'Medical Staff', 'Full-time', 2,
         'Licensed physician with a PRC ID and completed residency or specialty training.', 'Open'
  UNION ALL SELECT 'Security Guard', 'Security', 'Full-time', 4,
         'Valid security guard license. Able to work night and holiday shifts and stay calm in emergencies.', 'Open'
  UNION ALL SELECT 'Janitor', 'Housekeeping', 'Full-time', 4,
         'Cleaning or sanitation experience. Willing to follow hospital infection-control routines. No degree required.', 'Open'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM vacancies);


-- ===== 002_security.sql =====

-- Batch 2: security. Select the target database before running.

ALTER TABLE users
  ADD COLUMN department VARCHAR(100) NULL AFTER role,
  ADD COLUMN position VARCHAR(100) NULL AFTER department,
  ADD COLUMN approval ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved' AFTER is_active;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('login', 'signup') NOT NULL DEFAULT 'login',
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_email_ip (kind, email, ip, created_at),
  KEY idx_attempts_ip (kind, ip, created_at)
) ENGINE=InnoDB;


-- ===== 003_archive.sql =====

-- Batch 3: archive applicants instead of deleting them. Select the target database before running.

ALTER TABLE applications
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD KEY idx_applications_archived (archived);


-- ===== 004_archive_vacancies.sql =====

-- Batch 4: archive vacancies instead of deleting them. Select the target database before running.

ALTER TABLE vacancies
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD KEY idx_vacancies_archived (archived);


-- ===== 005_super_admin.sql =====

-- Batch 5: Super Admin access and account audit history.
-- Run after database/002_security.sql in the selected target database.

ALTER TABLE users
  MODIFY role ENUM('HR1', 'HR2', 'HR3', 'HR4', 'ADMIN', 'EMPLOYEE', 'TRAINEE', 'PATIENT', 'SUPER_ADMIN') NOT NULL DEFAULT 'EMPLOYEE';

UPDATE users
SET email = 'hr1superadmin@hospital.com'
WHERE email = 'hr1superadmin@1234' AND role = 'SUPER_ADMIN';

UPDATE users
SET email = 'hr1admin@hospital.com'
WHERE email = 'hr3@hotel.com' AND role IN ('HR3', 'ADMIN');

INSERT INTO users (name, email, password_hash, role, department, position, is_active, approval)
VALUES (
  'HR1 Super Administrator',
  'hr1superadmin@hospital.com',
  '$2y$12$nJ.rf3lWdLw37.ooo.EIeu6xmz.Bt2yVoyCXlkvKAjrYZ8kEM6P1q',
  'SUPER_ADMIN',
  'System Administration',
  'Super Administrator',
  1,
  'approved'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  password_hash = VALUES(password_hash),
  role = 'SUPER_ADMIN',
  department = VALUES(department),
  position = VALUES(position),
  is_active = 1,
  approval = 'approved';

CREATE TABLE IF NOT EXISTS super_admin_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target_user_id INT UNSIGNED NULL,
  details VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_super_admin_audit_created (created_at),
  KEY idx_super_admin_audit_target (target_user_id),
  CONSTRAINT fk_super_admin_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ===== 006_admin_password.sql =====

-- Batch 6: seed or update the HR1 Admin demo account credentials.
-- Run after database/002_security.sql and database/005_super_admin.sql in the selected target database.

INSERT INTO users (name, email, password_hash, role, department, position, is_active, approval)
VALUES (
	'HR1 Administrator',
	'hr1admin@hospital.com',
	'$2y$10$GpXARXofxsesZP8uWRZEe.3.jqONldq.pgTrlt/G4Tz6051yPqAqm',
	'HR1',
	'HR Administration',
	'Administrator',
	1,
	'approved'
)
ON DUPLICATE KEY UPDATE
	name = VALUES(name),
	password_hash = VALUES(password_hash),
	role = 'HR1',
	department = VALUES(department),
	position = VALUES(position),
	is_active = 1,
	approval = 'approved';

-- ===== 007_employee_portal.sql =====

-- Batch 7: employee-owned profile, goals, and self-assessment data.
-- Run once after database/schema.sql, database/002_security.sql, and
-- database/005_super_admin.sql in the selected target database.

ALTER TABLE employees
  ADD COLUMN user_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN contact VARCHAR(50) NULL AFTER email,
  ADD COLUMN hire_date DATE NULL AFTER position,
  ADD COLUMN emergency_contact VARCHAR(255) NULL AFTER hire_date,
  ADD COLUMN salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER emergency_contact,
  MODIFY COLUMN status ENUM('active', 'inactive', 'on_leave') NOT NULL DEFAULT 'active',
  ADD UNIQUE KEY uq_employees_user_id (user_id),
  ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

UPDATE employees e
JOIN users u ON u.email = e.email AND u.role = 'EMPLOYEE'
SET e.user_id = u.id,
    e.department = COALESCE(e.department, u.department),
    e.position = COALESCE(e.position, u.position)
WHERE e.user_id IS NULL;

INSERT INTO employees (employee_code, first_name, last_name, email, department, position, status, user_id)
SELECT CONCAT('USR-', u.id),
       SUBSTRING_INDEX(u.name, ' ', 1),
       COALESCE(NULLIF(TRIM(SUBSTRING(u.name, LENGTH(SUBSTRING_INDEX(u.name, ' ', 1)) + 1)), ''), '-'),
       u.email, u.department, u.position, 'active', u.id
FROM users u
LEFT JOIN employees e ON e.user_id = u.id OR e.email = u.email
WHERE u.role = 'EMPLOYEE' AND u.is_active = 1 AND u.approval = 'approved' AND e.id IS NULL;

CREATE TABLE IF NOT EXISTS employee_goals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  goal VARCHAR(300) NOT NULL,
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_employee_goals_user (user_id, created_at),
  CONSTRAINT fk_employee_goals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_performance_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  period VARCHAR(16) NOT NULL,
  ratings JSON NOT NULL,
  average DECIMAL(3,2) NOT NULL,
  comments VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_review_period (user_id, period),
  KEY idx_employee_reviews_user (user_id, created_at),
  CONSTRAINT fk_employee_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ===== 008_announcements.sql =====

-- Batch 8: Super Admin announcements shown on the Admin and Employee dashboards.
-- Run after 007_employee_portal.sql in the selected target database.

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(1000) NULL,
  audience ENUM('all', 'admin', 'employee', 'trainee') NOT NULL DEFAULT 'all',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_created (created_at),
  CONSTRAINT fk_announcements_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ===== 009_archive_announcements_goals.sql =====

-- Batch 9: archive instead of delete (announcements and employee goals).
-- Run before using the new api.php, in the selected target database.

ALTER TABLE announcements
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER audience;

ALTER TABLE employee_goals
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER progress;


-- ===== 010_hire_orders.sql =====

-- Batch 10: vacancies become hire orders received from HR3.
-- Run in the selected target database.

ALTER TABLE vacancies
  MODIFY status ENUM('Pending', 'Open', 'Closed') NOT NULL DEFAULT 'Open',
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'HR1' AFTER status,
  ADD COLUMN source_ref VARCHAR(60) NULL AFTER source,
  ADD COLUMN requested_by VARCHAR(150) NULL AFTER source_ref,
  ADD UNIQUE KEY uq_vacancies_source_ref (source, source_ref);


-- ===== 011_admin_schema_repair.sql =====

-- Ensure the Super Admin tables and announcement archive field exist.
-- Safe on a fresh import and on a database where these objects already exist.

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(1000) NULL,
  audience ENUM('all', 'admin', 'employee', 'trainee') NOT NULL DEFAULT 'all',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_created (created_at),
  CONSTRAINT fk_announcements_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS super_admin_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target_user_id INT UNSIGNED NULL,
  details VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_super_admin_audit_created (created_at),
  KEY idx_super_admin_audit_target (target_user_id),
  CONSTRAINT fk_super_admin_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @add_announcement_archived = IF(
  EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'archived'
  ),
  'SELECT 1',
  'ALTER TABLE announcements ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER audience'
);
PREPARE repair_statement FROM @add_announcement_archived;
EXECUTE repair_statement;
DEALLOCATE PREPARE repair_statement;


-- ===== 012_trainee_accounts.sql =====

-- Database support for the post-acceptance onboarding workflow:
-- applicants remain in applications without HIMS accounts; create a TRAINEE
-- user only after acceptance, then change that same user to EMPLOYEE after
-- onboarding. The application must enforce the restricted trainee portal.
ALTER TABLE users
  MODIFY role ENUM('HR1', 'HR2', 'HR3', 'HR4', 'ADMIN', 'EMPLOYEE', 'TRAINEE', 'PATIENT', 'SUPER_ADMIN') NOT NULL DEFAULT 'EMPLOYEE';

ALTER TABLE announcements
  MODIFY audience ENUM('all', 'admin', 'employee', 'trainee') NOT NULL DEFAULT 'all';

CREATE TABLE IF NOT EXISTS trainee_training (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  program VARCHAR(150) NOT NULL DEFAULT 'General onboarding',
  start_date DATE NULL,
  expected_end_date DATE NULL,
  status ENUM('Not started', 'In progress', 'Completed') NOT NULL DEFAULT 'Not started',
  notes VARCHAR(1000) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trainee_training_user (user_id),
  CONSTRAINT fk_trainee_training_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
