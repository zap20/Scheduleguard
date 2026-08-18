import 'dart:convert';

import 'package:flutter/material.dart';

import '../../app_scope.dart';
import '../../core/constants.dart';
import '../../core/result.dart';
import '../../data/models/attendance_record.dart';
import '../../data/models/class_block.dart';
import '../../data/models/faculty.dart';

/// Offline-only capture. Reads caches + writes `attendance_record` locally.
/// Never calls the network.
class AttendanceCaptureScreen extends StatefulWidget {
  const AttendanceCaptureScreen({
    super.key,
    required this.classBlockId,
    this.scannedFacultySchoolId,
    this.initialStatus,
  })  : facultyOnlyNoSchedule = false,
        facultyId = null,
        facultyName = null,
        schoolId = null;

  /// Faculty scanned with no meeting in local cache — NoSchedule warning.
  const AttendanceCaptureScreen.noScheduleForFaculty({
    super.key,
    required this.facultyId,
    required this.facultyName,
    required this.schoolId,
  })  : classBlockId = '',
        scannedFacultySchoolId = schoolId,
        initialStatus = 'NoSchedule',
        facultyOnlyNoSchedule = true;

  final String classBlockId;
  final String? scannedFacultySchoolId;
  final String? initialStatus;
  final bool facultyOnlyNoSchedule;
  final String? facultyId;
  final String? facultyName;
  final String? schoolId;

  @override
  State<AttendanceCaptureScreen> createState() =>
      _AttendanceCaptureScreenState();
}

