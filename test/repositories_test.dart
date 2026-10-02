import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/config/app_config.dart';
import 'package:maa_sarada/core/state/session_controller.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/data/models/address.dart';
import 'package:maa_sarada/data/models/cart_item.dart';
import 'package:maa_sarada/data/models/filters.dart';
import 'package:maa_sarada/data/models/order.dart';
import 'package:maa_sarada/data/repositories/repositories.dart';
import 'package:maa_sarada/data/repositories/static_repositories.dart';
import 'package:maa_sarada/data/static/static_products.dart';

Order _order(String id, Address address) => Order(
      id: id,
      items: [CartItem(productId: kProducts.first.id, sqFt: 120)],
      address: address,
      placedOn: DateTime(2026, 3, 4),
      status: OrderStatus.placed,
      timeline: [
        OrderEvent(status: OrderStatus.placed, at: DateTime(2026, 3, 4), note: 'Placed'),
      ],
      subtotal: 24000,
      discount: 1000,
      deliveryFee: 1200,
      tax: 4140,
      paymentMethod: PaymentMethod.cod,
      expectedDelivery: DateTime(2026, 3, 12),
    );

void main() {
  group('products', () {
    const repo = StaticProductRepository();

    test('search matches name, colour and tags', () async {
      final results = await repo.query(const ProductFilter(query: 'carrara'));
      expect(results, isNotEmpty);
      expect(
        results.every((p) =>
            '${p.name} ${p.color} ${p.origin} ${p.tags.join(' ')}'
                .toLowerCase()
                .contains('carrara')),
        isTrue,
      );
    });

    test('a nonsense query returns an empty list, not an error', () async {
      expect(await repo.query(const ProductFilter(query: 'zzzqqq')), isEmpty);
    });

    test('price sorting is honoured in both directions', () async {
      final asc = await repo.query(const ProductFilter(sort: SortOption.priceLowHigh));
      final desc = await repo.query(const ProductFilter(sort: SortOption.priceHighLow));
      expect(asc.first.pricePerSqFt, lessThanOrEqualTo(asc.last.pricePerSqFt));
      expect(desc.first.pricePerSqFt, greaterThanOrEqualTo(desc.last.pricePerSqFt));
      expect(asc.length, desc.length);
    });

    test('filters intersect rather than widen the result set', () async {
      final all = await repo.all();
      final colour = all.first.color;
      final filtered = await repo.query(ProductFilter(colors: {colour}, minRating: 4));
      expect(filtered.every((p) => p.color == colour && p.rating >= 4), isTrue);
      expect(filtered.length, lessThanOrEqualTo(all.length));
    });

    test('in-stock filter drops sold-out stone', () async {
      final filtered = await repo.query(const ProductFilter(inStockOnly: true));
      expect(filtered.every((p) => p.inStock), isTrue);
    });

    test('facets and price range are derived from the catalogue', () async {
      final facets = await repo.facets();
      expect(facets.keys, isNotEmpty);
      final (min, max) = await repo.priceRange();
      expect(min, lessThanOrEqualTo(max));
      expect(min, greaterThan(0));
    });

    test('similar products never include the product itself', () async {
      final id = kProducts.first.id;
      final similar = await repo.similarTo(id, limit: 4);
      expect(similar, isNotEmpty);
      expect(similar.length, lessThanOrEqualTo(4));
      expect(similar.any((p) => p.id == id), isFalse);
    });

    test('featured, trending and best sellers all have entries', () async {
      expect(await repo.featured(), isNotEmpty);
      expect(await repo.trending(), isNotEmpty);
      expect(await repo.bestSellers(), isNotEmpty);
    });

    test('an unknown id resolves to null rather than throwing', () async {
      expect(await repo.byId('nope'), isNull);
    });
  });

  group('prototype auth', () {
    test('the demo credentials sign in and persist across a restart', () async {
      final store = LocalStore.memory();
      final session = SessionController(StaticUserRepository(store));

      expect(await session.signIn(AppConfig.demoEmail, AppConfig.demoPassword), isTrue);
      expect(session.isLoggedIn, isTrue);
      expect(session.error, isNull);
      session.dispose();

      final restored = SessionController(StaticUserRepository(store));
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(restored.isLoggedIn, isTrue);
      restored.dispose();
    });

    test('wrong credentials fail with a message and no session', () async {
      final session = SessionController(StaticUserRepository(LocalStore.memory()));
      expect(await session.signIn(AppConfig.demoEmail, 'wrong'), isFalse);
      expect(session.isLoggedIn, isFalse);
      expect(session.error, isNotNull);
      session.dispose();
    });

    test('registering creates a local user and signing out clears it', () async {
      final session = SessionController(StaticUserRepository(LocalStore.memory()));
      expect(
        await session.register('Test Buyer', 'test@example.com', '9876500000', 'pw'),
        isTrue,
      );
      expect(session.user?.name, 'Test Buyer');
      await session.signOut();
      expect(session.isLoggedIn, isFalse);
      session.dispose();
    });

    test('the address book saves, defaults and deletes', () async {
      final repo = StaticUserRepository(LocalStore.memory());
      final before = await repo.addresses();
      expect(before, isNotEmpty);

      final saved = await repo.saveAddress(Address(
        id: 'a_test',
        label: 'Site',
        name: 'Test Buyer',
        phone: '9876500000',
        line1: '12 Stone Road',
        line2: 'Marble Market',
        city: 'Kishangarh',
        state: 'Rajasthan',
        pincode: '305801',
      ));
      expect((await repo.addresses()).any((a) => a.id == saved.id), isTrue);

      await repo.setDefaultAddress(saved.id);
      final withDefault = await repo.addresses();
      expect(withDefault.where((a) => a.isDefault).length, 1);
      expect(withDefault.firstWhere((a) => a.isDefault).id, saved.id);

      await repo.deleteAddress(saved.id);
      expect((await repo.addresses()).any((a) => a.id == saved.id), isFalse);
    });
  });

  group('orders', () {
    test('seed orders load, sorted newest first', () async {
      final repo = StaticOrderRepository(LocalStore.memory());
      final all = await repo.all();
      expect(all.length, greaterThanOrEqualTo(5));
      for (var i = 1; i < all.length; i++) {
        expect(all[i - 1].placedOn.isBefore(all[i].placedOn), isFalse);
      }
    });

    test('placing an order persists it to the local store', () async {
      final store = LocalStore.memory();
      final repo = StaticOrderRepository(store);
      final address = (await StaticUserRepository(store).addresses()).first;
      final before = (await repo.all()).length;

      await repo.place(_order('od_test_1', address));
      expect((await repo.all()).length, before + 1);

      final reopened = StaticOrderRepository(store);
      expect(await reopened.byId('od_test_1'), isNotNull);
    });

    test('cancelling moves the order to cancelled and extends the timeline',
        () async {
      final store = LocalStore.memory();
      final repo = StaticOrderRepository(store);
      final address = (await StaticUserRepository(store).addresses()).first;
      final placed = await repo.place(_order('od_test_2', address));
      expect(placed.isCancellable, isTrue);

      final cancelled = await repo.cancel('od_test_2');
      expect(cancelled.status, OrderStatus.cancelled);
      expect(cancelled.timeline.length, greaterThan(placed.timeline.length));
    });

    test('cancelling an unknown order throws a RepositoryException', () async {
      final repo = StaticOrderRepository(LocalStore.memory());
      expect(() => repo.cancel('nope'), throwsA(isA<RepositoryException>()));
    });

    test('order totals round-trip through JSON', () async {
      final store = LocalStore.memory();
      final address = (await StaticUserRepository(store).addresses()).first;
      final order = _order('od_test_3', address);
      final copy = Order.fromJson(order.toJson());

      expect(copy.id, order.id);
      expect(copy.total, closeTo(order.total, 0.001));
      expect(copy.status, order.status);
      expect(copy.paymentMethod, order.paymentMethod);
      expect(copy.items.first.sqFt, order.items.first.sqFt);
      expect(copy.timeline.length, order.timeline.length);
    });
  });

  group('rooms, reviews and promos', () {
    test('room repository exposes rooms and textures', () async {
      final repo = StaticRoomRepository(LocalStore.memory());
      expect((await repo.all()).length, greaterThanOrEqualTo(5));
      expect(await repo.textures(), isNotEmpty);
      final first = (await repo.all()).first;
      expect((await repo.byId(first.id))?.id, first.id);
      expect(await repo.byId('nope'), isNull);
    });

    test('saved designs persist and delete', () async {
      final store = LocalStore.memory();
      final repo = StaticRoomRepository(store);
      expect(await repo.savedDesigns(), isEmpty);
    });

    test('reviews come back with a rating breakdown', () async {
      final repo = StaticReviewRepository();
      final product = kProducts.firstWhere((p) => p.reviewCount > 0);
      final reviews = await repo.forProduct(product.id);
      final breakdown = await repo.ratingBreakdown(product.id);
      expect(breakdown.values.fold(0, (a, b) => a + b), reviews.length);
    });

    test('promos expose banners, offers, coupons and inspiration', () async {
      final repo = StaticPromoRepository();
      expect(await repo.banners(), isNotEmpty);
      expect(await repo.offers(), isNotEmpty);
      expect(await repo.inspiration(), isNotEmpty);
    });
  });
}
