class Category {
  const Category({
    required this.id,
    required this.name,
    required this.image,
    required this.description,
    this.productCount = 0,
    this.accent,
  });

  final String id;
  final String name;
  final String image;
  final String description;
  final int productCount;
  final int? accent;
}
