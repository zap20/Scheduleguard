# ScheduleGuard — Web System Audit (pre-Flutter)

**Scope:** Fact-finding only. No Flutter or sync endpoint code in this document.  
**Method:** Inspected repo files (no `composer.json` / `package.json` / ORM layer present).  
**Generated from:** `database/schema.sql`, `includes/*`, `api/**/*.php`, `public/js/*`, `router.php`, `config/*`.

---

## 1. Backend stack

| Item | Finding | Evidence |
|------|---------|----------|
| Language | **PHP** (strict types, PHP 8+ features e.g. `str_starts_with`) | `router.php`, all `api/` + `includes/` |
| Framework | **None** (plain PHP scripts, not Laravel/Symfony/etc.) | No `composer.json`; one file ≈ one route under `api/` |
| Package managers | **None found** | No `composer.json`, `package.json`, `requirements.txt`, `pom.xml`, `Gemfile`, `go.mod`, `Cargo.toml` |
| HTTP entry | Built-in server router maps `/api/*` → `api/**/*.php`, else `public/` | `router.php` L5–L24 |
| DB access | **PDO** MySQL/MariaDB (no ORM / Eloquent / Doctrine) | `config/database.php` L10–L32 |
| Frontend | Static HTML + vanilla JS (no React/Vue build) | `public/*.html`, `public/js/*.js` |
| Auth crypto | `password_verify` / bcrypt hashes; API tokens hashed SHA-256 | `api/auth/login.php` L28; `includes/Auth.php` L164, L208 |

**Conclusion:** Custom PHP + MySQL/MariaDB JSON API + static web UI. Not a GraphQL server.

---

## 2. Database tables / fields

**Canonical schema:** `database/schema.sql` (header L1–L2: MySQL 8+ / MariaDB 10.4+).  
**No file named** `data-dictionary*`, `ERD*`, or similar was found; schema + `database/migrate_*.php` are the dictionary.

### Drop / create order note
`schema.sql` L12–L20 drops `auditLog`, `enrollment`, `block`, `attendanceRecord`, `schedule`, `subject`, `user`, `room`, `department`. `classBlock` / `classBlockMember` are created later (L167+) and linked via `ALTER TABLE schedule` (L219–L223).

### Tables and fields (from `database/schema.sql`)

#### `department` (L24–L30)
`uid`, `name`, `createdAt`

#### `room` (L32–L42)
`uid`, `name`, `building`, `capacity`, `roomType` ENUM(`LAB`,`LECTURE`), `createdAt`

#### `user` (L44–L78)
`uid`, `departmentId`, `firstName`, `lastName`, `email`, `schoolId`,  
`role` ENUM(`Checker`,`Faculty`,`Dean`,`HR`,`ProgramHead`,`Student`),  
`phoneNumber`, `status`,  
`employmentType` ENUM(`Regular`,`PartTime`) NULL,  
`yearLevel` ENUM(1st–4th Year) NULL,  
`studentType` ENUM(`Regular`,`Irregular`) NULL,  
`enrollmentEvalStatus` ENUM(`Pending`,`Approved`,`Rejected`) NULL,  
`enrollmentEvalBy`, `enrollmentEvalAt`, `enrollmentEvalNotes`,  
`passwordHash`, `apiToken`, `tokenExpiresAt`, `createdAt`

#### `subject` (L80–L113)
`uid`, `departmentId`, `servingDepartmentId`, `code`, `title`, `yearLevel`,  
`semester` ENUM(`1st Semester`,`2nd Semester`,`Summer`), `curriculumYear`,  
`subjectType` ENUM(`MAJOR`,`MINOR`), `units`, `lectureHours`, `labHours`,  
`labSessionCount`, `preferredRoomType`, `status`, `createdAt`

#### `schedule` (L115–L163) — teaching meeting row
`uid`, `facultyId` NULL (TBF when null), `roomId`, `departmentId`, `createdBy`, `subjectId`,  
`day`, `startTime`, `endTime`, `academicYear`, `semester`,  
`yearLevel`, `blockNumber`, `blockName`, **`classBlockId`**, `studentType`,  
`status` (default `draft`), `createdAt`

#### `classBlock` (L165–L192) — **class section** (not a hold)
Comment L165–L166: distinct from student hold table `` `block` ``.  
`uid`, `departmentId`, `yearLevel`, `blockNumber`, `name`, `academicYear`, `semester`,  
`studentType`, `createdBy`, `status` (default `Open`), `createdAt`

#### `classBlockMember` (L194–L217)
`uid`, `classBlockId`, `studentId`, `assignedBy`, `status` (default `assigned`), `createdAt`

#### `attendanceRecord` (L225–L247) — attendance verification row
`uid`, `scheduleId`, `checkerId`,  
`status` ENUM(`Present`,`Late`,`WrongRoom`,`NoSchedule`,`Absent`),  
`isOffline` TINYINT(1) default 0,  
`timestamp` DATETIME (scan time),  
`syncedAt` DATETIME NULL  

