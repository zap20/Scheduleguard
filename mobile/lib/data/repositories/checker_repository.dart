import 'package:intl/intl.dart';

import '../../core/faculty_qr.dart';
import '../../core/result.dart';
import '../local/daos/class_block_dao.dart';
import '../local/daos/faculty_dao.dart';
import '../models/class_block.dart';
import '../models/faculty.dart';
import '../remote/sync_api.dart';

/// Result of resolving a faculty ID QR against the local meeting cache.
class FacultyScanResolve {
  const FacultyScanResolve({
    required this.schoolId,
    this.faculty,
    this.meeting,
  });

  final String schoolId;
  final Faculty? faculty;
  final ClassBlock? meeting;

  bool get hasFaculty => faculty != null;
  bool get hasMeeting => meeting != null;
}

/// Checker home + faculty-scan resolution from **local read-only caches**.
class CheckerRepository {
  CheckerRepository({
    required ClassBlockDao classBlockDao,
    required FacultyDao facultyDao,
    required SyncApi syncApi,
  })  : _classBlockDao = classBlockDao,
        _facultyDao = facultyDao,
        _syncApi = syncApi;

  final ClassBlockDao _classBlockDao;
  final FacultyDao _facultyDao;
  final SyncApi _syncApi;

  static String weekdayName([DateTime? now]) {
    return DateFormat('EEEE').format(now ?? DateTime.now());
  }

  Future<List<ClassBlock>> todaysBlocksLocal() {
    return _classBlockDao.listByDay(weekdayName());
  }

  Future<ClassBlock?> blockById(String id) => _classBlockDao.getById(id);

  Future<Faculty?> facultyById(String id) => _facultyDao.getById(id);

  /// Pull faculty + class_block caches from remote (downstream only).
  Future<Result<List<ClassBlock>>> refreshCachesFromRemote() async {
    final pull = await _syncApi.pullCheckerCaches();
    if (pull is Success<({List<Faculty> faculty, List<ClassBlock> blocks})>) {
      await _facultyDao.replaceAll(pull.data.faculty);
      await _classBlockDao.replaceAll(pull.data.blocks);
      return Success(await todaysBlocksLocal());
    }
    final local = await todaysBlocksLocal();
    if (local.isNotEmpty) {
      return Success(local);
    }
    return Failure(
      (pull as Failure<({List<Faculty> faculty, List<ClassBlock> blocks})>)
          .message,
    );
  }

  /// Parse faculty QR / typed schoolId and find today's meeting for that faculty.
  Future<Result<FacultyScanResolve>> resolveFacultyScan(
    String rawQrOrSchoolId, {
    DateTime? now,
  }) async {
    final schoolId = FacultyQr.parse(rawQrOrSchoolId);
    if (schoolId == null || schoolId.isEmpty) {
      return const Failure('Invalid faculty QR / ID number');
    }

    final faculty = await _facultyDao.getBySchoolId(schoolId);
    if (faculty == null) {
      return Success(
        FacultyScanResolve(schoolId: schoolId, faculty: null, meeting: null),
      );
    }

    final at = now ?? DateTime.now();
    final day = weekdayName(at);
    final meetings =
        await _classBlockDao.listByFacultyAndDay(faculty.id, day);
    final meeting =
        meetings.isEmpty ? null : _pickCurrentOrNearest(meetings, at);

    return Success(
      FacultyScanResolve(
        schoolId: schoolId,
        faculty: faculty,
        meeting: meeting,
      ),
    );
  }

  ClassBlock? _pickCurrentOrNearest(List<ClassBlock> meetings, DateTime at) {
    if (meetings.isEmpty) return null;

    for (final m in meetings) {
      if (_isWithin(m, at)) return m;
    }

    ClassBlock? best;
    var bestDelta = 1 << 30;
    final minutesNow = at.hour * 60 + at.minute;
    for (final m in meetings) {
      final start = _toMinutes(m.startTime);
      final end = _toMinutes(m.endTime);
      if (start == null) continue;
      final delta = minutesNow < start
          ? start - minutesNow
          : (end != null && minutesNow > end ? minutesNow - end : 0);
      if (delta < bestDelta) {
        bestDelta = delta;
        best = m;
      }
    }
    return best ?? meetings.first;
  }

  bool _isWithin(ClassBlock m, DateTime at) {
    final start = _toMinutes(m.startTime);
    final end = _toMinutes(m.endTime);
    if (start == null || end == null) return false;
    final now = at.hour * 60 + at.minute;
    return now >= start && now <= end;
  }

  int? _toMinutes(String hhmm) {
    final parts = hhmm.trim().split(':');
    if (parts.length < 2) return null;
    final h = int.tryParse(parts[0]);
    final m = int.tryParse(parts[1]);
    if (h == null || m == null) return null;
    return h * 60 + m;
  }
}
