import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../data/models/attendance_record.dart';

class SyncStatusScreen extends StatefulWidget {
  const SyncStatusScreen({
    super.key,
    this.embeddedInShell = false,
    this.onPendingChanged,
  });

  final bool embeddedInShell;
  final ValueChanged<int>? onPendingChanged;

  @override
  State<SyncStatusScreen> createState() => _SyncStatusScreenState();
}

class _SyncStatusScreenState extends State<SyncStatusScreen> {
  List<AttendanceRecord> _pending = [];
  List<AttendanceRecord> _recent = [];
  bool _busy = true;
  String? _status;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final dao = AppScope.of(context).attendanceDao;
    final pending = await dao.getPendingAttendance();
    final recent = await dao.listRecent(limit: 40);
    if (!mounted) return;
    setState(() {
      _pending = pending;
      _recent = recent;
      _busy = false;
    });
    widget.onPendingChanged?.call(pending.length);
  }

  Future<void> _syncNow() async {
    setState(() {
      _busy = true;
      _status = null;
    });
    final report = await AppScope.of(context)
        .attendanceRepository
        .syncPendingAttendance();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _status = report.summaryMessage;
    });
    await _load();
  }

  Widget _content() {
    if (_busy && _pending.isEmpty && _recent.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FilledButton.icon(
          onPressed: _busy ? null : _syncNow,
          icon: const Icon(Icons.sync),
          label: Text(_busy ? 'Working…' : 'Sync now'),
        ),
        if (_status != null) ...[
          const SizedBox(height: 12),
          Text(_status!),
        ],
        const SizedBox(height: 8),
        Text(
          'Pushes pending attendance only to POST /api/attendance/sync.php. '
          'Faculty / class_block caches are never uploaded.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 20),
        Text(
          'Pending / failed (${_pending.length})',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        if (_pending.isEmpty)
          const Text('No pending attendance records.')
        else
          ..._pending.map((r) => ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text('${r.status} · ${r.syncStatus}'),
                subtitle: Text(
                  'local_id ${r.localId}\n'
                  'block ${r.classBlockId} · faculty ${r.facultyId}\n'
                  '${r.recordedAt}'
                  '${r.syncError == null ? "" : "\n${r.syncError}"}',
                ),
                isThreeLine: true,
              )),
        const SizedBox(height: 16),
        Text(
          'Recent local (${_recent.length})',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        ..._recent.map((r) => ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text('${r.status} · ${r.syncStatus}'),
              subtitle: Text(
                r.serverId == null ? r.localId : 'server_id ${r.serverId}',
              ),
            )),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (widget.embeddedInShell) {
      return _content();
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Sync status')),
      body: _content(),
    );
  }
}
