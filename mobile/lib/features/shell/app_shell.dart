import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../data/models/auth_user.dart';
import '../checker_home/checker_home_screen.dart';
import '../faculty_scan/faculty_scan_screen.dart';
import '../reports/attendance_reports_screen.dart';
import '../room_schedule/room_schedule_screen.dart';
import '../student_schedule/student_schedule_screen.dart';
import '../sync_status/sync_status_screen.dart';

enum AppDest {
  home,
  scanFaculty,
  reports,
  roomSchedule,
  studentSchedule,
  syncStatus,
}

class AppShell extends StatefulWidget {
  const AppShell({super.key, required this.onLogout});

  final Future<void> Function() onLogout;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  AppDest _dest = AppDest.home;
  int _pending = 0;

  AuthUser? get _user => AppScope.of(context).authRepository.currentUser;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refreshPending());
  }

  Future<void> _refreshPending() async {
    final u = AppScope.of(context).authRepository.currentUser;
    if (u?.isChecker != true) return;
    final n = await AppScope.of(context).attendanceDao.pendingCount();
    if (!mounted) return;
    setState(() => _pending = n);
  }

  List<AppDest> get _destinations {
    final u = _user;
    if (u == null) return const [AppDest.home];
    final items = <AppDest>[AppDest.home];
    if (u.isChecker) {
      items.addAll([
        AppDest.scanFaculty,
        AppDest.reports,
        AppDest.roomSchedule,
        AppDest.syncStatus,
      ]);
    } else {
      if (u.canViewAttendanceReports) items.add(AppDest.reports);
      if (u.canViewRoomSchedule) items.add(AppDest.roomSchedule);
      if (u.canViewEnrolledStudents || u.canViewOwnSchedule) {
        items.add(AppDest.studentSchedule);
      }
    }
    return items;
  }

  String _label(AppDest d) {
    switch (d) {
      case AppDest.home:
        return _user?.isChecker == true ? 'Checker' : 'Home';
      case AppDest.scanFaculty:
        return 'Scan faculty QR';
      case AppDest.reports:
        return 'Attendance reports';
      case AppDest.roomSchedule:
        return 'Weekly schedule';
      case AppDest.studentSchedule:
        return _user?.isStudent == true ? 'My schedule' : 'Enrolled students';
      case AppDest.syncStatus:
        return 'Sync status';
    }
  }

  IconData _icon(AppDest d) {
    switch (d) {
      case AppDest.home:
        return Icons.home_outlined;
      case AppDest.scanFaculty:
        return Icons.qr_code_scanner;
      case AppDest.reports:
        return Icons.analytics_outlined;
      case AppDest.roomSchedule:
        return Icons.calendar_view_week_outlined;
      case AppDest.studentSchedule:
        return Icons.school_outlined;
      case AppDest.syncStatus:
        return Icons.cloud_sync_outlined;
    }
  }

  Widget _body() {
    final u = _user;
    switch (_dest) {
      case AppDest.home:
        if (u?.isChecker == true) {
          return CheckerHomeScreen(
            onLogout: () {
              widget.onLogout();
            },
            embeddedInShell: true,
            onOpenScan: () => setState(() => _dest = AppDest.scanFaculty),
            onOpenReports: () => setState(() => _dest = AppDest.reports),
            onOpenRoomSchedule: () =>
                setState(() => _dest = AppDest.roomSchedule),
            onOpenSync: () => setState(() => _dest = AppDest.syncStatus),
            onPendingChanged: (n) {
              if (mounted) setState(() => _pending = n);
            },
          );
        }
        return _RoleHomePanel(
          user: u,
          onOpenReports: u?.canViewAttendanceReports == true
              ? () => setState(() => _dest = AppDest.reports)
              : null,
          onOpenRoomSchedule: u?.canViewRoomSchedule == true
              ? () => setState(() => _dest = AppDest.roomSchedule)
              : null,
          onOpenStudentSchedule:
              (u?.canViewEnrolledStudents == true ||
                      u?.canViewOwnSchedule == true)
                  ? () => setState(() => _dest = AppDest.studentSchedule)
                  : null,
        );
      case AppDest.scanFaculty:
        return const FacultyScanScreen(embeddedInShell: true);
      case AppDest.reports:
        return const AttendanceReportsScreen(embeddedInShell: true);
      case AppDest.roomSchedule:
        return const RoomScheduleScreen(embeddedInShell: true);
      case AppDest.studentSchedule:
        return const StudentScheduleScreen(embeddedInShell: true);
      case AppDest.syncStatus:
        return SyncStatusScreen(
          embeddedInShell: true,
          onPendingChanged: (n) {
            if (mounted) setState(() => _pending = n);
          },
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final u = _user;
    final dests = _destinations;
    if (!dests.contains(_dest)) {
      _dest = AppDest.home;
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(_label(_dest)),
        actions: [
          if (u?.isChecker == true &&
              (_dest == AppDest.home || _dest == AppDest.syncStatus))
            IconButton(
              tooltip: 'Sync status',
              onPressed: () => setState(() => _dest = AppDest.syncStatus),
              icon: Badge(
                isLabelVisible: _pending > 0,
                label: Text('$_pending'),
                child: const Icon(Icons.cloud_sync_outlined),
              ),
            ),
        ],
      ),
      drawer: Drawer(
        child: SafeArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DrawerHeader(
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.primary,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    Text(
                      'ScheduleGuard',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                          ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      u?.fullName ?? 'User',
                      style: const TextStyle(color: Colors.white),
                    ),
                    Text(
                      u?.role ?? '',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.85),
                      ),
                    ),
                  ],
                ),
              ),
              for (final d in dests)
                ListTile(
                  leading: Icon(_icon(d)),
                  title: Text(_label(d)),
                  selected: _dest == d,
                  onTap: () {
                    setState(() => _dest = d);
                    Navigator.pop(context);
                    _refreshPending();
                  },
                ),
              const Spacer(),
              const Divider(),
              ListTile(
                leading: const Icon(Icons.logout),
                title: const Text('Sign out'),
                onTap: () async {
                  Navigator.pop(context);
                  await widget.onLogout();
                },
              ),
            ],
          ),
        ),
      ),
      body: _body(),
    );
  }
}

