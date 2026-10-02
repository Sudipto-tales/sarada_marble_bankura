import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/bridge/module_bridge.dart';
import 'package:maa_sarada/core/config/feature_flags.dart';
import 'package:maa_sarada/core/state/cart_controller.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/data/models/cart_item.dart';
import 'package:maa_sarada/data/repositories/static_repositories.dart';
import 'package:maa_sarada/data/static/static_products.dart';

List<File> _dartFilesUnder(String dir) => Directory(dir)
    .listSync(recursive: true)
    .whereType<File>()
    .where((f) => f.path.endsWith('.dart'))
    .toList();

/// The spec's hard rule: removing the 3D module or the calculator must not
/// break the shop. That only holds if the shop never imports their internals.
void main() {
  const optionalModules = ['features/visualization', 'features/calculator'];

  const shopFeatures = [
    'lib/features/catalog',
    'lib/features/product',
    'lib/features/cart',
    'lib/features/checkout',
    'lib/features/orders',
    'lib/features/wishlist',
    'lib/features/search',
    'lib/features/account',
    'lib/features/home',
  ];

  test('no e-commerce screen imports a 3D or calculator internal', () {
    final offenders = <String>[];
    for (final dir in shopFeatures) {
      for (final file in _dartFilesUnder(dir)) {
        for (final line in file.readAsLinesSync()) {
          if (!line.startsWith('import ')) continue;
          for (final module in optionalModules) {
            if (line.contains(module)) {
              offenders.add('${file.path}: ${line.trim()}');
            }
          }
        }
      }
    }
    expect(offenders, isEmpty,
        reason: 'the shop must reach optional modules only through routes '
            'and the module bridge');
  });

  test('the optional modules never touch cart, orders or the shop screens', () {
    final offenders = <String>[];
    for (final module in optionalModules) {
      for (final file in _dartFilesUnder('lib/$module')) {
        for (final line in file.readAsLinesSync()) {
          if (!line.startsWith('import ')) continue;
          final forbidden = [
            'features/cart/',
            'features/checkout/',
            'features/orders/',
            'features/product/',
            'features/catalog/',
          ];
          for (final f in forbidden) {
            if (line.contains(f)) offenders.add('${file.path}: ${line.trim()}');
          }
        }
      }
    }
    expect(offenders, isEmpty);
  });

  test('the calculator never imports the 3D renderer, and vice versa', () {
    for (final file in _dartFilesUnder('lib/features/calculator')) {
      expect(file.readAsStringSync().contains('features/visualization'), isFalse,
          reason: file.path);
    }
    for (final file in _dartFilesUnder('lib/features/visualization')) {
      expect(file.readAsStringSync().contains('features/calculator'), isFalse,
          reason: file.path);
    }
  });

  test('every optional route is guarded by a feature flag', () {
    final router = File('lib/core/routing/router.dart').readAsStringSync();
    for (final flag in [
      'FeatureFlags.visualizationEnabled',
      'FeatureFlags.calculatorEnabled',
      'FeatureFlags.compareEnabled',
    ]) {
      expect(router.contains(flag), isTrue, reason: flag);
    }
  });

  test('the shell only shows the 3D tab when the module is on', () {
    final shell = File('lib/features/shell/app_shell.dart').readAsStringSync();
    expect(shell.contains('FeatureFlags.visualizationEnabled'), isTrue);
    // Flags are compile-time constants, so the tab disappears from the build.
    expect(FeatureFlags.visualizationEnabled, isTrue);
  });

  test('a visualization result reaches the cart as a plain quantity', () async {
    final cart = CartController(
      LocalStore.memory(),
      const StaticProductRepository(),
    );
    final product = kProducts.first;
    const result = VisualizationResult(
      productId: 'ignored-here',
      surfaceId: 'floor',
      estimatedSqFt: 240,
    );

    await cart.setQuantity(product, result.estimatedSqFt,
        source: CartSource.visualizer);

    expect(cart.sqFtOf(product.id), closeTo(240, 0.001));
    expect(cart.items.first.addedFrom, CartSource.visualizer);
    expect(cart.subtotal, closeTo(product.pricePerSqFt * 240, 0.001));
    cart.dispose();
  });

  test('a calculation result reaches the cart the same way', () async {
    final cart = CartController(
      LocalStore.memory(),
      const StaticProductRepository(),
    );
    final product = kProducts[2];
    const result = CalculationResult(
      productId: 'ignored-here',
      requiredSqFt: 155.5,
      estimatedCost: 99999,
      slabCount: 6,
      wastagePercent: 8,
    );

    await cart.setQuantity(product, result.requiredSqFt,
        source: CartSource.calculator);

    expect(cart.sqFtOf(product.id), closeTo(155.5, 0.001));
    // The cart prices from the catalogue, never from the module's estimate.
    expect(cart.subtotal, closeTo(product.pricePerSqFt * 155.5, 0.001));
    expect(cart.subtotal, isNot(closeTo(result.estimatedCost, 1)));
    cart.dispose();
  });

  test('the bridge carries ids and numbers only — no widgets, no repositories',
      () {
    final bridge = File('lib/core/bridge/module_bridge.dart').readAsStringSync();
    final imports =
        bridge.split('\n').where((l) => l.startsWith('import ')).toList();
    expect(imports, isEmpty,
        reason: 'the bridge must be plain Dart data with no dependencies');
  });

  test('nothing in the app talks to the network', () {
    final offenders = <String>[];
    for (final file in _dartFilesUnder('lib')) {
      final src = file.readAsStringSync();
      for (final needle in [
        'dart:html',
        'package:http/',
        'HttpClient(',
        'Image.network',
        'NetworkImage',
      ]) {
        if (src.contains(needle)) offenders.add('${file.path}: $needle');
      }
    }
    expect(offenders, isEmpty, reason: 'v1 must be fully offline');
  });
}
