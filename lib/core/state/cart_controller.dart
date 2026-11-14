
import '../../data/models/cart_item.dart';
import '../../data/models/coupon.dart';
import '../../data/models/product.dart';
import '../../data/repositories/repositories.dart';
import '../config/app_config.dart';
import '../store/local_store.dart';
import 'safe_notifier.dart';

/// Cart + pricing. Owns every money rule so no widget computes totals itself.
class CartController extends SafeNotifier {
  CartController(this._store, this._products) {
    _items = _store
        .readList(StoreKeys.cart)
        .map(CartItem.fromJson)
        .toList();
    _hydrate();
  }

  final LocalStore _store;
  final ProductRepository _products;

  List<CartItem> _items = [];
  final Map<String, Product> _cache = {};
  Coupon? _coupon;
  bool _loading = false;

  List<CartItem> get items => List.unmodifiable(_items);
  bool get isEmpty => _items.isEmpty;
  int get count => _items.length;
  bool get isLoading => _loading;
  Coupon? get coupon => _coupon;

  Product? product(String id) => _cache[id];

  Future<void> _hydrate() async {
    if (_items.isEmpty) return;
    _loading = true;
    notifyListeners();
    final list = await _products.byIds(_items.map((e) => e.productId).toList());
    for (final p in list) {
      _cache[p.id] = p;
    }
    _loading = false;
    notifyListeners();
  }

  bool contains(String productId) =>
      _items.any((i) => i.productId == productId);

  double sqFtOf(String productId) =>
      _items.where((i) => i.productId == productId).fold(0.0, (s, i) => s + i.sqFt);

  Future<bool> add(
    Product product, {
    double sqFt = 50,
    CartSource source = CartSource.catalog,
    String? note,
  }) async {
    if (!canAdd(product, sqFt)) return false;
    _cache[product.id] = product;
    final i = _items.indexWhere((e) => e.productId == product.id);
    if (i >= 0) {
      _items[i] = _items[i].copyWith(sqFt: _items[i].sqFt + sqFt, note: note);
    } else {
      _items.add(CartItem(
          productId: product.id, sqFt: sqFt, addedFrom: source, note: note));
    }
    _persist();
    notifyListeners();
    return true;
  }

  bool canAdd(Product product, double sqFt) =>
      _validQuantity(product, sqFtOf(product.id) + sqFt);

  /// Used by the calculator / visualizer bridge: set an exact area rather than
  /// accumulating one.
  Future<bool> setQuantity(Product product, double sqFt,
      {CartSource source = CartSource.catalog, String? note}) async {
    if (!_validQuantity(product, sqFt)) return false;
    _cache[product.id] = product;
    final i = _items.indexWhere((e) => e.productId == product.id);
    final item = CartItem(
        productId: product.id, sqFt: sqFt, addedFrom: source, note: note);
    if (i >= 0) {
      _items[i] = item;
    } else {
      _items.add(item);
    }
    _persist();
    notifyListeners();
    return true;
  }

  bool updateSqFt(String productId, double sqFt) {
    final i = _items.indexWhere((e) => e.productId == productId);
    if (i < 0) return false;
    if (sqFt <= 0) {
      _items.removeAt(i);
    } else {
      final product = _cache[productId];
      if (product == null || !_validQuantity(product, sqFt)) return false;
      _items[i] = _items[i].copyWith(sqFt: sqFt);
    }
    _persist();
    notifyListeners();
    return true;
  }

  /// Remove only the quantities captured by a completed cart checkout. This
  /// preserves any quantity the shopper added while payment was in progress.
  void consume(List<CartItem> purchasedItems) {
    for (final purchased in purchasedItems) {
      final i = _items.indexWhere((e) => e.productId == purchased.productId);
      if (i < 0) continue;
      final remaining = _items[i].sqFt - purchased.sqFt;
      if (remaining <= 0) {
        _items.removeAt(i);
      } else {
        _items[i] = _items[i].copyWith(sqFt: remaining);
      }
    }
    _persist();
    notifyListeners();
  }

  void remove(String productId) {
    _items.removeWhere((e) => e.productId == productId);
    _persist();
    notifyListeners();
  }

  void clear() {
    _items = [];
    _coupon = null;
    _persist();
    notifyListeners();
  }

  void applyCoupon(Coupon? coupon) {
    _coupon = coupon;
    notifyListeners();
  }

  // ---- pricing -----------------------------------------------------------

  double lineTotal(CartItem item) {
    final p = _cache[item.productId];
    return (p?.pricePerSqFt ?? 0) * item.sqFt;
  }

  double lineSavings(CartItem item) {
    final p = _cache[item.productId];
    if (p == null) return 0;
    return p.savingsPerSqFt * item.sqFt;
  }

  double get subtotal =>
      _items.fold(0.0, (sum, item) => sum + lineTotal(item));

  double get savings => _items.fold(0.0, (sum, item) => sum + lineSavings(item));

  double get couponDiscount => _coupon?.discountFor(subtotal) ?? 0;

  double get deliveryFee => subtotal <= 0
      ? 0
      : (subtotal >= AppConfig.freeDeliveryAbove ? 0 : AppConfig.deliveryCharge);

  double get taxableAmount => (subtotal - couponDiscount).clamp(0, double.infinity);

  double get tax => taxableAmount * AppConfig.gstPercent / 100;

  double get total => taxableAmount + deliveryFee + tax;

  double get totalSqFt => _items.fold(0.0, (s, i) => s + i.sqFt);

  bool _validQuantity(Product product, double quantity) =>
      quantity.isFinite && quantity > 0 && quantity <= product.stock;

  void _persist() =>
      _store.write(StoreKeys.cart, _items.map((e) => e.toJson()).toList());
}
