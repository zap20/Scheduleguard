import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../core/result.dart';
import '../../data/models/auth_user.dart';
import '../../data/remote/schedule_api.dart';
import '../schedule/schedule_detail_view.dart';
import '../schedule/schedule_pick_card.dart';

/// Dean: enrolled students as cards grouped by school year + year level.
/// Student: own weekly grid (distributed enrollments).
class StudentScheduleScreen extends StatefulWidget {
  const StudentScheduleScreen({
    super.key,
    this.embeddedInShell = false,
  });

  final bool embeddedInShell;

  @override
  State<StudentScheduleScreen> createState() => _StudentScheduleScreenState();
}

class _EnrolledStudent {
  const _EnrolledStudent({
    required this.uid,
    required this.fullName,
    required this.schoolId,
    required this.schoolYear,
    required this.yearLevel,
    required this.studentType,
    required this.blocks,
    required this.meetings,
  });

  final String uid;
  final String fullName;
  final String schoolId;
  final String schoolYear;
  final String yearLevel;
  final String studentType;
  final List<String> blocks;
  final List<Map<String, dynamic>> meetings;
}

class _StudentScheduleScreenState extends State<StudentScheduleScreen> {
  ScheduleApi? _api;
  AuthUser? _user;
  List<Map<String, dynamic>> _rows = [];
  String? _selectedStudentId;
  bool _loading = true;
  String? _error;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_api != null) return;
    final scope = AppScope.of(context);
    _api = ScheduleApi(scope.http);
    _user = scope.authRepository.currentUser;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final api = _api;
    final user = _user;
    if (api == null || user == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = user.isStudent
        ? await api.myStudentSchedule()
        : await api.deanStudentSchedules();
    if (!mounted) return;
    if (res is Failure<List<Map<String, dynamic>>>) {
      setState(() {
        _loading = false;
        _error = res.message;
        _rows = [];
      });
      return;
    }
    setState(() {
      _rows = (res as Success<List<Map<String, dynamic>>>).data;
      _loading = false;
    });
  }

  String _schoolYear(Map<String, dynamic> row) {
    final schoolId = (row['studentSchoolId'] ?? '').toString().trim();
    final match = RegExp(r'^(\d{4})').firstMatch(schoolId);
    if (match != null) return match.group(1)!;
    final ay = row['academicYear'];
    if (ay is num && ay > 0) return ay.toInt().toString();
    return 'Unknown';
  }

  String _yearLevel(Map<String, dynamic> row) {
    final yl = (row['studentYearLevel'] ?? '').toString().trim();
    return yl.isEmpty ? 'Unspecified' : yl;
  }

  List<_EnrolledStudent> _collectStudents() {
    final byId = <String, _EnrolledStudent>{};
    for (final row in _rows) {
      final id = (row['studentId'] ?? '').toString();
      if (id.isEmpty) continue;
      final existing = byId[id];
      final block = (row['blockName'] ?? '').toString().trim();
      if (existing == null) {
        byId[id] = _EnrolledStudent(
          uid: id,
          fullName: (row['studentName'] ?? 'Student').toString(),
          schoolId: (row['studentSchoolId'] ?? '').toString(),
          schoolYear: _schoolYear(row),
          yearLevel: _yearLevel(row),
          studentType: (row['studentType'] ?? '').toString(),
          blocks: [if (block.isNotEmpty) block],
          meetings: [row],
        );
      } else {
        final blocks = [...existing.blocks];
        if (block.isNotEmpty && !blocks.contains(block)) blocks.add(block);
        byId[id] = _EnrolledStudent(
          uid: existing.uid,
          fullName: existing.fullName,
          schoolId: existing.schoolId,
          schoolYear: existing.schoolYear,
          yearLevel: existing.yearLevel,
          studentType: existing.studentType,
          blocks: blocks,
          meetings: [...existing.meetings, row],
        );
      }
    }
    final list = byId.values.toList()
      ..sort((a, b) => a.fullName.compareTo(b.fullName));
    return list;
  }

  List<GroupedScheduleSection> _groupedSections(List<_EnrolledStudent> students) {
    const yearOrder = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    final byYear = <String, Map<String, List<_EnrolledStudent>>>{};
    for (final s in students) {
      byYear.putIfAbsent(s.schoolYear, () => {});
      byYear[s.schoolYear]!.putIfAbsent(s.yearLevel, () => []).add(s);
    }
    final schoolYears = byYear.keys.toList()
      ..sort((a, b) {
        if (a == 'Unknown') return 1;
        if (b == 'Unknown') return -1;
        return (int.tryParse(b) ?? 0).compareTo(int.tryParse(a) ?? 0);
      });

    final sections = <GroupedScheduleSection>[];
    for (final sy in schoolYears) {
      final levels = byYear[sy]!.keys.toList()
        ..sort((a, b) {
          final ia = yearOrder.indexOf(a);
          final ib = yearOrder.indexOf(b);
          if (ia != -1 && ib != -1) return ia - ib;
          if (ia != -1) return -1;
          if (ib != -1) return 1;
          return a.compareTo(b);
        });
      for (final yl in levels) {
        final group = byYear[sy]![yl]!;
        sections.add(
          GroupedScheduleSection(
            label: 'School year $sy · $yl · ${group.length} '
                'student${group.length == 1 ? '' : 's'}',
            children: group.map((s) {
              final blocks = s.blocks.isEmpty ? 'No block name' : s.blocks.join(', ');
              final type = s.studentType.trim();
              return SchedulePickCard(
                eyebrow: '${s.schoolId.isEmpty ? 'No school ID' : s.schoolId} · ${s.yearLevel}',
                title: s.fullName,
                stat:
                    '${s.meetings.length} meeting${s.meetings.length == 1 ? '' : 's'} · $blocks'
                    '${type.isEmpty ? '' : ' · $type'}',
                onTap: () => setState(() => _selectedStudentId = s.uid),
              );
            }).toList(),
          ),
        );
      }
    }
    return sections;
  }

  Widget _content() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _rows.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    if (_user?.isStudent == true) {
      return ScheduleDetailView(
        title: 'My schedule',
        subtitle: '${_rows.length} meeting${_rows.length == 1 ? '' : 's'}',
        meetings: _rows,
        onBack: () {},
        showBack: false,
        emptyLabel: 'No distributed schedule for this term.',
      );
    }

    final students = _collectStudents();
    if (_selectedStudentId != null) {
      _EnrolledStudent? selected;
      for (final s in students) {
        if (s.uid == _selectedStudentId) {
          selected = s;
          break;
        }
      }
      final rows = selected?.meetings ??
          _rows
              .where((r) => (r['studentId'] ?? '').toString() == _selectedStudentId)
              .toList();
      return ScheduleDetailView(
        title: selected?.fullName ?? 'Weekly grid',
        subtitle: [
          if ((selected?.schoolId ?? '').isNotEmpty) selected!.schoolId,
          if ((selected?.yearLevel ?? '').isNotEmpty) selected!.yearLevel,
          if ((selected?.studentType ?? '').isNotEmpty) selected!.studentType,
          if (selected != null && selected.blocks.isNotEmpty)
            selected.blocks.join(', '),
        ].join(' · '),
        meetings: rows,
        onBack: () => setState(() => _selectedStudentId = null),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Text(
            'Students already enrolled this term. Tap a card for the weekly grid.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        Expanded(
          child: GroupedScheduleCardList(
            sections: _groupedSections(students),
            emptyLabel: 'No enrolled students for this term yet.',
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
      appBar: AppBar(
        title: Text(_user?.isStudent == true ? 'My schedule' : 'Enrolled students'),
      ),
      body: _content(),
    );
  }
}
