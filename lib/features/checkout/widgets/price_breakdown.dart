import 'package:flutter/material.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';

/// Single source of truth for how money is displayed. Values are computed by
/// CartController — this widget only renders them.
class PriceBreakdown extends StatelessWidget {
  const PriceBreakdown({
    super.key,
    required this.subtotal,
    required this.savings,
    required this.couponDiscount,
    required this.delivery,
    required this.tax,
    required this.total,
    this.title = 'Price details',
  });

  final double subtotal;
  final double savings;
  final double couponDiscount;
  final double delivery;
  final double tax;
  final double total;
  final String title;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
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
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.titleSmall),
                ),
                if (savings > 0) ...[
                  const SizedBox(width: 10),
                  Text(
                    'You save ${Fmt.rupees(savings)}',
                    style: t.labelSmall?.copyWith(color: AppColors.success),
                  ),
                ],
              ],
            ),
            const SizedBox(height: AppDimens.md),
            _row(context, 'Subtotal', Fmt.rupees(subtotal)),
            if (couponDiscount > 0)
              _row(context, 'Coupon discount', '- ${Fmt.rupees(couponDiscount)}',
                  color: AppColors.success),
            _row(
              context,
              'Delivery',
              delivery <= 0 ? 'FREE' : Fmt.rupees(delivery),
              color: delivery <= 0 ? AppColors.success : null,
            ),
            _row(context, 'GST (18%)', Fmt.rupees(tax)),
            const Padding(
              padding: EdgeInsets.symmetric(vertical: AppDimens.md),
              child: Divider(height: 1),
            ),
            Row(
              children: [
                Expanded(
                  child: Text('Total payable',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.titleMedium),
                ),
                const SizedBox(width: 10),
                Text(Fmt.rupees(total), style: t.titleLarge),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(BuildContext context, String label, String value, {Color? color}) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: t.bodyMedium?.copyWith(color: AppColors.muted),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            value,
            style: t.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}