Notes in schema L233–L235: countable late/absent uses `schedule.startTime`/`endTime` + `ATTENDANCE_GRACE_MINUTES` (env; see `includes/Attendance.php`).

**Not stored on `attendanceRecord`:** `faculty_id`, `class_block_id`, device/method. Faculty and class section are reached via `schedule` (`schedule.facultyId`, `schedule.classBlockId`).

#### `block` (L249–L273) — **student blocking / enrollment hold**
`uid`, `studentId`, `departmentId`, `issuedBy`, `reason`, `status` (default `Active`), `createdAt`

#### `enrollment` (L275–L298) — student ↔ **schedule** (not classBlock directly)
`uid`, `studentId`, `scheduleId`, `assignedBy`, `status` (default `assigned`), `createdAt`

#### `auditLog` (L300+)
`uid`, `userId`, `relatedRecordId`, `action`, `module`, `message`, `timestamp`

### Migrations (additive history; many folded into current schema)
Under `database/migrate_*.php`, notably:  
`migrate_class_block.php`, `migrate_curriculum.php`, `migrate_semester.php`,  
`migrate_schedule_faculty_nullable.php`, `migrate_user_faculty_student_fields.php`,  
`migrate_student_evaluation.php`, `migrate_school_id.php`, `migrate_room_type.php`,  
`migrate_subject_major_minor.php`, `migrate_schedule_command.php`, etc.

---

## 3. API endpoints (REST-style JSON; not GraphQL)

Routing: path `/api/...` → file `api/...` (`router.php` L15–L18).  
Auth: most routes call `requireRoles([...])` from `includes/Auth.php`.

### Authentication / session tokens

| Method | Path | Roles | File |
|--------|------|-------|------|
| POST | `/api/auth/login.php` | public | `api/auth/login.php` |
| POST | `/api/auth/logout.php` | any authenticated | `api/auth/logout.php` |
| GET | `/api/auth/me.php` | any authenticated | `api/auth/me.php` |

### Faculty / attendance

| Method | Path | Roles | File | Notes |
|--------|------|-------|------|-------|
| GET | `/api/faculty/index.php` | Dean, ProgramHead, HR | `api/faculty/index.php` | Faculty list |
| GET | `/api/faculty/schedule.php` | Faculty | `api/faculty/schedule.php` | Own teaching schedule |
| GET | `/api/attendance/mine.php` | Faculty | `api/attendance/mine.php` | **Read-only** own scans |
| GET | `/api/attendance/review.php` | HR | `api/attendance/review.php` | Read-only review |
| GET | `/api/attendance/oversight.php` | Dean | `api/attendance/oversight.php` | Read-only oversight |
| GET | `/api/attendance/analytics.php` | Dean, HR | `api/attendance/analytics.php` | Analytics |

**There is no `POST/PUT` under `api/attendance/`** that inserts a check-in (confirmed by listing `api/attendance/*` — only the four GET files above).

### Class block / class schedule

| Method | Path | Roles | File |
|--------|------|-------|------|
| GET | `/api/class-blocks/list.php` | Dean, ProgramHead | `api/class-blocks/list.php` |
| POST | `/api/class-blocks/create.php` | Dean | `api/class-blocks/create.php` |
| GET | `/api/class-blocks/members.php` | Dean, ProgramHead | `api/class-blocks/members.php` |
| POST | `/api/class-blocks/assign-student.php` | ProgramHead | `api/class-blocks/assign-student.php` |
| GET | `/api/class-blocks/schedules.php` | Dean, ProgramHead | `api/class-blocks/schedules.php` |
| GET/POST… | `/api/schedules/*` | mostly Dean | list/create/update/confirm/override/conflicts/import/faculty-view/student-view/parse-command/generate-*/save-option/parse-load-command/assign-load-command/remove-load |

Genetic schedule generation (local PHP, no external AI):  
`api/schedules/parse-command.php`, `generate-from-command.php`, `generate-options.php`, `save-option.php` → `includes/ScheduleOptimizer.php` / `ScheduleCommand.php`.

### Student blocking (enrollment holds)

| Method | Path | Roles | File |
|--------|------|-------|------|
| GET | `/api/blocking/list.php` | ProgramHead, Dean, HR | `api/blocking/list.php` |
| POST | `/api/blocking/create.php` | ProgramHead, Dean | `api/blocking/create.php` |
| POST/PATCH/PUT | `/api/blocking/update.php` | ProgramHead, Dean | `api/blocking/update.php` |
| GET | `/api/student/blocks.php` | Student | `api/student/blocks.php` | Own holds |

Logic: `includes/Blocking.php` on table **`block`**. Do not confuse with `classBlock`.

### Other notable APIs (inventory)

`api/health.php`, `api/dashboard/summary.php`, `api/audit/list.php`,  
`api/departments/*`, `api/rooms/*`, `api/subjects/*`, `api/users/*`,  
`api/students/*` (+ evaluation-queue / evaluate), `api/student/schedule.php`,  
`api/enrollment/*` (pending/list/enroll/distribute).

