import 'package:dio/dio.dart';

import 'constants.dart';

/// Thin Dio wrapper: JSON + optional Bearer token (STACK.md auth).
class AppHttpClient {
  AppHttpClient({String? baseUrl, Dio? dio})
      : _dio = dio ??
            Dio(
              BaseOptions(
                baseUrl: baseUrl ?? AppConstants.apiBaseUrl,
                connectTimeout: const Duration(seconds: 20),
                receiveTimeout: const Duration(seconds: 30),
                headers: const {
                  'Accept': 'application/json',
                  'Content-Type': 'application/json',
                },
              ),
            );

  final Dio _dio;
  String? _bearerToken;

  Dio get raw => _dio;

  void setBearerToken(String? token) {
    _bearerToken = token;
    if (token == null || token.isEmpty) {
      _dio.options.headers.remove('Authorization');
    } else {
      _dio.options.headers['Authorization'] = 'Bearer $token';
    }
  }

  String? get bearerToken => _bearerToken;

  Future<Response<dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) {
    return _dio.get(path, queryParameters: query);
  }

  Future<Response<dynamic>> post(
    String path, {
    Object? data,
  }) {
    return _dio.post(path, data: data);
  }
}