class _RoleHomePanel extends StatelessWidget {
  const _RoleHomePanel({
    required this.user,
    this.onOpenReports,
    this.onOpenRoomSchedule,
    this.onOpenStudentSchedule,
  });

  final AuthUser? user;
  final VoidCallback? onOpenReports;
  final VoidCallback? onOpenRoomSchedule;
  final VoidCallback? onOpenStudentSchedule;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Welcome, ${user?.fullName ?? 'user'}',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        const SizedBox(height: 8),
        Text(
          'Signed in as ${user?.role}. '
          'Use the menu for attendance reports, weekly schedules, and enrolled students. '
          'Full admin tools remain on the web app.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 20),
        if (onOpenReports != null)
          ListTile(
            leading: const Icon(Icons.analytics_outlined),
            title: const Text('Attendance reports'),
            trailing: const Icon(Icons.chevron_right),
            onTap: onOpenReports,
          ),
        if (onOpenRoomSchedule != null)
          ListTile(
            leading: const Icon(Icons.calendar_view_week_outlined),
            title: const Text('Weekly schedule'),
            trailing: const Icon(Icons.chevron_right),
            onTap: onOpenRoomSchedule,
          ),
        if (onOpenStudentSchedule != null)
          ListTile(
            leading: const Icon(Icons.school_outlined),
            title: Text(
              user?.isStudent == true ? 'My schedule' : 'Enrolled students',
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: onOpenStudentSchedule,
          ),
      ],
    );
  }
}
