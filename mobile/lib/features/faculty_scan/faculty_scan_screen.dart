import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../app_scope.dart';
import '../../core/faculty_qr.dart';
import '../../core/result.dart';
import '../../data/repositories/checker_repository.dart';
import '../attendance_capture/attendance_capture_screen.dart';

/// Scan a faculty ID QR (`schoolId`, e.g. 2020-501), resolve meeting, capture.
class FacultyScanScreen extends StatefulWidget {
  const FacultyScanScreen({super.key, this.embeddedInShell = false});

  final bool embeddedInShell;

  @override
  State<FacultyScanScreen> createState() => _FacultyScanScreenState();
}

class _FacultyScanScreenState extends State<FacultyScanScreen> {
  final _manual = TextEditingController();
  final _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.normal,
    facing: CameraFacing.back,
  );
  bool _handling = false;
  String? _error;

  @override
  void dispose() {
    _manual.dispose();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_handling) return;
    final raw = capture.barcodes
        .map((b) => b.rawValue)
        .whereType<String>()
        .firstWhere((s) => s.trim().isNotEmpty, orElse: () => '');
    if (raw.isEmpty) return;
    await _resolveAndOpen(raw);
  }

  Future<void> _resolveAndOpen(String raw) async {
    if (_handling) return;
    setState(() {
      _handling = true;
      _error = null;
    });

    final result =
        await AppScope.of(context).checkerRepository.resolveFacultyScan(raw);

    if (!mounted) return;

    if (result is Failure<FacultyScanResolve>) {
      setState(() {
        _handling = false;
        _error = result.message;
      });
      return;
    }

    final resolved = (result as Success<FacultyScanResolve>).data;
    await _controller.stop();
    if (!mounted) return;

    if (!resolved.hasFaculty) {
      setState(() {
        _handling = false;
        _error =
            'Faculty ID ${resolved.schoolId} not in local cache. Pull to refresh when online.';
      });
      await _controller.start();
      return;
    }

    if (resolved.hasMeeting) {
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => AttendanceCaptureScreen(
            classBlockId: resolved.meeting!.id,
            scannedFacultySchoolId: resolved.schoolId,
          ),
        ),
      );
      return;
    }

    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('No schedule for this faculty'),
        content: Text(
          '${resolved.faculty!.name} (${resolved.schoolId}) has no class '
          'in the local cache for today right now.\n\n'
          'Record a NoSchedule warning?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Record NoSchedule'),
          ),
        ],
      ),
    );

    if (!mounted) return;

    if (go == true) {
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => AttendanceCaptureScreen.noScheduleForFaculty(
            facultyId: resolved.faculty!.id,
            facultyName: resolved.faculty!.name,
            schoolId: resolved.schoolId,
          ),
        ),
      );
      return;
    }

    setState(() => _handling = false);
    await _controller.start();
  }

  Widget _content() {
    return Column(
      children: [
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              MobileScanner(
                controller: _controller,
                onDetect: _onDetect,
              ),
              if (_handling)
                const ColoredBox(
                  color: Color(0x66000000),
                  child: Center(child: CircularProgressIndicator()),
                ),
              Align(
                alignment: Alignment.topCenter,
                child: Container(
                  margin: const EdgeInsets.all(16),
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 8,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text(
                    'Scan faculty ID QR (school ID number)',
                    style: TextStyle(color: Colors.white),
                  ),
                ),
              ),
            ],
          ),
        ),
        Material(
          elevation: 8,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Fallback — enter faculty ID number',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: _manual,
                  decoration: InputDecoration(
                    hintText:
                        'e.g. 2020-501 or ${FacultyQr.encode('2020-501')}',
                    border: const OutlineInputBorder(),
                    isDense: true,
                  ),
                  onSubmitted: (_) => _resolveAndOpen(_manual.text),
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed:
                      _handling ? null : () => _resolveAndOpen(_manual.text),
                  child: const Text('Look up faculty'),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ],
              ],
            ),
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
      appBar: AppBar(title: const Text('Scan faculty QR')),
      body: _content(),
    );
  }
}
