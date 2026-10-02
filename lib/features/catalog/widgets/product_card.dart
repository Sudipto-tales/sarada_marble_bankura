import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../core/widgets/price_text.dart';
import '../../../data/models/product.dart';

/// The catalog's primary unit. Used by home rails, grids, search and compare.
class ProductCard extends StatelessWidget {
  const ProductCard({
    super.key,
    required this.product,
    this.width,
    this.onTap,
    this.showWishlist = true,
    this.compact = false,
  });

  final Product product;
  final double? width;
  final VoidCallback? onTap;
  final bool showWishlist;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final t = Theme.of(context).textTheme;
    final heroTag = 'product-${product.id}-${width ?? 0}';

    return SizedBox(
      width: width,
      child: Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          onTap: onTap ??
              () => Navigator.pushNamed(
                    context,
                    Routes.productDetails,
                    arguments: ProductArgs(product.id, heroTag: heroTag),
                  ),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              border: Border.all(
                color: Theme.of(context).brightness == Brightness.dark
                    ? Colors.white10
                    : AppColors.line,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Stack(
                  children: [
                    ClipRRect(
                      borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(AppDimens.radiusMd)),
                      child: AspectRatio(
                        aspectRatio: compact ? 1.35 : 1.15,
                        child: Hero(
                          tag: heroTag,
                          child: AppImage(
                            product.image,
                            placeholderColor: AppColors.surface,
                          ),
                        ),
                      ),
                    ),
                    if (product.discount > 0)
                      Positioned(
                        left: 8,
                        top: 8,
                        child: TagChip(
                          label: '${product.discount}% OFF',
                          color: Colors.white,
                          background: AppColors.danger,
                          dense: true,
                        ),
                      ),
                    if (showWishlist)
                      Positioned(
                        right: 4,
                        top: 4,
                        child: Observer(
                          listenable: deps.wishlist,
                          builder: (context, wishlist) {
                            final saved = wishlist.contains(product.id);
                            return IconButton(
                              visualDensity: VisualDensity.compact,
                              style: IconButton.styleFrom(
                                backgroundColor:
                                    Colors.white.withValues(alpha: 0.86),
                                minimumSize: const Size(30, 30),
                              ),
                              icon: Icon(
                                saved
                                    ? Icons.favorite_rounded
                                    : Icons.favorite_border_rounded,
                                size: 17,
                                color: saved ? AppColors.danger : AppColors.ink,
                              ),
                              onPressed: () => wishlist.toggle(product.id),
                            );
                          },
                        ),
                      ),
                    if (!product.inStock)
                      Positioned.fill(
                        child: Container(
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.62),
                            borderRadius: const BorderRadius.vertical(
                                top: Radius.circular(AppDimens.radiusMd)),
                          ),
                          alignment: Alignment.center,
                          child: const TagChip(
                            label: 'OUT OF STOCK',
                            color: Colors.white,
                            background: AppColors.ink,
                          ),
                        ),
                      ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(10, 10, 10, 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        product.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: t.titleSmall,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${product.finish} · ${product.origin.split(',').first}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: t.bodySmall,
                      ),
                      const SizedBox(height: 6),
                      RatingBadge(
                          rating: product.rating,
                          count: product.reviewCount,
                          dense: true),
                      const SizedBox(height: 6),
                      PriceText(
                        price: product.pricePerSqFt,
                        original: product.originalPrice,
                        discount: product.discount,
                        size: compact ? 14 : 15,
                        showDiscount: false,
                      ),
                      if (product.isLowStock) ...[
                        const SizedBox(height: 6),
                        TagChip(
                          label: 'Only ${product.stock} sq.ft left',
                          color: AppColors.warning,
                          dense: true,
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Wide list-style tile used in search results and order/cart contexts.
class ProductListTile extends StatelessWidget {
  const ProductListTile({
    super.key,
    required this.product,
    this.trailing,
    this.onTap,
    this.subtitle,
  });

  final Product product;
  final Widget? trailing;
  final VoidCallback? onTap;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return InkWell(
      onTap: onTap ??
          () => Navigator.pushNamed(context, Routes.productDetails,
              arguments: ProductArgs(product.id)),
      child: Padding(
        padding: const EdgeInsets.symmetric(
            horizontal: AppDimens.lg, vertical: AppDimens.md),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AppImage(product.image,
                width: 78, height: 78, radius: AppDimens.radiusSm),
            const SizedBox(width: AppDimens.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(product.name,
                      style: t.titleSmall,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 2),
                  Text(
                    subtitle ?? '${product.color} · ${product.finish} · ${product.thickness}',
                    style: t.bodySmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 6),
                  PriceText(
                    price: product.pricePerSqFt,
                    original: product.originalPrice,
                    discount: product.discount,
                    size: 14,
                  ),
                ],
              ),
            ),
            if (trailing != null) ...[
              const SizedBox(width: AppDimens.sm),
              trailing!,
            ],
          ],
        ),
      ),
    );
  }
}

/// Skeleton shown while a rail or grid loads.
class ProductCardSkeleton extends StatelessWidget {
  const ProductCardSkeleton({super.key, this.width});
  final double? width;

  @override
  Widget build(BuildContext context) => Container(
        width: width,
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          border: Border.all(color: AppColors.line),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            AspectRatio(
              aspectRatio: 1.15,
              child: Container(color: AppColors.line.withValues(alpha: 0.5)),
            ),
            const Padding(
              padding: EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _Bar(widthFactor: 0.9),
                  SizedBox(height: 8),
                  _Bar(widthFactor: 0.55),
                  SizedBox(height: 10),
                  _Bar(widthFactor: 0.7, height: 16),
                ],
              ),
            ),
          ],
        ),
      );
}

class _Bar extends StatelessWidget {
  const _Bar({required this.widthFactor, this.height = 10});
  final double widthFactor;
  final double height;

  @override
  Widget build(BuildContext context) => FractionallySizedBox(
        widthFactor: widthFactor,
        alignment: Alignment.centerLeft,
        child: Container(
          height: height,
          decoration: BoxDecoration(
            color: AppColors.line,
            borderRadius: BorderRadius.circular(6),
          ),
        ),
      );
}
