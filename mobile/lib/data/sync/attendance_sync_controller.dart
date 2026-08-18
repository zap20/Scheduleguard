import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';

import '../repositories/attendance_repository.dart';

/// Triggers [AttendanceRepository.syncPendingAttendance] when:
/// - connectivity returns online and there is at least one pending row
/// - app resumes to foreground (optional; early-returns if empty/offline)
class AttendanceSyncController with WidgetsBindingObserver {
  AttendanceSyncController({
    required AttendanceRepository attendanceRepository,
    Connectivity? connectivity,
  })  : _attendanceRepository = attendanceRepository,
        _connectivity = connectivity ?? Connectivity();

  final AttendanceRepository _attendanceRepository;
  final Connectivity _connectivity;

  StreamSubscription<List<ConnectivityResult>>? _sub;
  bool _wasOffline = false;
  bool _started = false;

  void start() {
    if (_started) return;
    _started = true;
    WidgetsBinding.instance.addObserver(this);
    _sub = _connectivity.onConnectivityChanged.listen(_onConnectivity);
    // Prime offline flag + opportunistic sync if already online with pending.
    unawaited(_bootstrap());
  }

  void stop() {
    if (!_started) return;
    _started = false;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_sub?.cancel());
    _sub = null;
  }

  Future<void> _bootstrap() async {
    final online = await _attendanceRepository.hasConnectivity;
    _wasOffline = !online;
    if (online) {
      await _attendanceRepository.syncPendingAttendance();
    }
  }

  Future<void> _onConnectivity(List<ConnectivityResult> results) async {
    final online = results.any((r) => r != ConnectivityResult.none);
    if (online && _wasOffline) {
      final pending = await _attendanceRepository.pendingCount();
      if (pending > 0) {
        await _attendanceRepository.syncPendingAttendance();
      }
    }
    _wasOffline = !online;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_attendanceRepository.syncPendingAttendance());
    }
  }
}
