# Open questions (local vs server)

## Local schema (implemented)

Checker offline DB (v2):

| Table | Direction | Notes |
|-------|-----------|--------|
| `faculty` | pull only | Cache of Faculty users |
| `class_block` | pull only | Denormalized checker UI cache |
| `attendance_record` | write + push | Only upstream-synced table |

`attendance_record` columns: `local_id`, `server_id`, `faculty_id`,
`class_block_id`, `checker_id`, `status`, `recorded_at`, `device_meta`,
`sync_status` (`pending` \| `syncing` \| `synced` \| `failed`), `sync_error`.

## Gaps vs STACK.md / server schema

1. **Server attendance keys on `scheduleId`**  
   `POST /api/attendance/sync.php` resolves `scheduleId` from
   `faculty_id` + `class_block_id` (+ weekday from `recorded_at` when possible).
   Run `php database/migrate_attendance_sync_client_local_id.php` for
   `clientLocalId` / `deviceMeta` columns (idempotency + optional meta).

2. **Server `classBlock` has no subject/room/time/faculty**  
   Those live on `schedule`. Local `class_block` is intentionally denormalized
   for offline checker UX. Cache pull API is still separate / TBD.

3. **No Checker cache pull API yet**  
   Attendance **push** exists (`POST /api/attendance/sync.php`). GET cache pull
   is not part of that endpoint.

4. **No checker ↔ class_block assignment table** on server  
   Who sees which blocks is undefined until assignment or a Checker-scoped
   pull query exists.

5. **Faculty QR format (app convention)**  
   QR carries faculty **ID number** = `user.schoolId` (e.g. `2020-501`), optionally
   prefixed `SGFAC:`. Checker resolves today’s meeting for that faculty from the
   local schedule cache. Room QRs are not used for check-in.

6. **NoSchedule without a meeting**  
   Local row may use `class_block_id=faculty-nosched:<schoolId>` with real
   `faculty_id`. Server sync may reject until it accepts faculty-only warnings.
