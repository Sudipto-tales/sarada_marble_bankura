/// Route names in one place. Screens are pushed by name with typed argument
/// objects so no screen constructs another screen's dependencies.
class Routes {
  const Routes._();

  static const String splash = '/';
  static const String shell = '/shell';

  static const String catalog = '/catalog';
  static const String brandCatalogs = '/brand-catalogs';
  static const String brandCatalogViewer = '/brand-catalogs/view';
  static const String search = '/search';
  static const String productDetails = '/product';
  static const String gallery = '/gallery';
  static const String compare = '/compare';
  static const String reviews = '/reviews';
  static const String writeReview = '/reviews/write';

  static const String cart = '/cart';
  static const String wishlist = '/wishlist';
  static const String coupons = '/coupons';

  static const String login = '/login';
  static const String register = '/register';

  static const String addressList = '/addresses';
  static const String addressForm = '/addresses/form';
  static const String checkout = '/checkout';
  static const String payment = '/payment';
  static const String orderConfirmation = '/order/confirmation';

  static const String orders = '/orders';
  static const String orderDetails = '/order';
  static const String trackOrder = '/order/track';
  static const String returnRequest = '/order/return';

  static const String appearance = '/settings/appearance';
  static const String account = '/account';
  static const String profileEdit = '/account/profile';
  static const String notifications = '/notifications';
  static const String help = '/help';
  static const String about = '/about';
  static const String savedDesigns = '/designs';
  static const String requestSample = '/request/sample';
  static const String requestQuote = '/request/quote';

  // Optional modules — guarded by FeatureFlags.
  static const String visualizer = '/visualizer';
  static const String roomPicker = '/visualizer/rooms';
  static const String calculator = '/calculator';
}

/// Typed arguments.
class ProductArgs {
  const ProductArgs(this.productId, {this.heroTag});
  final String productId;
  final String? heroTag;
}

class CatalogArgs {
  const CatalogArgs({
    this.categoryId,
    this.title,
    this.query,
    this.onlyOffers = false,
  });
  final String? categoryId;
  final String? title;
  final String? query;
  final bool onlyOffers;
}

class GalleryArgs {
  const GalleryArgs({required this.images, this.initialIndex = 0, this.title});
  final List<String> images;
  final int initialIndex;
  final String? title;
}

class VisualizerArgs {
  const VisualizerArgs({
    this.roomId,
    this.productId,
    this.designId,
    this.initialMode,
    this.photoPng,
  });
  final String? designId;
  final String? roomId;
  final String? productId;
  final String? initialMode;
  final String? photoPng;
}

class CalculatorArgs {
  const CalculatorArgs({this.productId});
  final String? productId;
}

class CheckoutArgs {
  const CheckoutArgs({required this.buyNowProductId, this.buyNowSqFt});
  final String? buyNowProductId;
  final double? buyNowSqFt;
}

class OrderArgs {
  const OrderArgs(this.orderId);
  final String orderId;
}

class AddressFormArgs {
  const AddressFormArgs({this.addressId});
  final String? addressId;
}

class ReviewArgs {
  const ReviewArgs(this.productId);
  final String productId;
}

class BrandCatalogArgs {
  const BrandCatalogArgs({this.brandId, this.brandName});
  final String? brandId;
  final String? brandName;
}

class BrandCatalogViewerArgs {
  const BrandCatalogViewerArgs(this.catalogId);
  final String catalogId;
}
