-- ScheduleGuard seed data
-- Default password for every seeded user: Password123!
--
-- Seed primary keys are short readable IDs (dept-comp, user-faculty, …).
-- New rows created by the app still get UUID v4 via generateUid().
-- Human-facing person ID is schoolId (YYYY-NNN), unique per role.

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
TRUNCATE TABLE `user`;
TRUNCATE TABLE room;
TRUNCATE TABLE department;
SET FOREIGN_KEY_CHECKS = 1;

-- Departments
INSERT INTO department (uid, name, createdAt) VALUES
('dept-comp', 'College of Computing', NOW()),
('dept-eng', 'College of Engineering', NOW());

-- Rooms
INSERT INTO room (uid, name, building, capacity, roomType, createdAt) VALUES
('room-lab101', 'Lab 101', 'Main Building', 40, 'LAB', NOW()),
('room-205', 'Room 205', 'Annex', 35, 'LECTURE', NOW()),
('room-lab201', 'Lab 201', 'Main Building', 30, 'LAB', NOW()),
('room-301', 'Room 301', 'Annex', 45, 'LECTURE', NOW()),
('room-206', 'Room 206', 'Annex', 40, 'LECTURE', NOW()),
('room-207', 'Room 207', 'Annex', 40, 'LECTURE', NOW());

-- Users (1 per role + Checker)
-- schoolId format: YYYY-NNN (e.g. 2020-001)
-- Unique per role only — Faculty and Student both use 2020-001 on purpose.
-- passwordHash = bcrypt("Password123!")
INSERT INTO `user` (
  uid,
  departmentId,
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
  'dept-comp',
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
-- Faculty 2020-001 (same schoolId as Student below — allowed across roles)
(
  'user-faculty',
  'dept-comp',
  'Fiona',
  'Faculty',
  'faculty@scheduleguard.test',
  '2020-001',
  'Faculty',
  '09010000002',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-dean',
  'dept-comp',
  'Dana',
  'Dean',
  'dean@scheduleguard.test',
  '2020-201',
  'Dean',
  '09010000003',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
(
  'user-hr',
  NULL,
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
  'user-ph',
  'dept-comp',
  'Paula',
  'ProgramHead',
  'programhead@scheduleguard.test',
  '2020-401',
  'ProgramHead',
  '09010000005',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
),
-- Student 2020-001 (same schoolId as Faculty above — allowed across roles)
(
  'user-student',
  'dept-comp',
  'Sam',
  'Student',
  'student@scheduleguard.test',
  '2020-001',
  'Student',
  '09010000006',
  'Active',
  '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.',
  NOW()
);

-- Curriculum subjects (prerequisite data for schedule + AI optimizer)
INSERT INTO subject (
  uid, departmentId, code, title, yearLevel, semester, curriculumYear, units, preferredRoomType, status, createdAt
) VALUES
(
  'subj-db101',
  'dept-comp',
  'DB101',
  'Introduction to Databases',
  '1st Year',
  '1st Semester',
  2026,
  3.0,
  'LECTURE',
  'Active',
  NOW()
),
(
  'subj-it205',
  'dept-comp',
  'IT205',
  'Web Application Development',
  '2nd Year',
  '1st Semester',
  2026,
  3.0,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it322',
  'dept-comp',
  'IT 322',
  'Systems Integration and Architecture',
  '3rd Year',
  '1st Semester',
  2026,
  3.0,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it323',
  'dept-comp',
  'IT 323',
  'Applications Development and Emerging Technologies',
  '3rd Year',
  '1st Semester',
  2026,
  3.0,
  'LAB',
  'Active',
  NOW()
),
(
  'subj-it324',
  'dept-comp',
  'IT 324',
  'Information Assurance and Security',
  '3rd Year',
  '1st Semester',
  2026,
  3.0,
  'LECTURE',
  'Active',
  NOW()
);

-- Sample schedules (created by Program Head for Faculty)
INSERT INTO schedule (
  uid, facultyId, roomId, departmentId, createdBy, subjectId, day, startTime, endTime, academicYear, semester, status, createdAt
) VALUES
(
  'sched-db101',
  'user-faculty',
  'room-lab101',
  'dept-comp',
  'user-ph',
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
  'user-faculty',
  'room-205',
  'dept-comp',
  'user-ph',
  'subj-it205',
  'Wednesday',
  '10:00:00',
  '11:30:00',
  2025,
  '1',
  'confirmed',
  NOW()
);

-- Attendance sample rows
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

-- Enrollment intentionally omitted from seed so Sam Student appears on the
-- Program Head pending list when cleared. Use Enrollment UI to assign + distribute.

-- Sample block (inactive example)
INSERT INTO block (uid, studentId, departmentId, issuedBy, reason, status, createdAt) VALUES
(
  'block-1',
  'user-student',
  'dept-comp',
  'user-dean',
  'Seeded example block for testing clearance workflows.',
  'Cleared',
  DATE_SUB(NOW(), INTERVAL 7 DAY)
);

-- Audit seed
INSERT INTO auditLog (uid, userId, relatedRecordId, action, module, message, timestamp) VALUES
(
  'audit-1',
  'user-ph',
  'sched-db101',
  'CREATE',
  'schedule',
  'Seeded schedule created for Introduction to Databases.',
  NOW()
);
