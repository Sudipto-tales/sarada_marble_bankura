import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/widgets/app_image.dart';

/// Sticky top bar: brand mark, delivery pin, search entry and notifications.
class HomeHeader extends StatelessWidget {
  const HomeHeader({super.key});

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return SliverAppBar(
      pinned: true,
      floating: true,
      elevation: 0,
      toolbarHeight: 58,
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      titleSpacing: AppDimens.lg,
      title: Row(
        children: [
          AppImage('assets/brand/logo_mark.webp', width: 34, height: 34),
          const SizedBox(width: 10),
          Expanded(
            child: Observer(
              listenable: deps.session,
              builder: (context, session) {
                final address = session.defaultAddress;
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('Deliver to',
                        style: Theme.of(context).textTheme.labelSmall),
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            address == null
                                ? 'Select delivery location'
                                : '${address.city} ${address.pincode}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context)
                                .textTheme
                                .titleSmall
                                ?.copyWith(fontSize: 13.5),
                          ),
                        ),
                        const Icon(Icons.keyboard_arrow_down_rounded, size: 18),
                      ],
                    ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
      actions: [
        Observer(
          listenable: deps.notificationCenter,
          builder: (context, center) => IconButton(
            onPressed: () =>
                Navigator.pushNamed(context, Routes.notifications),
            icon: center.unread > 0
                ? Badge.count(
                    count: center.unread,
                    child: const Icon(Icons.notifications_none_rounded),
                  )
                : const Icon(Icons.notifications_none_rounded),
          ),
        ),
        Observer(
          listenable: deps.wishlist,
          builder: (context, wishlist) => IconButton(
            onPressed: () => Navigator.pushNamed(context, Routes.wishlist),
            icon: wishlist.count > 0
                ? Badge.count(
                    count: wishlist.count,
                    child: const Icon(Icons.favorite_border_rounded),
                  )
                : const Icon(Icons.favorite_border_rounded),
          ),
        ),
        const SizedBox(width: 4),
      ],
      bottom: PreferredSize(
        preferredSize: const Size.fromHeight(58),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
              AppDimens.lg, 0, AppDimens.lg, AppDimens.md),
          child: _SearchEntry(),
        ),
      ),
    );
  }
}

class _SearchEntry extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Hero(
      tag: 'search-bar',
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          onTap: () => Navigator.pushNamed(context, Routes.search),
          child: Container(
            height: 46,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              border: Border.all(color: AppColors.line),
            ),
            child: Row(
              children: [
                const Icon(Icons.search_rounded,
                    size: 20, color: AppColors.muted),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Search marble, granite, colours…',
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(color: AppColors.mutedSoft),
                  ),
                ),
                const Icon(Icons.tune_rounded, size: 19, color: AppColors.deep),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
