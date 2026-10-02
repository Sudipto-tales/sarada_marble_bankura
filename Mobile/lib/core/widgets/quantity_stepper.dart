import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_dimens.dart';

/// Area stepper. Marble is bought in square feet, so the step is coarse and
/// the user can also type an exact figure.
class QuantityStepper extends StatelessWidget {
  const QuantityStepper({
    super.key,
    required this.value,
    required this.onChanged,
    this.step = 10,
    this.min = 10,
    this.max = 5000,
    this.suffix = 'sq.ft',
    this.dense = false,
    this.onTapValue,
  });

  final double value;
  final ValueChanged<double> onChanged;
  final double step;
  final double min;
  final double max;
  final String suffix;
  final bool dense;
  final VoidCallback? onTapValue;

  @override
  Widget build(BuildContext context) {
    final h = dense ? 34.0 : 42.0;
    return Container(
      height: h,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppDimens.radiusSm),
        border: Border.all(color: AppColors.line),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _btn(Icons.remove_rounded, h,
              value > min ? () => onChanged((value - step).clamp(min, max)) : null),
          GestureDetector(
            onTap: onTapValue,
            child: Container(
              constraints: BoxConstraints(minWidth: dense ? 62 : 82),
              alignment: Alignment.center,
              child: Text(
                '${value.toStringAsFixed(value % 1 == 0 ? 0 : 1)} $suffix',
                style: TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize: dense ? 12.5 : 14,
                ),
              ),
            ),
          ),
          _btn(Icons.add_rounded, h,
              value < max ? () => onChanged((value + step).clamp(min, max)) : null),
        ],
      ),
    );
  }

  Widget _btn(IconData icon, double h, VoidCallback? onTap) => SizedBox(
        width: h,
        height: h,
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: onTap,
            child: Icon(
              icon,
              size: dense ? 16 : 18,
              color: onTap == null ? AppColors.mutedSoft : AppColors.deep,
            ),
          ),
        ),
      );
}
