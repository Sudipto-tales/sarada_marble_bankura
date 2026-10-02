import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/config/app_config.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/data/models/cart_item.dart';
import 'package:maa_sarada/data/models/coupon.dart';
import 'package:maa_sarada/data/repositories/static_repositories.dart';
import 'package:maa_sarada/data/static/static_products.dart';
import 'package:maa_sarada/core/state/cart_controller.dart';

void main() {
  late CartController cart;

  setUp(() {
    cart = CartController(LocalStore.memory(), const StaticProductRepository());
  });

  tearDown(() => cart.dispose());

  test('an empty cart has no charges at all', () {
    expect(cart.isEmpty, isTrue);
    expect(cart.subtotal, 0);
    expect(cart.deliveryFee, 0, reason: 'no delivery fee on nothing');
    expect(cart.tax, 0);
    expect(cart.total, 0);
  });

  test('adding the same product twice updates the line instead of duplicating',
      () async {
    final product = kProducts.first;
    await cart.add(product, sqFt: 40);
    await cart.add(product, sqFt: 25);

    expect(cart.count, 1);
    expect(cart.sqFtOf(product.id), closeTo(65, 0.001));
    expect(cart.contains(product.id), isTrue);
  });

  test('setQuantity replaces the line — the bridge hand-off must not stack',
      () async {
    final product = kProducts.first;
    await cart.add(product, sqFt: 40);
    await cart.setQuantity(product, 180, source: CartSource.calculator);

    expect(cart.count, 1);
    expect(cart.sqFtOf(product.id), closeTo(180, 0.001));
    expect(cart.items.first.addedFrom, CartSource.calculator);
  });

  test('line totals, savings and tax follow the catalog price', () async {
    final product = kProducts.firstWhere((p) => p.originalPrice > p.pricePerSqFt);
    await cart.add(product, sqFt: 100);

    final expectedSubtotal = product.pricePerSqFt * 100;
    expect(cart.subtotal, closeTo(expectedSubtotal, 0.001));
    expect(
      cart.savings,
      closeTo((product.originalPrice - product.pricePerSqFt) * 100, 0.001),
    );
    expect(
      cart.tax,
      closeTo(expectedSubtotal * AppConfig.gstPercent / 100, 0.001),
    );
  });

  test('delivery is free above the threshold and charged below it', () async {
    final product = kProducts.first;

    await cart.add(product, sqFt: 1);
    expect(cart.subtotal, lessThan(AppConfig.freeDeliveryAbove));
    expect(cart.deliveryFee, AppConfig.deliveryCharge);

    cart.updateSqFt(product.id, AppConfig.freeDeliveryAbove / product.pricePerSqFt);
    expect(cart.subtotal, greaterThanOrEqualTo(AppConfig.freeDeliveryAbove));
    expect(cart.deliveryFee, 0);
  });

  test('coupon discount comes off before tax and is capped', () async {
    final product = kProducts.first;
    await cart.add(product, sqFt: 500);

    final coupon = Coupon(
      code: 'TEST10',
      title: '10% off',
      description: 'test',
      percentOff: 10,
      maxDiscount: 1000,
      minOrder: 0,
      expiresOn: DateTime.now().add(const Duration(days: 30)),
    );
    cart.applyCoupon(coupon);

    expect(cart.couponDiscount, 1000, reason: 'capped at maxDiscount');
    expect(cart.taxableAmount, closeTo(cart.subtotal - 1000, 0.001));
    expect(
      cart.tax,
      closeTo(cart.taxableAmount * AppConfig.gstPercent / 100, 0.001),
    );
    expect(
      cart.total,
      closeTo(cart.taxableAmount + cart.deliveryFee + cart.tax, 0.001),
    );

    cart.applyCoupon(null);
    expect(cart.couponDiscount, 0);
  });

  test('a coupon below its minimum order does not apply', () async {
    await cart.add(kProducts.first, sqFt: 1);
    cart.applyCoupon(Coupon(
      code: 'BIG',
      title: 'big spenders',
      description: 'test',
      percentOff: 20,
      maxDiscount: 50000,
      minOrder: 500000,
      expiresOn: DateTime.now().add(const Duration(days: 30)),
    ));
    expect(cart.couponDiscount, 0);
  });

  test('setting a line to zero removes it, and clear empties the cart', () async {
    await cart.add(kProducts[0], sqFt: 30);
    await cart.add(kProducts[1], sqFt: 30);
    expect(cart.count, 2);

    cart.updateSqFt(kProducts[0].id, 0);
    expect(cart.count, 1);
    expect(cart.contains(kProducts[0].id), isFalse);

    cart.clear();
    expect(cart.isEmpty, isTrue);
    expect(cart.total, 0);
  });

  test('cart lines persist through the local store', () async {
    final store = LocalStore.memory();
    final a = CartController(store, const StaticProductRepository());
    await a.add(kProducts[3], sqFt: 77);
    a.dispose();

    final b = CartController(store, const StaticProductRepository());
    expect(b.count, 1);
    expect(b.sqFtOf(kProducts[3].id), closeTo(77, 0.001));
    b.dispose();
  });
}