Full file list under `api/` (61 PHP route files as of audit) matches the shell listing used for this report.

---

## 4. Attendance verification — end-to-end (as implemented today)

### Intended data model
A verification event is a row in **`attendanceRecord`**, keyed to a **`schedule`** meeting and a **Checker** user:

| Field | Role in check-in |
|-------|------------------|
| `scheduleId` | Which class meeting was verified (implies faculty via `schedule.facultyId`, optional `schedule.classBlockId`) |
| `checkerId` | Who recorded the scan (`user` with role Checker) |
| `status` | `Present` \| `Late` \| `WrongRoom` \| `NoSchedule` \| `Absent` |
| `timestamp` | Scan time |
| `isOffline` | Offline capture flag (seed uses `0`/`1`) |
| `syncedAt` | When synced (nullable; supports offline→online narrative) |

Billable late/absent minutes are **computed at read time** from schedule slot + grace (`includes/Attendance.php` `attendanceCountedMinutes`, L67+; grace via `attendanceGraceMinutes()` L13+ / env `ATTENDANCE_GRACE_MINUTES`).

### What the web app can do today
1. **Read paths (live API)**  
   - Faculty: `GET /api/attendance/mine.php` → `fetchFacultyAttendance()` (`api/attendance/mine.php` L17–L26; explicitly “Read-only” L7).  
   - HR: `GET /api/attendance/review.php`.  
   - Dean: `GET /api/attendance/oversight.php`, `GET /api/attendance/analytics.php`.  
   - Shared SELECT: `attendanceSelectSql()` joins `attendanceRecord` ⋈ `schedule` ⋈ `subject` ⋈ `room` ⋈ `department` ⋈ faculty ⋈ checker (`includes/Attendance.php` L142–L176).

2. **Write paths (no Checker check-in controller)**  
   - Grep of `api/` for `INSERT INTO attendanceRecord` → **no matches**.  
   - Rows are created by **seed/CLI scripts**, e.g.:  
     - `database/seed.sql` L301–L352 (`INSERT INTO attendanceRecord ...`)  
     - `database/seed_attendance_two_months.php` L285–L288 (`INSERT INTO attendanceRecord (uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt)`)  
     - `database/seed_attendance_today.php`, `database/seed_absent_analytics.php`

3. **Checker UI**  
   - `public/js/shell.js` L42: Checker nav is only `app.html` (Dashboard). No attendance capture page is wired for Checker in `MODULES`.

### Implication for mobile
A Flutter “check-in” feature **cannot** call an existing write API; it would need the planned **new sync/check-in endpoint** (out of scope for this audit file). Until then, attendance data is seed-populated and viewed read-only by Faculty/HR/Dean.

---

## 5. Authentication mechanism (for mobile reuse)

**Dual auth** (session cookie **or** Bearer API token) — not JWT.

| Mechanism | Details | Evidence |
|-----------|---------|----------|
| PHP session cookie | Name from `SESSION_NAME` (default `scheduleguard_session`); HttpOnly; SameSite=Lax | `includes/Auth.php` `startAppSession()` L13–L29 |
| Opaque Bearer token | 64-byte hex plaintext returned once at login; **SHA-256 hash** stored in `user.apiToken`; expiry `user.tokenExpiresAt` (`TOKEN_TTL_HOURS`, default 24) | `issueApiToken()` L198–L213; `findUserByApiToken()` L156–L175 |
| Login | Email + password → `password_verify` → set `$_SESSION['user']` + `issueApiToken` → JSON `{ user, token, role }` | `api/auth/login.php` L19–L54 |
| Request auth | Prefer session `uid`; else `Authorization: Bearer <token>` | `authenticate()` L39–L72; `extractBearerToken()` L130–L150 |
| Web client storage | `localStorage` key `scheduleguard_token`; sends `Authorization: Bearer …` and `credentials: "include"` | `public/js/api.js` L18–L64 |

**Mobile recommendation (reuse, no change required):** use `POST /api/auth/login.php`, store returned `token`, send `Authorization: Bearer <token>` on subsequent calls. Cookie session is optional for browsers; Bearer alone is sufficient for API auth.

**Not used:** JWT libraries, OAuth, GraphQL auth.

---

## Terminology reminder (do not conflate)

| Term | Table / module | Meaning |
|------|----------------|---------|
| **Class block** | `classBlock`, `includes/ClassBlock.php`, `api/class-blocks/*` | Scheduled class/section; GA scheduling attaches `schedule.classBlockId` |
| **Student blocking** | `block`, `includes/Blocking.php`, `api/blocking/*` | Enrollment hold / clearance |

---

## Gap summary relevant to Flutter

1. Attendance **read** APIs exist; **write/check-in API does not**.  
2. Auth for mobile should reuse **Bearer token** from login (opaque hashed token, not JWT).  
3. Check-in payload, when designed, should target `attendanceRecord` fields above and resolve faculty/class section through `scheduleId`, not invent parallel `faculty_id`/`class_block_id` columns unless a migration is explicitly requested later.

---

*End of audit. No Flutter or sync code written.*
