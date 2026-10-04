import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../data/models/brand_catalog.dart';
import '../../data/models/product.dart';

void _openCatalog(BuildContext context, BrandCatalog catalog) {
  Navigator.pushNamed(
    context,
    Routes.brandCatalogViewer,
    arguments: BrandCatalogViewerArgs(catalog.id),
  );
}

class BrandCatalogCard extends StatelessWidget {
  const BrandCatalogCard({super.key, required this.catalog});
  final BrandCatalog catalog;

  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    child: InkWell(
      onTap: () => _openCatalog(context, catalog),
      child: Padding(
        padding: const EdgeInsets.all(6),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            AspectRatio(
              aspectRatio: 594 / 846,
              child: AppImage(catalog.coverAsset, fit: BoxFit.contain),
            ),
            const SizedBox(height: 6),
            Text(
              catalog.title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 2),
            Text(
              catalog.brandName,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    ),
  );
}

/// Owns its request so catalog failures never block the shopping feed.
class CatalogCollection extends StatefulWidget {
  const CatalogCollection({super.key, this.brandId, required this.builder});
  final String? brandId;
  final Widget Function(BuildContext, List<BrandCatalog>) builder;

  @override
  State<CatalogCollection> createState() => _CatalogCollectionState();
}

class _CatalogCollectionState extends State<CatalogCollection> {
  late Future<List<BrandCatalog>> _future;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _future = AppScope.read(context).brandCatalogs.all(brandId: widget.brandId);
  }

  @override
  void didUpdateWidget(CatalogCollection oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.brandId != widget.brandId) _load();
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<List<BrandCatalog>>(
    future: _future,
    builder: (context, snapshot) {
      if (snapshot.hasError) {
        return TextButton.icon(
          onPressed: () => setState(_load),
          icon: const Icon(Icons.refresh),
          label: const Text('Could not load catalogs. Retry'),
        );
      }
      if (!snapshot.hasData) {
        return const Padding(
          padding: EdgeInsets.all(16),
          child: LinearProgressIndicator(),
        );
      }
      return widget.builder(context, snapshot.data!);
    },
  );
}

class HomeBrandCatalogs extends StatelessWidget {
  const HomeBrandCatalogs({super.key});

  @override
  Widget build(BuildContext context) => CatalogCollection(
    builder: (context, catalogs) {
      if (catalogs.isEmpty) return const SizedBox.shrink();
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionHeader(
            title: 'Brand catalogs',
            actionLabel: 'View all',
            onAction: () => Navigator.pushNamed(context, Routes.brandCatalogs),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final catalog in catalogs.take(6))
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: SizedBox(
                      width: 184,
                      child: BrandCatalogCard(catalog: catalog),
                    ),
                  ),
              ],
            ),
          ),
        ],
      );
    },
  );
}

class ProductBrandCatalogAction extends StatelessWidget {
  const ProductBrandCatalogAction({super.key, required this.product});
  final Product product;

  @override
  Widget build(BuildContext context) {
    final brandId = product.brandId;
    if (brandId == null) return const SizedBox.shrink();
    return CatalogCollection(
      brandId: brandId,
      builder: (context, catalogs) {
        if (catalogs.isEmpty) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: OutlinedButton.icon(
            icon: const Icon(Icons.menu_book_outlined),
            label: Text('View ${product.brand} catalog'),
            onPressed: () {
              if (catalogs.length == 1) {
                _openCatalog(context, catalogs.first);
              } else {
                Navigator.pushNamed(
                  context,
                  Routes.brandCatalogs,
                  arguments: BrandCatalogArgs(
                    brandId: brandId,
                    brandName: product.brand,
                  ),
                );
              }
            },
          ),
        );
      },
    );
  }
}

class BrandCatalogLibrary extends StatefulWidget {
  const BrandCatalogLibrary({super.key, required this.args});
  final BrandCatalogArgs args;

  @override
  State<BrandCatalogLibrary> createState() => _BrandCatalogLibraryState();
}

class _BrandCatalogLibraryState extends State<BrandCatalogLibrary> {
  String? _selectedBrand;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        widget.args.brandName == null
            ? 'Brand catalogs'
            : '${widget.args.brandName} catalogs',
      ),
    ),
    body: CatalogCollection(
      brandId: widget.args.brandId,
      builder: (context, catalogs) {
        if (catalogs.isEmpty) {
          return const Center(child: Text('No catalogs available yet.'));
        }
        final brands = {
          for (final catalog in catalogs) catalog.brandId: catalog.brandName,
        };
        final visible = catalogs
            .where((c) => _selectedBrand == null || c.brandId == _selectedBrand)
            .toList();
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (widget.args.brandId == null && brands.length > 1)
              Wrap(
                spacing: 8,
                children: [
                  ChoiceChip(
                    label: const Text('All brands'),
                    selected: _selectedBrand == null,
                    onSelected: (_) => setState(() => _selectedBrand = null),
                  ),
                  for (final brand in brands.entries)
                    ChoiceChip(
                      label: Text(brand.value),
                      selected: _selectedBrand == brand.key,
                      onSelected: (_) =>
                          setState(() => _selectedBrand = brand.key),
                    ),
                ],
              ),
            LayoutBuilder(
              builder: (context, constraints) {
                final columns = constraints.maxWidth >= 900
                    ? 4
                    : constraints.maxWidth >= 600
                    ? 3
                    : 2;
                final width =
                    (constraints.maxWidth - (columns - 1) * 8) / columns;
                return Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final catalog in visible)
                      SizedBox(
                        width: width,
                        child: BrandCatalogCard(catalog: catalog),
                      ),
                  ],
                );
              },
            ),
          ],
        );
      },
    ),
  );
}
