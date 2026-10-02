import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/cart_item.dart';
import '../../data/models/order.dart';
import '../../data/models/product.dart';
import '../checkout/widgets/price_breakdown.dart';
import 'orders_screen.dart';
import 'widgets/tracking_timeline.dart';

class OrderDetailsScreen extends StatefulWidget {
  const OrderDetailsScreen({
    super.key,
    required this.args,
    this.trackingOnly = false,
  });

  final OrderArgs args;
  final bool trackingOnly;

  @override
  State<OrderDetailsScreen> createState() => _OrderDetailsScreenState();
}

class _OrderDetailsScreenState extends State<OrderDetailsScreen> {
  late Future<Order?> _future = _load();

  Future<Order?> _load() =>
      AppScope.read(context).orders.byId(widget.args.orderId);

  void _reload() => setState(() => _future = _load());

  Future<void> _cancel(Order order) async {
    final ok = await confirmDialog(
      context,
      title: 'Cancel this order?',
      message:
          'Order ${order.id} will be cancelled and any payment refunded to the source account.',
      confirmLabel: 'Cancel order',
      cancelLabel: 'Keep order',
      destructive: true,
    );
    if (!ok || !mounted) return;
    await AppScope.read(context).orders.cancel(order.id);
    if (!mounted) return;
    Toast.success(context, 'Order cancelled');
    _reload();
  }

  Future<void> _reorder(Order order) async {
    final deps = AppScope.read(context);
    final products =
        await deps.products.byIds(order.items.map((e) => e.productId).toList());
    final byId = {for (final p in products) p.id: p};
    for (final item in order.items) {
      final product = byId[item.productId];
      if (product == null) continue;
      await deps.cart.setQuantity(product, item.sqFt,
          source: CartSource.reorder);
    }
    if (!mounted) return;
    Toast.success(context, 'Items added to cart',
        actionLabel: 'View cart',
        onAction: () => Navigator.pushNamed(context, Routes.cart));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.trackingOnly ? 'Track order' : 'Order details'),
      ),
      body: FutureBuilder<Order?>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const LoadingView();
          }
          if (snap.hasError || snap.data == null) {
            return ErrorView(onRetry: _reload, title: 'Order not found');
          }
          final order = snap.data!;
          return ListView(
            padding: const EdgeInsets.only(bottom: AppDimens.xxxl),
            children: [
              Padding(
                padding: const EdgeInsets.all(AppDimens.lg),
                child: Row(
                  children: [
                    TagChip(
                      label: order.status.label.toUpperCase(),
                      color: OrderCard.statusColor(order.status),
                    ),
                    const Spacer(),
                    Text('Placed ${Fmt.date(order.placedOn)}',
                        style: Theme.of(context).textTheme.labelSmall),
                  ],
                ),
              ),
              TrackingTimeline(order: order),
              const SizedBox(height: AppDimens.lg),
              const SectionHeader(title: 'Items'),
              _Items(order: order),
              const SizedBox(height: AppDimens.md),
              PriceBreakdown(
                subtotal: order.subtotal,
                savings: 0,
                couponDiscount: order.discount,
                delivery: order.deliveryFee,
                tax: order.tax,
                total: order.total,
                title: 'Payment summary',
              ),
              const SizedBox(height: AppDimens.lg),
              Padding(
                padding: AppDimens.screenPad,
                child: Container(
                  padding: const EdgeInsets.all(AppDimens.md),
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.surface,
                    borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Delivery address',
                          style: Theme.of(context).textTheme.labelSmall),
                      const SizedBox(height: 4),
                      Text(order.address.name,
                          style: Theme.of(context).textTheme.titleSmall),
                      Text(order.address.formatted,
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(height: 1.4)),
                      const Divider(height: AppDimens.xl),
                      Row(
                        children: [
                          const Icon(Icons.receipt_long_outlined,
                              size: 17, color: AppColors.deep),
                          const SizedBox(width: 8),
                          Text('Invoice ${order.invoiceNo}',
                              style: Theme.of(context).textTheme.bodyMedium),
                          const Spacer(),
                          TextButton(
                            onPressed: () => Toast.show(context,
                                'Invoice download is disabled in this prototype'),
                            child: const Text('Download'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppDimens.lg),
              Padding(
                padding: AppDimens.screenPad,
                child: Column(
                  children: [
                    if (order.isCancellable)
                      OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppColors.danger,
                          side: BorderSide(
                              color: AppColors.danger.withValues(alpha: 0.4)),
                        ),
                        onPressed: () => _cancel(order),
                        icon: const Icon(Icons.close_rounded, size: 18),
                        label: const Text('Cancel order'),
                      ),
                    if (order.isReturnable) ...[
                      OutlinedButton.icon(
                        onPressed: () async {
                          await Navigator.pushNamed(
                              context, Routes.returnRequest,
                              arguments: OrderArgs(order.id));
                          _reload();
                        },
                        icon: const Icon(Icons.assignment_return_outlined,
                            size: 18),
                        label: const Text('Request return or replacement'),
                      ),
                      const SizedBox(height: AppDimens.sm),
                    ],
                    const SizedBox(height: AppDimens.sm),
                    GradientButton(
                      label: 'Reorder',
                      icon: Icons.replay_rounded,
                      onPressed: () => _reorder(order),
                    ),
                    const SizedBox(height: AppDimens.sm),
                    TextButton.icon(
                      onPressed: () => Navigator.pushNamed(context, Routes.help),
                      icon: const Icon(Icons.support_agent_rounded, size: 18),
                      label: const Text('Need help with this order?'),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Items extends StatelessWidget {
  const _Items({required this.order});

  final Order order;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return FutureBuilder<List<Product>>(
      future: deps.products.byIds(order.items.map((e) => e.productId).toList()),
      builder: (context, snap) {
        final byId = {for (final p in snap.data ?? const <Product>[]) p.id: p};
        return Column(
          children: [
            for (final item in order.items)
              Builder(builder: (context) {
                final product = byId[item.productId];
                if (product == null) {
                  return const Padding(
                    padding: EdgeInsets.all(AppDimens.lg),
                    child: LoadingView(),
                  );
                }
                return ListTile(
                  onTap: () => Navigator.pushNamed(
                      context, Routes.productDetails,
                      arguments: ProductArgs(product.id)),
                  leading: AppImage(product.image,
                      width: 52, height: 52, radius: AppDimens.radiusSm),
                  title: Text(product.name,
                      style: Theme.of(context).textTheme.titleSmall),
                  subtitle: Text(
                    '${Fmt.sqft(item.sqFt)} · ${item.addedFrom.label}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  trailing: Text(
                    Fmt.rupees(product.pricePerSqFt * item.sqFt),
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                );
              }),
          ],
        );
      },
    );
  }
}
