import 'package:flutter/material.dart';

import '../../core/config/feature_flags.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/category.dart';
import '../../data/models/filters.dart';
import '../../data/models/product.dart';
import 'widgets/filter_sheet.dart';
import 'widgets/product_card.dart';

/// Product grid with category chips, filters, sort and a compare tray.
/// Used both as a bottom-nav tab (`embedded`) and as a pushed route.
class CatalogScreen extends StatefulWidget {
  const CatalogScreen({super.key, this.args, this.embedded = false});

  final CatalogArgs? args;
  final bool embedded;

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen>
    with AutomaticKeepAliveClientMixin {
  late ProductFilter _filter;
  late Future<List<Product>> _future;
  Future<List<Category>>? _categoriesFuture;
  Map<String, List<String>> _facets = const {};
  (double, double) _priceRange = (0, 2000);

  @override
  bool get wantKeepAlive => widget.embedded;

  @override
  void initState() {
    super.initState();
    final args = widget.args;
    _filter = ProductFilter(
      categoryIds: args?.categoryId == null ? const {} : {args!.categoryId!},
      query: args?.query ?? '',
    );
    _future = _load();
    _loadMeta();
  }

  Future<List<Product>> _load() async {
    final deps = AppScope.read(context);
    final list = await deps.products.query(_filter);
    if (widget.args?.onlyOffers ?? false) {
      return list.where((p) => p.discount > 0).toList();
    }
    return list;
  }

  Future<void> _loadMeta() async {
    final deps = AppScope.read(context);
    _categoriesFuture = deps.categories.all();
    final facets = await deps.products.facets();
    final range = await deps.products.priceRange();
    if (!mounted) return;
    setState(() {
      _facets = facets;
      _priceRange = range;
    });
  }

  void _apply(ProductFilter filter) {
    setState(() {
      _filter = filter;
      _future = _load();
    });
  }

  Future<void> _openFilters() async {
    final categories = await (_categoriesFuture ?? Future.value(<Category>[]));
    if (!mounted) return;
    final result = await showModalBottomSheet<ProductFilter>(
      context: context,
      isScrollControlled: true,
      builder: (context) => FilterSheet(
        initial: _filter,
        facets: _facets,
        categories: categories,
        priceRange: _priceRange,
      ),
    );
    if (result != null) _apply(result);
  }

  Future<void> _openSort() async {
    final result = await showSortSheet(context, _filter.sort);
    if (result != null) _apply(_filter.copyWith(sort: result));
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    final title = widget.args?.title ??
        (widget.args?.categoryId != null ? 'Category' : 'All marble');
    final deps = AppScope.of(context);

    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !widget.embedded,
        title: Text(title),
        actions: [
          IconButton(
            onPressed: () => Navigator.pushNamed(context, Routes.search),
            icon: const Icon(Icons.search_rounded),
          ),
          IconButton(
            onPressed: () => Navigator.pushNamed(context, Routes.wishlist),
            icon: const Icon(Icons.favorite_border_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          _CategoryChips(
            future: _categoriesFuture,
            selected: _filter.categoryIds,
            onSelected: (id) {
              final next = Set<String>.from(_filter.categoryIds);
              if (!next.remove(id)) next.add(id);
              _apply(_filter.copyWith(categoryIds: next));
            },
          ),
          const Divider(height: 1),
          Expanded(
            child: FutureBuilder<List<Product>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return _GridSkeleton();
                }
                if (snap.hasError) {
                  return ErrorView(onRetry: () => _apply(_filter));
                }
                final products = snap.data ?? const [];
                if (products.isEmpty) {
                  return EmptyView(
                    icon: Icons.search_off_rounded,
                    title: 'No marble matches those filters',
                    message:
                        'Try widening the price range or clearing a filter or two.',
                    actionLabel: _filter.isEmpty ? null : 'Clear filters',
                    onAction:
                        _filter.isEmpty ? null : () => _apply(_filter.cleared()),
                  );
                }
                return _ProductGrid(
                  products: products,
                  header: '${Fmt.plural(products.length, 'product')} found',
                );
              },
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Observer(
          listenable: deps.browsing,
          builder: (context, browsing) => Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (FeatureFlags.compareEnabled && browsing.compareIds.isNotEmpty)
                _CompareTray(
                  count: browsing.compareIds.length,
                  onClear: browsing.clearCompare,
                  onCompare: browsing.canCompare
                      ? () => Navigator.pushNamed(context, Routes.compare)
                      : null,
                ),
              _FilterBar(
                activeCount: _filter.activeCount,
                sortLabel: _filter.sort.label,
                onFilter: _openFilters,
                onSort: _openSort,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ProductGrid extends StatelessWidget {
  const _ProductGrid({required this.products, required this.header});

  final List<Product> products;
  final String header;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final columns = AppDimens.gridColumns(width);
    return CustomScrollView(
      slivers: [
        SliverPadding(
          padding: const EdgeInsets.fromLTRB(
              AppDimens.lg, AppDimens.md, AppDimens.lg, 0),
          sliver: SliverToBoxAdapter(
            child: Text(header, style: Theme.of(context).textTheme.bodySmall),
          ),
        ),
        SliverPadding(
          padding: const EdgeInsets.all(AppDimens.lg),
          sliver: SliverGrid.builder(
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              mainAxisSpacing: AppDimens.md,
              crossAxisSpacing: AppDimens.md,
              childAspectRatio: 0.56,
            ),
            itemCount: products.length,
            itemBuilder: (context, i) => ProductCard(product: products[i]),
          ),
        ),
      ],
    );
  }
}

class _GridSkeleton extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final columns = AppDimens.gridColumns(MediaQuery.sizeOf(context).width);
    return GridView.builder(
      padding: const EdgeInsets.all(AppDimens.lg),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: columns,
        mainAxisSpacing: AppDimens.md,
        crossAxisSpacing: AppDimens.md,
        childAspectRatio: 0.56,
      ),
      itemCount: 6,
      itemBuilder: (context, _) => const ProductCardSkeleton(),
    );
  }
}

class _CategoryChips extends StatelessWidget {
  const _CategoryChips({
    required this.future,
    required this.selected,
    required this.onSelected,
  });

  final Future<List<Category>>? future;
  final Set<String> selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 52,
      child: FutureBuilder<List<Category>>(
        future: future,
        builder: (context, snap) {
          final categories = snap.data ?? const <Category>[];
          if (categories.isEmpty) return const SizedBox.shrink();
          return ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(
                horizontal: AppDimens.lg, vertical: AppDimens.sm),
            itemCount: categories.length,
            separatorBuilder: (_, _) => const SizedBox(width: 8),
            itemBuilder: (context, i) {
              final c = categories[i];
              return FilterChip(
                label: Text(c.name),
                selected: selected.contains(c.id),
                onSelected: (_) => onSelected(c.id),
              );
            },
          );
        },
      ),
    );
  }
}

