import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Port of `public/js/schedule-grid.js` — weekly timetable with 30‑minute slots.
class WeeklyScheduleGrid extends StatelessWidget {
  const WeeklyScheduleGrid({
    super.key,
    required this.blocks,
    this.startHour = 7,
    this.endHour = 21,
    this.showMeta = true,
  });

  final List<ScheduleGridBlock> blocks;

  /// Inclusive start as hour (7 = 7:00 AM). Supports fractions (20.5 = 8:30 PM).
  final double startHour;
  final double endHour;
  final bool showMeta;

  static const _dayOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  static const _slotHeight = 22.0;
  static const _timeWidth = 78.0;
  static const _minDayWidth = 88.0;

  @override
  Widget build(BuildContext context) {
    final rangeStart = (startHour * 60).round();
    final rangeEnd = (endHour * 60).round();
    final slots = _buildSlots(rangeStart, rangeEnd);
    final prepared = _prepareBlocks(blocks, rangeStart, rangeEnd);
    final dayMaps = _placeBlocks(prepared, slots);
    final theme = Theme.of(context);
    final line = theme.dividerColor.withValues(alpha: 0.55);
    final surface = theme.colorScheme.surface;
    final inkSoft = theme.colorScheme.onSurfaceVariant;

    final gridWidth = _timeWidth + _dayOrder.length * _minDayWidth;
    final bodyHeight = slots.length * _slotHeight;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showMeta)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              'Weekly grid · ${_minutesToLabel(rangeStart)} – '
              '${_minutesToLabel(rangeEnd)} · ${prepared.length} meeting(s)',
              style: theme.textTheme.bodySmall?.copyWith(color: inkSoft),
            ),
          ),
        Expanded(
          child: Container(
            decoration: BoxDecoration(
              border: Border.all(color: line),
              borderRadius: BorderRadius.circular(12),
              color: surface,
            ),
            clipBehavior: Clip.antiAlias,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final width = gridWidth < constraints.maxWidth
                    ? constraints.maxWidth
                    : gridWidth;
                final dayW = (width - _timeWidth) / _dayOrder.length;
                return Scrollbar(
                  thumbVisibility: true,
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: SizedBox(
                      width: width,
                      height: constraints.maxHeight,
                      child: Column(
                        children: [
                          _HeaderRow(
                            line: line,
                            surface: surface,
                            dayWidth: dayW,
                          ),
                          Expanded(
                            child: Scrollbar(
                              child: SingleChildScrollView(
                                child: SizedBox(
                                  height: bodyHeight,
                                  width: width,
                                  child: Row(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      _TimeColumn(
                                        slots: slots,
                                        line: line,
                                        surface: surface,
                                        inkSoft: inkSoft,
                                      ),
                                      for (final day in _dayOrder)
                                        _DayColumn(
                                          day: day,
                                          slots: slots,
                                          cells: dayMaps[day]!,
                                          line: line,
                                          dayWidth: dayW,
                                        ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  static List<_Slot> _buildSlots(int rangeStart, int rangeEnd) {
    final slots = <_Slot>[];
    for (var t = rangeStart; t < rangeEnd; t += 30) {
      slots.add(
        _Slot(
          start: t,
          end: t + 30,
          label: '${_minutesToLabel(t)}–${_minutesToLabel(t + 30)}',
        ),
      );
    }
    return slots;
  }

  static List<_PreparedBlock> _prepareBlocks(
    List<ScheduleGridBlock> classBlocks,
    int rangeStart,
    int rangeEnd,
  ) {
    final prepared = <_PreparedBlock>[];
    for (var i = 0; i < classBlocks.length; i++) {
      final raw = classBlocks[i];
      final day = _normalizeDay(raw.day);
      final startMin = _parseTimeToMinutes(raw.startTime);
      final endMin = _parseTimeToMinutes(raw.endTime);
      if (day == null || startMin == null || endMin == null || endMin <= startMin) {
        continue;
      }
      final roundedStart = (startMin ~/ 30) * 30;
      var roundedEnd = ((endMin + 29) ~/ 30) * 30;
      if (roundedEnd <= roundedStart) roundedEnd = roundedStart + 30;
      final start = roundedStart < rangeStart ? rangeStart : roundedStart;
      final end = roundedEnd > rangeEnd ? rangeEnd : roundedEnd;
      if (end <= start) continue;
      prepared.add(
        _PreparedBlock(
          day: day,
          start: start,
          end: end,
          label: raw.label,
        ),
      );
    }
    prepared.sort((a, b) {
      final d = _dayOrder.indexOf(a.day) - _dayOrder.indexOf(b.day);
      if (d != 0) return d;
      if (a.start != b.start) return a.start - b.start;
      return a.end - b.end;
    });
    return prepared;
  }

  static Map<String, List<_Cell>> _placeBlocks(
    List<_PreparedBlock> blocks,
    List<_Slot> slots,
  ) {
    final slotIndexByStart = <int, int>{
      for (var i = 0; i < slots.length; i++) slots[i].start: i,
    };
    final dayMaps = <String, List<_Cell>>{
      for (final day in _dayOrder)
        day: List.generate(slots.length, (_) => const _Cell.empty()),
    };

    for (final block in blocks) {
      final startIdx = slotIndexByStart[block.start];
      if (startIdx == null) continue;
      final rowspan = ((block.end - block.start) / 30).round().clamp(1, 999);
      final endIdx = startIdx + rowspan;
      final map = dayMaps[block.day]!;

      var conflict = false;
      var hostIdx = startIdx;
      for (var i = startIdx; i < endIdx && i < map.length; i++) {
        final cell = map[i];
        if (cell.kind == _CellKind.start) {
          conflict = true;
          hostIdx = i;
          break;
        }
        if (cell.kind == _CellKind.covered) {
          conflict = true;
          var j = i;
          while (j > 0 && map[j].kind == _CellKind.covered) {
            j--;
          }
          if (map[j].kind == _CellKind.start) hostIdx = j;
          break;
        }
      }

      if (!conflict) {
        map[startIdx] = _Cell.start(
          labels: [block.label],
          rowspan: rowspan,
          overlap: false,
        );
        for (var i = startIdx + 1; i < endIdx && i < map.length; i++) {
          map[i] = const _Cell.covered();
        }
        continue;
      }

      final host = map[hostIdx];
      if (host.kind != _CellKind.start) {
        map[startIdx] = _Cell.start(
          labels: [block.label],
          rowspan: rowspan,
          overlap: true,
        );
        for (var i = startIdx + 1; i < endIdx && i < map.length; i++) {
          if (map[i].kind == _CellKind.empty) {
            map[i] = const _Cell.covered();
          }
        }
        continue;
      }

      final labels = [...host.labels, block.label];
      final hostEnd = hostIdx + host.rowspan;
      final neededEnd = hostEnd > endIdx ? hostEnd : endIdx;
      var newRowspan = host.rowspan;
      if (neededEnd > hostEnd) {
        for (var i = hostEnd; i < neededEnd && i < map.length; i++) {
          if (map[i].kind == _CellKind.start) {
            labels.addAll(map[i].labels);
          }
          map[i] = const _Cell.covered();
        }
        newRowspan = neededEnd - hostIdx;
      }
      map[hostIdx] = _Cell.start(
        labels: labels,
        rowspan: newRowspan,
        overlap: true,
      );
    }
    return dayMaps;
  }

  static String? _normalizeDay(String day) {
    switch (day.trim().toLowerCase()) {
      case 'mon':
      case 'monday':
        return 'Mon';
      case 'tue':
      case 'tues':
      case 'tuesday':
        return 'Tue';
      case 'wed':
      case 'wednesday':
        return 'Wed';
      case 'thu':
      case 'thur':
      case 'thurs':
      case 'thursday':
        return 'Thu';
      case 'fri':
      case 'friday':
        return 'Fri';
      case 'sat':
      case 'saturday':
        return 'Sat';
      case 'sun':
      case 'sunday':
        return 'Sun';
      default:
        return null;
    }
  }

  static int? _parseTimeToMinutes(String value) {
    final raw = value.trim();
    if (raw.isEmpty) return null;
    final ampm = RegExp(
      r'^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])$',
    ).firstMatch(raw);
    if (ampm != null) {
      var h = int.parse(ampm.group(1)!);
      final m = int.parse(ampm.group(2)!);
      final ap = ampm.group(4)!.toLowerCase();
      if (h == 12) h = 0;
      if (ap == 'pm') h += 12;
      return h * 60 + m;
    }
    final mil = RegExp(r'^(\d{1,2}):(\d{2})(?::(\d{2}))?$').firstMatch(raw);
    if (mil != null) {
      final h = int.parse(mil.group(1)!);
      final m = int.parse(mil.group(2)!);
      if (h > 23 || m > 59) return null;
      return h * 60 + m;
    }
    return null;
  }

  static String _minutesToLabel(int totalMinutes) {
    final h24 = totalMinutes ~/ 60;
    final m = totalMinutes % 60;
    final suffix = h24 >= 12 ? 'PM' : 'AM';
    final h12 = h24 % 12 == 0 ? 12 : h24 % 12;
    return '$h12:${m.toString().padLeft(2, '0')} $suffix';
  }
}

class ScheduleGridBlock {
  const ScheduleGridBlock({
    required this.day,
    required this.startTime,
    required this.endTime,
    required this.label,
  });

  final String day;
  final String startTime;
  final String endTime;
  final String label;

  /// Same label shape as web `toGridBlocks`.
  static List<ScheduleGridBlock> fromMeetings(
    List<Map<String, dynamic>> rows,
  ) {
    return rows.map((row) {
      final code = (row['subjectCode'] ?? '').toString().trim();
      final fac = (row['facultyName'] ?? row['instructor'] ?? '')
          .toString()
          .trim();
      final block = (row['blockName'] ?? row['classBlockName'] ?? '')
          .toString()
          .trim();
      final room = (row['roomName'] ?? row['roomLabel'] ?? '').toString().trim();
      final parts = <String>[
        if (code.isNotEmpty) code else 'Subject',
        fac.isEmpty ? 'TBF' : fac,
        if (block.isNotEmpty) block,
        if (room.isNotEmpty) room,
      ];
      return ScheduleGridBlock(
        day: (row['day'] ?? '').toString(),
        startTime: (row['startTime'] ?? '').toString(),
        endTime: (row['endTime'] ?? '').toString(),
        label: parts.join(' · '),
      );
    }).toList();
  }
}

class _Slot {
  const _Slot({required this.start, required this.end, required this.label});
  final int start;
  final int end;
  final String label;
}

class _PreparedBlock {
  const _PreparedBlock({
    required this.day,
    required this.start,
    required this.end,
    required this.label,
  });
  final String day;
  final int start;
  final int end;
  final String label;
}

enum _CellKind { empty, start, covered }

class _Cell {
  const _Cell._({
    required this.kind,
    this.labels = const [],
    this.rowspan = 1,
    this.overlap = false,
  });

  const _Cell.empty() : this._(kind: _CellKind.empty);
  const _Cell.covered() : this._(kind: _CellKind.covered);
  const _Cell.start({
    required List<String> labels,
    required int rowspan,
    required bool overlap,
  }) : this._(
          kind: _CellKind.start,
          labels: labels,
          rowspan: rowspan,
          overlap: overlap,
        );

  final _CellKind kind;
  final List<String> labels;
  final int rowspan;
  final bool overlap;
}

class _HeaderRow extends StatelessWidget {
  const _HeaderRow({
    required this.line,
    required this.surface,
    required this.dayWidth,
  });
  final Color line;
  final Color surface;
  final double dayWidth;

  @override
  Widget build(BuildContext context) {
    final brandTint = Color.lerp(surface, AppTheme.brand, 0.12)!;
    return Container(
      decoration: BoxDecoration(
        color: brandTint,
        border: Border(bottom: BorderSide(color: line, width: 2)),
      ),
      child: Row(
        children: [
          SizedBox(
            width: WeeklyScheduleGrid._timeWidth,
            height: 32,
            child: Container(
              alignment: Alignment.centerLeft,
              padding: const EdgeInsets.symmetric(horizontal: 6),
              decoration: BoxDecoration(
                border: Border(right: BorderSide(color: line, width: 2)),
                color: Color.lerp(surface, AppTheme.brand, 0.18),
              ),
              child: Text(
                'TIME',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.4,
                      fontSize: 10,
                    ),
              ),
            ),
          ),
          for (final day in WeeklyScheduleGrid._dayOrder)
            SizedBox(
              width: dayWidth,
              height: 32,
              child: Container(
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  border: Border(right: BorderSide(color: line)),
                ),
                child: Text(
                  day.toUpperCase(),
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.4,
                        fontSize: 10,
                      ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _TimeColumn extends StatelessWidget {
  const _TimeColumn({
    required this.slots,
    required this.line,
    required this.surface,
    required this.inkSoft,
  });

  final List<_Slot> slots;
  final Color line;
  final Color surface;
  final Color inkSoft;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: WeeklyScheduleGrid._timeWidth,
      child: Column(
        children: [
          for (final slot in slots)
            Container(
              height: WeeklyScheduleGrid._slotHeight,
              width: WeeklyScheduleGrid._timeWidth,
              alignment: Alignment.centerLeft,
              padding: const EdgeInsets.symmetric(horizontal: 4),
              decoration: BoxDecoration(
                color: surface,
                border: Border(
                  right: BorderSide(color: line, width: 2),
                  bottom: BorderSide(color: line),
                ),
              ),
              child: Text(
                slot.label,
                maxLines: 1,
                overflow: TextOverflow.clip,
                style: TextStyle(
                  fontSize: 8.5,
                  fontWeight: FontWeight.w600,
                  color: inkSoft,
                  height: 1.1,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _DayColumn extends StatelessWidget {
  const _DayColumn({
    required this.day,
    required this.slots,
    required this.cells,
    required this.line,
    required this.dayWidth,
  });

  final String day;
  final List<_Slot> slots;
  final List<_Cell> cells;
  final Color line;
  final double dayWidth;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: dayWidth,
      height: slots.length * WeeklyScheduleGrid._slotHeight,
      child: Stack(
        children: [
          Column(
            children: [
              for (var i = 0; i < slots.length; i++)
                Container(
                  height: WeeklyScheduleGrid._slotHeight,
                  decoration: BoxDecoration(
                    border: Border(
                      right: BorderSide(color: line),
                      bottom: BorderSide(color: line),
                    ),
                  ),
                ),
            ],
          ),
          for (var i = 0; i < cells.length; i++)
            if (cells[i].kind == _CellKind.start)
              Positioned(
                top: i * WeeklyScheduleGrid._slotHeight,
                left: 0,
                right: 0,
                height: cells[i].rowspan * WeeklyScheduleGrid._slotHeight,
                child: _BlockCell(cell: cells[i]),
              ),
        ],
      ),
    );
  }
}

class _BlockCell extends StatelessWidget {
  const _BlockCell({required this.cell});
  final _Cell cell;

  @override
  Widget build(BuildContext context) {
    final overlap = cell.overlap;
    final bg = Color.lerp(
      Theme.of(context).colorScheme.surface,
      overlap ? const Color(0xFFB42318) : AppTheme.brand,
      overlap ? 0.18 : 0.22,
    )!;
    final border = overlap ? const Color(0xFFB42318) : AppTheme.brand;

    return Container(
      margin: const EdgeInsets.all(0.5),
      padding: const EdgeInsets.fromLTRB(5, 3, 4, 2),
      decoration: BoxDecoration(
        color: bg,
        border: Border(left: BorderSide(color: border, width: 3)),
      ),
      child: SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (overlap)
              Text(
                'Room overlap',
                style: TextStyle(
                  fontSize: 8,
                  fontWeight: FontWeight.w700,
                  color: border,
                ),
              ),
            for (final label in cell.labels)
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Text(
                  label,
                  style: const TextStyle(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w700,
                    height: 1.2,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
