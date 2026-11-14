-- Batch 22: qualifications for each job opening (shown on the careers page so applicants can check if they qualify).
-- One qualification per line. Run once in MySQL Workbench.
-- First select your HIMS database (phpMyAdmin: click it on the left; MySQL Workbench: double-click it so it is bold).

ALTER TABLE vacancies ADD COLUMN qualifications TEXT NULL AFTER description;

-- Starting qualifications for the four default openings (only fills empty ones).
-- ("id > 0" keeps MySQL Workbench safe update mode happy.)
UPDATE vacancies SET qualifications = CONCAT_WS('\n',
  'Graduate of BS Nursing',
  'Valid PRC Nursing license',
  'Basic Life Support (BLS) certificate',
  'At least 1 year of hospital experience (fresh graduates may apply)',
  'Willing to work rotating and night shifts')
WHERE id > 0 AND title = 'Nurse' AND (qualifications IS NULL OR qualifications = '');

UPDATE vacancies SET qualifications = CONCAT_WS('\n',
  'Doctor of Medicine (MD) graduate',
  'Valid PRC Physician license',
  'Completed residency or specialty training',
  'BLS and ACLS certificates',
  'Willing to take on-call duty')
WHERE id > 0 AND title = 'Doctor' AND (qualifications IS NULL OR qualifications = '');

UPDATE vacancies SET qualifications = CONCAT_WS('\n',
  'At least high school graduate',
  'Valid Security Guard License (SOSIA / PNP)',
  'Physically fit, at least 21 years old',
  'Willing to work night and holiday shifts',
  'Good communication skills')
WHERE id > 0 AND title = 'Security Guard' AND (qualifications IS NULL OR qualifications = '');

UPDATE vacancies SET qualifications = CONCAT_WS('\n',
  'At least elementary or high school graduate',
  'Cleaning or sanitation experience is an advantage',
  'Willing to follow hospital infection-control routines',
  'Physically fit and willing to work shifts')
WHERE id > 0 AND title = 'Janitor' AND (qualifications IS NULL OR qualifications = '');
