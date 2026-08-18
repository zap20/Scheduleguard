-- ScheduleGuard seed data (CICT focus)
-- Default password for every seeded user: Password123!
--
-- Seed primary keys are short readable IDs (dept-cict, user-cict-dean, …).
-- New rows created by the app still get UUID v4 via generateUid().
-- Human-facing person ID is schoolId (YYYY-NNN), unique per role.
-- For full 24-faculty + 2-month attendance demos, also run:
--   php database/focus_cict.php
--   php database/seed_attendance_two_months.php

USE scheduleguard;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE auditLog;
TRUNCATE TABLE enrollment;
TRUNCATE TABLE classBlockMember;
TRUNCATE TABLE classBlock;
TRUNCATE TABLE block;
TRUNCATE TABLE attendanceRecord;
TRUNCATE TABLE schedule;
TRUNCATE TABLE subject;
TRUNCATE TABLE student;
TRUNCATE TABLE faculty;
TRUNCATE TABLE departmentUser;
TRUNCATE TABLE `user`;
TRUNCATE TABLE room;
TRUNCATE TABLE department;
SET FOREIGN_KEY_CHECKS = 1;

-- Departments (CICT only)
INSERT INTO department (uid, name, createdAt) VALUES
('dept-cict', 'CICT', NOW()),
('dept-crim', 'Criminology', NOW()),
('dept-gened', 'GENED', NOW());

-- Rooms
INSERT INTO room (uid, name, building, capacity, roomType, createdAt) VALUES
('room-lab101', 'Lab 101', 'Main Building', 40, 'LAB', NOW()),
('room-205', 'Room 205', 'Annex', 35, 'LECTURE', NOW()),
('room-lab201', 'Lab 201', 'Main Building', 30, 'LAB', NOW()),
('room-301', 'Room 301', 'Annex', 45, 'LECTURE', NOW()),
('room-206', 'Room 206', 'Annex', 40, 'LECTURE', NOW()),
('room-207', 'Room 207', 'Annex', 40, 'LECTURE', NOW());

-- Accounts (identity + auth). Department / faculty / student rows follow.
-- schoolId format: YYYY-NNN (e.g. 2020-001)
-- passwordHash = bcrypt("Password123!")
INSERT INTO `user` (
  uid,
  firstName,
  lastName,
  email,
  schoolId,
  role,
  phoneNumber,
  status,
  passwordHash,
  createdAt
) VALUES
(
  'user-checker',
  'Carla',
  'Checker',
  'checker@scheduleguard.test',
  '2020-101',
  'Checker',
  '09010000001',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-cict-dean',
  'Dana',
  'Dean',
  'cict.dean@scheduleguard.test',
  '2020-201',
  'Dean',
  '09010000003',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-hr',
  'Helen',
  'HR',
  'hr@scheduleguard.test',
  '2020-301',
  'HR',
  '09010000004',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-cict-ph',
  'Paula',
  'ProgramHead',
  'cict.programhead@scheduleguard.test',
  '2020-401',
  'ProgramHead',
  '09010000005',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-cict-student',
  'Sam',
  'Student',
  'cict.student@scheduleguard.test',
  '2020-001',
  'Student',
  '09010000006',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-cict-fac-01',
  'Ana',
  'Santos',
  'cict.faculty01@scheduleguard.test',
  '2020-501',
  'Faculty',
  '09010000501',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-cict-fac-02',
  'Ben',
  'Garcia',
  'cict.faculty02@scheduleguard.test',
  '2020-502',
  'Faculty',
  '09010000502',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-gened-dean',
  'Gina',
  'Dean',
  'gened.dean@scheduleguard.test',
  '2020-202',
  'Dean',
  '09010000013',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-gened-ph',
  'Grace',
  'ProgramHead',
  'gened.programhead@scheduleguard.test',
  '2020-402',
  'ProgramHead',
  '09010000015',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-gened-fac-01',
  'Cara',
  'Reyes',
  'gened.faculty01@scheduleguard.test',
  '2020-601',
  'Faculty',
  '09010000601',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
);

INSERT INTO departmentUser (userId, departmentId, createdAt) VALUES
('user-checker', 'dept-cict', NOW()),
('user-cict-dean', 'dept-cict', NOW()),
('user-hr', 'dept-cict', NOW()),
('user-cict-ph', 'dept-cict', NOW()),
('user-cict-student', 'dept-cict', NOW()),
('user-cict-fac-01', 'dept-cict', NOW()),
('user-cict-fac-02', 'dept-cict', NOW()),
('user-gened-dean', 'dept-gened', NOW()),
('user-gened-ph', 'dept-gened', NOW()),
('user-gened-fac-01', 'dept-gened', NOW());

INSERT INTO faculty (userId, employmentType, createdAt) VALUES
('user-cict-fac-01', 'Regular', NOW()),
('user-cict-fac-02', 'Regular', NOW()),
('user-gened-fac-01', 'Regular', NOW());

