import 'package:flutter/widgets.dart';

import 'core/http_client.dart';
import 'data/local/app_database.dart';
import 'data/local/daos/attendance_dao.dart';
import 'data/local/daos/class_block_dao.dart';
import 'data/local/daos/faculty_dao.dart';
import 'data/local/daos/session_dao.dart';
import 'data/remote/auth_api.dart';
import 'data/remote/sync_api.dart';
import 'data/repositories/attendance_repository.dart';
import 'data/repositories/auth_repository.dart';
import 'data/repositories/checker_repository.dart';
import 'data/repositories/sync_repository.dart';
import 'data/sync/attendance_sync_controller.dart';

/// Simple service locator via InheritedWidget (no riverpod/bloc dependency).
class AppScope extends InheritedWidget {
  AppScope({
    super.key,
    required super.child,
    AppHttpClient? httpClient,
    AppDatabase? database,
  })  : http = httpClient ?? AppHttpClient(),
        db = database ?? AppDatabase.instance,
        sessionDao = SessionDao(database ?? AppDatabase.instance),
        facultyDao = FacultyDao(database ?? AppDatabase.instance),
        classBlockDao = ClassBlockDao(database ?? AppDatabase.instance),
        attendanceDao = AttendanceDao(database ?? AppDatabase.instance) {
    authApi = AuthApi(http);
    syncApi = SyncApi(http);
    authRepository = AuthRepository(
      sessionDao: sessionDao,
      authApi: authApi,
      http: http,
    );
    checkerRepository = CheckerRepository(
      classBlockDao: classBlockDao,
      facultyDao: facultyDao,
      syncApi: syncApi,
    );
    attendanceRepository = AttendanceRepository(
      dao: attendanceDao,
      syncApi: syncApi,
    );
    syncRepository = SyncRepository(
      attendanceDao: attendanceDao,
      attendanceRepository: attendanceRepository,
      checkerRepository: checkerRepository,
    );
    syncController = AttendanceSyncController(
      attendanceRepository: attendanceRepository,
    );
  }

  final AppHttpClient http;
  final AppDatabase db;
  final SessionDao sessionDao;
  final FacultyDao facultyDao;
  final ClassBlockDao classBlockDao;
  final AttendanceDao attendanceDao;
  late final AuthApi authApi;
  late final SyncApi syncApi;
  late final AuthRepository authRepository;
  late final CheckerRepository checkerRepository;
  late final AttendanceRepository attendanceRepository;
  late final SyncRepository syncRepository;
  late final AttendanceSyncController syncController;

  static AppScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope not found');
    return scope!;
  }

  @override
  bool updateShouldNotify(covariant AppScope oldWidget) => false;
}
