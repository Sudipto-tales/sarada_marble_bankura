/// Catalog item. `textureId` is the ONLY link to the visualization module —
/// the e-commerce layer never learns how that texture is rendered.
class Product {
  const Product({
    required this.id,
    required this.name,
    required this.description,
    required this.pricePerSqFt,
    required this.originalPrice,
    required this.rating,
    required this.reviewCount,
    required this.color,
    required this.finish,
    required this.thickness,
    required this.origin,
    required this.dimensions,
    required this.categoryId,
    required this.brand,
    this.brandId,
    required this.image,
    required this.gallery,
    required this.textureId,
    required this.stock,
    this.isFeatured = false,
    this.isTrending = false,
    this.isBestSeller = false,
    this.tags = const [],
    this.applications = const [],
    this.slabSqFt = 0,
  });

  final String id;
  final String name;
  final String description;
  final double pricePerSqFt;
  final double originalPrice;
  final double rating;
  final int reviewCount;
  final String color;
  final String finish;
  final String thickness;
  final String origin;
  final String dimensions;
  final String categoryId;
  final String brand;
  final String? brandId;
  final String image;
  final List<String> gallery;
  final String textureId;
  final int stock;
  final bool isFeatured;
  final bool isTrending;
  final bool isBestSeller;
  final List<String> tags;
  final List<String> applications;

  /// Coverage of one slab, used by the calculator to suggest slab counts.
  final double slabSqFt;

  int get discount => originalPrice <= 0
      ? 0
      : (((originalPrice - pricePerSqFt) / originalPrice) * 100).round();

  bool get inStock => stock > 0;
  bool get isLowStock => stock > 0 && stock <= 12;

  double get savingsPerSqFt =>
      (originalPrice - pricePerSqFt).clamp(0, double.infinity);
}
