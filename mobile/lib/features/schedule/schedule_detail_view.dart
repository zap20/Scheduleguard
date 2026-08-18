import 'package:flutter/material.dart';

import '../room_schedule/weekly_schedule_grid.dart';

class ScheduleDetailView extends StatelessWidget {
  const ScheduleDetailView({
    super.key,
    required this.title,
    required this.subtitle,
    required this.meetings,
    required this.onBack,
    this.showBack = true,
    this.emptyLabel = 'No meetings for this selection.',
  });

  final String title;
  final String subtitle;
  final List<Map<String, dynamic>> meetings;
  final VoidCallback onBack;
  final bool showBack;
  final String emptyLabel;

  @override
  Widget build(BuildContext context) {
    final blocks = ScheduleGridBlock.fromMeetings(meetings);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showBack)
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 8, 16, 4),
            child: Row(
              children: [
                TextButton.icon(
                  onPressed: onBack,
                  icon: const Icon(Icons.arrow_back),
                  label: const Text('Back to cards'),
                ),
              ],
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 4),
              Text(
                subtitle,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
            ],
          ),
        ),
        Expanded(
          child: meetings.isEmpty
              ? Center(child: Text(emptyLabel))
              : Padding(
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                  child: WeeklyScheduleGrid(blocks: blocks),
                ),
        ),
      ],
    );
  }
}
