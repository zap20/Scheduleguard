import 'package:dio/dio.dart';

import '../../core/http_client.dart';
import '../../core/result.dart';
import '../models/auth_user.dart';
import 'api_paths.dart';

class AuthApi {
  AuthApi(this._http);
  final AppHttpClient _http;

  Future<Result<({AuthUser user, String token})>> login({
    required String email,
    required String password,
  }) async {
    try {
      final res = await _http.post(
        ApiPaths.login,
        data: {'email': email, 'password': password},
      );
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ?? 'Login failed',
        );
      }
      final data = body['data'] as Map<String, dynamic>? ?? {};
      final userMap = data['user'] as Map<String, dynamic>? ?? {};
      final token = (data['token'] ?? '').toString();
      if (token.isEmpty) {
        return const Failure('Login response missing token');
      }
      final user = AuthUser.fromJson(userMap);
      return Success((user: user, token: token));
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    } catch (e) {
      return Failure(e.toString());
    }
  }

  Future<Result<AuthUser>> me() async {
    try {
      final res = await _http.get(ApiPaths.me);
      final body = res.data;
      if (body is! Map || body['success'] != true) {
        return Failure(
          (body is Map ? body['error'] : null)?.toString() ?? 'Session invalid',
        );
      }
      final data = body['data'] as Map<String, dynamic>? ?? {};
      final userMap = (data['user'] ?? data) as Map<String, dynamic>;
      return Success(AuthUser.fromJson(userMap));
    } on DioException catch (e) {
      return Failure(_dioMessage(e));
    } catch (e) {
      return Failure(e.toString());
    }
  }

  Future<Result<bool>> logout() async {
    try {
      await _http.post(ApiPaths.logout, data: {});
      return const Success(true);
    } on DioException catch (e) {
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
