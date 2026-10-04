import 'package:flutter/widgets.dart';

import '../../data/repositories/repositories.dart';
import '../../data/repositories/brand_catalog_repository.dart';
import '../../data/repositories/static_repositories.dart';
import '../store/local_store.dart';
import 'browsing_controller.dart';
import 'cart_controller.dart';
import 'notification_controller.dart';
import 'session_controller.dart';
import 'theme_controller.dart';
import 'wishlist_controller.dart';

/// Composition root. Everything the app needs is built here once and handed
/// down through an [InheritedWidget]; swapping `Static*Repository` for an
/// `Api*Repository` later is a change to this file only.
class AppDependencies {
  AppDependencies._({
    required this.store,
    required this.products,
    required this.categories,
    required this.reviews,
    required this.orders,
    required this.users,
    required this.rooms,
    required this.promos,
    required this.notifications,
  }) : cart = CartController(store, products),
       wishlist = WishlistController(store),
       session = SessionController(users),
       browsing = BrowsingController(store),
       theme = ThemeController(store),
       notificationCenter = NotificationController(notifications);

  factory AppDependencies.static(LocalStore store) => AppDependencies._(
    store: store,
    products: const StaticProductRepository(),
    categories: const StaticCategoryRepository(),
    reviews: StaticReviewRepository(),
    orders: StaticOrderRepository(store),
    users: StaticUserRepository(store),
    rooms: StaticRoomRepository(store),
    promos: const StaticPromoRepository(),
    notifications: StaticNotificationRepository(store),
  );

  final BrandCatalogRepository brandCatalogs =
      const BundledBrandCatalogRepository();

  final LocalStore store;

  final ProductRepository products;
  final CategoryRepository categories;
  final ReviewRepository reviews;
  final OrderRepository orders;
  final UserRepository users;
  final RoomRepository rooms;
  final PromoRepository promos;
  final NotificationRepository notifications;

  final CartController cart;
  final WishlistController wishlist;
  final SessionController session;
  final BrowsingController browsing;
  final ThemeController theme;
  final NotificationController notificationCenter;

  void dispose() {
    cart.dispose();
    wishlist.dispose();
    session.dispose();
    browsing.dispose();
    theme.dispose();
    notificationCenter.dispose();
  }
}

class AppScope extends InheritedWidget {
  const AppScope({super.key, required this.deps, required super.child});

  final AppDependencies deps;

  static AppDependencies of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope is missing above this widget');
    return scope!.deps;
  }

  /// Non-listening lookup for callbacks and initState.
  static AppDependencies read(BuildContext context) {
    final scope = context.getInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope is missing above this widget');
    return scope!.deps;
  }

  @override
  bool updateShouldNotify(AppScope oldWidget) => deps != oldWidget.deps;
}

/// Rebuilds [builder] whenever the listenable fires. Keeps screens free of
/// setState plumbing without pulling in a state-management package.
class Observer<T extends Listenable> extends StatelessWidget {
  const Observer({super.key, required this.listenable, required this.builder});

  final T listenable;
  final Widget Function(BuildContext context, T value) builder;

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: listenable,
    builder: (context, _) => builder(context, listenable),
  );
}
