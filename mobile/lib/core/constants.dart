/// App-wide constants. Base URL points at the PHP API under Apache
/// (`http://localhost/schoolguard/api`) or the built-in server.
class AppConstants {
  AppConstants._();

  /// Override at build/run time if needed:
  /// Android emulator: `flutter run --dart-define=API_BASE=http://10.0.2.2/schoolguard/api`
  /// Windows / iOS sim: `flutter run --dart-define=API_BASE=http://127.0.0.1/schoolguard/api`
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://127.0.0.1/schoolguard/api',
  );

  static const String roleChecker = 'Checker';

  /// Status values from server `attendanceRecord.status` ENUM (STACK.md).
  static const List<String> attendanceStatuses = [
    'Present',
    'Late',
    'WrongRoom',
    'NoSchedule',
    'Absent',
  ];

  static const String syncPending = 'pending';
  static const String syncSyncing = 'syncing';
  static const String syncSynced = 'synced';
  static const String syncFailed = 'failed';

  static const String localDbName = 'scheduleguard_checker.db';
  static const int localDbVersion = 4;
}