class _FilterBar extends StatelessWidget {
  const _FilterBar({
    required this.activeCount,
    required this.sortLabel,
    required this.onFilter,
    required this.onSort,
  });

  final int activeCount;
  final String sortLabel;
  final VoidCallback onFilter;
  final VoidCallback onSort;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: const Border(top: BorderSide(color: AppColors.line)),
      ),
      child: Row(
        children: [
          Expanded(
            child: InkWell(
              onTap: onSort,
              child: SizedBox(
                height: 52,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.swap_vert_rounded, size: 19),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        sortLabel,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelLarge,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Container(width: 1, height: 26, color: AppColors.line),
          Expanded(
            child: InkWell(
              onTap: onFilter,
              child: SizedBox(
                height: 52,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.tune_rounded, size: 19),
                    const SizedBox(width: 8),
                    Text('Filters', style: Theme.of(context).textTheme.labelLarge),
                    if (activeCount > 0) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.teal,
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Text(
                          '$activeCount',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 10.5,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CompareTray extends StatelessWidget {
  const _CompareTray({
    required this.count,
    required this.onClear,
    required this.onCompare,
  });

  final int count;
  final VoidCallback onClear;
  final VoidCallback? onCompare;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
          horizontal: AppDimens.lg, vertical: AppDimens.sm),
      color: AppColors.inkSoft,
      child: Row(
        children: [
          Icon(Icons.compare_arrows_rounded,
              size: 18, color: AppColors.cyan.withValues(alpha: 0.9)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '$count selected to compare',
              style: const TextStyle(
                  color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
          ),
          TextButton(
            onPressed: onClear,
            child: const Text('Clear', style: TextStyle(color: Colors.white70)),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              minimumSize: const Size(0, 36),
              padding: const EdgeInsets.symmetric(horizontal: 16),
            ),
            onPressed: onCompare ??
                () => Toast.show(context, 'Select at least 2 products'),
            child: const Text('Compare'),
          ),
        ],
      ),
    );
  }
}
