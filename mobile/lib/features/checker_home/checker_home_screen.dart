import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../app_scope.dart';
import '../../core/result.dart';
import '../../data/models/class_block.dart';
import '../../data/models/faculty.dart';
import '../../data/repositories/checker_repository.dart';
import '../attendance_capture/attendance_capture_screen.dart';
import '../faculty_scan/faculty_scan_screen.dart';
import '../sync_status/sync_status_screen.dart';

class CheckerHomeScreen extends StatefulWidget {
  const CheckerHomeScreen({
    super.key,
    required this.onLogout,
    this.embeddedInShell = false,
    this.onOpenScan,
    this.onOpenReports,
    this.onOpenRoomSchedule,
    this.onOpenSync,
    this.onPendingChanged,
  });

  final VoidCallback onLogout;
  final bool embeddedInShell;
  final VoidCallback? onOpenScan;
  final VoidCallback? onOpenReports;
  final VoidCallback? onOpenRoomSchedule;
  final VoidCallback? onOpenSync;
  final ValueChanged<int>? onPendingChanged;

  @override
  State<CheckerHomeScreen> createState() => _CheckerHomeScreenState();
}

class _CheckerHomeScreenState extends State<CheckerHomeScreen>
    with WidgetsBindingObserver {
  List<ClassBlock> _blocks = [];
  final Map<String, Faculty> _facultyById = {};
  int _pending = 0;
  bool _loading = true;
  String? _banner;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadLocal());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _refreshPendingBadge();
    }
  }

  Future<void> _loadLocal() async {
    final scope = AppScope.of(context);
    setState(() {
      _loading = true;
      _banner = null;
    });

    final today = CheckerRepository.weekdayName();
    final local = await scope.checkerRepository.todaysBlocksLocal();
    final pending = await scope.attendanceDao.pendingCount();
    final facultyMap = await _resolveFaculty(scope, local);

    if (!mounted) return;
    setState(() {
      _blocks = local;
      _facultyById
        ..clear()
        ..addAll(facultyMap);
      _pending = pending;
      _loading = false;
      if (local.isEmpty) {
        _banner =
            'No class blocks cached for $today. '
            'Pull down to refresh when online (fills read-only caches).';
      }
    });
    widget.onPendingChanged?.call(pending);
  }

  Future<void> _pullCachesThenLocal() async {
    final scope = AppScope.of(context);
    setState(() => _banner = null);
    final refresh = await scope.checkerRepository.refreshCachesFromRemote();
    await _loadLocal();
    if (!mounted) return;
    if (refresh is Failure) {
      setState(() => _banner = (refresh as Failure).message);
    }
  }

  Future<void> _refreshPendingBadge() async {
    final pending = await AppScope.of(context).attendanceDao.pendingCount();
    if (!mounted) return;
    setState(() => _pending = pending);
    widget.onPendingChanged?.call(pending);
  }

  Future<Map<String, Faculty>> _resolveFaculty(
    AppScope scope,
    List<ClassBlock> blocks,
  ) async {
    final map = <String, Faculty>{};
    for (final b in blocks) {
      final fid = b.facultyId;
      if (fid == null || fid.isEmpty || map.containsKey(fid)) continue;
      final f = await scope.facultyDao.getById(fid);
      if (f != null) map[fid] = f;
    }
    return map;
  }

  Future<void> _openCapture(ClassBlock block) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => AttendanceCaptureScreen(classBlockId: block.id),
      ),
    );
    await _loadLocal();
  }

  Future<void> _openScan() async {
    if (widget.onOpenScan != null) {
      widget.onOpenScan!();
      return;
    }
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const FacultyScanScreen()),
    );
    await _loadLocal();
  }

  Widget _body() {
    final user = AppScope.of(context).authRepository.currentUser;
    final deviceNow = DateTime.now();
    final todayLabel = DateFormat.yMMMEd().format(deviceNow);
    final weekday = CheckerRepository.weekdayName(deviceNow);

    return RefreshIndicator(
      onRefresh: _pullCachesThenLocal,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      user?.fullName ?? 'Checker',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    Text('$todayLabel · $weekday'),
                  ],
                ),
              ),
              _PendingBadge(count: _pending),
            ],
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _openScan,
            icon: const Icon(Icons.qr_code_scanner),
            label: const Text('Scan faculty QR'),
          ),
          const SizedBox(height: 8),
          Text(
            'Scan the faculty ID QR (school ID number), then record attendance offline. '
            'Open the drawer for reports and room schedules.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (widget.embeddedInShell) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (widget.onOpenReports != null)
                  OutlinedButton.icon(
                    onPressed: widget.onOpenReports,
                    icon: const Icon(Icons.analytics_outlined, size: 18),
                    label: const Text('Reports'),
                  ),
                if (widget.onOpenRoomSchedule != null)
                  OutlinedButton.icon(
                    onPressed: widget.onOpenRoomSchedule,
                    icon: const Icon(Icons.calendar_view_week_outlined,
                        size: 18),
                    label: const Text('Weekly schedule'),
                  ),
              ],
            ),
          ],
          if (_banner != null) ...[
            const SizedBox(height: 12),
            Material(
              color: Theme.of(context).colorScheme.secondaryContainer,
              borderRadius: BorderRadius.circular(12),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(_banner!),
              ),
            ),
          ],
          const SizedBox(height: 24),
          Text(
            'Today’s meetings (fallback)',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 4),
          Text(
            'From local cache for this device day. Prefer Scan faculty QR.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          if (_loading)
            const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: CircularProgressIndicator(),
              ),
            )
          else if (_blocks.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 32),
              child: Text('No class blocks to show for today.'),
            )
          else
            ..._blocks.map((b) {
              final facultyName = b.facultyId == null || b.facultyId!.isEmpty
                  ? 'TBF'
                  : (_facultyById[b.facultyId!]?.name ?? b.facultyId!);
              return _BlockCard(
                block: b,
                facultyName: facultyName,
                onTap: () => _openCapture(b),
              );
            }),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (widget.embeddedInShell) {
      return _body();
    }
    return Scaffold(
      appBar: AppBar(
        title: const Text('Checker'),
        actions: [
          IconButton(
            tooltip: 'Pending sync',
            onPressed: () async {
              if (widget.onOpenSync != null) {
                widget.onOpenSync!();
                return;
              }
              await Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const SyncStatusScreen()),
              );
              await _loadLocal();
            },
            icon: Badge(
              isLabelVisible: _pending > 0,
              label: Text('$_pending'),
              child: const Icon(Icons.cloud_sync_outlined),
            ),
          ),
          IconButton(
            tooltip: 'Sign out',
            onPressed: () async {
              await AppScope.of(context).authRepository.logout();
              widget.onLogout();
            },
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: _body(),
    );
  }
}

class _PendingBadge extends StatelessWidget {
  const _PendingBadge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final active = count > 0;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: active
            ? theme.colorScheme.tertiaryContainer
            : theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.pending_actions,
            size: 18,
            color: active
                ? theme.colorScheme.onTertiaryContainer
                : theme.colorScheme.onSurfaceVariant,
          ),
          const SizedBox(width: 6),
          Text(
            active ? '$count pending' : 'All synced',
            style: theme.textTheme.labelLarge?.copyWith(
              color: active
                  ? theme.colorScheme.onTertiaryContainer
                  : theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

class _BlockCard extends StatelessWidget {
  const _BlockCard({
    required this.block,
    required this.facultyName,
    required this.onTap,
  });

  final ClassBlock block;
  final String facultyName;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: ListTile(
        onTap: onTap,
        title: Text(block.displayTitle),
        subtitle: Text(
          '$facultyName\n'
          '${block.scheduleTime.isEmpty ? "—" : block.scheduleTime} · '
          '${block.room.isEmpty ? "—" : block.room}',
        ),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
      ),
    );
  }
}
