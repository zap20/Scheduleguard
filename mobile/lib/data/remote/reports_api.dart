import 'package:dio/dio.dart';

import '../../core/http_client.dart';
import '../../core/result.dart';
import 'api_paths.dart';

class ReportsApi {
  ReportsApi(this._http);
  final AppHttpClient _http;

  Future<Result<Map<String, dynamic>>> fetchTopAbsent({
    int? academicYear,
    String? semester,
  }) async {
    try {
      final res = await _http.get(
        ApiPaths.attendanceAnalytics,
        query: {
          if (academicYear != null) 'academicYear': academicYear,
          if (semester != null && semester.isNotEmpty) 'semester': semester,
        },
      );
      return _mapBody(res.data);
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<Map<String, dynamic>>> fetchOversight({
    String? dateFrom,
    String? dateTo,
  }) async {
    try {
      final res = await _http.get(
        ApiPaths.attendanceOversight,
        query: {
          if (dateFrom != null) 'dateFrom': dateFrom,
          if (dateTo != null) 'dateTo': dateTo,
        },
      );
      return _mapBody(res.data);
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<Map<String, dynamic>>> fetchReview({
    String? dateFrom,
    String? dateTo,
  }) async {
    try {
      final res = await _http.get(
        ApiPaths.attendanceReview,
        query: {
          if (dateFrom != null) 'dateFrom': dateFrom,
          if (dateTo != null) 'dateTo': dateTo,
        },
      );
      return _mapBody(res.data);
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Future<Result<Map<String, dynamic>>> fetchMine() async {
    try {
      final res = await _http.get(ApiPaths.attendanceMine);
      return _mapBody(res.data);
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    }
  }

  Result<Map<String, dynamic>> _mapBody(dynamic body) {
    if (body is! Map || body['success'] != true) {
      return Failure(
        (body is Map ? body['error'] : null)?.toString() ?? 'Request failed',
      );
    }
    final data = body['data'];
    if (data is Map<String, dynamic>) return Success(data);
    if (data is Map) {
      return Success(Map<String, dynamic>.from(data));
    }
    return const Failure('Unexpected response shape');
  }

  String _dioMessage(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['error'] != null) return data['error'].toString();
    return e.message ?? 'Network error';
  }
}
