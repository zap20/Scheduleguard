import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../core/result.dart';
import '../../data/remote/reports_api.dart';

/// Attendance analytical reports (web analytics / oversight / review / mine).
class AttendanceReportsScreen extends StatefulWidget {
  const AttendanceReportsScreen({
    super.key,
    this.embeddedInShell = false,
  });

  final bool embeddedInShell;

  @override
  State<AttendanceReportsScreen> createState() =>
      _AttendanceReportsScreenState();
}

class _AttendanceReportsScreenState extends State<AttendanceReportsScreen>
    with SingleTickerProviderStateMixin {
  TabController? _tabs;
  ReportsApi? _api;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _topAbsent = [];
  List<Map<String, dynamic>> _records = [];
  bool _facultyOnly = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_api != null) return;
    final scope = AppScope.of(context);
    _api = ReportsApi(scope.http);
    final user = scope.authRepository.currentUser;
    _facultyOnly = user?.isFaculty == true;
    if (!_facultyOnly) {
      _tabs = TabController(length: 2, vsync: this);
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _tabs?.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final user = AppScope.of(context).authRepository.currentUser;
    final api = _api;
    if (user == null || api == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      if (user.isFaculty) {
        final mine = await api.fetchMine();
        if (mine is Failure) {
          setState(() {
            _loading = false;
            _error = (mine as Failure).message;
          });
          return;
        }
        final data = (mine as Success<Map<String, dynamic>>).data;
        _records = _asMapList(data['records']);
        _topAbsent = [];
      } else {
        final top = await api.fetchTopAbsent();
        if (top is Success<Map<String, dynamic>>) {
          _topAbsent = _asMapList(top.data['topAbsent']);
        } else {
          _error = (top as Failure).message;
        }

        final Result<Map<String, dynamic>> list = user.isHr
            ? await api.fetchReview()
            : await api.fetchOversight();
        if (list is Success<Map<String, dynamic>>) {
          _records = _asMapList(list.data['records']);
        } else if (_error == null) {
          _error = (list as Failure).message;
        }
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<Map<String, dynamic>> _asMapList(dynamic raw) {
    if (raw is! List) return [];
    return raw
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList();
  }

  Widget _content() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _topAbsent.isEmpty && _records.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(_error!, textAlign: TextAlign.center),
        ),
      );
    }
    if (_facultyOnly) {
      return _RecordsList(records: _records);
    }
    final tabs = _tabs!;
    return Column(
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBar(
            controller: tabs,
            tabs: const [
              Tab(text: 'Top absences'),
              Tab(text: 'Records'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            controller: tabs,
            children: [
              RefreshIndicator(
                onRefresh: _load,
                child: _topAbsent.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 80),
                          Center(child: Text('No absence rankings.')),
                        ],
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: _topAbsent.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, i) {
                          final row = _topAbsent[i];
                          final faculty = (row['faculty'] as Map?) ?? {};
                          final name = (faculty['fullName'] ??
                                  faculty['name'] ??
                                  'Faculty')
                              .toString();
                          final rank = row['rank'] ?? (i + 1);
                          final absentH =
                              row['absentHours'] ?? row['absentMinutes'];
                          final lateH =
                              row['lateHours'] ?? row['lateMinutes'];
                          return ListTile(
                            leading: CircleAvatar(child: Text('$rank')),
                            title: Text(name),
                            subtitle: Text(
                              'Absent: $absentH · Late: $lateH',
                            ),
                          );
                        },
                      ),
              ),
              RefreshIndicator(
                onRefresh: _load,
                child: _RecordsList(records: _records),
              ),
            ],
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (widget.embeddedInShell) {
      return _content();
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Attendance reports')),
      body: _content(),
    );
  }
}

class _RecordsList extends StatelessWidget {
  const _RecordsList({required this.records});
  final List<Map<String, dynamic>> records;

  @override
  Widget build(BuildContext context) {
    if (records.isEmpty) {
      return ListView(
        children: const [
          SizedBox(height: 80),
          Center(child: Text('No attendance records.')),
        ],
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(12),
      itemCount: records.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, i) {
        final r = records[i];
        final status = (r['status'] ?? '').toString();
        final ts = (r['timestamp'] ?? '').toString();
        final faculty = (r['faculty'] as Map?) ?? {};
        final schedule = (r['schedule'] as Map?) ?? {};
        final subject = (schedule['subjectCode'] ??
                schedule['subjectName'] ??
                schedule['expectedLabel'] ??
                '')
            .toString();
        final name =
            (faculty['fullName'] ?? faculty['name'] ?? '').toString();
        return ListTile(
          dense: true,
          title: Text(status + (name.isEmpty ? '' : ' · $name')),
          subtitle: Text(
            [
              if (subject.isNotEmpty) subject,
              if (ts.isNotEmpty) ts,
            ].join('\n'),
          ),
          isThreeLine: subject.isNotEmpty && ts.isNotEmpty,
        );
      },
    );
  }
}
