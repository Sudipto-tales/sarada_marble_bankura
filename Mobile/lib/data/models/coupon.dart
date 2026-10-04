class Coupon {
  const Coupon({
    required this.code,
    required this.title,
    required this.description,
    required this.percentOff,
    required this.maxDiscount,
    required this.minOrder,
    required this.expiresOn,
    this.flatOff = 0,
  });

  final String code;
  final String title;
  final String description;
  final double percentOff;
  final double maxDiscount;
  final double minOrder;
  final DateTime expiresOn;
  final double flatOff;

  bool isApplicable(double subtotal) =>
      subtotal >= minOrder && DateTime.now().isBefore(expiresOn);

  double discountFor(double subtotal) {
    if (!isApplicable(subtotal)) return 0;
    if (flatOff > 0) return flatOff.clamp(0, subtotal);
    final raw = subtotal * percentOff / 100;
    return raw > maxDiscount ? maxDiscount : raw;
  }
}

class Offer {
  const Offer({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.image,
    this.couponCode,
    this.categoryId,
  });

  final String id;
  final String title;
  final String subtitle;
  final String image;
  final String? couponCode;
  final String? categoryId;
}

class PromoBanner {
  const PromoBanner({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.image,
    required this.ctaLabel,
    this.categoryId,
    this.productId,
    this.imageOnly = false,
    this.animatedImage,
    this.badge = 'Stone edit',
    this.backgroundColor = 0xFFDCEAB5,
  });

  final String id;
  final String title;
  final String subtitle;
  final String image;
  final String ctaLabel;
  final String? categoryId;
  final String? productId;
  final bool imageOnly;
  final String? animatedImage;
  final String badge;
  final int backgroundColor;

  factory PromoBanner.fromJson(Map<String, dynamic> json) => PromoBanner(
    id: json['id'] as String,
    title: json['title'] as String? ?? '',
    subtitle: json['subtitle'] as String? ?? '',
    image: json['image'] as String,
    ctaLabel: json['ctaLabel'] as String? ?? '',
    categoryId: json['categoryId'] as String?,
    productId: json['productId'] as String?,
    imageOnly: json['imageOnly'] as bool? ?? false,
    animatedImage: json['animatedImage'] as String?,
    badge: json['badge'] as String? ?? '',
    backgroundColor: json['backgroundColor'] as int? ?? 0xFFDCEAB5,
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'title': title,
    'subtitle': subtitle,
    'image': image,
    'ctaLabel': ctaLabel,
    'categoryId': categoryId,
    'productId': productId,
    'imageOnly': imageOnly,
    'animatedImage': animatedImage,
    'badge': badge,
    'backgroundColor': backgroundColor,
  };
}
