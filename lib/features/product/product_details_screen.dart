import 'package:flutter/material.dart';
import '../brand_catalogs/brand_catalog_widgets.dart';

import '../../core/bridge/module_bridge.dart';
import '../../core/config/feature_flags.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/price_text.dart';
import '../../core/widgets/quantity_stepper.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/shimmer.dart';
import '../../data/models/product.dart';
import '../../data/models/review.dart';
import 'widgets/product_discovery.dart';
import 'widgets/product_gallery.dart';
import 'widgets/product_specs.dart';
import 'widgets/review_summary.dart';

class ProductDetailsScreen extends StatefulWidget {
  const ProductDetailsScreen({super.key, required this.args});

  final ProductArgs args;

  @override
  State<ProductDetailsScreen> createState() => _ProductDetailsScreenState();
}

class _ProductDetailsScreenState extends State<ProductDetailsScreen> {
  late Future<_Details> _future = _load();
  double _sqFt = 50;

  Future<_Details> _load() async {
    final deps = AppScope.read(context);
    final product = await deps.products.byId(widget.args.productId);
    if (product == null) {
      throw Exception('Product not found');
    }
    deps.browsing.markViewed(product.id);
    final results = await Future.wait([
      deps.reviews.forProduct(product.id),
      deps.reviews.ratingBreakdown(product.id),
      deps.products.similarTo(product.id, limit: 8),
      deps.products.all(),
    ]);
    return _Details(
      product: product,
      reviews: results[0] as List<Review>,
      breakdown: results[1] as Map<int, int>,
      similar: results[2] as List<Product>,
      catalog: results[3] as List<Product>,
    );
  }

  Future<void> _addToCart(Product product, {bool buyNow = false}) async {
    final deps = AppScope.read(context);
    await deps.cart.add(product, sqFt: _sqFt);
    if (!mounted) return;
    if (buyNow) {
      Navigator.pushNamed(
        context,
        Routes.checkout,
        arguments: CheckoutArgs(buyNowProductId: product.id, buyNowSqFt: _sqFt),
      );
    } else {
      Toast.success(
        context,
        '${Fmt.sqft(_sqFt)} of ${product.name} added',
        actionLabel: 'View cart',
        onAction: () => Navigator.pushNamed(context, Routes.cart),
      );
    }
  }