class _AttendanceCaptureScreenState extends State<AttendanceCaptureScreen> {
  ClassBlock? _block;
  Faculty? _faculty;
  AttendanceRecord? _saved;
  String? _selectedStatus;
  bool _loading = true;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _selectedStatus = widget.initialStatus;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadLocal());
  }

  Future<void> _loadLocal() async {
    if (widget.facultyOnlyNoSchedule) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _selectedStatus = 'NoSchedule';
      });
      return;
    }

    final scope = AppScope.of(context);
    final block = await scope.classBlockDao.getById(widget.classBlockId);
    Faculty? faculty;
    final facultyId = block?.facultyId;
    if (facultyId != null && facultyId.isNotEmpty) {
      faculty = await scope.facultyDao.getById(facultyId);
    }
    if (!mounted) return;
    setState(() {
      _block = block;
      _faculty = faculty;
      _loading = false;
    });
  }

  Future<void> _submit() async {
    final user = AppScope.of(context).authRepository.currentUser;
    final status = _selectedStatus;

    if (user == null) {
      setState(() => _error = 'Not signed in');
      return;
    }
    if (status == null) {
      setState(() => _error = 'Select a status');
      return;
    }

    late final String facultyId;
    late final String classBlockId;
    String? deviceMeta;

    if (widget.facultyOnlyNoSchedule) {
      if (status != 'NoSchedule' && status != 'WrongRoom') {
        setState(
          () => _error = 'Without a meeting, only NoSchedule / WrongRoom apply',
        );
        return;
      }
      facultyId = widget.facultyId!;
      classBlockId = 'faculty-nosched:${widget.schoolId}';
      deviceMeta = jsonEncode({
        'schoolId': widget.schoolId,
        'facultyId': widget.facultyId,
        'scan': 'faculty_qr_no_meeting',
      });
    } else {
      final block = _block;
      if (block == null) {
        setState(() => _error = 'Meeting not in local cache');
        return;
      }
      final fid = block.facultyId;
      if (fid == null || fid.isEmpty) {
        setState(() => _error = 'No faculty on this meeting (TBF)');
        return;
      }
      facultyId = fid;
      classBlockId = block.id;
      if (widget.scannedFacultySchoolId != null) {
        deviceMeta = jsonEncode({
          'schoolId': widget.scannedFacultySchoolId,
          'scan': 'faculty_qr',
        });
      }
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    final result = await AppScope.of(context).attendanceRepository.capture(
          facultyId: facultyId,
          classBlockId: classBlockId,
          checkerId: user.uid,
          status: status,
          deviceMeta: deviceMeta,
        );

    if (!mounted) return;

    if (result is Failure<AttendanceRecord>) {
      setState(() {
        _submitting = false;
        _error = result.message;
      });
      return;
    }

    final record = (result as Success<AttendanceRecord>).data;
    setState(() {
      _saved = record;
      _submitting = false;
    });

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Saved ${record.status} · pending sync'),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  List<String> get _statuses {
    if (widget.facultyOnlyNoSchedule) {
      return const ['NoSchedule', 'WrongRoom'];
    }
    return AppConstants.attendanceStatuses;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final block = _block;
    final facultyName = widget.facultyOnlyNoSchedule
        ? (widget.facultyName ?? 'Faculty')
        : (_faculty?.name ??
            (block?.facultyId == null || block!.facultyId!.isEmpty
                ? 'TBF'
                : block.facultyId!));
    final locked = _submitting || _saved != null;
    final idDisplay = widget.scannedFacultySchoolId ??
        _faculty?.schoolId ??
        widget.schoolId ??
        '—';

    return Scaffold(
      appBar: AppBar(title: const Text('Record attendance')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (!widget.facultyOnlyNoSchedule && block == null)
                  Text(
                    'Meeting ${widget.classBlockId} not found in local cache.',
                    style: TextStyle(color: theme.colorScheme.error),
                  )
                else ...[
                  Text(
                    widget.facultyOnlyNoSchedule
                        ? 'No schedule for scanned faculty'
                        : block!.displayTitle,
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 12),
                  _InfoRow(label: 'Faculty', value: facultyName),
                  _InfoRow(label: 'ID no.', value: idDisplay),
                  if (!widget.facultyOnlyNoSchedule) ...[
                    _InfoRow(
                      label: 'Time',
                      value: block!.scheduleTime.isEmpty
                          ? '—'
                          : block.scheduleTime,
                    ),
                    _InfoRow(
                      label: 'Room',
                      value: block.room.isEmpty ? '—' : block.room,
                    ),
                  ],
                  const SizedBox(height: 8),
                  Text(
                    'Saved on this device only. Sync happens later from Sync status.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 20),
                  Text('Status', style: theme.textTheme.titleMedium),
                  const SizedBox(height: 8),
                  ..._statuses.map((status) {
                    final selected = _selectedStatus == status;
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Material(
                        color: selected
                            ? theme.colorScheme.secondaryContainer
                            : theme.colorScheme.surfaceContainerHighest,
                        borderRadius: BorderRadius.circular(12),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: locked
                              ? null
                              : () => setState(() {
                                    _selectedStatus = status;
                                    _error = null;
                                  }),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 14,
                              vertical: 14,
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  selected
                                      ? Icons.radio_button_checked
                                      : Icons.radio_button_off,
                                  color: selected
                                      ? theme.colorScheme.primary
                                      : theme.colorScheme.onSurfaceVariant,
                                ),
                                const SizedBox(width: 12),
                                Text(
                                  status,
                                  style: theme.textTheme.titleSmall,
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    );
                  }),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      _error!,
                      style: TextStyle(color: theme.colorScheme.error),
                    ),
                  ],
                  if (_saved != null) ...[
                    const SizedBox(height: 16),
                    Material(
                      color: theme.colorScheme.primaryContainer,
                      borderRadius: BorderRadius.circular(12),
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(
                              Icons.check_circle,
                              color: theme.colorScheme.primary,
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                'Recorded ${_saved!.status}\n'
                                'sync_status: ${_saved!.syncStatus}\n'
                                'recorded_at: ${_saved!.recordedAt}',
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(true),
                      child: const Text('Done'),
                    ),
                  ] else ...[
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _submitting ? null : _submit,
                      child: Text(_submitting ? 'Saving…' : 'Submit'),
                    ),
                  ],
                ],
              ],
            ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 72,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
        ],
      ),
    );
  }
}
