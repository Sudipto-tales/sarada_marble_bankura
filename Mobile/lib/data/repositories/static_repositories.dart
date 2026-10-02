import 'dart:math' as math;

import '../../core/config/app_config.dart';
import '../../core/store/local_store.dart';
import '../models/address.dart';
import '../models/app_notification.dart';
import '../models/app_user.dart';
import '../models/category.dart';
import '../models/coupon.dart';
import '../models/filters.dart';
import '../models/marble_texture.dart';
import '../models/order.dart';
import '../models/product.dart';
import '../models/review.dart';
import '../models/room.dart';
import '../models/saved_design.dart';
import '../static/static_categories.dart';
import '../static/static_notifications.dart';
import '../static/static_orders.dart';
import '../static/static_products.dart';
import '../static/static_promos.dart';
import '../static/static_reviews.dart';
import '../static/static_rooms.dart';
import '../static/static_textures.dart';
import '../static/static_user.dart';
import 'repositories.dart';

/// Simulated I/O latency so loading states are real, not decorative.
Future<T> _delayed<T>(T value, [int ms = 260]) =>
    Future.delayed(Duration(milliseconds: ms), () => value);

class StaticProductRepository implements ProductRepository {
  const StaticProductRepository();

  @override
  Future<List<Product>> all() => _delayed(List.of(kProducts));

  @override
  Future<Product?> byId(String id) =>
      _delayed(kProducts.where((p) => p.id == id).firstOrNull, 160);

  @override
  Future<List<Product>> byIds(List<String> ids) {
    final index = {for (final p in kProducts) p.id: p};
    return _delayed([
      for (final id in ids)
        if (index[id] != null) index[id]!,
    ], 160);
  }

  @override
  Future<List<Product>> byCategory(String categoryId) => _delayed(
      kProducts.where((p) => p.categoryId == categoryId).toList());

  @override
  Future<List<Product>> query(ProductFilter filter) {
    final list = kProducts.where(filter.matches).toList();
    _sort(list, filter.sort);
    return _delayed(list, 300);
  }

  @override
  Future<List<Product>> featured() =>
      _delayed(kProducts.where((p) => p.isFeatured).toList());

  @override
  Future<List<Product>> trending() =>
      _delayed(kProducts.where((p) => p.isTrending).toList());

  @override
  Future<List<Product>> bestSellers() =>
      _delayed(kProducts.where((p) => p.isBestSeller).toList());

  @override
  Future<List<Product>> similarTo(String productId, {int limit = 8}) {
    final base = kProducts.where((p) => p.id == productId).firstOrNull;
    if (base == null) return _delayed(const <Product>[]);
    int score(Product p) {
      var s = 0;
      if (p.categoryId == base.categoryId) s += 3;
      if (p.color == base.color) s += 2;
      if (p.finish == base.finish) s += 1;
      if ((p.pricePerSqFt - base.pricePerSqFt).abs() < base.pricePerSqFt * 0.4) {
        s += 2;
      }
      return s;
    }

    final list = kProducts.where((p) => p.id != productId).toList()
      ..sort((a, b) => score(b).compareTo(score(a)));
    return _delayed(list.take(limit).toList());
  }

  @override
  Future<Map<String, List<String>>> facets() {
    Set<String> pick(String Function(Product) f) =>
        kProducts.map(f).toSet();
    return _delayed({
      'color': pick((p) => p.color).toList()..sort(),
      'finish': pick((p) => p.finish).toList()..sort(),
      'origin': pick((p) => p.origin).toList()..sort(),
      'brand': pick((p) => p.brand).toList()..sort(),
    }, 120);
  }

  @override
  Future<(double, double)> priceRange() {
    final prices = kProducts.map((p) => p.pricePerSqFt);
    return _delayed((
      prices.reduce(math.min).floorToDouble(),
      prices.reduce(math.max).ceilToDouble(),
    ), 60);
  }

  static void _sort(List<Product> list, SortOption sort) {
    switch (sort) {
      case SortOption.relevance:
        list.sort((a, b) {
          final rank = (b.isBestSeller ? 2 : 0) +
              (b.isFeatured ? 1 : 0) -
              ((a.isBestSeller ? 2 : 0) + (a.isFeatured ? 1 : 0));
          return rank != 0 ? rank : b.rating.compareTo(a.rating);
        });
      case SortOption.priceLowHigh:
        list.sort((a, b) => a.pricePerSqFt.compareTo(b.pricePerSqFt));
      case SortOption.priceHighLow:
        list.sort((a, b) => b.pricePerSqFt.compareTo(a.pricePerSqFt));
      case SortOption.rating:
        list.sort((a, b) => b.rating.compareTo(a.rating));
      case SortOption.newest:
        list.sort((a, b) => b.id.compareTo(a.id));
      case SortOption.discount:
        list.sort((a, b) => b.discount.compareTo(a.discount));
    }
  }
}

