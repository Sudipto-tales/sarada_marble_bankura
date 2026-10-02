import '../models/app_notification.dart';
import '../models/app_user.dart';
import '../models/address.dart';
import '../models/category.dart';
import '../models/coupon.dart';
import '../models/filters.dart';
import '../models/marble_texture.dart';
import '../models/order.dart';
import '../models/product.dart';
import '../models/review.dart';
import '../models/room.dart';
import '../models/saved_design.dart';

/// Repository contracts. The UI depends only on these; today they are backed by
/// static data, tomorrow by `Api*` implementations. No widget may reach past
/// these interfaces into a data source.

abstract class ProductRepository {
  Future<List<Product>> all();
  Future<Product?> byId(String id);
  Future<List<Product>> byIds(List<String> ids);
  Future<List<Product>> byCategory(String categoryId);
  Future<List<Product>> query(ProductFilter filter);
  Future<List<Product>> featured();
  Future<List<Product>> trending();
  Future<List<Product>> bestSellers();
  Future<List<Product>> similarTo(String productId, {int limit = 8});

  /// Facet values for the filter sheet.
  Future<Map<String, List<String>>> facets();
  Future<(double, double)> priceRange();
}

abstract class CategoryRepository {
  Future<List<Category>> all();
  Future<Category?> byId(String id);
}

abstract class ReviewRepository {
  Future<List<Review>> forProduct(String productId);
  Future<Map<int, int>> ratingBreakdown(String productId);
  Future<void> submit(Review review);
}

abstract class OrderRepository {
  Future<List<Order>> all();
  Future<Order?> byId(String id);
  Future<Order> place(Order order);
  Future<Order> cancel(String id);
  Future<Order> requestReturn(String id, String reason);
}

abstract class UserRepository {
  Future<AppUser?> current();
  Future<AppUser> signIn(String email, String password);
  Future<AppUser> register(String name, String email, String phone, String password);
  Future<void> signOut();
  Future<AppUser> updateProfile(AppUser user);

  Future<List<Address>> addresses();
  Future<Address> saveAddress(Address address);
  Future<void> deleteAddress(String id);
  Future<void> setDefaultAddress(String id);
}

/// Visualization-module data. The e-commerce module never calls this.
abstract class RoomRepository {
  Future<List<Room>> all();
  Future<Room?> byId(String id);
  Future<List<MarbleTexture>> textures();
  Future<MarbleTexture?> texture(String id);

  Future<List<SavedDesign>> savedDesigns();
  Future<SavedDesign> saveDesign(SavedDesign design);
  Future<void> deleteDesign(String id);
}

abstract class PromoRepository {
  Future<List<PromoBanner>> banners();
  Future<List<PromoBanner>> inspiration();
  Future<List<Offer>> offers();
  Future<List<Coupon>> coupons();
  Future<Coupon?> couponByCode(String code);
}

abstract class NotificationRepository {
  Future<List<AppNotification>> all();
  Future<void> markRead(String id);
  Future<void> markAllRead();
}

/// Thrown by repositories so the UI can show a retry state without knowing
/// whether the failure came from a socket, a file or a stub.
class RepositoryException implements Exception {
  const RepositoryException(this.message);
  final String message;
  @override
  String toString() => message;
}
