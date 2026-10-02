import 'package:flutter/material.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../data/models/product.dart';

class ProductSpecs extends StatelessWidget {
  const ProductSpecs({super.key, required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    final rows = <(String, String)>[
      ('Stone type', product.categoryId.replaceAll('_', ' ')),
      ('Colour', product.color),
      ('Finish', product.finish),
      ('Thickness', product.thickness),
      ('Slab size', product.dimensions),
      ('Coverage per slab', '${product.slabSqFt.toStringAsFixed(0)} sq.ft'),
      ('Origin', product.origin),
      ('Brand', product.brand),
      ('Available stock', '${product.stock} sq.ft'),
    ];
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          border: Border.all(color: AppColors.line),
        ),
        child: Column(
          children: [
            for (var i = 0; i < rows.length; i++)
              Container(
                padding: const EdgeInsets.symmetric(
                    horizontal: AppDimens.md, vertical: 11),
                decoration: BoxDecoration(
                  border: i == 0
                      ? null
                      : const Border(top: BorderSide(color: AppColors.line)),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 132,
                      child: Text(
                        rows[i].$1,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                    Expanded(
                      child: Text(
                        rows[i].$2,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
