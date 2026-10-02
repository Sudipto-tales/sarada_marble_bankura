import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../utils/formatters.dart';

/// Price + struck-through MRP + discount, in one consistent treatment.
class PriceText extends StatelessWidget {
  const PriceText({
    super.key,
    required this.price,
    this.original,
    this.discount,
    this.unit = '/sq.ft',
    this.size = 16,
    this.showDiscount = true,
  });

  final double price;
  final double? original;
  final int? discount;
  final String unit;
  final double size;
  final bool showDiscount;

  @override
  Widget build(BuildContext context) {
    final hasOriginal = original != null && original! > price;
    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 6,
      children: [
        RichText(
          text: TextSpan(
            style: DefaultTextStyle.of(context).style,
            children: [
              TextSpan(
                text: Fmt.rupees(price),
                style: TextStyle(
                  fontSize: size,
                  fontWeight: FontWeight.w700,
                  color: Theme.of(context).colorScheme.onSurface,
                  letterSpacing: -0.2,
                ),
              ),
              if (unit.isNotEmpty)
                TextSpan(
                  text: unit,
                  style: TextStyle(
                    fontSize: size * 0.68,
                    fontWeight: FontWeight.w600,
                    color: AppColors.muted,
                  ),
                ),
            ],
          ),
        ),
        if (hasOriginal)
          Text(
            Fmt.rupees(original!),
            style: TextStyle(
              fontSize: size * 0.78,
              color: AppColors.mutedSoft,
              decoration: TextDecoration.lineThrough,
              decorationColor: AppColors.mutedSoft,
              fontWeight: FontWeight.w500,
            ),
          ),
        if (showDiscount && (discount ?? 0) > 0)
          Text(
            '${discount!}% off',
            style: TextStyle(
              fontSize: size * 0.78,
              color: AppColors.success,
              fontWeight: FontWeight.w700,
            ),
          ),
      ],
    );
  }
}