class StaticCategoryRepository implements CategoryRepository {
  const StaticCategoryRepository();

  @override
  Future<List<Category>> all() => _delayed(List.of(kCategories), 180);

  @override
  Future<Category?> byId(String id) =>
      _delayed(kCategories.where((c) => c.id == id).firstOrNull, 100);
}

class StaticReviewRepository implements ReviewRepository {
  StaticReviewRepository();

  final List<Review> _extra = [];

  @override
  Future<List<Review>> forProduct(String productId) {
    final list = [...kReviews, ..._extra]
        .where((r) => r.productId == productId)
        .toList()
      ..sort((a, b) => b.date.compareTo(a.date));
    return _delayed(list, 240);
  }

  @override
  Future<Map<int, int>> ratingBreakdown(String productId) async {
    final list = await forProduct(productId);
    final map = {for (var i = 1; i <= 5; i++) i: 0};
    for (final r in list) {
      map[r.rating.round()] = (map[r.rating.round()] ?? 0) + 1;
    }
    return map;
  }

  @override
  Future<void> submit(Review review) async {
    _extra.add(review);
    await _delayed(null, 200);
  }
}

class StaticOrderRepository implements OrderRepository {
  StaticOrderRepository(this._store) {
    final stored = _store.readList(StoreKeys.orders);
    if (stored.isEmpty) {
      _orders = List.of(kOrders);
      _persist();
    } else {
      _orders = stored.map(Order.fromJson).toList();
    }
  }

  final LocalStore _store;
  late List<Order> _orders;

  void _persist() =>
      _store.write(StoreKeys.orders, _orders.map((o) => o.toJson()).toList());

  @override
  Future<List<Order>> all() {
    final list = List.of(_orders)
      ..sort((a, b) => b.placedOn.compareTo(a.placedOn));
    return _delayed(list, 320);
  }

  @override
  Future<Order?> byId(String id) =>
      _delayed(_orders.where((o) => o.id == id).firstOrNull, 140);

  @override
  Future<Order> place(Order order) async {
    _orders.insert(0, order);
    _persist();
    return _delayed(order, 900);
  }

  @override
  Future<Order> cancel(String id) async {
    final i = _orders.indexWhere((o) => o.id == id);
    if (i < 0) throw const RepositoryException('Order not found');
    final updated = _orders[i].copyWith(
      status: OrderStatus.cancelled,
      timeline: [
        ..._orders[i].timeline,
        OrderEvent(
          status: OrderStatus.cancelled,
          at: DateTime.now(),
          note: 'Cancelled by you. Refund initiated to the source account.',
        ),
      ],
    );
    _orders[i] = updated;
    _persist();
    return _delayed(updated, 500);
  }

  @override
  Future<Order> requestReturn(String id, String reason) async {
    final i = _orders.indexWhere((o) => o.id == id);
    if (i < 0) throw const RepositoryException('Order not found');
    final updated = _orders[i].copyWith(
      status: OrderStatus.returned,
      timeline: [
        ..._orders[i].timeline,
        OrderEvent(
          status: OrderStatus.returned,
          at: DateTime.now(),
          note: 'Return requested: $reason. Pickup will be scheduled.',
        ),
      ],
    );
    _orders[i] = updated;
    _persist();
    return _delayed(updated, 500);
  }
}

class StaticUserRepository implements UserRepository {
  StaticUserRepository(this._store) {
    final stored = _store.readList(StoreKeys.addresses);
    _addresses = stored.isEmpty
        ? List.of(kDemoAddresses)
        : stored.map(Address.fromJson).toList();
  }

  final LocalStore _store;
  late List<Address> _addresses;

  @override
  Future<AppUser?> current() async {
    final map = _store.readMap(StoreKeys.user);
    if (map.isEmpty) return null;
    return AppUser.fromJson(map);
  }

  @override
  Future<AppUser> signIn(String email, String password) async {
    await _delayed(null, 700);
    final e = email.trim().toLowerCase();
    // Prototype-only check against local demo credentials. No server, no token.
    final ok = e == AppConfig.demoEmail && password == AppConfig.demoPassword;
    if (!ok) {
      throw const RepositoryException(
          'Incorrect email or password. Use the demo credentials shown below.');
    }
    _store.write(StoreKeys.user, kDemoUser.toJson());
    return kDemoUser;
  }

  @override
  Future<AppUser> register(
      String name, String email, String phone, String password) async {
    await _delayed(null, 800);
    final user = AppUser(
      id: 'u_${DateTime.now().millisecondsSinceEpoch}',
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim(),
      memberSince: DateTime.now(),
    );
    _store.write(StoreKeys.user, user.toJson());
    return user;
  }

