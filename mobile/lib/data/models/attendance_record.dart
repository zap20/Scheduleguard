import '../../core/constants.dart';

/// Local attendance row — only table written on-device and pushed upstream.
///
/// Column names follow the mobile schema (local_id / sync_status / …).
/// Server `attendanceRecord` still uses `uid` + `scheduleId` (STACK.md);
/// mapping is deferred to the sync layer (see OPEN_QUESTIONS.md).
class AttendanceRecord {
  const AttendanceRecord({
    required this.localId,
    this.serverId,
    required this.facultyId,
    required this.classBlockId,
    required this.checkerId,
    required this.status,
    required this.recordedAt,
    this.deviceMeta,
    this.syncStatus = AppConstants.syncPending,
    this.syncError,
  });

  final String localId;
  final String? serverId;
  final String facultyId;
  final String classBlockId;
  final String checkerId;

  /// Present | Late | WrongRoom | NoSchedule | Absent
  final String status;

  /// Device timestamp, ISO-8601.
  final String recordedAt;
  final String? deviceMeta;

  /// pending | syncing | synced | failed
  final String syncStatus;
  final String? syncError;

  bool get isPendingSync =>
      syncStatus == AppConstants.syncPending ||
      syncStatus == AppConstants.syncFailed;

  Map<String, dynamic> toMap() => {
        'local_id': localId,
        'server_id': serverId,
        'faculty_id': facultyId,
        'class_block_id': classBlockId,
        'checker_id': checkerId,
        'status': status,
        'recorded_at': recordedAt,
        'device_meta': deviceMeta,
        'sync_status': syncStatus,
        'sync_error': syncError,
      };

  /// Payload for POST /api/attendance/sync.php (snake_case contract).
  /// [classBlockId] may be a meeting/cache id; [schedule_id] mirrors it when
  /// the local cache row is keyed by schedule.uid.
  Map<String, dynamic> toSyncJson() => {
        'local_id': localId,
        'faculty_id': facultyId,
        'class_block_id': classBlockId,
        'schedule_id': classBlockId,
        'checker_id': checkerId,
        'status': status,
        'recorded_at': recordedAt,
        'device_meta': deviceMeta,
      };

  factory AttendanceRecord.fromMap(Map<String, dynamic> map) {
    return AttendanceRecord(
      localId: (map['local_id'] ?? '').toString(),
      serverId: map['server_id']?.toString(),
      facultyId: (map['faculty_id'] ?? '').toString(),
      classBlockId: (map['class_block_id'] ?? '').toString(),
      checkerId: (map['checker_id'] ?? '').toString(),
      status: (map['status'] ?? '').toString(),
      recordedAt: (map['recorded_at'] ?? '').toString(),
      deviceMeta: map['device_meta']?.toString(),
      syncStatus:
          (map['sync_status'] ?? AppConstants.syncPending).toString(),
      syncError: map['sync_error']?.toString(),
    );
  }
}
