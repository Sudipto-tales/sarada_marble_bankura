/// A line item. Quantity is in square feet — marble is sold by area.
class CartItem {
  const CartItem({
    required this.productId,
    required this.sqFt,
    this.note,
    this.addedFrom = CartSource.catalog,
  });

  final String productId;
  final double sqFt;
  final String? note;
  final CartSource addedFrom;

  CartItem copyWith({double? sqFt, String? note, CartSource? addedFrom}) =>
      CartItem(
        productId: productId,
        sqFt: sqFt ?? this.sqFt,
        note: note ?? this.note,
        addedFrom: addedFrom ?? this.addedFrom,
      );

  Map<String, dynamic> toJson() => {
        'productId': productId,
        'sqFt': sqFt,
        'note': note,
        'addedFrom': addedFrom.name,
      };

  factory CartItem.fromJson(Map<String, dynamic> j) => CartItem(
        productId: j['productId'] as String,
        sqFt: (j['sqFt'] as num?)?.toDouble() ?? 1,
        note: j['note'] as String?,
        addedFrom: CartSource.values.firstWhere(
          (e) => e.name == j['addedFrom'],
          orElse: () => CartSource.catalog,
        ),
      );
}

/// Where the line came from. Purely informational — the cart does not change
/// behaviour based on it, so the optional modules stay decoupled.
enum CartSource { catalog, calculator, visualizer, reorder }

extension CartSourceLabel on CartSource {
  String get label => switch (this) {
        CartSource.catalog => 'Catalog',
        CartSource.calculator => 'Calculator estimate',
        CartSource.visualizer => 'Room design',
        CartSource.reorder => 'Reordered',
      };
}
