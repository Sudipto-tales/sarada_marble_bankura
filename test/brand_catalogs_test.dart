import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/theme/app_theme.dart';
import 'package:maa_sarada/data/models/brand_catalog.dart';
import 'package:maa_sarada/data/models/product.dart';
import 'package:maa_sarada/data/repositories/brand_catalog_repository.dart';
import 'package:maa_sarada/features/brand_catalogs/brand_catalog_widgets.dart';

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump(const Duration(milliseconds: 200));
  }
}

void main() {
  group('BundledBrandCatalogRepository', () {
    const repo = BundledBrandCatalogRepository();

    test('all() returns bundled catalogs', () async {
      final all = await repo.all();
      expect(all, isNotEmpty);
      expect(all.any((c) => c.id == 'kajaria-kasamood'), isTrue);
    });

    test('filtering by brandId works', () async {
      final kajaria = await repo.all(brandId: 'kajaria');
      expect(kajaria, isNotEmpty);
      expect(kajaria.every((c) => c.brandId == 'kajaria'), isTrue);

      final other = await repo.all(brandId: 'non_existent_brand');
      expect(other, isEmpty);
    });

    test('byId returns matching catalog or null', () async {
      final catalog = await repo.byId('kajaria-kasamood');
      expect(catalog, isNotNull);
      expect(catalog!.brandId, 'kajaria');
      expect(catalog.brandName, 'Kajaria Eternity');
      expect(catalog.title, 'KasaMood collection');
      expect(catalog.pdfAsset, 'assets/catalogs/kasamood/KasaMood.pdf');
      expect(catalog.coverAsset, 'assets/catalogs/kasamood/cover.png');

      final notFound = await repo.byId('unknown');
      expect(notFound, isNull);
    });

    test('BrandCatalog model round-trips via JSON', () {
      const catalog = BrandCatalog(
        id: 'test-cat',
        brandId: 'test-brand',
        brandName: 'Test Brand',
        title: 'Test Title',
        coverAsset: 'cover.png',
        pdfAsset: 'test.pdf',
        version: '1.0',
      );
      final json = catalog.toJson();
      final restored = BrandCatalog.fromJson(json);
      expect(restored.id, catalog.id);
      expect(restored.brandId, catalog.brandId);
      expect(restored.brandName, catalog.brandName);
      expect(restored.title, catalog.title);
      expect(restored.coverAsset, catalog.coverAsset);
      expect(restored.pdfAsset, catalog.pdfAsset);
      expect(restored.version, catalog.version);
    });
  });

  group('Brand Catalog UI Widgets', () {
    testWidgets('BrandCatalogCard displays cover, title, and brand name', (
      tester,
    ) async {
      const catalog = BrandCatalog(
        id: 'kajaria-kasamood',
        brandId: 'kajaria',
        brandName: 'Kajaria Eternity',
        title: 'KasaMood collection',
        coverAsset: 'assets/catalogs/kasamood/cover.png',
        pdfAsset: 'assets/catalogs/kasamood/KasaMood.pdf',
        version: '2025-03-07',
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light(),
          home: const Scaffold(
            body: SizedBox(
              width: 200,
              child: BrandCatalogCard(catalog: catalog),
            ),
          ),
        ),
      );

      expect(find.text('KasaMood collection'), findsOneWidget);
      expect(find.text('Kajaria Eternity'), findsOneWidget);
    });

    testWidgets(
      'ProductBrandCatalogAction shows for matching brand and hides for unmatched',
      (tester) async {
        final deps = AppDependencies.static(LocalStore.memory());

        const kajariaProduct = Product(
          id: 'p_kajaria_test',
          name: 'Kajaria Test Tile',
          description: 'Test tile',
          pricePerSqFt: 200,
          originalPrice: 250,
          rating: 4.8,
          reviewCount: 10,
          color: 'Beige',
          finish: 'Polished',
          thickness: '10 mm',
          origin: 'India',
          dimensions: '600x600',
          categoryId: 'beige_marble',
          brand: 'Kajaria Eternity',
          brandId: 'kajaria',
          image: 'assets/catalogs/kasamood/cover.png',
          gallery: [],
          textureId: 't1',
          stock: 100,
        );

        const otherProduct = Product(
          id: 'p_other_test',
          name: 'Other Tile',
          description: 'Other tile',
          pricePerSqFt: 200,
          originalPrice: 250,
          rating: 4.8,
          reviewCount: 10,
          color: 'White',
          finish: 'Polished',
          thickness: '10 mm',
          origin: 'India',
          dimensions: '600x600',
          categoryId: 'white_marble',
          brand: 'Maa Sarada Select',
          brandId: 'maa-sarada-select',
          image: 'assets/catalogs/kasamood/cover.png',
          gallery: [],
          textureId: 't2',
          stock: 100,
        );

        await tester.pumpWidget(
          AppScope(
            deps: deps,
            child: MaterialApp(
              theme: AppTheme.light(),
              home: const Scaffold(
                body: Column(
                  children: [
                    ProductBrandCatalogAction(product: kajariaProduct),
                    ProductBrandCatalogAction(product: otherProduct),
                  ],
                ),
              ),
            ),
          ),
        );

        await _settle(tester);
        expect(find.text('View Kajaria Eternity catalog'), findsOneWidget);
        expect(find.byIcon(Icons.menu_book_outlined), findsOneWidget);

        await tester.pumpWidget(const SizedBox.shrink());
        await _settle(tester);
        deps.dispose();
      },
    );

    testWidgets('HomeBrandCatalogs renders rail with published catalogs', (
      tester,
    ) async {
      final deps = AppDependencies.static(LocalStore.memory());

      await tester.pumpWidget(
        AppScope(
          deps: deps,
          child: MaterialApp(
            theme: AppTheme.light(),
            home: const Scaffold(
              body: SingleChildScrollView(child: HomeBrandCatalogs()),
            ),
          ),
        ),
      );

      await _settle(tester);
      expect(find.text('Brand catalogs'), findsOneWidget);
      expect(find.text('View all'), findsOneWidget);
      expect(find.text('KasaMood collection'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await _settle(tester);
      deps.dispose();
    });

    testWidgets('BrandCatalogLibrary displays catalogs and filter chips', (
      tester,
    ) async {
      final deps = AppDependencies.static(LocalStore.memory());

      await tester.pumpWidget(
        AppScope(
          deps: deps,
          child: MaterialApp(
            theme: AppTheme.light(),
            home: const BrandCatalogLibrary(args: BrandCatalogArgs()),
          ),
        ),
      );

      await _settle(tester);
      expect(find.text('Brand catalogs'), findsOneWidget);
      expect(find.text('KasaMood collection'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await _settle(tester);
      deps.dispose();
    });
  });
}
