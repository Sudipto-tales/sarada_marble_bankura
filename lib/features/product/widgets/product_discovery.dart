import 'package:flutter/material.dart';

import '../../../core/theme/glossy_surface.dart';

import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../core/widgets/feedback.dart';
import '../../../data/models/product.dart';
import '../../catalog/widgets/product_card.dart';
import '../../home/widgets/home_sections.dart';

class ProductDiscovery extends StatelessWidget {
  const ProductDiscovery({
    super.key,
    required this.product,
    required this.similar,
    required this.catalog,
    required this.sqFt,
  });

  final Product product;
  final List<Product> similar;
  final List<Product> catalog;
  final double sqFt;

  @override
  Widget build(BuildContext context) {
    final companion = similar.where((p) => p.inStock).firstOrNull;
    final images = {product.image, ...product.gallery}.toList();
    final brands = catalog.map((p) => p.brand).toSet().toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (similar.isNotEmpty) ...[
          const SectionHeader(
            title: 'You might also like',
            subtitle: 'Selected for your taste in stone',
          ),
          ProductRail(products: similar.take(4).toList()),
          const SectionGap(),
        ],
        if (companion != null) ...[
          const SectionHeader(
            title: 'Better together',
            subtitle: 'A stone pairing for your next project',
          ),
          Padding(
            padding: AppDimens.screenPad,
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: glossySurface(context),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(child: _PairItem(product: product)),
                      const Padding(
                        padding: EdgeInsets.all(10),
                        child: Icon(
                          Icons.add_rounded,
                          color: AppColors.deep,
                          size: 22,
                        ),
                      ),
                      Expanded(child: _PairItem(product: companion)),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Text(
                    '${Fmt.sqft(sqFt)} of each stone',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    Fmt.rupees(
                      (product.pricePerSqFt + companion.pricePerSqFt) * sqFt,
                    ),
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Save ${Fmt.rupees((product.savingsPerSqFt + companion.savingsPerSqFt) * sqFt)} against original prices · GST extra',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 14),
                  GradientButton(
                    label: 'Add the combo',
                    icon: Icons.shopping_bag_outlined,
                    onPressed: product.inStock
                        ? () async {
                            final cart = AppScope.read(context).cart;
                            await cart.add(product, sqFt: sqFt);
                            await cart.add(companion, sqFt: sqFt);
                            if (context.mounted) {
                              Toast.success(
                                context,
                                'Both stones added to your cart',
                              );
                            }
                          }
                        : null,
                  ),
                ],
              ),
            ),
          ),
          const SectionGap(),
        ],
        SectionHeader(
          title: 'Explore this stone',
          subtitle: '${product.name} · slab, detail and room views',
        ),
        SizedBox(
          height: 236,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: AppDimens.screenPad,
            itemCount: images.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, i) {
              final asset = images[i];
              final label = asset.contains('_room')
                  ? 'In a room'
                  : asset.contains('_macro')
                  ? 'Veining & texture'
                  : asset.contains('_tile')
                  ? 'Tile layout'
                  : 'The full slab';
              return SizedBox(
                width: 248,
                child: Material(
                  color: Theme.of(context).colorScheme.surface,
                  borderRadius: BorderRadius.circular(22),
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: () => Navigator.pushNamed(
                      context,
                      Routes.gallery,
                      arguments: GalleryArgs(
                        images: images,
                        initialIndex: i,
                        title: product.name,
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: SizedBox(
                            width: double.infinity,
                            child: AppImage(asset),
                          ),
                        ),
                        Padding(
                          padding: const EdgeInsets.all(12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  label,
                                  style: Theme.of(context).textTheme.titleSmall,
                                ),
                              ),
                              const Icon(Icons.open_in_full_rounded, size: 16),
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
        ),
        const SectionGap(),
        if (similar.isNotEmpty) ...[
          const SectionHeader(
            title: 'Similar products',
            subtitle: 'Compare finishes, character and price',
          ),
          Padding(
            padding: AppDimens.screenPad,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final width = (constraints.maxWidth - 8) / 2;
                return GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: similar.take(8).length,
                  gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    crossAxisSpacing: 8,
                    mainAxisSpacing: 8,
                    mainAxisExtent: AppDimens.productHeight(context, width),
                  ),
                  itemBuilder: (context, i) => ProductCard(product: similar[i]),
                );
              },
            ),
          ),
          const SectionGap(),
        ],
        const SectionHeader(
          title: 'Shop by brands',
          subtitle: 'Find your signature collection',
        ),
        SizedBox(
          height: 194,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: AppDimens.screenPad,
            itemCount: brands.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, i) {
              final brand = brands[i];
              final representative = catalog.firstWhere(
                (p) => p.brand == brand,
              );
              return SizedBox(
                width: 200,
                child: Material(
                  color: Theme.of(context).colorScheme.surface,
                  borderRadius: BorderRadius.circular(22),
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: () => Navigator.pushNamed(
                      context,
                      Routes.catalog,
                      arguments: CatalogArgs(title: brand, query: brand),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: SizedBox(
                            width: double.infinity,
                            child: AppImage(
                              representative.gallery
                                      .where((a) => a.contains('_room'))
                                      .firstOrNull ??
                                  representative.image,
                            ),
                          ),
                        ),
                        Padding(
                          padding: const EdgeInsets.all(12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  brand,
                                  style: Theme.of(context).textTheme.titleSmall,
                                  maxLines: 2,
                                ),
                              ),
                              const Icon(Icons.arrow_forward_rounded, size: 16),
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
        ),
        const SizedBox(height: 32),
      ],
    );
  }
}

class _PairItem extends StatelessWidget {
  const _PairItem({required this.product});
  final Product product;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      AspectRatio(
        aspectRatio: 1.15,
        child: AppImage(product.image, radius: 16),
      ),
      const SizedBox(height: 8),
      Text(
        product.name,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.titleSmall,
      ),
      const SizedBox(height: 4),
      Text(
        '${Fmt.rupees(product.pricePerSqFt)}/sq.ft',
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ],
  );
}
