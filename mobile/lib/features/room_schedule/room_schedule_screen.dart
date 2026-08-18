import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../core/result.dart';
import '../../data/remote/schedule_api.dart';
import '../schedule/schedule_detail_view.dart';
import '../schedule/schedule_pick_card.dart';

/// Port of web faculty timetable: room/faculty cards, then weekly grid.
class RoomScheduleScreen extends StatefulWidget {
  const RoomScheduleScreen({
    super.key,
    this.embeddedInShell = false,
  });

  final bool embeddedInShell;

  @override
  State<RoomScheduleScreen> createState() => _RoomScheduleScreenState();
}

enum _BrowseMode { room, faculty }

class _RoomScheduleScreenState extends State<RoomScheduleScreen> {
  ScheduleApi? _api;
  List<Map<String, dynamic>> _rooms = [];
  List<Map<String, dynamic>> _faculty = [];
  List<Map<String, dynamic>> _meetings = [];
  _BrowseMode _mode = _BrowseMode.room;
  String? _detailKind;
  String? _detailId;
  String _facultyQuery = '';
  bool _loading = true;
  String? _error;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_api != null) return;
    _api = ScheduleApi(AppScope.of(context).http);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final api = _api;
    if (api == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    final roomsRes = await api.listRooms();
    final schedRes = await api.listFacultySchedules();
    final facultyRes = await api.listFaculty();
    if (!mounted) return;
    if (roomsRes is Failure<List<Map<String, dynamic>>>) {
      setState(() {
        _loading = false;
        _error = roomsRes.message;
      });
      return;
    }
    if (schedRes is Failure<List<Map<String, dynamic>>>) {
      setState(() {
        _loading = false;
        _error = schedRes.message;
      });
      return;
    }
    setState(() {
      _rooms = (roomsRes as Success<List<Map<String, dynamic>>>).data;
      _meetings = (schedRes as Success<List<Map<String, dynamic>>>).data;
      _faculty = facultyRes is Success<List<Map<String, dynamic>>>
          ? facultyRes.data
          : const [];
      _loading = false;
    });
  }

  String _roomLabel(Map<String, dynamic> r) {
    final label = (r['label'] ?? '').toString().trim();
    if (label.isNotEmpty) return label;
    final b = (r['building'] ?? '').toString();
    final n = (r['name'] ?? '').toString();
    if (b.isEmpty) return n;
    if (n.isEmpty) return b;
    return '$b / $n';
  }

  String _roomType(Map<String, dynamic> r) {
    return (r['roomType'] ?? r['room_type'] ?? 'LECTURE').toString().toUpperCase();
  }

  List<Map<String, dynamic>> _meetingsForRoom(String roomId) {
    return _meetings
        .where((row) => (row['roomId'] ?? '').toString() == roomId)
        .toList();
  }

  List<Map<String, dynamic>> _meetingsForFaculty(String facultyId) {
    return _meetings
        .where((row) => (row['facultyId'] ?? '').toString() == facultyId)
        .toList();
  }

  String _instructor(Map<String, dynamic> row) {
    final name =
        (row['instructor'] ?? row['facultyName'] ?? '').toString().trim();
    return name.isEmpty ? 'TBF' : name;
  }

  String _facultyName(String id, List<Map<String, dynamic>> rows) {
    for (final f in _faculty) {
      if ((f['uid'] ?? '').toString() == id) {
        final name = (f['fullName'] ?? '').toString().trim();
        if (name.isNotEmpty) return name;
        final combined =
            '${f['firstName'] ?? ''} ${f['lastName'] ?? ''}'.trim();
        if (combined.isNotEmpty) return combined;
      }
    }
    if (rows.isNotEmpty) return _instructor(rows.first);
    return 'Faculty';
  }

  String _employmentLabel(String id) {
    for (final f in _faculty) {
      if ((f['uid'] ?? '').toString() == id) {
        final raw = (f['employmentType'] ?? '').toString();
        if (raw == 'PartTime') return 'Part-time';
        if (raw == 'Regular') return 'Regular';
      }
    }
    return '';
  }

  Widget _cards() {
    if (_mode == _BrowseMode.room) {
      final groups = <String, List<Map<String, dynamic>>>{
        'LECTURE': [],
        'LAB': [],
        'OTHER': [],
      };
      for (final room in _rooms) {
        final type = _roomType(room);
        if (type == 'LAB') {
          groups['LAB']!.add(room);
        } else if (type == 'LECTURE') {
          groups['LECTURE']!.add(room);
        } else {
          groups['OTHER']!.add(room);
        }
      }
      const order = ['LECTURE', 'LAB', 'OTHER'];
      const labels = {
        'LECTURE': 'Lecture rooms',
        'LAB': 'Lab rooms',
        'OTHER': 'Other rooms',
      };
      final sections = <GroupedScheduleSection>[];
      for (final key in order) {
        final rooms = groups[key]!;
        if (rooms.isEmpty) continue;
        sections.add(
          GroupedScheduleSection(
            label: labels[key]!,
            children: rooms.map((room) {
              final id = (room['uid'] ?? '').toString();
              final rows = _meetingsForRoom(id);
              final type = _roomType(room);
              final free = rows.isEmpty ? ' · free' : '';
              return SchedulePickCard(
                eyebrow: '$type$free',
                title: _roomLabel(room),
                stat:
                    '${rows.length} meeting${rows.length == 1 ? '' : 's'}',
                onTap: () => setState(() {
                  _detailKind = 'room';
                  _detailId = id;
                }),
              );
            }).toList(),
          ),
        );
      }
      return GroupedScheduleCardList(
        sections: sections,
        emptyLabel: 'No rooms to browse.',
      );
    }

    final byFaculty = <String, List<Map<String, dynamic>>>{};
    for (final row in _meetings) {
      final id = (row['facultyId'] ?? '').toString().trim();
      if (id.isEmpty) continue;
      byFaculty.putIfAbsent(id, () => []).add(row);
    }
    for (final f in _faculty) {
      final id = (f['uid'] ?? '').toString().trim();
      if (id.isEmpty) continue;
      byFaculty.putIfAbsent(id, () => []);
    }
    final q = _facultyQuery.trim().toLowerCase();
    final ids = byFaculty.keys.toList()
      ..sort((a, b) =>
          _facultyName(a, byFaculty[a]!).compareTo(_facultyName(b, byFaculty[b]!)));
    final cards = ids.where((id) {
      if (q.isEmpty) return true;
      return _facultyName(id, byFaculty[id]!).toLowerCase().contains(q);
    }).map((id) {
      final rows = byFaculty[id]!;
      final emp = _employmentLabel(id);
      final eyebrow = rows.isEmpty
          ? 'No meetings this term'
          : (emp.isEmpty ? 'Faculty' : emp);
      return SchedulePickCard(
        eyebrow: eyebrow,
        title: _facultyName(id, rows),
        stat: rows.isEmpty
            ? 'Available · load 0'
            : '${rows.length} meeting${rows.length == 1 ? '' : 's'}',
        onTap: () => setState(() {
          _detailKind = 'faculty';
          _detailId = id;
        }),
      );
    }).toList();
    return GroupedScheduleCardList(
      emptyLabel: 'No faculty to browse.',
      sections: [
        if (cards.isNotEmpty)
          GroupedScheduleSection(label: 'Faculty', children: cards),
      ],
    );
  }

  Widget _content() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _meetings.isEmpty && _rooms.isEmpty) {
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

    if (_detailKind != null && _detailId != null) {
      final rows = _detailKind == 'room'
          ? _meetingsForRoom(_detailId!)
          : _meetingsForFaculty(_detailId!);
      String title = 'Weekly grid';
      if (_detailKind == 'room') {
        Map<String, dynamic>? room;
        for (final r in _rooms) {
          if ((r['uid'] ?? '').toString() == _detailId) {
            room = r;
            break;
          }
        }
        title = room == null ? 'Room' : _roomLabel(room);
      } else {
        title = _facultyName(_detailId!, rows);
      }
      return ScheduleDetailView(
        title: title,
        subtitle: '${rows.length} meeting${rows.length == 1 ? '' : 's'}',
        meetings: rows,
        onBack: () => setState(() {
          _detailKind = null;
          _detailId = null;
        }),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: SegmentedButton<_BrowseMode>(
            segments: const [
              ButtonSegment(
                value: _BrowseMode.room,
                label: Text('By room'),
                icon: Icon(Icons.meeting_room_outlined),
              ),
              ButtonSegment(
                value: _BrowseMode.faculty,
                label: Text('By faculty'),
                icon: Icon(Icons.person_outline),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: (next) {
              setState(() => _mode = next.first);
            },
          ),
        ),
        if (_mode == _BrowseMode.faculty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'Filter faculty…',
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
                isDense: true,
              ),
              onChanged: (value) => setState(() => _facultyQuery = value),
            ),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        Expanded(child: _cards()),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (widget.embeddedInShell) {
      return _content();
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Weekly schedule')),
      body: _content(),
    );
  }
}
