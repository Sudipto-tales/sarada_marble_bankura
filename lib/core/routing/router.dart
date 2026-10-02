import 'package:flutter/material.dart';

import '../../features/account/account_screen.dart';
import '../../features/account/profile_edit_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/calculator/screens/calculator_screen.dart';
import '../../features/cart/cart_screen.dart';
import '../../features/catalog/catalog_screen.dart';
import '../../features/checkout/address_screens.dart';
import '../../features/checkout/checkout_screen.dart';
import '../../features/checkout/order_confirmation_screen.dart';
import '../../features/checkout/payment_screen.dart';
import '../../features/compare/compare_screen.dart';
import '../../features/orders/order_details_screen.dart';
import '../../features/orders/orders_screen.dart';
import '../../features/orders/return_request_screen.dart';
import '../../features/product/product_details_screen.dart';
import '../../features/product/reviews_screen.dart';
import '../../features/product/widgets/product_gallery.dart';
import '../../features/search/search_screen.dart';
import '../../features/shell/app_shell.dart';
import '../../features/shell/splash_screen.dart';
import '../../features/support/coupons_screen.dart';
import '../../features/support/help_screen.dart';
import '../../features/support/notifications_screen.dart';
import '../../features/support/request_screens.dart';
import '../../features/visualization/room_customizer/room_customizer_screen.dart';
import '../../features/visualization/saved_designs_screen.dart';
import '../../features/visualization/visualizer_entry_screen.dart';
import '../../features/wishlist/wishlist_screen.dart';
import '../config/feature_flags.dart';
import 'routes.dart';

/// Central route table. Optional modules are guarded by [FeatureFlags] here,
/// so switching one off cannot leave a dangling route.
class AppRouter {
  const AppRouter._();

  static Route<dynamic> generate(RouteSettings settings) {
    final args = settings.arguments;
    final builder = _builderFor(settings.name, args);
    return MaterialPageRoute(builder: (_) => builder, settings: settings);
  }

  static Widget _builderFor(String? name, Object? args) {
    switch (name) {
      case Routes.splash:
        return const SplashScreen();
      case Routes.shell:
        return const AppShell();

      // ---- catalog -------------------------------------------------------
      case Routes.catalog:
        return CatalogScreen(args: args is CatalogArgs ? args : null);
      case Routes.search:
        return SearchScreen(
            initialQuery: args is String ? args : '');
      case Routes.productDetails:
        if (args is! ProductArgs) return const _NotFound(name: 'product');
        return ProductDetailsScreen(args: args);
      case Routes.gallery:
        if (args is! GalleryArgs) return const _NotFound(name: 'gallery');
        return GalleryScreen(args: args);
      case Routes.reviews:
        if (args is! ReviewArgs) return const _NotFound(name: 'reviews');
        return ReviewsScreen(args: args);
      case Routes.writeReview:
        if (args is! ReviewArgs) return const _NotFound(name: 'review');
        return WriteReviewScreen(args: args);
      case Routes.compare:
        return FeatureFlags.compareEnabled
            ? const CompareScreen()
            : const _ModuleDisabled(module: 'Product comparison');

      // ---- cart & checkout ----------------------------------------------
      case Routes.cart:
        return const CartScreen();
      case Routes.wishlist:
        return const WishlistScreen();
      case Routes.coupons:
        return const CouponsScreen();
      case Routes.checkout:
        return CheckoutScreen(
            args: args is CheckoutArgs
                ? args
                : const CheckoutArgs(buyNowProductId: null));
      case Routes.payment:
        if (args is! PaymentArgs) return const _NotFound(name: 'payment');
        return PaymentScreen(args: args);
      case Routes.orderConfirmation:
        if (args is! OrderArgs) return const _NotFound(name: 'confirmation');
        return OrderConfirmationScreen(args: args);

      // ---- account -------------------------------------------------------
      case Routes.login:
        return const LoginScreen();
      case Routes.register:
        return const RegisterScreen();
      case Routes.account:
        return const AccountScreen();
      case Routes.profileEdit:
        return const ProfileEditScreen();
      case Routes.addressList:
        return const AddressListScreen();
      case Routes.addressForm:
        return AddressFormScreen(
            args: args is AddressFormArgs ? args : const AddressFormArgs());

      // ---- orders --------------------------------------------------------
      case Routes.orders:
        return const OrdersScreen();
      case Routes.orderDetails:
        if (args is! OrderArgs) return const _NotFound(name: 'order');
        return OrderDetailsScreen(args: args);
      case Routes.trackOrder:
        if (args is! OrderArgs) return const _NotFound(name: 'tracking');
        return OrderDetailsScreen(args: args, trackingOnly: true);
      case Routes.returnRequest:
        if (args is! OrderArgs) return const _NotFound(name: 'return');
        return ReturnRequestScreen(args: args);

      // ---- support -------------------------------------------------------
      case Routes.notifications:
        return const NotificationsScreen();
      case Routes.help:
        return const HelpScreen();
      case Routes.about:
        return const AboutScreen();
      case Routes.requestSample:
        return RequestSampleScreen(productId: args is String ? args : null);
      case Routes.requestQuote:
        return const RequestQuoteScreen();

      // ---- optional modules ---------------------------------------------
      case Routes.visualizer:
      case Routes.roomPicker:
        if (!FeatureFlags.visualizationEnabled) {
          return const _ModuleDisabled(module: '3D room preview');
        }
        final v = args is VisualizerArgs ? args : const VisualizerArgs();
        return v.roomId == null
            ? VisualizerEntryScreen(productId: v.productId)
            : RoomCustomizerScreen(args: v);
      case Routes.savedDesigns:
        return FeatureFlags.visualizationEnabled
            ? const SavedDesignsScreen()
            : const _ModuleDisabled(module: '3D room preview');
      case Routes.calculator:
        if (!FeatureFlags.calculatorEnabled) {
          return const _ModuleDisabled(module: 'Cost calculator');
        }
        return CalculatorScreen(
            args: args is CalculatorArgs ? args : const CalculatorArgs());

      default:
        return _NotFound(name: name ?? 'unknown');
    }
  }
}

class _NotFound extends StatelessWidget {
  const _NotFound({required this.name});
  final String name;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              'This screen could not be opened ($name).',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyLarge,
            ),
          ),
        ),
      );
}

class _ModuleDisabled extends StatelessWidget {
  const _ModuleDisabled({required this.module});
  final String module;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              '$module is switched off in this build.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyLarge,
            ),
          ),
        ),
      );
}
