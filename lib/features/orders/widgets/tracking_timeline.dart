import 'package:flutter/material.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../data/models/order.dart';

/// Vertical delivery timeline. Completed steps are solid, the current step
/// pulses, future steps are outlined.
class TrackingTimeline extends StatelessWidget {
  const TrackingTimeline({super.key, required this.order});

  final Order order;

  static const _flow = [
    OrderStatus.placed,
    OrderStatus.confirmed,
    OrderStatus.processing,
    OrderStatus.shipped,
    OrderStatus.outForDelivery,
    OrderStatus.delivered,
  ];

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final terminal = order.status == OrderStatus.cancelled ||
        order.status == OrderStatus.returned;
    final steps = terminal
        ? order.timeline.map((e) => e.status).toList()
        : _flow;
    final eventByStatus = {for (final e in order.timeline) e.status: e};

    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.all(AppDimens.lg),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          border: Border.all(color: AppColors.line),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('Tracking',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.titleSmall),
                ),
                if (!terminal) ...[
                  const SizedBox(width: 10),
                  Text('Arriving ${Fmt.date(order.expectedDelivery)}',
                      style: t.labelSmall),
                ],
              ],
            ),
            const SizedBox(height: AppDimens.lg),
            for (var i = 0; i < steps.length; i++)
              _Step(
                status: steps[i],
                event: eventByStatus[steps[i]],
                done: eventByStatus.containsKey(steps[i]),
                current: steps[i] == order.status,
                last: i == steps.length - 1,
              ),
          ],
        ),
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
    required this.status,
    required this.event,
    required this.done,
    required this.current,
    required this.last,
  });

  final OrderStatus status;
  final OrderEvent? event;
  final bool done;
  final bool current;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final color = status == OrderStatus.cancelled
        ? AppColors.danger
        : status == OrderStatus.returned
            ? AppColors.warning
            : AppColors.teal;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: done ? color : Colors.transparent,
                  border: Border.all(
                    color: done ? color : AppColors.line,
                    width: 2,
                  ),
                ),
                child: done
                    ? const Icon(Icons.check_rounded,
                        size: 11, color: Colors.white)
                    : null,
              ),
              if (!last)
                Expanded(
                  child: Container(
                    width: 2,
                    margin: const EdgeInsets.symmetric(vertical: 2),
                    color: done ? color.withValues(alpha: 0.5) : AppColors.line,
                  ),
                ),
            ],
          ),
          const SizedBox(width: AppDimens.md),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: last ? 0 : AppDimens.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    status.label,
                    style: t.titleSmall?.copyWith(
                      color: done
                          ? Theme.of(context).colorScheme.onSurface
                          : AppColors.mutedSoft,
                      fontWeight: current ? FontWeight.w800 : FontWeight.w600,
                    ),
                  ),
                  if (event != null) ...[
                    const SizedBox(height: 2),
                    Text(event!.note, style: t.bodySmall),
                    const SizedBox(height: 2),
                    Text(
                      '${Fmt.dateTime(event!.at)}'
                      '${event!.location == null ? '' : ' · ${event!.location}'}',
                      style: t.labelSmall,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
