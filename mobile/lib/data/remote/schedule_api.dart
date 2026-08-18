import 'package:dio/dio.dart';

import '../../core/http_client.dart';
import '../../core/result.dart';
import 'api_paths.dart';

class ScheduleApi {
  ScheduleApi(this._http);
  final AppHttpClient _http;

  Future<Result<List<Map<String, dynamic>>>> listRooms({String? q}) async {
    try {
      final res = await _http.get(
        ApiPaths.roomsIndex,
        query: {if (q != null && q.isNotEmpty) 'q': q},
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ?? 'Rooms failed',
        );
      }
      final data = body['data'] as Map? ?? {};
      final list = (data['rooms'] as List?) ?? const [];
      return Success(
        list
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList(),
      );
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<List<Map<String, dynamic>>>> listFaculty() async {
    try {
      final res = await _http.get(ApiPaths.facultyIndex);
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ??
              'Faculty list failed',
        );
      }
      final data = body['data'] as Map? ?? {};
      final list = (data['faculty'] as List?) ?? const [];
      return Success(
        list
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList(),
      );
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<List<Map<String, dynamic>>>> listFacultySchedules({
    String? facultyId,
    String? roomId,
  }) async {
    try {
      final res = await _http.get(
        ApiPaths.schedulesFacultyView,
        query: {
          if (facultyId != null && facultyId.isNotEmpty) 'facultyId': facultyId,
          if (roomId != null && roomId.isNotEmpty) 'roomId': roomId,
        },
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ??
              'Schedules failed',
        );
      }
      final data = body['data'] as Map? ?? {};
      final list = (data['schedules'] as List?) ?? const [];
      return Success(
        list
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList(),
      );
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<List<Map<String, dynamic>>>> schedulesForRoom(
    String roomId,
  ) {
    return listFacultySchedules(roomId: roomId);
  }

  Future<Result<List<Map<String, dynamic>>>> deanStudentSchedules({
    String? studentId,
  }) async {
    try {
      final res = await _http.get(
        ApiPaths.schedulesStudentView,
        query: {
          if (studentId != null && studentId.isNotEmpty) 'studentId': studentId,
        },
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ??
              'Student schedules failed',
        );
      }
      final data = body['data'] as Map? ?? {};
      final list = (data['schedules'] as List?) ?? const [];
      return Success(
        list
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList(),
      );
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<List<Map<String, dynamic>>>> myStudentSchedule() async {
    try {
      final res = await _http.get(ApiPaths.studentSchedule);
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ??
              'Student schedule failed',
        );
      }
      final data = body['data'] as Map? ?? {};
      final list = (data['schedules'] as List?) ?? const [];
      return Success(
        list
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList(),
      );
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  String _dioMessage(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['error'] != null) return data['error'].toString();
    return e.message ?? 'Network error';
  }
}
