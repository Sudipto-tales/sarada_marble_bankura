class BrandCatalog {
  const BrandCatalog({
    required this.id,
    required this.brandId,
    required this.brandName,
    required this.title,
    required this.coverAsset,
    required this.pdfAsset,
    required this.version,
  });

  final String id;
  final String brandId;
  final String brandName;
  final String title;
  final String coverAsset;
  final String pdfAsset;
  final String version;

  Map<String, dynamic> toJson() => {
    'id': id,
    'brandId': brandId,
    'brandName': brandName,
    'title': title,
    'coverAsset': coverAsset,
    'pdfAsset': pdfAsset,
    'version': version,
  };

  factory BrandCatalog.fromJson(Map<String, dynamic> json) => BrandCatalog(
    id: json['id'] as String? ?? '',
    brandId: json['brandId'] as String? ?? '',
    brandName: json['brandName'] as String? ?? '',
    title: json['title'] as String? ?? '',
    coverAsset: json['coverAsset'] as String? ?? '',
    pdfAsset: json['pdfAsset'] as String? ?? '',
    version: json['version'] as String? ?? '',
  );
}
