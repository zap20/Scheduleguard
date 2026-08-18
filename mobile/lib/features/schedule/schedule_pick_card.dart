import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Port of web `.schedule-pick-card` (faculty / student / room browsers).
class SchedulePickCard extends StatelessWidget {
  const SchedulePickCard({
    super.key,
    required this.eyebrow,
    required this.title,
    required this.stat,
    this.cta = 'View weekly grid →',
    required this.onTap,
  });

  final String eyebrow;
  final String title;
  final String stat;
  final String cta;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: theme.colorScheme.outline.withValues(alpha: 0.28),
            ),
          ),
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                eyebrow.toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  letterSpacing: 0.06 * 16,
                  fontWeight: FontWeight.w700,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                title,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                stat,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                cta,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: AppTheme.brand,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class GroupedScheduleSection {
  const GroupedScheduleSection({required this.label, required this.children});
  final String label;
  final List<Widget> children;
}

class GroupedScheduleCardList extends StatelessWidget {
  const GroupedScheduleCardList({
    super.key,
    required this.sections,
    this.emptyLabel = 'Nothing to browse.',
  });

  final List<GroupedScheduleSection> sections;
  final String emptyLabel;

  @override
  Widget build(BuildContext context) {
    if (sections.isEmpty) {
      return Center(child: Text(emptyLabel));
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      itemCount: sections.length,
      itemBuilder: (context, i) {
        final section = sections[i];
        return Padding(
          padding: const EdgeInsets.only(bottom: 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                section.label,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.3,
                    ),
              ),
              const SizedBox(height: 10),
              ...section.children.map(
                (child) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: child,
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
