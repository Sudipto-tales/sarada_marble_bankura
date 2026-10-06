import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/routing/router.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/theme/app_theme.dart';
import 'package:maa_sarada/core/widgets/shimmer.dart';
import 'package:maa_sarada/data/static/static_products.dart';
import 'package:maa_sarada/features/catalog/widgets/product_card.dart';
import 'package:maa_sarada/features/shell/app_shell.dart';

const _capture = bool.fromEnvironment('CAPTURE_PREVIEWS');
const _previewDirectory = String.fromEnvironment(
  'PREVIEW_DIRECTORY',
  defaultValue: '../Docs/previews',
);
const _boundary = ValueKey('preview-boundary');

Future<void> tick(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 200));
  }
}

Future<void> capture(WidgetTester tester, String name) async {
  if (!_capture) return;
  await tester.runAsync(() async {
    // Let local image decoding finish before recording the rendered widget.
    await Future<void>.delayed(const Duration(milliseconds: 300));
  });
  await tester.pump();
  final boundary = tester.renderObject<RenderRepaintBoundary>(
    find.byKey(_boundary),
  );
  await tester.runAsync(() async {
    final image = await boundary.toImage(pixelRatio: 2);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    final file = File('$_previewDirectory/mobile-stone-$name.png');
    await file.parent.create(recursive: true);
    await file.writeAsBytes(bytes!.buffer.asUint8List());
    image.dispose();
  });
}

void main() {
  setUpAll(() async {
    if (!_capture) return;
    for (final entry in {
      'sans-serif': 'segoeui.ttf',
      'Roboto': 'segoeui.ttf',
      'serif': 'georgia.ttf',
    }.entries) {
      final loader = FontLoader(entry.key);
      loader.addFont(
        File(
          Platform.isWindows
              ? 'C:/Windows/Fonts/${entry.value}'
              : '/usr/share/fonts/truetype/dejavu/${entry.key == 'serif' ? 'DejaVuSerif' : 'DejaVuSans'}.ttf',
        ).readAsBytes().then((bytes) => ByteData.sublistView(bytes)),
      );
      await loader.load();
    }
    final icons = FontLoader('MaterialIcons');
    icons.addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });

  for (final width in [320.0, 390.0]) {
    for (final dark in [false, true]) {
      testWidgets('stone shell at $width, dark=$dark, reduced motion', (
        tester,
      ) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        final deps = AppDependencies.static(LocalStore.memory());
        addTearDown(deps.dispose);
        await tester.pumpWidget(
          AppScope(
            deps: deps,
            child: MaterialApp(
              theme: dark ? AppTheme.dark() : AppTheme.light(),
              onGenerateRoute: AppRouter.generate,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context).copyWith(disableAnimations: true),
                child: RepaintBoundary(key: _boundary, child: child!),
              ),
              home: const AppShell(),
            ),
          ),
        );
        await tick(tester);
        expect(tester.takeException(), isNull);
        if (width == 390 && !dark) {
          await capture(tester, 'home');
          if (_capture) {
            await tester.tap(find.text('By space'));
            await tick(tester);
            await capture(tester, 'home-space');
            await tester.tap(find.text('By material'));
            await tick(tester);
          }
        }
        for (final label in ['Catalog', 'Cart', 'Account', 'Home']) {
          await tester.tap(find.text(label).last);
          await tick(tester);
          expect(tester.takeException(), isNull, reason: label);
          if (width == 390 && !dark && label == 'Catalog') {
            await capture(tester, 'catalog');
          }
        }
        final navigator = tester.state<NavigatorState>(find.byType(Navigator));
        navigator.pushNamed(
          Routes.productDetails,
          arguments: ProductArgs(kProducts.first.id),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 50));
        expect(find.byType(Shimmer), findsWidgets);
        expect(find.byType(CircularProgressIndicator), findsNothing);
        if (width == 390 && !dark) {
          await tester.pump(const Duration(milliseconds: 300));
          await capture(tester, 'loading');
        }
        await tick(tester);
        expect(tester.takeException(), isNull);
        if (width == 390 && !dark) await capture(tester, 'product');
        final scroll = find.byType(CustomScrollView).hitTestable().last;
        await tester.scrollUntilVisible(
          find.text('Add the combo'),
          400,
          scrollable: find
              .descendant(of: scroll, matching: find.byType(Scrollable))
              .first,
          maxScrolls: 30,
        );
        await tick(tester);
        expect(tester.takeException(), isNull);
        await tester.tap(find.text('Add the combo'));
        await tick(tester);
        expect(deps.cart.count, 2);
        expect(deps.cart.sqFtOf(kProducts.first.id), 50);
        await tester.scrollUntilVisible(
          find.text('Shop by brands'),
          400,
          scrollable: find
              .descendant(of: scroll, matching: find.byType(Scrollable))
              .first,
          maxScrolls: 30,
        );
        await tick(tester);
        expect(tester.takeException(), isNull);

        await tester.pumpWidget(const SizedBox.shrink());
      });
    }
  }

  testWidgets(
    'product skeleton fits a narrow screen and stops with reduced motion',
    (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light(),
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(320, 640),
              disableAnimations: true,
              textScaler: TextScaler.linear(1.4),
            ),
            child: const Scaffold(body: ProductGridSkeleton()),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(tester.binding.hasScheduledFrame, isFalse);
    },
  );
}
