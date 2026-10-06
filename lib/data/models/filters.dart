import 'product.dart';

enum SortOption {
  relevance,
  priceLowHigh,
  priceHighLow,
  rating,
  newest,
  discount,
}

extension SortOptionLabel on SortOption {
  String get label => switch (this) {
    SortOption.relevance => 'Relevance',
    SortOption.priceLowHigh => 'Price: low to high',
    SortOption.priceHighLow => 'Price: high to low',
    SortOption.rating => 'Customer rating',
    SortOption.newest => 'Newest first',
    SortOption.discount => 'Discount',
  };
}

/// Immutable filter state shared by catalog and search.
class ProductFilter {
  const ProductFilter({
    this.categoryIds = const {},
    this.colors = const {},
    this.finishes = const {},
    this.origins = const {},
    this.brands = const {},
    this.minPrice,
    this.maxPrice,
    this.minRating,
    this.inStockOnly = false,
    this.sort = SortOption.relevance,
    this.query = '',
    this.application,
  });

  final Set<String> categoryIds;
  final Set<String> colors;
  final Set<String> finishes;
  final Set<String> origins;
  final Set<String> brands;
  final double? minPrice;
  final double? maxPrice;
  final double? minRating;
  final bool inStockOnly;
  final SortOption sort;
  final String query;
  final String? application;

  int get activeCount =>
      categoryIds.length +
      colors.length +
      finishes.length +
      origins.length +
      brands.length +
      (minPrice != null || maxPrice != null ? 1 : 0) +
      (minRating != null ? 1 : 0) +
      (inStockOnly ? 1 : 0) +
      (application != null ? 1 : 0);

  bool get isEmpty => activeCount == 0;

  ProductFilter copyWith({
    Set<String>? categoryIds,
    Set<String>? colors,
    Set<String>? finishes,
    Set<String>? origins,
    Set<String>? brands,
    double? minPrice,
    double? maxPrice,
    double? minRating,
    bool? inStockOnly,
    SortOption? sort,
    String? query,
    bool clearPrice = false,
    bool clearRating = false,
    String? application,
  }) => ProductFilter(
    categoryIds: categoryIds ?? this.categoryIds,
    colors: colors ?? this.colors,
    finishes: finishes ?? this.finishes,
    origins: origins ?? this.origins,
    brands: brands ?? this.brands,
    minPrice: clearPrice ? null : (minPrice ?? this.minPrice),
    maxPrice: clearPrice ? null : (maxPrice ?? this.maxPrice),
    minRating: clearRating ? null : (minRating ?? this.minRating),
    inStockOnly: inStockOnly ?? this.inStockOnly,
    sort: sort ?? this.sort,
    query: query ?? this.query,
    application: application ?? this.application,
  );

  ProductFilter cleared() => ProductFilter(sort: sort, query: query);

  bool matches(Product p) {
    if (application != null &&
        !p.applications.any(
          (value) => value.toLowerCase().contains(application!.toLowerCase()),
        )) {
      return false;
    }
    if (categoryIds.isNotEmpty && !categoryIds.contains(p.categoryId)) {
      return false;
    }
    if (colors.isNotEmpty && !colors.contains(p.color)) return false;
    if (finishes.isNotEmpty && !finishes.contains(p.finish)) return false;
    if (origins.isNotEmpty && !origins.contains(p.origin)) return false;
    if (brands.isNotEmpty && !brands.contains(p.brand)) return false;
    if (minPrice != null && p.pricePerSqFt < minPrice!) return false;
    if (maxPrice != null && p.pricePerSqFt > maxPrice!) return false;
    if (minRating != null && p.rating < minRating!) return false;
    if (inStockOnly && !p.inStock) return false;
    if (query.trim().isNotEmpty) {
      final q = query.toLowerCase().trim();
      final hay =
          '${p.name} ${p.color} ${p.finish} ${p.origin} ${p.brand} ${p.tags.join(' ')}'
              .toLowerCase();
      if (!hay.contains(q)) return false;
    }
    return true;
  }
}
