import '../models/brand_catalog.dart';

abstract class BrandCatalogRepository {
  Future<List<BrandCatalog>> all({String? brandId});
  Future<BrandCatalog?> byId(String id);
}

/// Customer-visible, published documents bundled for offline browsing.
class BundledBrandCatalogRepository implements BrandCatalogRepository {
  const BundledBrandCatalogRepository();

  static const _catalogs = [
    BrandCatalog(
      id: 'kajaria-kasamood',
      brandId: 'kajaria',
      brandName: 'Kajaria Eternity',
      title: 'KasaMood collection',
      coverAsset: 'assets/catalogs/kasamood/cover.png',
      pdfAsset: 'assets/catalogs/kasamood/KasaMood.pdf',
      version: '2025-03-07',
    ),
  ];

  @override
  Future<List<BrandCatalog>> all({String? brandId}) async => [
    for (final catalog in _catalogs)
      if (brandId == null || catalog.brandId == brandId) catalog,
  ];

  @override
  Future<BrandCatalog?> byId(String id) async {
    for (final catalog in _catalogs) {
      if (catalog.id == id) return catalog;
    }
    return null;
  }
}
