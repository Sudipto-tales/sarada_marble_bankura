import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/widgets/app_image.dart';
import '../../../data/models/category.dart';
import '../../../data/models/filters.dart';
import '../../../data/models/product.dart';
import 'home_sections.dart';

/// Browse the same offline catalog by stone material or intended application.
class HomeCategoryBrowser extends StatefulWidget {
  const HomeCategoryBrowser({
    super.key,
    required this.categories,
    required this.products,
  });

  final List<Category> categories;
  final List<Product> products;

  @override
  State<HomeCategoryBrowser> createState() => _HomeCategoryBrowserState();
}

class _HomeCategoryBrowserState extends State<HomeCategoryBrowser> {
  bool _bySpace = false;

  static const _spaces = [
    (label: 'Kitchen', application: 'Kitchen', image: 'modern_kitchen_thumb'),
    (label: 'Floors', application: 'flooring', image: 'luxury_living_thumb'),
    (label: 'Walls', application: 'wall', image: 'hotel_lobby_thumb'),
    (label: 'Bathroom', application: 'bath', image: 'premium_bathroom_thumb'),
    (
      label: 'Staircase',
      application: 'staircase',
      image: 'villa_interior_thumb',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
          child: Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                for (final bySpace in [false, true])
                  Expanded(
                    child: Semantics(
                      selected: _bySpace == bySpace,
                      child: TextButton(
                        style: TextButton.styleFrom(
                          backgroundColor: _bySpace == bySpace
                              ? Theme.of(context).colorScheme.surface
                              : Colors.transparent,
                          foregroundColor: _bySpace == bySpace
                              ? AppColors.coral
                              : Theme.of(context).colorScheme.onSurfaceVariant,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10),
                          ),
                        ),
                        onPressed: () => setState(() => _bySpace = bySpace),
                        child: Text(bySpace ? 'By space' : 'By material'),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
        if (!_bySpace)
          CategoryStrip(categories: widget.categories)
        else
          SizedBox(
            height:
                140 +
                (MediaQuery.textScalerOf(context).scale(12) - 12).clamp(0, 24) *
                    5,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _spaces.length,
              separatorBuilder: (_, _) => const SizedBox(width: 12),
              itemBuilder: (context, index) {
                final space = _spaces[index];
                final filter = ProductFilter(application: space.application);
                final count = widget.products.where(filter.matches).length;
                return SizedBox(
                  width:
                      100 +
                      (MediaQuery.textScalerOf(context).scale(12) - 12).clamp(
                            0,
                            24,
                          ) *
                          3,
                  child: Semantics(
                    button: true,
                    label: '${space.label}, $count products',
                    child: InkWell(
                      borderRadius: BorderRadius.circular(16),
                      onTap: () => Navigator.pushNamed(
                        context,
                        Routes.catalog,
                        arguments: CatalogArgs(
                          title: 'Stone for ${space.label.toLowerCase()}',
                          filter: filter,
                        ),
                      ),
                      child: Column(
                        children: [
                          AppImage(
                            'assets/images/rooms/${space.image}.webp',
                            width: 100,
                            height: 82,
                            radius: 16,
                          ),
                          const SizedBox(height: 8),
                          Text(
                            space.label,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.labelMedium,
                          ),
                          Text(
                            '$count stones',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.labelSmall,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
      ],
    );
  }
}
