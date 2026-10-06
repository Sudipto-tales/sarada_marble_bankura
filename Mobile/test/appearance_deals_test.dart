import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/state/theme_controller.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/data/models/coupon.dart';
import 'package:maa_sarada/data/static/static_promos.dart';
import 'package:maa_sarada/features/home/widgets/deals_showcase.dart';

void main() {
  test('appearance survives controller recreation', () async {
    final store = LocalStore.memory();
    final theme = ThemeController(store);
    theme.set(ThemeMode.system);
    theme.setAccent(Colors.purple);
    final restored = ThemeController(store);
    expect(restored.mode, ThemeMode.system);
    expect(restored.accent.toARGB32(), Colors.purple.toARGB32());
    theme.dispose();
    restored.dispose();
    await Future<void>.delayed(const Duration(milliseconds: 250));
  });
  test('image-only admin payload round trips', () {
    final card = PromoBanner.fromJson({
      'id': 'admin-1',
      'image': 'banner.webp',
      'imageOnly': true,
    });
    expect(PromoBanner.fromJson(card.toJson()).imageOnly, isTrue);
    expect(card.ctaLabel, isEmpty);
  });
  testWidgets('deals advance after four seconds and wrap forward', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: DealsShowcase(banners: kBanners)),
      ),
    );
    final pages = tester.widget<PageView>(find.byType(PageView)).controller!;
    await tester.pump(const Duration(milliseconds: 3999));
    expect(pages.page, 0);
    await tester.pump(const Duration(milliseconds: 1));
    await tester.pump(const Duration(milliseconds: 325));
    expect(pages.page, greaterThan(0));
    expect(pages.page, lessThan(1));
    await tester.pump(const Duration(milliseconds: 325));
    expect(pages.page, 1);
    final dots = find.bySemanticsLabel('Deal 2 of 5');
    expect(
      tester.getRect(dots).top,
      greaterThan(tester.getRect(find.byType(PageView)).bottom),
    );
    pages.jumpToPage(kBanners.length - 1);
    await tester.pump();
    await tester.pump(const Duration(seconds: 4));
    await tester.pump(const Duration(milliseconds: 650));
    expect(pages.page, kBanners.length);
    expect(find.text(kBanners.first.title), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('reduced motion keeps deals still', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: MediaQuery(
          data: MediaQueryData(disableAnimations: true),
          child: Scaffold(body: DealsShowcase(banners: kBanners)),
        ),
      ),
    );
    await tester.pump(const Duration(seconds: 10));
    final pages = tester.widget<PageView>(find.byType(PageView)).controller!;
    expect(pages.page, 0);
    await tester.tap(find.bySemanticsLabel('Deal 2 of 5'));
    await tester.pump();
    expect(pages.page, 1);
    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is Image &&
            widget.image is AssetImage &&
            (widget.image as AssetImage).assetName.endsWith('.gif'),
      ),
      findsNothing,
    );
    await tester.pumpWidget(const SizedBox.shrink());
  });

  for (final scale in [1.0, 2.0]) {
    testWidgets('deals swipe at narrow width and text scale $scale', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(320, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        MaterialApp(
          home: MediaQuery(
            data: MediaQueryData(
              size: const Size(320, 900),
              textScaler: TextScaler.linear(scale),
            ),
            child: const Scaffold(body: DealsShowcase(banners: kBanners)),
          ),
        ),
      );
      await tester.pump(const Duration(seconds: 1));
      expect(tester.takeException(), isNull);
      await tester.drag(find.byType(PageView), const Offset(-320, 0));
      await tester.pump(const Duration(milliseconds: 500));
      await tester.pump(const Duration(milliseconds: 500));
      expect(find.text('Lobby-grade stone'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
