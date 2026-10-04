import 'package:flutter/material.dart';

import '../../core/theme/glossy_surface.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/state/cart_controller.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/quantity_stepper.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/cart_item.dart';
import '../checkout/widgets/price_breakdown.dart';

class CartScreen extends StatelessWidget {
  const CartScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !embedded,
        title: Observer(
          listenable: deps.cart,
          builder: (context, cart) =>
              Text(cart.isEmpty ? 'Cart' : 'Cart (${cart.count})'),
        ),
        actions: [
          IconButton(
            onPressed: () => Navigator.pushNamed(context, Routes.wishlist),
            icon: const Icon(Icons.favorite_border_rounded),
          ),
        ],
      ),
      body: Observer(
        listenable: deps.cart,
        builder: (context, cart) {
          if (cart.isEmpty) {
            return EmptyView(
              icon: Icons.shopping_cart_outlined,
              title: 'Your cart is empty',
              message:
                  'Browse the catalog and add the marble you need. Quantities are in square feet.',
              actionLabel: 'Browse marble',
              onAction: () => Navigator.pushNamed(
                context,
                Routes.catalog,
                arguments: const CatalogArgs(),
              ),
            );
          }
          return ListView(
            padding: const EdgeInsets.only(bottom: AppDimens.xxxl),
            children: [
              for (final item in cart.items) _CartLine(item: item, cart: cart),
              const SizedBox(height: AppDimens.md),
              _CouponRow(cart: cart),
              const SizedBox(height: AppDimens.md),
              PriceBreakdown(
                subtotal: cart.subtotal,
                savings: cart.savings,
                couponDiscount: cart.couponDiscount,
                delivery: cart.deliveryFee,
                tax: cart.tax,
                total: cart.total,
              ),
              const SizedBox(height: AppDimens.lg),
              const _DeliveryPromise(),
            ],
          );
        },
      ),
      bottomNavigationBar: Observer(
        listenable: deps.cart,
        builder: (context, cart) {
          if (cart.isEmpty) return const SizedBox.shrink();
          return SafeArea(
            child: Container(
              padding: const EdgeInsets.all(AppDimens.lg),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: const BorderRadius.vertical(
                  top: Radius.circular(28),
                ),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.ink.withValues(alpha: 0.05),
                    blurRadius: 20,
                  ),
                ],
              ),
              child: Row(
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        Fmt.rupees(cart.total),
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      Text(
                        '${Fmt.sqft(cart.totalSqFt)} total',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                  const SizedBox(width: AppDimens.lg),
                  Expanded(
                    child: GradientButton(
                      label: 'Checkout',
                      icon: Icons.lock_rounded,
                      onPressed: () => Navigator.pushNamed(
                        context,
                        Routes.checkout,
                        arguments: const CheckoutArgs(buyNowProductId: null),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _CartLine extends StatelessWidget {
  const _CartLine({required this.item, required this.cart});

  final CartItem item;
  final CartController cart;

  @override
  Widget build(BuildContext context) {
    final product = cart.product(item.productId);
    final t = Theme.of(context).textTheme;
    if (product == null) {
      return const Padding(
        padding: EdgeInsets.all(AppDimens.lg),
        child: LoadingView(),
      );
    }
    return Dismissible(
      key: ValueKey(item.productId),
      direction: DismissDirection.endToStart,
      background: Container(
        alignment: Alignment.centerRight,
        color: AppColors.danger.withValues(alpha: 0.12),
        padding: const EdgeInsets.only(right: AppDimens.xl),
        child: const Icon(
          Icons.delete_outline_rounded,
          color: AppColors.danger,
        ),
      ),
      onDismissed: (_) {
        cart.remove(item.productId);
        Toast.show(context, '${product.name} removed');
      },
      child: Container(
        margin: const EdgeInsets.fromLTRB(
          AppDimens.lg,
          AppDimens.md,
          AppDimens.lg,
          0,
        ),
        padding: const EdgeInsets.all(AppDimens.md),
        decoration: glossySurface(context),
        child: Column(
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                InkWell(
                  onTap: () => Navigator.pushNamed(
                    context,
                    Routes.productDetails,
                    arguments: ProductArgs(product.id),
                  ),
                  child: AppImage(
                    product.image,
                    width: 76,
                    height: 76,
                    radius: AppDimens.radiusSm,
                  ),
                ),
                const SizedBox(width: AppDimens.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        product.name,
                        style: t.titleSmall,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${product.finish} · ${product.thickness}',
                        style: t.bodySmall,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${Fmt.rupees(product.pricePerSqFt)}/sq.ft',
                        style: t.labelMedium?.copyWith(color: AppColors.deep),
                      ),
                      if (item.addedFrom != CartSource.catalog) ...[
                        const SizedBox(height: 6),
                        TagChip(
                          label: item.addedFrom.label,
                          color: AppColors.deep,
                          dense: true,
                          icon: item.addedFrom == CartSource.calculator
                              ? Icons.calculate_rounded
                              : Icons.view_in_ar_rounded,
                        ),
                      ],
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(Fmt.rupees(cart.lineTotal(item)), style: t.titleSmall),
                    if (cart.lineSavings(item) > 0)
                      Text(
                        'Save ${Fmt.rupees(cart.lineSavings(item))}',
                        style: t.labelSmall?.copyWith(color: AppColors.success),
                      ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: AppDimens.md),
            Row(
              children: [
                QuantityStepper(
                  value: item.sqFt,
                  step: 10,
                  dense: true,
                  onChanged: (v) => cart.updateSqFt(item.productId, v),
                ),
                const Spacer(),
                // Icon-only actions: the row has to survive long product names,
                // wide fonts and large text scales without wrapping.
                IconButton(
                  tooltip: 'Move to wishlist',
                  visualDensity: VisualDensity.compact,
                  onPressed: () {
                    AppScope.read(context).wishlist.toggle(product.id);
                    cart.remove(product.id);
                    Toast.show(context, 'Moved to wishlist');
                  },
                  icon: const Icon(Icons.favorite_border_rounded, size: 19),
                ),
                IconButton(
                  tooltip: 'Remove',
                  visualDensity: VisualDensity.compact,
                  color: AppColors.danger,
                  onPressed: () => cart.remove(product.id),
                  icon: const Icon(Icons.delete_outline_rounded, size: 19),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _CouponRow extends StatelessWidget {
  const _CouponRow({required this.cart});

  final CartController cart;

  @override
  Widget build(BuildContext context) {
    final applied = cart.coupon;
    return Padding(
      padding: AppDimens.screenPad,
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: () async {
          final result = await Navigator.pushNamed(context, Routes.coupons);
          if (result is String && context.mounted) {
            final coupon = await AppScope.read(
              context,
            ).promos.couponByCode(result);
            if (!context.mounted) return;
            if (coupon == null || !coupon.isApplicable(cart.subtotal)) {
              Toast.error(context, 'This coupon does not apply to your cart');
            } else {
              cart.applyCoupon(coupon);
              Toast.success(context, '${coupon.code} applied');
            }
          }
        },
        child: Container(
          padding: const EdgeInsets.all(AppDimens.md),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            border: Border.all(
              color: applied == null ? AppColors.line : AppColors.success,
            ),
          ),
          child: Row(
            children: [
              Icon(
                Icons.local_offer_rounded,
                size: 19,
                color: applied == null ? AppColors.gold : AppColors.success,
              ),
              const SizedBox(width: AppDimens.md),
              Expanded(
                child: Text(
                  applied == null
                      ? 'Apply a coupon'
                      : '${applied.code} applied · ${Fmt.rupees(cart.couponDiscount)} off',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              if (applied != null)
                TextButton(
                  onPressed: () => cart.applyCoupon(null),
                  child: const Text('Remove'),
                )
              else
                const Icon(Icons.chevron_right_rounded),
            ],
          ),
        ),
      ),
    );
  }
}

class _DeliveryPromise extends StatelessWidget {
  const _DeliveryPromise();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.all(AppDimens.md),
        decoration: BoxDecoration(
          color: AppColors.ice.withValues(alpha: 0.45),
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        ),
        child: Row(
          children: [
            const Icon(
              Icons.local_shipping_rounded,
              size: 20,
              color: AppColors.deep,
            ),
            const SizedBox(width: AppDimens.md),
            Expanded(
              child: Text(
                'Slabs are crated and edge-protected. Site delivery in 5-9 days, unloading assistance included.',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.inkSoft,
                  height: 1.35,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
