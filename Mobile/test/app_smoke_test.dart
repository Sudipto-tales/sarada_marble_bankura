import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/app.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/data/static/static_orders.dart';
import 'package:maa_sarada/data/static/static_products.dart';
import 'package:maa_sarada/data/static/static_rooms.dart';

/// Repeated pumps instead of pumpAndSettle: the shell keeps subtle looping
/// animations alive, so settling would never terminate.
Future<void> _tick(WidgetTester tester, {int times = 10}) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 220));
  }
}

Future<AppDependencies> _boot(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1170, 2532);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final deps = AppDependencies.static(LocalStore.memory());
  await tester.pumpWidget(MaaSaradaApp(deps: deps));
  await _tick(tester); // splash timer + first repository loads
  await _tick(tester);
  return deps;
}

void main() {
  testWidgets('the app boots through the splash into the shell', (
    tester,
  ) async {
    await _boot(tester);

    expect(find.byKey(const ValueKey('stone-navigation')), findsOneWidget);
    for (final label in ['Home', 'Catalog', 'Room View', 'Cart', 'Account']) {
      expect(find.text(label), findsWidgets, reason: label);
    }
    expect(tester.takeException(), isNull);
  });

  testWidgets('every bottom tab opens without throwing', (tester) async {
    await _boot(tester);

    for (final label in ['Catalog', 'Room View', 'Cart', 'Account', 'Home']) {
      await tester.tap(find.text(label).last);
      await _tick(tester);
      expect(tester.takeException(), isNull, reason: 'tab $label');
    }
  });

  testWidgets('the cart tab reflects lines added through the controller', (
    tester,
  ) async {
    final deps = await _boot(tester);

    await deps.cart.add(kProducts.first, sqFt: 60);
    await _tick(tester);

    expect(find.byType(Badge), findsWidgets, reason: 'cart badge appears');

    await tester.tap(find.text('Cart').last);
    await _tick(tester);
    expect(find.textContaining(kProducts.first.name), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  group('routes render', () {
    final cases = <String, Object?>{
      Routes.catalog: const CatalogArgs(),
      Routes.search: null,
      Routes.gallery: GalleryArgs(images: kProducts.first.gallery),
      Routes.writeReview: ReviewArgs(kProducts.first.id),
      Routes.checkout: const CheckoutArgs(buyNowProductId: null),
      Routes.profileEdit: null,
      Routes.wishlist: null,
      Routes.coupons: null,
      Routes.login: null,
      Routes.register: null,
      Routes.addressList: null,
      Routes.addressForm: const AddressFormArgs(),
      Routes.orders: null,
      Routes.account: null,
      Routes.notifications: null,
      Routes.help: null,
      Routes.about: null,
      Routes.savedDesigns: null,
      Routes.requestSample: null,
      Routes.requestQuote: null,
      Routes.compare: null,
      Routes.calculator: const CalculatorArgs(),
      Routes.roomPicker: const VisualizerArgs(),
    };

    cases.forEach((route, args) {
      testWidgets('$route opens clean', (tester) async {
        await _boot(tester);
        final navigator = tester.state<NavigatorState>(find.byType(Navigator));
        navigator.pushNamed(route, arguments: args);
        await _tick(tester);
        expect(tester.takeException(), isNull, reason: route);
      });
    });

    testWidgets('${Routes.productDetails} opens clean', (tester) async {
      await _boot(tester);
      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      navigator.pushNamed(
        Routes.productDetails,
        arguments: ProductArgs(kProducts.first.id),
      );
      await _tick(tester);
      expect(find.textContaining(kProducts.first.name), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('${Routes.reviews} opens clean', (tester) async {
      await _boot(tester);
      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      navigator.pushNamed(
        Routes.reviews,
        arguments: ReviewArgs(kProducts.first.id),
      );
      await _tick(tester);
      expect(tester.takeException(), isNull);
    });

    for (final route in [
      Routes.orderDetails,
      Routes.trackOrder,
      Routes.returnRequest,
    ]) {
      testWidgets('$route opens clean', (tester) async {
        await _boot(tester);
        final navigator = tester.state<NavigatorState>(find.byType(Navigator));
        navigator.pushNamed(route, arguments: OrderArgs(kOrders.first.id));
        await _tick(tester);
        expect(tester.takeException(), isNull, reason: route);
      });
    }

    testWidgets('${Routes.visualizer} loads a real room in 3D', (tester) async {
      await _boot(tester);
      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      navigator.pushNamed(
        Routes.visualizer,
        arguments: VisualizerArgs(roomId: kRooms.first.id),
      );
      await _tick(tester, times: 16);
      expect(tester.takeException(), isNull);
      expect(find.byType(CustomPaint), findsWidgets);
    });

    testWidgets('an unknown route falls back instead of crashing', (
      tester,
    ) async {
      await _boot(tester);
      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      navigator.pushNamed('/does-not-exist');
      await _tick(tester);
      expect(tester.takeException(), isNull);
    });
  });
}
