-- ScheduleGuard schema
-- MySQL 8+ / MariaDB 10.4+

CREATE DATABASE IF NOT EXISTS scheduleguard
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE scheduleguard;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS auditLog;
DROP TABLE IF EXISTS enrollment;
DROP TABLE IF EXISTS block;
DROP TABLE IF EXISTS attendanceRecord;
DROP TABLE IF EXISTS schedule;
DROP TABLE IF EXISTS subject;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS room;
DROP TABLE IF EXISTS department;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE department (
  uid VARCHAR(36) NOT NULL,
  name VARCHAR(150) NOT NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_department_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room (
  uid VARCHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  building VARCHAR(100) NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  roomType ENUM('LAB', 'LECTURE') NOT NULL DEFAULT 'LECTURE',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_room_building_name (building, name),
  KEY idx_room_type (roomType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user` (
  uid VARCHAR(36) NOT NULL,
  departmentId VARCHAR(36) NULL,
  firstName VARCHAR(100) NOT NULL,
  lastName VARCHAR(100) NOT NULL,
  email VARCHAR(191) NOT NULL,
  schoolId VARCHAR(20) NOT NULL,
  role ENUM('Checker', 'Faculty', 'Dean', 'HR', 'ProgramHead', 'Student') NOT NULL,
  phoneNumber VARCHAR(30) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Active',
  -- Auth fields (required for bcrypt + token-based API access; not part of domain model)
  passwordHash VARCHAR(255) NOT NULL,
  apiToken CHAR(64) NULL,
  tokenExpiresAt DATETIME NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_user_email (email),
  UNIQUE KEY uq_user_api_token (apiToken),
  UNIQUE KEY uq_user_school_role (schoolId, role),
  KEY idx_user_department (departmentId),
  KEY idx_user_role (role),
  CONSTRAINT fk_user_department
    FOREIGN KEY (departmentId) REFERENCES department (uid)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subject (
  uid VARCHAR(36) NOT NULL,
  departmentId VARCHAR(36) NOT NULL,
  code VARCHAR(50) NOT NULL,
  title VARCHAR(200) NOT NULL,
  yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NOT NULL,
  semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
  curriculumYear SMALLINT UNSIGNED NOT NULL,
  units DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  preferredRoomType ENUM('LAB', 'LECTURE') NOT NULL DEFAULT 'LECTURE',
  status VARCHAR(30) NOT NULL DEFAULT 'Active',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_subject_dept_code_year (departmentId, code, curriculumYear),
  KEY idx_subject_department (departmentId),
  KEY idx_subject_year_sem (yearLevel, semester),
  KEY idx_subject_curriculum_year (curriculumYear),
  KEY idx_subject_status (status),
  CONSTRAINT fk_subject_department
    FOREIGN KEY (departmentId) REFERENCES department (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule (
  uid VARCHAR(36) NOT NULL,
  facultyId VARCHAR(36) NULL,
  roomId VARCHAR(36) NOT NULL,
  departmentId VARCHAR(36) NOT NULL,
  createdBy VARCHAR(36) NOT NULL,
  subjectId VARCHAR(36) NOT NULL,
  day VARCHAR(20) NOT NULL,
  startTime TIME NOT NULL,
  endTime TIME NOT NULL,
  academicYear SMALLINT UNSIGNED NOT NULL,
  semester VARCHAR(10) NOT NULL,
  yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NULL,
  blockNumber INT UNSIGNED NULL,
  blockName VARCHAR(100) NULL,
  classBlockId VARCHAR(36) NULL,
  studentType VARCHAR(20) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  KEY idx_schedule_faculty (facultyId),
  KEY idx_schedule_room (roomId),
  KEY idx_schedule_department (departmentId),
  KEY idx_schedule_created_by (createdBy),
  KEY idx_schedule_subject (subjectId),
  KEY idx_schedule_term (academicYear, semester),
  KEY idx_schedule_block (departmentId, yearLevel, academicYear, semester, blockNumber),
  KEY idx_schedule_class_block (classBlockId),
  CONSTRAINT fk_schedule_faculty
    FOREIGN KEY (facultyId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_room
    FOREIGN KEY (roomId) REFERENCES room (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_department
    FOREIGN KEY (departmentId) REFERENCES department (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_created_by
    FOREIGN KEY (createdBy) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_subject
    FOREIGN KEY (subjectId) REFERENCES subject (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class section blocks (Dean creates; Program Head assigns students).
-- Distinct from `block`, which is a student hold/clearance record.
CREATE TABLE classBlock (
  uid VARCHAR(36) NOT NULL,
  departmentId VARCHAR(36) NOT NULL,
  yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NOT NULL,
  blockNumber INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  academicYear SMALLINT UNSIGNED NOT NULL,
  semester VARCHAR(10) NOT NULL,
  studentType VARCHAR(20) NULL,
  createdBy VARCHAR(36) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Open',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_class_block_slot (departmentId, yearLevel, academicYear, semester, blockNumber),
  KEY idx_class_block_department (departmentId),
  KEY idx_class_block_term (academicYear, semester),
  KEY idx_class_block_created_by (createdBy),
  CONSTRAINT fk_class_block_department
    FOREIGN KEY (departmentId) REFERENCES department (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_class_block_created_by
    FOREIGN KEY (createdBy) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE classBlockMember (
  uid VARCHAR(36) NOT NULL,
  classBlockId VARCHAR(36) NOT NULL,
  studentId VARCHAR(36) NOT NULL,
  assignedBy VARCHAR(36) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'assigned',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_class_block_member (classBlockId, studentId),
  KEY idx_class_block_member_student (studentId),
  KEY idx_class_block_member_assigned_by (assignedBy),
  CONSTRAINT fk_class_block_member_block
    FOREIGN KEY (classBlockId) REFERENCES classBlock (uid)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_class_block_member_student
    FOREIGN KEY (studentId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_class_block_member_assigned_by
    FOREIGN KEY (assignedBy) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE schedule
  ADD CONSTRAINT fk_schedule_class_block
  FOREIGN KEY (classBlockId) REFERENCES classBlock (uid)
  ON UPDATE CASCADE
  ON DELETE SET NULL;

CREATE TABLE attendanceRecord (
  uid VARCHAR(36) NOT NULL,
  scheduleId VARCHAR(36) NOT NULL,
  checkerId VARCHAR(36) NOT NULL,
  status ENUM('Present', 'Late', 'WrongRoom', 'NoSchedule', 'Absent') NOT NULL,
  isOffline TINYINT(1) NOT NULL DEFAULT 0,
  timestamp DATETIME NOT NULL,
  syncedAt DATETIME NULL,
  PRIMARY KEY (uid),
  KEY idx_attendance_schedule (scheduleId),
  KEY idx_attendance_checker (checkerId),
  CONSTRAINT fk_attendance_schedule
    FOREIGN KEY (scheduleId) REFERENCES schedule (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_checker
    FOREIGN KEY (checkerId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE block (
  uid VARCHAR(36) NOT NULL,
  studentId VARCHAR(36) NOT NULL,
  departmentId VARCHAR(36) NOT NULL,
  issuedBy VARCHAR(36) NOT NULL,
  reason TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Active',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  KEY idx_block_student (studentId),
  KEY idx_block_department (departmentId),
  KEY idx_block_issued_by (issuedBy),
  CONSTRAINT fk_block_student
    FOREIGN KEY (studentId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_block_department
    FOREIGN KEY (departmentId) REFERENCES department (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_block_issued_by
    FOREIGN KEY (issuedBy) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrollment (
  uid VARCHAR(36) NOT NULL,
  studentId VARCHAR(36) NOT NULL,
  scheduleId VARCHAR(36) NOT NULL,
  assignedBy VARCHAR(36) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'assigned',
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_enrollment_student_schedule (studentId, scheduleId),
  KEY idx_enrollment_schedule (scheduleId),
  KEY idx_enrollment_assigned_by (assignedBy),
  CONSTRAINT fk_enrollment_student
    FOREIGN KEY (studentId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_schedule
    FOREIGN KEY (scheduleId) REFERENCES schedule (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_assigned_by
    FOREIGN KEY (assignedBy) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auditLog (
  uid VARCHAR(36) NOT NULL,
  userId VARCHAR(36) NOT NULL,
  relatedRecordId VARCHAR(36) NULL,
  action VARCHAR(50) NOT NULL,
  module VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  KEY idx_audit_user (userId),
  KEY idx_audit_module (module),
  KEY idx_audit_related (relatedRecordId),
  CONSTRAINT fk_audit_user
    FOREIGN KEY (userId) REFERENCES `user` (uid)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
