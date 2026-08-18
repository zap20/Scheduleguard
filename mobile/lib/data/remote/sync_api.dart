import 'package:dio/dio.dart';

import '../../core/http_client.dart';
import '../../core/result.dart';
import '../models/attendance_record.dart';
import '../models/attendance_sync_result.dart';
import '../models/class_block.dart';
import '../models/faculty.dart';
import 'api_paths.dart';

/// Remote attendance push + optional cache pull (downstream only).
class SyncApi {
  SyncApi(this._http);
  final AppHttpClient _http;

  /// POST /api/attendance/sync.php — attendance records only.
  Future<Result<List<AttendanceSyncResult>>> pushAttendance(
    List<AttendanceRecord> pending,
  ) async {
    if (pending.isEmpty) {
      return const Success([]);
    }
    try {
      final res = await _http.post(
        ApiPaths.attendanceSync,
        data: {
          'records': pending.map((r) => r.toSyncJson()).toList(),
        },
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ??
              'Sync rejected by server',
        );
      }
      final data = body['data'] as Map<String, dynamic>? ?? {};
      final raw = (data['results'] as List?) ?? const [];
      final out = <AttendanceSyncResult>[];
      for (final item in raw) {
        if (item is Map) {
          out.add(
            AttendanceSyncResult.fromJson(Map<String, dynamic>.from(item)),
          );
        }
      }
      return Success(out);
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    } catch (e) {
      return Failure(e.toString());
    }
  }

  /// Downstream-only pull of faculty + class_block caches (not this sync endpoint).
  Future<Result<({List<Faculty> faculty, List<ClassBlock> blocks})>>
      pullCheckerCaches() async {
    try {
      final res = await _http.get(
        ApiPaths.attendanceSync,
        query: {'mode': 'caches'},
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ?? 'Pull failed',
        );
      }
      final data = body['data'] as Map<String, dynamic>? ?? {};
      final now = DateTime.now().toUtc().toIso8601String();
      final faculty = <Faculty>[];
      for (final raw in (data['faculty'] as List?) ?? const []) {
        if (raw is Map) {
          faculty.add(
            Faculty.fromRemoteJson(
              Map<String, dynamic>.from(raw),
              cachedAt: now,
            ),
          );
        }
      }
      final blocks = <ClassBlock>[];
      for (final raw in (data['classBlocks'] as List?) ??
          (data['class_blocks'] as List?) ??
          const []) {
        if (raw is Map) {
          blocks.add(
            ClassBlock.fromRemoteJson(
              Map<String, dynamic>.from(raw),
              cachedAt: now,
            ),
          );
        }
      }
      return Success((faculty: faculty, blocks: blocks));
    } on DioException catch (e) {
      if (e.response?.statusCode == 404 || e.response?.statusCode == 405) {
        return const Failure(
          'No Checker cache pull API yet. Showing local cache only.',
        );
      }
      return Failure(_dioMessage(e));
    } catch (e) {
      return Failure(e.toString());
    }
  }

  String _dioMessage(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['error'] != null) {
      return data['error'].toString();
    }
    return e.message ?? 'Network error';
  }
}