  /// Calculator hand-off: the module returns a plain result object and this
  /// screen decides what it means (prefill the quantity).
  void _onCalculated(CalculationResult result) {
    setState(() => _sqFt = result.requiredSqFt);
    Toast.show(
      context,
      'Quantity set to ${Fmt.sqft(result.requiredSqFt)} from the calculator',
    );
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      body: FutureBuilder<_Details>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const _DetailsSkeleton();
          }
          if (snap.hasError) {
            return Scaffold(
              appBar: AppBar(),
              body: ErrorView(
                onRetry: () => setState(() => _future = _load()),
                title: 'Could not load this marble',
              ),
            );
          }
          final data = snap.data!;
          return _Content(
            data: data,
            sqFt: _sqFt,
            onSqFt: (v) => setState(() => _sqFt = v),
            onCalculated: _onCalculated,
          );
        },
      ),
      bottomNavigationBar: FutureBuilder<_Details>(
        future: _future,
        builder: (context, snap) {
          final product = snap.data?.product;
          if (product == null) return const SizedBox.shrink();
          return SafeArea(
            child: Container(
              padding: const EdgeInsets.symmetric(
                horizontal: AppDimens.lg,
                vertical: AppDimens.md,
              ),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: const BorderRadius.vertical(
                  top: Radius.circular(32),
                ),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.ink.withValues(alpha: 0.06),
                    blurRadius: 24,
                  ),
                ],
              ),
              child: Row(
                children: [
                  Observer(
                    listenable: deps.cart,
                    builder: (context, cart) => Expanded(
                      child: OutlinedButton.icon(
                        onPressed: product.inStock
                            ? () => _addToCart(product)
                            : null,
                        icon: const Icon(
                          Icons.add_shopping_cart_rounded,
                          size: 18,
                        ),
                        label: Text(
                          cart.contains(product.id)
                              ? 'Add more'
                              : 'Add to cart',
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: AppDimens.md),
                  Expanded(
                    child: GradientButton(
                      label: product.inStock ? 'Buy now' : 'Out of stock',
                      icon: Icons.bolt_rounded,
                      onPressed: product.inStock
                          ? () => _addToCart(product, buyNow: true)
                          : null,
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

class _Details {
  const _Details({
    required this.product,
    required this.reviews,
    required this.breakdown,
    required this.similar,
    required this.catalog,
  });

  final Product product;
  final List<Review> reviews;
  final Map<int, int> breakdown;
  final List<Product> similar;
  final List<Product> catalog;
}

class _Content extends StatelessWidget {
  const _Content({
    required this.data,
    required this.sqFt,
    required this.onSqFt,
    required this.onCalculated,
  });

  final _Details data;
  final double sqFt;
  final ValueChanged<double> onSqFt;
  final CalculationHandoff onCalculated;

  @override
  Widget build(BuildContext context) {
    final product = data.product;
    final deps = AppScope.of(context);
    final t = Theme.of(context).textTheme;
    final estimate = product.pricePerSqFt * sqFt;

    return CustomScrollView(
      slivers: [
        SliverAppBar(
          pinned: true,
          expandedHeight: 360,
          backgroundColor: Theme.of(context).scaffoldBackgroundColor,
          flexibleSpace: FlexibleSpaceBar(
            background: ProductGallery(product: product),
          ),
          actions: [
            Observer(
              listenable: deps.browsing,
              builder: (context, browsing) => IconButton(
                tooltip: 'Compare',
                onPressed: () {
                  final ok = browsing.toggleCompare(product.id);
                  if (!ok) {
                    Toast.error(context, 'Compare holds up to 4 products');
                  } else if (browsing.isComparing(product.id)) {
                    Toast.show(
                      context,
                      'Added to compare',
                      actionLabel: browsing.canCompare ? 'Compare' : null,
                      onAction: browsing.canCompare
                          ? () => Navigator.pushNamed(context, Routes.compare)
                          : null,
                    );
                  }
                },
                icon: Icon(
                  browsing.isComparing(product.id)
                      ? Icons.compare_arrows_rounded
                      : Icons.compare_arrows_outlined,
                  color: browsing.isComparing(product.id)
                      ? AppColors.teal
                      : null,
                ),
              ),
            ),
            Observer(
              listenable: deps.wishlist,
              builder: (context, wishlist) => IconButton(
                onPressed: () {
                  final added = wishlist.toggle(product.id);
                  Toast.show(
                    context,
                    added ? 'Saved to wishlist' : 'Removed from wishlist',
                  );
                },
                icon: Icon(
                  wishlist.contains(product.id)
                      ? Icons.favorite_rounded
                      : Icons.favorite_border_rounded,
                  color: wishlist.contains(product.id)
                      ? AppColors.danger
                      : null,
                ),
              ),
            ),
          ],
        ),
        SliverList.list(
          children: [
            Padding(
              padding: AppDimens.screenPad,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SizedBox(height: AppDimens.md),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      if (product.isBestSeller)
                        const TagChip(
                          label: 'BESTSELLER',
                          color: AppColors.ink,
                          background: AppColors.goldSoft,
                          dense: true,
                        ),
                      if (product.isTrending) ...[
                        const SizedBox(width: 6),
                        const TagChip(
                          label: 'TRENDING',
                          color: AppColors.deep,
                          dense: true,
                        ),
                      ],
                      Text(product.brand, style: t.labelSmall),
                    ],
                  ),
                  const SizedBox(height: AppDimens.sm),
                  Text(product.name, style: t.headlineSmall),
                  const SizedBox(height: 6),
                  Text(
                    '${product.color} · ${product.finish} · ${product.origin}',
                    style: t.bodySmall,
                  ),
                  const SizedBox(height: AppDimens.md),
                  Row(
                    children: [
                      RatingBadge(
                        rating: product.rating,
                        count: product.reviewCount,
                      ),
                      const SizedBox(width: AppDimens.md),
                      if (product.inStock)
                        TagChip(
                          label: product.isLowStock
                              ? 'Only ${product.stock} sq.ft left'
                              : 'In stock',
                          color: product.isLowStock
                              ? AppColors.warning
                              : AppColors.success,
                          dense: true,
                        )
                      else
                        const TagChip(
                          label: 'Out of stock',
                          color: AppColors.danger,
                          dense: true,
                        ),
                    ],
                  ),
                  const SizedBox(height: AppDimens.lg),
                  PriceText(
                    price: product.pricePerSqFt,
                    original: product.originalPrice,
                    discount: product.discount,
                    size: 26,
                  ),
                  Text(
                    'Inclusive of edge polishing. GST extra.',
                    style: t.labelSmall,
                  ),
                  const SizedBox(height: AppDimens.lg),
                  ProductBrandCatalogAction(product: product),
                  _QuantityBlock(
                    sqFt: sqFt,
                    onSqFt: onSqFt,
                    estimate: estimate,
                    product: product,
                    onCalculated: onCalculated,
                  ),
                ],
              ),
            ),
            const SectionGap(),
            if (FeatureFlags.visualizationEnabled)
              _VisualizeCta(product: product),
            const SectionGap(),
            _DeliveryBlock(product: product),
            const SectionGap(),
            SectionHeader(title: 'About this stone'),
            Padding(
              padding: AppDimens.screenPad,
              child: Text(
                product.description,
                style: t.bodyLarge?.copyWith(height: 1.55),
              ),
            ),
            const SizedBox(height: AppDimens.lg),
            Padding(
              padding: AppDimens.screenPad,
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final use in product.applications)
                    TagChip(label: use, color: AppColors.deep),
                ],
              ),
            ),
            const SectionGap(),
            const SectionHeader(title: 'Specifications'),
            ProductSpecs(product: product),
            const SectionGap(),
            ReviewSummary(
              product: product,
              reviews: data.reviews,
              breakdown: data.breakdown,
            ),
            const SectionGap(),
            ProductDiscovery(
              product: product,
              similar: data.similar,
              catalog: data.catalog,
              sqFt: sqFt,
            ),
            const SizedBox(height: AppDimens.xxxl),
          ],
        ),
      ],
    );
  }
}

class _QuantityBlock extends StatelessWidget {
  const _QuantityBlock({
    required this.sqFt,
    required this.onSqFt,
    required this.estimate,
    required this.product,
    required this.onCalculated,
  });

  final double sqFt;
  final ValueChanged<double> onSqFt;
  final double estimate;
  final Product product;
  final CalculationHandoff onCalculated;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.all(AppDimens.md),
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
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Area required', style: t.labelSmall),
                    const SizedBox(height: 2),
                    Text(Fmt.rupees(estimate), style: t.titleLarge),
                    Text('estimated for ${Fmt.sqft(sqFt)}', style: t.bodySmall),
                  ],
                ),
              ),
              QuantityStepper(value: sqFt, onChanged: onSqFt),
            ],
          ),
          if (FeatureFlags.calculatorEnabled) ...[
            const Divider(height: AppDimens.xl),
            InkWell(
              onTap: () async {
                final result = await Navigator.pushNamed(
                  context,
                  Routes.calculator,
                  arguments: CalculatorArgs(productId: product.id),
                );
                if (result is CalculationResult) onCalculated(result);
              },
              child: Row(
                children: [
                  const Icon(
                    Icons.calculate_rounded,
                    size: 19,
                    color: AppColors.deep,
                  ),
                  const SizedBox(width: AppDimens.md),
                  Expanded(
                    child: Text(
                      'Not sure how much? Use the area calculator',
                      style: t.titleSmall,
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded, size: 20),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _VisualizeCta extends StatelessWidget {
  const _VisualizeCta({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: AppDimens.screenPad,
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: () => Navigator.pushNamed(
          context,
          Routes.visualizer,
          arguments: VisualizerArgs(productId: product.id),
        ),
        child: Container(
          padding: const EdgeInsets.all(AppDimens.lg),
          decoration: BoxDecoration(
            gradient: AppColors.brandGradient,
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          ),
          child: Row(
            children: [
              const Icon(
                Icons.view_in_ar_rounded,
                size: 28,
                color: Colors.white,
              ),
              const SizedBox(width: AppDimens.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'See ${product.name} in a 3D room',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Apply it to floors, walls or counters and look around',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.85),
                        fontSize: 11.5,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: Colors.white),
            ],
          ),
        ),
      ),
    );
  }
}

class _DeliveryBlock extends StatefulWidget {
  const _DeliveryBlock({required this.product});

  final Product product;

  @override
  State<_DeliveryBlock> createState() => _DeliveryBlockState();
}

class _DeliveryBlockState extends State<_DeliveryBlock> {
  final _controller = TextEditingController();
  String? _message;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _check() {
    final pin = _controller.text.trim();
    if (pin.length != 6 || int.tryParse(pin) == null) {
      setState(() => _message = 'Enter a valid 6-digit PIN code');
      return;
    }
    // Static rule for the prototype: even PIN codes get the faster window.
    final fast = int.parse(pin) % 2 == 0;
    setState(
      () => _message = fast
          ? 'Deliverable · site delivery in 5-6 days'
          : 'Deliverable · site delivery in 8-9 days',
    );
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.all(AppDimens.md),
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
                const Icon(
                  Icons.local_shipping_outlined,
                  size: 19,
                  color: AppColors.deep,
                ),
                const SizedBox(width: 10),
                Text('Delivery', style: Theme.of(context).textTheme.titleSmall),
              ],
            ),
            const SizedBox(height: AppDimens.md),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _controller,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    decoration: const InputDecoration(
                      hintText: 'Enter PIN code',
                      counterText: '',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: AppDimens.sm),
                OutlinedButton(
                  onPressed: _check,
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size(0, 46),
                  ),
                  child: const Text('Check'),
                ),
              ],
            ),
            if (_message != null) ...[
              const SizedBox(height: AppDimens.sm),
              Text(
                _message!,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: _message!.startsWith('Enter')
                      ? AppColors.danger
                      : AppColors.success,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            const Divider(height: AppDimens.xl),
            const _Promise(
              icon: Icons.replay_rounded,
              text: '7-day replacement for damaged or mismatched slabs',
            ),
            const SizedBox(height: 10),
            const _Promise(
              icon: Icons.verified_outlined,
              text: 'Quarry-graded, no seconds or patched slabs',
            ),
            const SizedBox(height: 10),
            const _Promise(
              icon: Icons.handyman_outlined,
              text: 'Installation partners available in 40+ cities',
            ),
          ],
        ),
      ),
    );
  }
}

