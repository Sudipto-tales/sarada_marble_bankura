import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/theme/glossy_surface.dart';
import '../../../core/widgets/product_image_gallery.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../core/widgets/price_text.dart';
import '../../../core/widgets/shimmer.dart';
import '../../../core/widgets/stone_motion.dart';
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

    final tint = stoneTint(product.color, Theme.of(context).brightness);
    return SizedBox(
      width: width,
      child: StoneMotion(
        child: Material(
          color: Colors.transparent,
          borderRadius: BorderRadius.circular(20),
          clipBehavior: Clip.antiAlias,
          child: Ink(
            decoration: glossySurface(context, radius: 20),
            child: InkWell(
              onTap:
                  onTap ??
                  () => Navigator.pushNamed(
                    context,
                    Routes.productDetails,
                    arguments: ProductArgs(product.id, heroTag: heroTag),
                  ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  _ProductImageFrame(
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(16),
                          child: Hero(
                            tag: heroTag,
                            child: ProductImageGallery(
                              key: ValueKey(product.id),
                              images: [product.image, ...product.gallery],
                              placeholderColor: tint,
                            ),
                          ),
                        ),
                        if (showWishlist)
                          Positioned(
                            right: 6,
                            top: 6,
                            child: Observer(
                              listenable: deps.wishlist,
                              builder: (context, wishlist) => IconButton(
                                tooltip: wishlist.contains(product.id)
                                    ? 'Remove from wishlist'
                                    : 'Save marble',
                                style: IconButton.styleFrom(
                                  backgroundColor: Theme.of(
                                    context,
                                  ).colorScheme.surface.withValues(alpha: .92),
                                  side: BorderSide(
                                    color: Colors.white.withValues(alpha: .6),
                                  ),
                                  minimumSize: const Size(44, 44),
                                ),
                                icon: Icon(
                                  wishlist.contains(product.id)
                                      ? Icons.favorite_rounded
                                      : Icons.favorite_border_rounded,
                                  size: 19,
                                  color: wishlist.contains(product.id)
                                      ? AppColors.clay
                                      : Theme.of(context).colorScheme.onSurface,
                                ),
                                onPressed: () => wishlist.toggle(product.id),
                              ),
                            ),
                          ),
                        if (!product.inStock)
                          const Positioned(
                            left: 8,
                            bottom: 8,
                            child: TagChip(
                              label: 'OUT OF STOCK',
                              color: Colors.white,
                              background: AppColors.ink,
                              dense: true,
                            ),
                          ),
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          product.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: t.titleSmall,
                        ),
                        const SizedBox(height: 3),
                        Text(
                          product.brand,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: t.labelSmall?.copyWith(
                            fontSize: 10,
                            letterSpacing: .35,
                            color: Theme.of(context).colorScheme.primary,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '${product.finish} · ${product.origin.split(',').first}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: t.bodySmall,
                        ),
                        const SizedBox(height: 5),
                        RatingBadge(
                          rating: product.rating,
                          count: product.reviewCount,
                          dense: true,
                        ),
                        const SizedBox(height: 5),
                        PriceText(
                          price: product.pricePerSqFt,
                          original: product.originalPrice,
                          discount: product.discount,
                          size: compact ? 14 : 15,
                          showDiscount: false,
                        ),
                        if (product.isLowStock) ...[
                          const SizedBox(height: 6),
                          Text(
                            'Only ${product.stock} sq.ft left',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: t.labelSmall?.copyWith(
                              color: AppColors.clay,
                            ),
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
      ),
    );
  }
}

/// Thin inset with fixed geometry independent of the source image dimensions.
class _ProductImageFrame extends StatelessWidget {
  const _ProductImageFrame({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) => SizedBox(
      height: AppDimens.productImageHeight(constraints.maxWidth),
      child: Padding(padding: const EdgeInsets.all(5), child: child),
    ),
  );
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
      onTap:
          onTap ??
          () => Navigator.pushNamed(
            context,
            Routes.productDetails,
            arguments: ProductArgs(product.id),
          ),
      child: Padding(
        padding: const EdgeInsets.symmetric(
          horizontal: AppDimens.lg,
          vertical: AppDimens.md,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AppImage(product.image, width: 78, height: 78, radius: 0),
            const SizedBox(width: AppDimens.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    style: t.titleSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle ??
                        '${product.color} · ${product.finish} · ${product.thickness}',
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
  Widget build(BuildContext context) => Semantics(
    label: 'Loading marble',
    child: ExcludeSemantics(
      child: Container(
        width: width,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            _ProductImageFrame(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(20),
                child: const AspectRatio(
                  aspectRatio: 4 / 3,
                  child: Shimmer(height: double.infinity, radius: 0),
                ),
              ),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(12, 8, 12, 10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Shimmer(width: double.infinity, height: 16),
                  SizedBox(height: 5),
                  Shimmer(width: 90, height: 12),
                  SizedBox(height: 5),
                  Shimmer(width: 64, height: 14),
                  SizedBox(height: 5),
                  Shimmer(width: 100, height: 18),
                ],
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

/// Shared by catalog and wishlist while product metadata is being fetched.
class ProductGridSkeleton extends StatelessWidget {
  const ProductGridSkeleton({super.key});
  @override
  Widget build(BuildContext context) => GridView.builder(
    padding: const EdgeInsets.all(AppDimens.lg),
    gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
      crossAxisCount: AppDimens.gridColumns(MediaQuery.sizeOf(context).width),
      mainAxisSpacing: AppDimens.sm,
      crossAxisSpacing: AppDimens.sm,
      mainAxisExtent: AppDimens.gridProductHeight(context),
    ),
    itemCount: 6,
    itemBuilder: (_, _) => const ProductCardSkeleton(),
  );
}

/// Search uses rows rather than cards, so its skeleton retains that geometry.
class ProductListSkeleton extends StatelessWidget {
  const ProductListSkeleton({super.key});
  @override
  Widget build(BuildContext context) => ListView.separated(
    padding: const EdgeInsets.all(16),
    itemCount: 6,
    separatorBuilder: (_, _) => const SizedBox(height: 24),
    itemBuilder: (_, _) => const Row(
      children: [
        Shimmer(width: 78, height: 78, radius: 24),
        SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Shimmer(width: double.infinity, height: 16),
              SizedBox(height: 10),
              Shimmer(width: 100, height: 12),
              SizedBox(height: 10),
              Shimmer(width: 80, height: 18),
            ],
          ),
        ),
      ],
    ),
  );
}
