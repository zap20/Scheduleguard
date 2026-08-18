import '../local/daos/attendance_dao.dart';
import '../models/attendance_sync_result.dart';
import 'attendance_repository.dart';
import 'checker_repository.dart';

/// Thin facade: attendance push vs cache pull stay separate.
class SyncRepository {
  SyncRepository({
    required AttendanceDao attendanceDao,
    required AttendanceRepository attendanceRepository,
    required CheckerRepository checkerRepository,
  })  : _attendanceDao = attendanceDao,
        _attendanceRepository = attendanceRepository,
        _checkerRepository = checkerRepository;

  final AttendanceDao _attendanceDao;
  final AttendanceRepository _attendanceRepository;
  final CheckerRepository _checkerRepository;

  Future<int> pendingCount() => _attendanceDao.pendingCount();

  /// Manual / automatic attendance push only (no faculty/class_block upload).
  Future<AttendanceSyncReport> syncPendingAttendance() =>
      _attendanceRepository.syncPendingAttendance();

  /// Downstream cache refresh (server → device). Separate from attendance sync.
  Future<void> refreshCaches() =>
      _checkerRepository.refreshCachesFromRemote();
}