INSERT INTO student (userId, yearLevel, studentType, enrollmentEvalStatus, createdAt) VALUES
('user-cict-student', '1st Year', 'Regular', 'Pending', NOW());

-- Subjects (CICT majors + cross-program minor)
INSERT INTO subject (
  uid, departmentId, servingDepartmentId, code, title, yearLevel, semester, curriculumYear,
  subjectType, units, lectureHours, labHours, labSessionCount, preferredRoomType, status, createdAt
) VALUES
(
  'subj-db101',
  'dept-cict',
  NULL,
  'DB101',
  'Introduction to Databases',
  '1st Year',
  '1st Semester',
  2026,
  'MAJOR',
  3.0,
  1.5,
  0,
  1,
  'LECTURE',
  'Active',
  NOW()
),
(
  'subj-it205',
  'dept-cict',
  NULL,
  'IT205',
  'Web Application Development',
  '2nd Year',
  '1st Semester',
  2026,
  'MAJOR',
  3.0,
  1.5,
  3.0,
  2,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it322',
  'dept-cict',
  NULL,
  'IT 322',
  'Systems Integration and Architecture',
  '3rd Year',
  '1st Semester',
  2026,
  'MAJOR',
  3.0,
  1.5,
  3.0,
  2,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it323',
  'dept-cict',
  NULL,
  'IT 323',
  'Applications Development and Emerging Technologies',
  '3rd Year',
  '1st Semester',
  2026,
  'MAJOR',
  3.0,
  1.5,
  3.0,
  2,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it324',
  'dept-cict',
  NULL,
  'IT 324',
  'Information Assurance and Security',
  '3rd Year',
  '1st Semester',
  2026,
  'MAJOR',
  3.0,
  1.5,
  0,
  1,
  'LECTURE',
  'Active',
  NOW()
),
(
  'subj-if211',
  'dept-cict',
  'dept-crim',
  'IF211',
  'IT Fundamentals',
  '1st Year',
  '1st Semester',
  2026,
  'MINOR',
  3.0,
  1.5,
  1.5,
  1,
  'LECTURE',
  'Active',
  NOW()
);

-- Sample schedules
INSERT INTO schedule (
  uid, facultyId, roomId, departmentId, createdBy, subjectId, day, startTime, endTime, academicYear, semester, status, createdAt
) VALUES
(
  'sched-db101',
  'user-cict-fac-01',
  'room-lab101',
  'dept-cict',
  'user-cict-ph',
  'subj-db101',
  'Monday',
  '08:00:00',
  '09:30:00',
  2025,
  '1',
  'confirmed',
  NOW()
),
(
  'sched-it205',
  'user-cict-fac-02',
  'room-205',
  'dept-cict',
  'user-cict-ph',
  'subj-it205',
  'Wednesday',
  '10:00:00',
  '11:30:00',
  2025,
  '1',
  'confirmed',
  NOW()
);

-- Attendance sample rows (small demo set).
-- For ~2 months of attendance per faculty, run:
--   php database/seed_attendance_two_months.php
INSERT INTO attendanceRecord (
  uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt
) VALUES
(
  'att-1',
  'sched-db101',
  'user-checker',
  'Present',
  0,
  DATE_SUB(NOW(), INTERVAL 2 DAY),
  DATE_SUB(NOW(), INTERVAL 2 DAY)
),
(
  'att-2',
  'sched-db101',
  'user-checker',
  'Late',
  1,
  DATE_SUB(NOW(), INTERVAL 1 DAY),
  NOW()
),
(
  'att-3',
  'sched-it205',
  'user-checker',
  'WrongRoom',
  0,
  NOW(),
  NOW()
),
(
  'att-4',
  'sched-db101',
  'user-checker',
  'Absent',
  0,
  DATE_SUB(NOW(), INTERVAL 3 DAY),
  DATE_SUB(NOW(), INTERVAL 3 DAY)
),
(
  'att-5',
  'sched-it205',
  'user-checker',
  'Absent',
  0,
  DATE_SUB(NOW(), INTERVAL 4 DAY),
  DATE_SUB(NOW(), INTERVAL 4 DAY)
),
(
  'att-6',
  'sched-it205',
  'user-checker',
  'NoSchedule',
  0,
  DATE_SUB(NOW(), INTERVAL 5 DAY),
  DATE_SUB(NOW(), INTERVAL 5 DAY)
);

-- Sample block (inactive example)
INSERT INTO block (uid, studentId, departmentId, issuedBy, reason, status, createdAt) VALUES
(
  'block-1',
  'user-cict-student',
  'dept-cict',
  'user-cict-dean',
  'Seeded example block for testing clearance workflows.',
  'Cleared',
  DATE_SUB(NOW(), INTERVAL 7 DAY)
);

-- Audit seed
INSERT INTO auditLog (uid, userId, relatedRecordId, action, module, message, timestamp) VALUES
(
  'audit-1',
  'user-cict-ph',
  'sched-db101',
  'CREATE',
  'schedule',
  'Seeded schedule created for Introduction to Databases.',
  NOW()
);