  @override
  Future<void> signOut() async {
    _store.write(StoreKeys.user, null);
    await _delayed(null, 200);
  }

  @override
  Future<AppUser> updateProfile(AppUser user) async {
    _store.write(StoreKeys.user, user.toJson());
    return _delayed(user, 400);
  }

  @override
  Future<List<Address>> addresses() => _delayed(List.of(_addresses), 200);

  @override
  Future<Address> saveAddress(Address address) async {
    final i = _addresses.indexWhere((a) => a.id == address.id);
    if (i >= 0) {
      _addresses[i] = address;
    } else {
      _addresses.add(address);
    }
    if (address.isDefault) {
      _addresses = [
        for (final a in _addresses)
          a.id == address.id ? a : a.copyWith(isDefault: false),
      ];
    }
    _persistAddresses();
    return _delayed(address, 400);
  }

  @override
  Future<void> deleteAddress(String id) async {
    _addresses.removeWhere((a) => a.id == id);
    _persistAddresses();
    await _delayed(null, 250);
  }

  @override
  Future<void> setDefaultAddress(String id) async {
    _addresses = [
      for (final a in _addresses) a.copyWith(isDefault: a.id == id),
    ];
    _persistAddresses();
    await _delayed(null, 200);
  }

  void _persistAddresses() => _store.write(
      StoreKeys.addresses, _addresses.map((a) => a.toJson()).toList());
}

class StaticRoomRepository implements RoomRepository {
  StaticRoomRepository(this._store) {
    _designs = _store
        .readList(StoreKeys.savedDesigns)
        .map(SavedDesign.fromJson)
        .toList();
  }

  final LocalStore _store;
  late List<SavedDesign> _designs;

  @override
  Future<List<Room>> all() => _delayed(List.of(kRooms), 260);

  @override
  Future<Room?> byId(String id) =>
      _delayed(kRooms.where((r) => r.id == id).firstOrNull, 140);

  @override
  Future<List<MarbleTexture>> textures() => _delayed(List.of(kTextures), 160);

  @override
  Future<MarbleTexture?> texture(String id) =>
      _delayed(kTextures.where((t) => t.id == id).firstOrNull, 60);

  @override
  Future<List<SavedDesign>> savedDesigns() {
    final list = List.of(_designs)
      ..sort((a, b) => b.createdOn.compareTo(a.createdOn));
    return _delayed(list, 200);
  }

  @override
  Future<SavedDesign> saveDesign(SavedDesign design) async {
    final i = _designs.indexWhere((d) => d.id == design.id);
    if (i >= 0) {
      _designs[i] = design;
    } else {
      _designs.add(design);
    }
    _store.write(
        StoreKeys.savedDesigns, _designs.map((d) => d.toJson()).toList());
    return _delayed(design, 350);
  }

  @override
  Future<void> deleteDesign(String id) async {
    _designs.removeWhere((d) => d.id == id);
    _store.write(
        StoreKeys.savedDesigns, _designs.map((d) => d.toJson()).toList());
    await _delayed(null, 200);
  }
}

class StaticPromoRepository implements PromoRepository {
  const StaticPromoRepository();

  @override
  Future<List<PromoBanner>> banners() => _delayed(List.of(kBanners), 160);

  @override
  Future<List<PromoBanner>> inspiration() =>
      _delayed(List.of(kInspiration), 200);

  @override
  Future<List<Offer>> offers() => _delayed(List.of(kOffers), 180);

  @override
  Future<List<Coupon>> coupons() => _delayed(List.of(kCoupons), 180);

  @override
  Future<Coupon?> couponByCode(String code) => _delayed(
        kCoupons
            .where((c) => c.code.toUpperCase() == code.trim().toUpperCase())
            .firstOrNull,
        350,
      );
}

class StaticNotificationRepository implements NotificationRepository {
  StaticNotificationRepository(this._store) {
    final read = _store.read<List<dynamic>>(StoreKeys.notifications) ?? const [];
    _read = read.map((e) => e.toString()).toSet();
    _items = kNotifications
        .map((n) => _read.contains(n.id) ? n.markRead() : n)
        .toList();
  }

  final LocalStore _store;
  late Set<String> _read;
  late List<AppNotification> _items;

  @override
  Future<List<AppNotification>> all() => _delayed(List.of(_items), 220);

  @override
  Future<void> markRead(String id) async {
    _read.add(id);
    _items = [
      for (final n in _items) n.id == id ? n.markRead() : n,
    ];
    _store.write(StoreKeys.notifications, _read.toList());
  }

  @override
  Future<void> markAllRead() async {
    _read.addAll(_items.map((n) => n.id));
    _items = _items.map((n) => n.markRead()).toList();
    _store.write(StoreKeys.notifications, _read.toList());
  }
}
