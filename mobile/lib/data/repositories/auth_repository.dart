import '../../core/http_client.dart';
import '../../core/result.dart';
import '../local/daos/session_dao.dart';
import '../models/auth_user.dart';
import '../remote/auth_api.dart';

class AuthRepository {
  AuthRepository({
    required SessionDao sessionDao,
    required AuthApi authApi,
    required AppHttpClient http,
  })  : _sessionDao = sessionDao,
        _authApi = authApi,
        _http = http;

  final SessionDao _sessionDao;
  final AuthApi _authApi;
  final AppHttpClient _http;

  AuthUser? currentUser;

  Future<Result<AuthUser>> restoreSession() async {
    final saved = await _sessionDao.readSession();
    if (saved == null) {
      return const Failure('No local session');
    }
    _http.setBearerToken(saved.token);
    currentUser = saved.user;

    final remote = await _authApi.me();
    if (remote is Success<AuthUser>) {
      final user = remote.data;
      currentUser = user;
      await _sessionDao.saveSession(token: saved.token, user: user);
      return Success(user);
    }

    // Offline: keep last local session.
    return Success(saved.user);
  }

  Future<Result<AuthUser>> login({
    required String email,
    required String password,
  }) async {
    final result = await _authApi.login(email: email, password: password);
    if (result is Failure<({AuthUser user, String token})>) {
      return Failure(result.message);
    }
    final pair = (result as Success<({AuthUser user, String token})>).data;
    _http.setBearerToken(pair.token);
    currentUser = pair.user;
    await _sessionDao.saveSession(token: pair.token, user: pair.user);
    return Success(pair.user);
  }

  Future<void> logout() async {
    await _authApi.logout();
    _http.setBearerToken(null);
    currentUser = null;
    await _sessionDao.clear();
  }
}
