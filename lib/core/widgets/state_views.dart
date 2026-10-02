import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_dimens.dart';

/// Empty state with an optional call to action.
class EmptyView extends StatelessWidget {
  const EmptyView({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppDimens.xxl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: compact ? 64 : 88,
              height: compact ? 64 : 88,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  colors: [
                    AppColors.teal.withValues(alpha: 0.16),
                    AppColors.cyan.withValues(alpha: 0.06),
                  ],
                ),
              ),
              child: Icon(icon, size: compact ? 28 : 38, color: AppColors.deep),
            ),
            const SizedBox(height: AppDimens.lg),
            Text(title, style: t.titleLarge, textAlign: TextAlign.center),
            const SizedBox(height: AppDimens.sm),
            Text(
              message,
              style: t.bodyMedium?.copyWith(color: AppColors.muted),
              textAlign: TextAlign.center,
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppDimens.xl),
              FilledButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}

/// Error state with retry. Never shows a raw exception to the user.
class ErrorView extends StatelessWidget {
  const ErrorView({
    super.key,
    required this.onRetry,
    this.title = 'Something went wrong',
    this.message = 'We could not load this right now. Please try again.',
    this.compact = false,
  });

  final VoidCallback onRetry;
  final String title;
  final String message;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppDimens.xxl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: compact ? 56 : 76,
              height: compact ? 56 : 76,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.danger.withValues(alpha: 0.10),
              ),
              child: Icon(Icons.cloud_off_rounded,
                  size: compact ? 26 : 34, color: AppColors.danger),
            ),
            const SizedBox(height: AppDimens.lg),
            Text(title, style: t.titleLarge, textAlign: TextAlign.center),
            const SizedBox(height: AppDimens.sm),
            Text(
              message,
              style: t.bodyMedium?.copyWith(color: AppColors.muted),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppDimens.xl),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Try again'),
            ),
          ],
        ),
      ),
    );
  }
}

class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.label});

  final String? label;

  @override
  Widget build(BuildContext context) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(
              width: 30,
              height: 30,
              child: CircularProgressIndicator(strokeWidth: 2.4),
            ),
            if (label != null) ...[
              const SizedBox(height: AppDimens.md),
              Text(label!, style: Theme.of(context).textTheme.bodySmall),
            ],
          ],
        ),
      );
}
