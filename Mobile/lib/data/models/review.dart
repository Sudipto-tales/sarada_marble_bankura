class Review {
  const Review({
    required this.id,
    required this.productId,
    required this.author,
    required this.rating,
    required this.title,
    required this.body,
    required this.date,
    this.verified = true,
    this.helpfulCount = 0,
    this.photos = const [],
    this.location,
  });

  final String id;
  final String productId;
  final String author;
  final double rating;
  final String title;
  final String body;
  final DateTime date;
  final bool verified;
  final int helpfulCount;
  final List<String> photos;
  final String? location;
}
