import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/theme/app_theme.dart';
import 'package:maa_sarada/data/models/filters.dart';
import 'package:maa_sarada/data/static/static_categories.dart';
import 'package:maa_sarada/data/static/static_products.dart';
import 'package:maa_sarada/features/home/widgets/category_browser.dart';
import 'package:maa_sarada/features/home/widgets/home_header.dart';
import 'package:maa_sarada/features/product/widgets/product_gallery.dart';

Widget detailGallery({bool reduced = false, bool enabled = true}) =>
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: reduced),
        child: TickerMode(
          enabled: enabled,
          child: Scaffold(
            body: Center(
              child: SizedBox(
                width: 390,
                height: 420,
                child: ProductGallery(product: kProducts.first),
              ),
            ),
          ),
        ),
      ),
    );

Future<void> advanceFrames(WidgetTester tester, int milliseconds) async {
  for (var elapsed = 0; elapsed < milliseconds; elapsed += 100) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets(
    'header and category modes fit narrow screens with enlarged text',
    (tester) async {
      tester.view.physicalSize = const Size(320, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final deps = AppDependencies.static(LocalStore.memory());
      addTearDown(deps.dispose);
      for (final dark in [false, true]) {
        await tester.pumpWidget(
          AppScope(
            deps: deps,
            child: MaterialApp(
              theme: dark ? AppTheme.dark() : AppTheme.light(),
              home: MediaQuery(
                data: const MediaQueryData(
                  size: Size(320, 900),
                  textScaler: TextScaler.linear(2),
                  disableAnimations: true,
                ),
                child: const Scaffold(
                  body: CustomScrollView(
                    slivers: [
                      HomeHeader(),
                      SliverToBoxAdapter(
                        child: HomeCategoryBrowser(
                          categories: kCategories,
                          products: kProducts,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
        await advanceFrames(tester, 400);
        expect(tester.takeException(), isNull);
        await tester.tap(find.text('By space'));
        await tester.pump();
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
      }
    },
  );

  test('space filters return matching products and survive refinement', () {
    for (final application in [
      'Kitchen',
      'flooring',
      'wall',
      'bath',
      'staircase',
    ]) {
      final filter = ProductFilter(application: application);
      final products = kProducts.where(filter.matches).toList();
      expect(products, isNotEmpty, reason: application);
      expect(
        products.every(
          (p) => p.applications.any(
            (value) => value.toLowerCase().contains(application.toLowerCase()),
          ),
        ),
        isTrue,
      );
      expect(filter.copyWith(inStockOnly: true).application, application);
      expect(filter.cleared().application, isNull);
    }
  });

  testWidgets('space tab changes categories and opens a filtered catalog', (
    tester,
  ) async {
    CatalogArgs? args;
    await tester.pumpWidget(
      MaterialApp(
        onGenerateRoute: (settings) {
          args = settings.arguments as CatalogArgs;
          return MaterialPageRoute<void>(builder: (_) => const Scaffold());
        },
        home: const Scaffold(
          body: HomeCategoryBrowser(
            categories: kCategories,
            products: kProducts,
          ),
        ),
      ),
    );
    expect(find.text('White Marble'), findsOneWidget);
    await tester.tap(find.text('By space'));
    await tester.pump();
    expect(find.text('White Marble'), findsNothing);
    expect(find.text('Kitchen'), findsOneWidget);
    await tester.tap(find.text('Kitchen'));
    await tester.pump();
    expect(args!.filter!.application, 'Kitchen');
    expect(kProducts.where(args!.filter!.matches), isNotEmpty);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'header location, search, notifications and filters work separately',
    (tester) async {
      final deps = AppDependencies.static(LocalStore.memory());
      addTearDown(deps.dispose);
      RouteSettings? opened;
      await tester.pumpWidget(
        AppScope(
          deps: deps,
          child: MaterialApp(
            theme: AppTheme.light(),
            onGenerateRoute: (settings) {
              opened = settings;
              return MaterialPageRoute<void>(builder: (_) => const Scaffold());
            },
            home: const Scaffold(
              body: CustomScrollView(slivers: [HomeHeader()]),
            ),
          ),
        ),
      );
      expect(find.text('Delivery location'), findsOneWidget);
      expect(find.byIcon(Icons.favorite_border_rounded), findsNothing);
      for (final pair in [
        ('Delivery location', Routes.addressList),
        ('Search marble, granite…', Routes.search),
      ]) {
        await tester.tap(find.text(pair.$1));
        await tester.pumpAndSettle();
        expect(opened!.name, pair.$2);
        tester.state<NavigatorState>(find.byType(Navigator)).pop();
        await tester.pumpAndSettle();
      }
      await tester.tap(find.byTooltip('Notifications'));
      await tester.pumpAndSettle();
      expect(opened!.name, Routes.notifications);
      tester.state<NavigatorState>(find.byType(Navigator)).pop();
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Filter products'));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('Filters'), findsOneWidget);
      await tester.tap(find.text('White Marble'));
      await tester.tap(find.textContaining('Apply').last);
      await tester.pumpAndSettle();
      expect(opened!.name, Routes.catalog);
      expect((opened!.arguments as CatalogArgs).filter!.categoryIds, {
        'white_marble',
      });
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'detail thumbnails select photos and restart the five second dwell',
    (tester) async {
      await tester.pumpWidget(detailGallery());
      final pages = tester.widget<PageView>(find.byType(PageView)).controller!;
      await tester.pump(const Duration(milliseconds: 4999));
      expect(pages.page, 0);
      await tester.tap(find.byKey(const ValueKey('product-thumbnail-2')));
      await advanceFrames(tester, 600);
      expect(pages.page, 2);
      expect(find.text('3 / 4'), findsOneWidget);
      await tester.pump();
      await advanceFrames(tester, 4000);
      expect(pages.page, 2);
      await advanceFrames(tester, 1600);
      expect(pages.page, 3);
      await advanceFrames(tester, 5600);
      expect(pages.page, 4);
      expect(find.text('1 / 4'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('reduced motion and inactive galleries stop auto play', (
    tester,
  ) async {
    for (final view in [
      detailGallery(reduced: true),
      detailGallery(enabled: false),
    ]) {
      await tester.pumpWidget(view);
      await tester.pump(const Duration(seconds: 12));
      final pages = tester.widget<PageView>(find.byType(PageView)).controller!;
      expect(pages.page, 0);
      await tester.tap(find.byKey(const ValueKey('product-thumbnail-1')));
      await tester.pump();
      if (pages.page != 1) await tester.pump(const Duration(milliseconds: 500));
      expect(pages.page, 1);
      await tester.pumpWidget(const SizedBox.shrink());
    }
    expect(tester.takeException(), isNull);
  });
}
