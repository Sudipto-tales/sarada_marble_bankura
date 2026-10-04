import 'package:flutter/material.dart';

/// Indeterminate progress follows actual room preparation, without a fake timer.
class VisualizerLoading extends StatelessWidget {
  const VisualizerLoading({super.key, required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 360),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: const EdgeInsets.all(28),
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary.withValues(alpha: .08),
                  borderRadius: BorderRadius.circular(28),
                ),
                child: Icon(
                  Icons.other_houses_outlined,
                  size: 56,
                  color: theme.colorScheme.primary,
                ),
              ),
              const SizedBox(height: 28),
              Text(
                label,
                textAlign: TextAlign.center,
                style: theme.textTheme.titleLarge,
              ),
              const SizedBox(height: 12),
              Text(
                'Getting your space and stone finishes ready. This may take a moment.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 24),
              LinearProgressIndicator(
                semanticsLabel: label,
                borderRadius: BorderRadius.circular(8),
              ),
              const SizedBox(height: 24),
              Text(
                'A little inspiration while you wait:\nTry a lighter floor to open up your space.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
