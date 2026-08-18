/// One row from POST /api/attendance/sync.php `data.results`.
class AttendanceSyncResult {
  const AttendanceSyncResult({
    required this.localId,
    this.serverId,
    required this.status,
    this.message,
  });

  final String localId;
  final String? serverId;

  /// created | duplicate | error
  final String status;
  final String? message;

  bool get isOk => status == 'created' || status == 'duplicate';

  factory AttendanceSyncResult.fromJson(Map<String, dynamic> json) {
    return AttendanceSyncResult(
      localId: (json['local_id'] ?? json['localId'] ?? '').toString(),
      serverId: (json['server_id'] ?? json['serverId'])?.toString(),
      status: (json['status'] ?? 'error').toString(),
      message: json['message']?.toString(),
    );
  }
}

/// Summary returned by [AttendanceRepository.syncPendingAttendance].
class AttendanceSyncReport {
  const AttendanceSyncReport({
    required this.attempted,
    required this.synced,
    required this.failed,
    required this.skippedOffline,
    required this.skippedEmpty,
    this.networkError,
  });

  final int attempted;
  final int synced;
  final int failed;
  final bool skippedOffline;
  final bool skippedEmpty;
  final String? networkError;

  String get summaryMessage {
    if (skippedOffline) return 'Offline — sync skipped';
    if (skippedEmpty) return 'Nothing pending to sync';
    if (networkError != null) {
      final msg = networkError!;
      if (msg.toLowerCase().contains('already in progress')) return msg;
      return 'Network error — records left pending. $msg';
    }
    return 'Synced $synced · failed $failed (of $attempted)';
  }
}