class _Promise extends StatelessWidget {
  const _Promise({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, size: 16, color: AppColors.muted),
      const SizedBox(width: 10),
      Expanded(child: Text(text, style: Theme.of(context).textTheme.bodySmall)),
    ],
  );
}

class _DetailsSkeleton extends StatelessWidget {
  const _DetailsSkeleton();

  @override
  Widget build(BuildContext context) => CustomScrollView(
    slivers: [
      SliverAppBar(
        pinned: true,
        expandedHeight: 360,
        flexibleSpace: FlexibleSpaceBar(
          background: Padding(
            padding: EdgeInsets.only(
              top: MediaQuery.paddingOf(context).top + 56,
              left: 16,
              right: 16,
              bottom: 12,
            ),
            child: const Shimmer(height: 300, radius: 32),
          ),
        ),
      ),
      SliverToBoxAdapter(
        child: Semantics(
          label: 'Loading marble details',
          child: const Padding(
            padding: EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Shimmer(width: 88, height: 20),
                SizedBox(height: 16),
                Shimmer(width: 240, height: 28),
                SizedBox(height: 12),
                Shimmer(width: 180, height: 14),
                SizedBox(height: 20),
                Shimmer(width: 100, height: 20),
                SizedBox(height: 20),
                Shimmer(width: 140, height: 32),
                SizedBox(height: 24),
                Shimmer(width: double.infinity, height: 76, radius: 24),
                SizedBox(height: 20),
                Shimmer(width: double.infinity, height: 100, radius: 24),
              ],
            ),
          ),
        ),
      ),
    ],
  );
}
