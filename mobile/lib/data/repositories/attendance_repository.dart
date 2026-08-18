import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:uuid/uuid.dart';

import '../../core/constants.dart';
import '../../core/result.dart';
import '../local/daos/attendance_dao.dart';
import '../models/attendance_record.dart';
import '../models/attendance_sync_result.dart';
import '../remote/sync_api.dart';

/// Local-first attendance writes + upstream sync of pending rows only.
class AttendanceRepository {
  AttendanceRepository({
    required AttendanceDao dao,
    required SyncApi syncApi,
    Connectivity? connectivity,
  })  : _dao = dao,
        _syncApi = syncApi,
        _connectivity = connectivity ?? Connectivity();

  final AttendanceDao _dao;
  final SyncApi _syncApi;
  final Connectivity _connectivity;
  final _uuid = const Uuid();

  static const int syncChunkSize = 50;

  bool _syncInFlight = false;

  Future<Result<AttendanceRecord>> capture({
    required String facultyId,
    required String classBlockId,
    required String checkerId,
    required String status,
    String? deviceMeta,
    DateTime? recordedAt,
  }) async {
    if (facultyId.isEmpty || classBlockId.isEmpty || checkerId.isEmpty) {
      return const Failure(
        'facultyId, classBlockId, and checkerId are required',
      );
    }
    if (!AppConstants.attendanceStatuses.contains(status)) {
      return Failure('Invalid status: $status');
    }

    final record = AttendanceRecord(
      localId: _uuid.v4(),
      serverId: null,
      facultyId: facultyId,
      classBlockId: classBlockId,
      checkerId: checkerId,
      status: status,
      recordedAt: (recordedAt ?? DateTime.now()).toUtc().toIso8601String(),
      deviceMeta: deviceMeta,
      syncStatus: AppConstants.syncPending,
    );
    await _dao.insertAttendance(record);
    return Success(record);
  }

  Future<List<AttendanceRecord>> pending() => _dao.getPendingAttendance();

  Future<int> pendingCount() => _dao.pendingCount();

  Future<AttendanceRecord?> latestForClassBlock(String classBlockId) =>
      _dao.latestForClassBlock(classBlockId);

  Future<bool> get hasConnectivity async {
    final result = await _connectivity.checkConnectivity();
    return result.any((r) => r != ConnectivityResult.none);
  }

  /// Push pending/failed local attendance rows. Never uploads faculty/class_block.
  ///
  /// Offline or empty queue → early return with **no** network call.
  /// Total network failure → rows stay `pending` (no data loss).
  Future<AttendanceSyncReport> syncPendingAttendance() async {
    if (!await hasConnectivity) {
      return const AttendanceSyncReport(
        attempted: 0,
        synced: 0,
        failed: 0,
        skippedOffline: true,
        skippedEmpty: false,
      );
    }

    final pending = await _dao.getPendingAttendance();
    if (pending.isEmpty) {
      return const AttendanceSyncReport(
        attempted: 0,
        synced: 0,
        failed: 0,
        skippedOffline: false,
        skippedEmpty: true,
      );
    }

    if (_syncInFlight) {
      return AttendanceSyncReport(
        attempted: 0,
        synced: 0,
        failed: 0,
        skippedOffline: false,
        skippedEmpty: false,
        networkError: 'Sync already in progress',
      );
    }

    _syncInFlight = true;
    var synced = 0;
    var failed = 0;

    try {
      for (var i = 0; i < pending.length; i += syncChunkSize) {
        final end = (i + syncChunkSize > pending.length)
            ? pending.length
            : i + syncChunkSize;
        final chunk = pending.sublist(i, end);

        final push = await _syncApi.pushAttendance(chunk);
        if (push is Failure<List<AttendanceSyncResult>>) {
          // Total network / transport failure for this chunk — leave pending.
          return AttendanceSyncReport(
            attempted: pending.length,
            synced: synced,
            failed: failed,
            skippedOffline: false,
            skippedEmpty: false,
            networkError: push.message,
          );
        }

        final results = (push as Success<List<AttendanceSyncResult>>).data;
        final byLocal = {
          for (final r in results)
            if (r.localId.isNotEmpty) r.localId: r,
        };

        for (final row in chunk) {
          final result = byLocal[row.localId];
          if (result == null) {
            await _dao.markFailed(row.localId, 'Not acknowledged by server');
            failed++;
            continue;
          }
          if (result.isOk &&
              result.serverId != null &&
              result.serverId!.isNotEmpty) {
            await _dao.markSynced(row.localId, result.serverId!);
            synced++;
          } else {
            await _dao.markFailed(
              row.localId,
              result.message ?? 'Sync error',
            );
            failed++;
          }
        }
      }

      return AttendanceSyncReport(
        attempted: pending.length,
        synced: synced,
        failed: failed,
        skippedOffline: false,
        skippedEmpty: false,
      );
    } finally {
      _syncInFlight = false;
    }
  }
}
