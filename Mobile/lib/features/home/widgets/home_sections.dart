import 'package:flutter/material.dart';

import '../../../core/config/feature_flags.dart';
import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../data/models/category.dart';
import '../../../data/models/coupon.dart';
import '../../../data/models/product.dart';
import '../../catalog/widgets/product_card.dart';

/// Horizontal rail of product cards.
class ProductRail extends StatelessWidget {
  const ProductRail({
    super.key,
    required this.products,
    this.height = 292,
    this.cardWidth = 168,
  });

  final List<Product> products;
  final double height;
  final double cardWidth;

  @override
  Widget build(BuildContext context) {
    if (products.isEmpty) return const SizedBox.shrink();
    return SizedBox(
      height: AppDimens.productHeight(
        context,
        cardWidth,
      ).clamp(height, double.infinity),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: AppDimens.screenPad,
        itemCount: products.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppDimens.md),
        itemBuilder: (context, i) =>
            ProductCard(product: products[i], width: cardWidth),
      ),
    );
  }
}

class CategoryStrip extends StatelessWidget {
  const CategoryStrip({super.key, required this.categories});

  final List<Category> categories;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 116,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: AppDimens.screenPad,
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppDimens.md),
        itemBuilder: (context, i) {
          final c = categories[i];
          return SizedBox(
            width: 82,
            child: Column(
              children: [
                InkWell(
                  borderRadius: BorderRadius.circular(40),
                  onTap: () => Navigator.pushNamed(
                    context,
                    Routes.catalog,
                    arguments: CatalogArgs(categoryId: c.id, title: c.name),
                  ),
                  child: Container(
                    width: 72,
                    height: 72,
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                      borderRadius: AppDimens.stoneCurve,
                      color: AppColors.line,
                    ),
                    child: ClipRRect(
                      borderRadius: AppDimens.stoneCurve,
                      child: AppImage(c.image),
                    ),
                  ),
                ),
                const SizedBox(height: 7),
                Text(
                  c.name,
                  maxLines: 2,
                  textAlign: TextAlign.center,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: Theme.of(context).colorScheme.onSurface,
                    height: 1.2,
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Two big entry points into the optional modules. Both tiles disappear when
/// their module is disabled — the home screen keeps working regardless.
class ModuleShortcuts extends StatelessWidget {
  const ModuleShortcuts({super.key});

  @override
  Widget build(BuildContext context) {
    final tiles = <Widget>[
      if (FeatureFlags.visualizationEnabled)
        _ShortcutTile(
          title: 'See it in your room',
          subtitle: 'Walk through a 3D room and swap marble live',
          icon: Icons.view_in_ar_rounded,
          image: 'assets/images/rooms/luxury_living_thumb.webp',
          onTap: () => Navigator.pushNamed(
            context,
            Routes.visualizer,
            arguments: const VisualizerArgs(),
          ),
        ),
      if (FeatureFlags.calculatorEnabled)
        _ShortcutTile(
          title: 'Cost calculator',
          subtitle: 'Area, wastage and slab count in one go',
          icon: Icons.calculate_rounded,
          image: 'assets/images/rooms/modern_kitchen_thumb.webp',
          onTap: () => Navigator.pushNamed(
            context,
            Routes.calculator,
            arguments: const CalculatorArgs(),
          ),
        ),
    ];
    if (tiles.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: AppDimens.screenPad,
      child: Row(
        children: [
          for (var i = 0; i < tiles.length; i++) ...[
            if (i > 0) const SizedBox(width: AppDimens.md),
            Expanded(child: tiles[i]),
          ],
        ],
      ),
    );
  }
}

class _ShortcutTile extends StatelessWidget {
  const _ShortcutTile({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.image,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final String image;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(AppDimens.radiusMd),
      onTap: onTap,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        child: SizedBox(
          height: 132,
          child: Stack(
            fit: StackFit.expand,
            children: [
              AppImage(image),
              const DecoratedBox(
                decoration: BoxDecoration(gradient: AppColors.photoScrim),
              ),
              Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(7),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.92),
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Icon(icon, size: 17, color: AppColors.deep),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                            fontSize: 13.5,
                            height: 1.2,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          subtitle,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.78),
                            fontSize: 10.5,
                            height: 1.25,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class OfferStrip extends StatelessWidget {
  const OfferStrip({super.key, required this.offers});

  final List<Offer> offers;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 148,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: AppDimens.screenPad,
        itemCount: offers.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppDimens.md),
        itemBuilder: (context, i) {
          final o = offers[i];
          return InkWell(
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            onTap: () => Navigator.pushNamed(
              context,
              Routes.catalog,
              arguments: CatalogArgs(
                categoryId: o.categoryId,
                title: o.title,
                onlyOffers: true,
              ),
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              child: SizedBox(
                width: 232,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    AppImage(o.image),
                    const DecoratedBox(
                      decoration: BoxDecoration(gradient: AppColors.photoScrim),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(13),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          if (o.couponCode != null)
                            TagChip(
                              label: 'CODE ${o.couponCode}',
                              color: AppColors.ink,
                              background: AppColors.goldSoft,
                              dense: true,
                            ),
                          const SizedBox(height: 7),
                          Text(
                            o.title,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                              fontSize: 15,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            o.subtitle,
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.8),
                              fontSize: 11.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class InspirationRail extends StatelessWidget {
  const InspirationRail({super.key, required this.items});

  final List<PromoBanner> items;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 208,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: AppDimens.screenPad,
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppDimens.md),
        itemBuilder: (context, i) {
          final item = items[i];
          return InkWell(
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            onTap: item.productId == null
                ? null
                : () => Navigator.pushNamed(
                    context,
                    Routes.productDetails,
                    arguments: ProductArgs(item.productId!),
                  ),
            child: SizedBox(
              width: 260,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                      child: AppImage(item.image, width: 260),
                    ),
                  ),
                  const SizedBox(height: 9),
                  Text(
                    item.title,
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  Text(
                    item.subtitle,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Trust / service promises. Static copy, no business logic.
class TrustStrip extends StatelessWidget {
  const TrustStrip({super.key});

  static const _items = [
    (Icons.verified_rounded, 'Quarry direct', 'Graded slabs, no seconds'),
    (
      Icons.local_shipping_rounded,
      'Site delivery',
      'Crated and edge-protected',
    ),
    (Icons.straighten_rounded, 'Free measure', 'On orders above ₹1 lakh'),
    (
      Icons.support_agent_rounded,
      'Stone advisors',
      'Talk to a real fabricator',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: AppDimens.lg),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          border: Border.all(color: AppColors.line),
        ),
        child: Row(
          children: [
            for (final (icon, title, sub) in _items)
              Expanded(
                child: Column(
                  children: [
                    Icon(icon, size: 22, color: AppColors.deep),
                    const SizedBox(height: 7),
                    Text(
                      title,
                      textAlign: TextAlign.center,
                      style: Theme.of(
                        context,
                      ).textTheme.labelMedium?.copyWith(fontSize: 11),
                    ),
                    const SizedBox(height: 2),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Text(
                        sub,
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          fontSize: 9.5,
                          height: 1.25,
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

/// Big editorial card that sends the user into the 3D module.
class VisualizerPromo extends StatelessWidget {
  const VisualizerPromo({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: AppDimens.screenPad,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppDimens.radiusLg),
        child: Stack(
          children: [
            SizedBox(
              height: 220,
              width: double.infinity,
              child: AppImage('assets/images/rooms/hotel_lobby.webp'),
            ),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      AppColors.ink.withValues(alpha: 0.85),
                      AppColors.ink.withValues(alpha: 0.25),
                    ],
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                  ),
                ),
              ),
            ),
            Positioned.fill(
              child: Padding(
                padding: const EdgeInsets.all(AppDimens.xl),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const TagChip(
                      label: '3D ROOM PREVIEW',
                      color: AppColors.ink,
                      background: AppColors.cyan,
                      dense: true,
                    ),
                    const SizedBox(height: 10),
                    const SizedBox(
                      width: 210,
                      child: Text(
                        'Try any marble in a real room before you buy',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                          height: 1.2,
                          letterSpacing: -0.3,
                        ),
                      ),
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      width: 180,
                      child: GradientButton(
                        label: 'Open 3D room',
                        icon: Icons.view_in_ar_rounded,
                        height: 44,
                        onPressed: () => Navigator.pushNamed(
                          context,
                          Routes.visualizer,
                          arguments: const VisualizerArgs(),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Small support card at the very bottom of home.
class SupportCard extends StatelessWidget {
  const SupportCard({super.key});

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.all(AppDimens.lg),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [AppColors.ice.withValues(alpha: 0.7), AppColors.surface],
          ),
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          border: Border.all(color: AppColors.line),
        ),
        child: Row(
          children: [
            const Icon(
              Icons.headset_mic_rounded,
              size: 26,
              color: AppColors.deep,
            ),
            const SizedBox(width: AppDimens.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Not sure which stone?',
                    style: t.titleSmall?.copyWith(color: AppColors.ink),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Request a free sample or a project quote.',
                    style: t.bodySmall?.copyWith(color: AppColors.inkSoft),
                  ),
                ],
              ),
            ),
            TextButton(
              onPressed: () =>
                  Navigator.pushNamed(context, Routes.requestSample),
              child: const Text('Request'),
            ),
          ],
        ),
      ),
    );
  }
}

/// "Recently viewed" chips-with-image rail.
class RecentlyViewedRail extends StatelessWidget {
  const RecentlyViewedRail({super.key, required this.products});

  final List<Product> products;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 96,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: AppDimens.screenPad,
        itemCount: products.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppDimens.md),
        itemBuilder: (context, i) {
          final p = products[i];
          return InkWell(
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            onTap: () => Navigator.pushNamed(
              context,
              Routes.productDetails,
              arguments: ProductArgs(p.id),
            ),
            child: Container(
              width: 214,
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                border: Border.all(color: AppColors.line),
              ),
              child: Row(
                children: [
                  AppImage(p.image, width: 62, height: 62, radius: 8),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          p.name,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '${Fmt.rupees(p.pricePerSqFt)}/sq.ft',
                          style: Theme.of(context).textTheme.labelMedium
                              ?.copyWith(color: AppColors.deep),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
