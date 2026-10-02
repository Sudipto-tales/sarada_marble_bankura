import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/product.dart';

/// Side-by-side comparison of 2-4 products.
class CompareScreen extends StatefulWidget {
  const CompareScreen({super.key});

  @override
  State<CompareScreen> createState() => _CompareScreenState();
}

class _CompareScreenState extends State<CompareScreen> {
  late Future<List<Product>> _future = _load();

  Future<List<Product>> _load() {
    final deps = AppScope.read(context);
    return deps.products.byIds(deps.browsing.compareIds);
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Compare'),
        actions: [
          TextButton(
            onPressed: () {
              deps.browsing.clearCompare();
              Navigator.pop(context);
            },
            child: const Text('Clear'),
          ),
        ],
      ),
      body: Observer(
        listenable: deps.browsing,
        builder: (context, browsing) {
          if (browsing.compareIds.length < 2) {
            return EmptyView(
              icon: Icons.compare_arrows_rounded,
              title: 'Pick at least two',
              message:
                  'Add 2 to 4 products to compare price, finish, origin and stock.',
              actionLabel: 'Browse marble',
              onAction: () => Navigator.pushReplacementNamed(
                  context, Routes.catalog,
                  arguments: const CatalogArgs()),
            );
          }
          return FutureBuilder<List<Product>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const LoadingView();
              }
              if (snap.hasError) {
                return ErrorView(onRetry: () => setState(() => _future = _load()));
              }
              final products = (snap.data ?? const <Product>[])
                  .where((p) => browsing.isComparing(p.id))
                  .toList();
              return _CompareTable(products: products);
            },
          );
        },
      ),
    );
  }
}

class _CompareTable extends StatelessWidget {
  const _CompareTable({required this.products});

  final List<Product> products;

  static const _rows = [
    'Price / sq.ft',
    'MRP',
    'Discount',
    'Rating',
    'Colour',
    'Finish',
    'Thickness',
    'Slab size',
    'Origin',
    'Brand',
    'Stock',
    'Best for',
  ];

  String _value(Product p, String row) => switch (row) {
        'Price / sq.ft' => Fmt.rupees(p.pricePerSqFt),
        'MRP' => Fmt.rupees(p.originalPrice),
        'Discount' => '${p.discount}%',
        'Rating' => '${Fmt.rating(p.rating)} (${p.reviewCount})',
        'Colour' => p.color,
        'Finish' => p.finish,
        'Thickness' => p.thickness,
        'Slab size' => p.dimensions,
        'Origin' => p.origin,
        'Brand' => p.brand,
        'Stock' => p.inStock ? '${p.stock} sq.ft' : 'Out of stock',
        _ => p.applications.isEmpty ? '—' : p.applications.first,
      };

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final colWidth = (MediaQuery.sizeOf(context).width - 120)
        .clamp(140.0, 600.0) /
        (products.length > 2 ? 2.2 : products.length);

    return SingleChildScrollView(
      child: Column(
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(width: 116),
                for (final p in products)
                  SizedBox(
                    width: colWidth,
                    child: Padding(
                      padding: const EdgeInsets.all(AppDimens.sm),
                      child: Column(
                        children: [
                          Stack(
                            children: [
                              AppImage(p.image,
                                  height: 96,
                                  width: colWidth - 16,
                                  radius: AppDimens.radiusSm),
                              Positioned(
                                right: 2,
                                top: 2,
                                child: InkWell(
                                  onTap: () =>
                                      deps.browsing.toggleCompare(p.id),
                                  child: const CircleAvatar(
                                    radius: 11,
                                    backgroundColor: Colors.white,
                                    child: Icon(Icons.close_rounded, size: 13),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            p.name,
                            maxLines: 2,
                            textAlign: TextAlign.center,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          for (var i = 0; i < _rows.length; i++)
            Container(
              color: i.isEven
                  ? Theme.of(context).colorScheme.surface
                  : Colors.transparent,
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    SizedBox(
                      width: 116,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                            horizontal: AppDimens.md, vertical: 12),
                        child: Text(_rows[i],
                            style: Theme.of(context).textTheme.bodySmall),
                      ),
                    ),
                    for (final p in products)
                      SizedBox(
                        width: colWidth,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                              horizontal: AppDimens.sm, vertical: 12),
                          child: Text(
                            _value(p, _rows[i]),
                            style: Theme.of(context)
                                .textTheme
                                .bodyMedium
                                ?.copyWith(fontWeight: FontWeight.w600),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: AppDimens.lg),
          Padding(
            padding: AppDimens.screenPad,
            child: Column(
              children: [
                for (final p in products)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppDimens.sm),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(p.name,
                              style: Theme.of(context).textTheme.titleSmall),
                        ),
                        TextButton(
                          onPressed: () => Navigator.pushNamed(
                              context, Routes.productDetails,
                              arguments: ProductArgs(p.id)),
                          child: const Text('Details'),
                        ),
                        const SizedBox(width: 6),
                        SizedBox(
                          width: 120,
                          child: GradientButton(
                            label: 'Add',
                            height: 40,
                            onPressed: () async {
                              await deps.cart.add(p);
                              if (context.mounted) {
                                Toast.success(context, '${p.name} added');
                              }
                            },
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: AppDimens.xl),
          Padding(
            padding: AppDimens.screenPad,
            child: Text(
              'Comparison is based on catalog data. Slab-to-slab variation is '
              'natural in stone — request a sample before large orders.',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: AppColors.muted),
            ),
          ),
          const SizedBox(height: AppDimens.xxxl),
        ],
      ),
    );
  }
}
