import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/filters.dart';
import '../../catalog/widgets/filter_sheet.dart';

/// Location and search share the coral backdrop from the storefront reference.
class HomeHeader extends StatelessWidget {
  const HomeHeader({super.key});

  Future<void> _openFilters(BuildContext context) async {
    final deps = AppScope.read(context);
    try {
      final categories = await deps.categories.all();
      final facets = await deps.products.facets();
      final range = await deps.products.priceRange();
      if (!context.mounted) return;
      final filter = await showModalBottomSheet<ProductFilter>(
        context: context,
        isScrollControlled: true,
        builder: (_) => FilterSheet(
          initial: const ProductFilter(),
          facets: facets,
          categories: categories,
          priceRange: range,
        ),
      );
      if (filter == null || !context.mounted) return;
      Navigator.pushNamed(
        context,
        Routes.catalog,
        arguments: CatalogArgs(title: 'Your selection', filter: filter),
      );
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not load filters. Please try again.'),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return SliverAppBar(
      pinned: true,
      floating: true,
      automaticallyImplyLeading: false,
      elevation: 0,
      scrolledUnderElevation: 0,
      toolbarHeight:
          68 +
          (MediaQuery.textScalerOf(context).scale(14) - 14).clamp(0, 28) * 2,
      backgroundColor: Colors.transparent,
      foregroundColor: Colors.white,
      systemOverlayStyle: SystemUiOverlayStyle.light,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      clipBehavior: Clip.antiAlias,
      flexibleSpace: const _HeaderBackdrop(),
      titleSpacing: 20,
      title: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.pushNamed(context, Routes.addressList),
        child: Observer(
          listenable: deps.session,
          builder: (context, session) {
            final address = session.defaultAddress;
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'Delivery location',
                    style: TextStyle(color: Colors.white, fontSize: 11),
                  ),
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      const Icon(Icons.location_on_rounded, size: 17),
                      const SizedBox(width: 5),
                      Flexible(
                        child: Text(
                          address == null
                              ? 'Choose your location'
                              : '${address.city}, ${address.pincode}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      const Icon(Icons.keyboard_arrow_down_rounded, size: 18),
                    ],
                  ),
                ],
              ),
            );
          },
        ),
      ),
      actions: [
        Observer(
          listenable: deps.notificationCenter,
          builder: (context, center) => IconButton.filledTonal(
            tooltip: 'Notifications',
            style: IconButton.styleFrom(
              backgroundColor: Colors.white.withValues(alpha: .18),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            onPressed: () => Navigator.pushNamed(context, Routes.notifications),
            icon: center.unread > 0
                ? Badge.count(
                    count: center.unread,
                    child: const Icon(Icons.notifications_none_rounded),
                  )
                : const Icon(Icons.notifications_none_rounded),
          ),
        ),
        const SizedBox(width: 16),
      ],
      bottom: PreferredSize(
        preferredSize: const Size.fromHeight(70),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Row(
            children: [
              const Expanded(child: _SearchEntry()),
              const SizedBox(width: 10),
              SizedBox(
                width: 50,
                height: 50,
                child: IconButton.filled(
                  tooltip: 'Filter products',
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: AppColors.coral,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed: () => _openFilters(context),
                  icon: const Icon(Icons.tune_rounded),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HeaderBackdrop extends StatelessWidget {
  const _HeaderBackdrop();

  @override
  Widget build(BuildContext context) => IgnorePointer(
    child: ClipRRect(
      borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
      child: Stack(
        clipBehavior: Clip.hardEdge,
        children: [
          const Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFFFF424F), Color(0xFFFF6869)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
            ),
          ),
          for (final size in [150.0, 205.0, 260.0])
            Positioned(
              right: 44 - size / 2,
              bottom: -size / 2,
              child: Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: Colors.white.withValues(alpha: .08),
                    width: 2,
                  ),
                ),
              ),
            ),
        ],
      ),
    ),
  );
}

class _SearchEntry extends StatelessWidget {
  const _SearchEntry();

  @override
  Widget build(BuildContext context) => Hero(
    tag: 'search-bar',
    child: Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.pushNamed(context, Routes.search),
        child: const SizedBox(
          height: 50,
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              children: [
                Icon(
                  Icons.search_rounded,
                  size: 21,
                  color: AppColors.mutedSoft,
                ),
                SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'Search marble, granite…',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontSize: 13, color: AppColors.mutedSoft),
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
