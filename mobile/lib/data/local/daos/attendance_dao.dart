import 'package:sqflite/sqflite.dart';

import '../../../core/constants.dart';
import '../../models/attendance_record.dart';
import '../app_database.dart';

/// Writes + sync-state for local `attendance_record` only.
class AttendanceDao {
  AttendanceDao(this._db);
  final AppDatabase _db;

  /// Insert a new capture. Always forces [sync_status] = pending.
  Future<void> insertAttendance(AttendanceRecord record) async {
    final db = await _db.database;
    final row = Map<String, dynamic>.from(record.toMap())
      ..['sync_status'] = AppConstants.syncPending
      ..['sync_error'] = null;
    await db.insert(
      'attendance_record',
      row,
      conflictAlgorithm: ConflictAlgorithm.abort,
    );
  }

  Future<List<AttendanceRecord>> getPendingAttendance() async {
    final db = await _db.database;
    final rows = await db.query(
      'attendance_record',
      where: 'sync_status = ? OR sync_status = ?',
      whereArgs: [AppConstants.syncPending, AppConstants.syncFailed],
      orderBy: 'recorded_at ASC',
    );
    return rows.map(AttendanceRecord.fromMap).toList();
  }

  Future<void> markSynced(String localId, String serverId) async {
    final db = await _db.database;
    await db.update(
      'attendance_record',
      {
        'server_id': serverId,
        'sync_status': AppConstants.syncSynced,
        'sync_error': null,
      },
      where: 'local_id = ?',
      whereArgs: [localId],
    );
  }

  Future<void> markFailed(String localId, String error) async {
    final db = await _db.database;
    await db.update(
      'attendance_record',
      {
        'sync_status': AppConstants.syncFailed,
        'sync_error': error,
      },
      where: 'local_id = ?',
      whereArgs: [localId],
    );
  }

  Future<void> markSyncing(String localId) async {
    final db = await _db.database;
    await db.update(
      'attendance_record',
      {
        'sync_status': AppConstants.syncSyncing,
        'sync_error': null,
      },
      where: 'local_id = ?',
      whereArgs: [localId],
    );
  }

  Future<List<AttendanceRecord>> listRecent({int limit = 40}) async {
    final db = await _db.database;
    final rows = await db.query(
      'attendance_record',
      orderBy: 'recorded_at DESC',
      limit: limit,
    );
    return rows.map(AttendanceRecord.fromMap).toList();
  }

  Future<AttendanceRecord?> latestForClassBlock(String classBlockId) async {
    final db = await _db.database;
    final rows = await db.query(
      'attendance_record',
      where: 'class_block_id = ?',
      whereArgs: [classBlockId],
      orderBy: 'recorded_at DESC',
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return AttendanceRecord.fromMap(rows.first);
  }

  Future<int> pendingCount() async {
    final db = await _db.database;
    final result = await db.rawQuery(
      'SELECT COUNT(*) AS c FROM attendance_record '
      'WHERE sync_status = ? OR sync_status = ?',
      [AppConstants.syncPending, AppConstants.syncFailed],
    );
    return (result.first['c'] as num?)?.toInt() ?? 0;
  }
}
