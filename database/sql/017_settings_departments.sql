-- HIMS migration 017: System Settings and Departments & Positions.
-- Run once in phpMyAdmin: select hr3_system > SQL tab > paste this file > Go.
-- Safe to run again: tables are only created if missing; existing data is not changed.

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value MEDIUMTEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Department names are compared without case, so "HR department" and "Hr department" count as one.
CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS positions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_positions_title (department_id, title),
  CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Start the list with the departments already used in HIMS (duplicates that differ only in case become one).
INSERT IGNORE INTO departments (name)
SELECT DISTINCT TRIM(CONVERT(department USING utf8mb4)) FROM employees WHERE department IS NOT NULL AND TRIM(department) <> ''
UNION SELECT DISTINCT TRIM(CONVERT(department USING utf8mb4)) FROM users WHERE department IS NOT NULL AND TRIM(department) <> ''
UNION SELECT DISTINCT TRIM(CONVERT(department USING utf8mb4)) FROM vacancies WHERE department IS NOT NULL AND TRIM(department) <> '';

-- Positions already used, under their department.
INSERT IGNORE INTO positions (department_id, title)
SELECT DISTINCT d.id, TRIM(CONVERT(e.position USING utf8mb4))
FROM employees e
JOIN departments d ON d.name = TRIM(CONVERT(e.department USING utf8mb4)) COLLATE utf8mb4_general_ci
WHERE e.position IS NOT NULL AND TRIM(e.position) <> '';

-- Check: the departments found in your data.
SELECT id, name FROM departments ORDER BY name;
