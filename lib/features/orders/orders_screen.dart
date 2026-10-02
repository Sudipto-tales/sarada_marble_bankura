import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/order.dart';
import '../../data/models/product.dart';

class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  late Future<List<Order>> _future = AppScope.read(context).orders.all();
  String _filter = 'All';

  static const _filters = ['All', 'Active', 'Delivered', 'Cancelled'];

  bool _matches(Order o) => switch (_filter) {
        'Active' => !o.status.isTerminal,
        'Delivered' => o.status == OrderStatus.delivered,
        'Cancelled' => o.status == OrderStatus.cancelled ||
            o.status == OrderStatus.returned,
        _ => true,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('My orders')),
      body: Column(
        children: [
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(
                  horizontal: AppDimens.lg, vertical: AppDimens.sm),
              children: [
                for (final f in _filters)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(f),
                      selected: _filter == f,
                      onSelected: (_) => setState(() => _filter = f),
                    ),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: FutureBuilder<List<Order>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const LoadingView();
                }
                if (snap.hasError) {
                  return ErrorView(
                    onRetry: () => setState(() =>
                        _future = AppScope.read(context).orders.all()),
                  );
                }
                final orders =
                    (snap.data ?? const <Order>[]).where(_matches).toList();
                if (orders.isEmpty) {
                  return EmptyView(
                    icon: Icons.receipt_long_outlined,
                    title: 'No orders here',
                    message: _filter == 'All'
                        ? 'Once you place an order it shows up here with live tracking.'
                        : 'No $_filter orders yet.',
                    actionLabel: 'Browse marble',
                    onAction: () => Navigator.pushNamed(context, Routes.catalog,
                        arguments: const CatalogArgs()),
                  );
                }
                return RefreshIndicator(
                  onRefresh: () async {
                    setState(
                        () => _future = AppScope.read(context).orders.all());
                    await _future;
                  },
                  child: ListView.builder(
                    padding: const EdgeInsets.only(bottom: AppDimens.xl),
                    itemCount: orders.length,
                    itemBuilder: (context, i) => OrderCard(order: orders[i]),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class OrderCard extends StatelessWidget {
  const OrderCard({super.key, required this.order});

  final Order order;

  static Color statusColor(OrderStatus status) => switch (status) {
        OrderStatus.delivered => AppColors.success,
        OrderStatus.cancelled => AppColors.danger,
        OrderStatus.returned => AppColors.warning,
        _ => AppColors.deep,
      };

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final t = Theme.of(context).textTheme;
    return Container(
      margin: const EdgeInsets.fromLTRB(
          AppDimens.lg, AppDimens.md, AppDimens.lg, 0),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        border: Border.all(color: AppColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: () => Navigator.pushNamed(context, Routes.orderDetails,
            arguments: OrderArgs(order.id)),
        child: Padding(
          padding: const EdgeInsets.all(AppDimens.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  TagChip(
                    label: order.status.label.toUpperCase(),
                    color: statusColor(order.status),
                    dense: true,
                  ),
                  const Spacer(),
                  Text(Fmt.date(order.placedOn), style: t.labelSmall),
                ],
              ),
              const SizedBox(height: AppDimens.md),
              FutureBuilder<List<Product>>(
                future: deps.products
                    .byIds(order.items.map((e) => e.productId).toList()),
                builder: (context, snap) {
                  final products = snap.data ?? const <Product>[];
                  return Row(
                    children: [
                      SizedBox(
                        width: 58,
                        height: 58,
                        child: products.isEmpty
                            ? Container(color: AppColors.line)
                            : AppImage(products.first.image,
                                radius: AppDimens.radiusSm),
                      ),
                      const SizedBox(width: AppDimens.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              products.isEmpty
                                  ? 'Order ${order.id}'
                                  : products.map((p) => p.name).join(', '),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: t.titleSmall,
                            ),
                            const SizedBox(height: 3),
                            Text(
                              '${order.id} · ${Fmt.sqft(order.totalSqFt)}',
                              style: t.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      Text(Fmt.rupees(order.total), style: t.titleSmall),
                    ],
                  );
                },
              ),
              const Divider(height: AppDimens.xl),
              Row(
                children: [
                  Icon(
                    order.status == OrderStatus.delivered
                        ? Icons.check_circle_outline_rounded
                        : Icons.local_shipping_outlined,
                    size: 16,
                    color: statusColor(order.status),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      order.status == OrderStatus.delivered
                          ? 'Delivered on ${Fmt.date(order.expectedDelivery)}'
                          : order.status == OrderStatus.cancelled
                              ? 'Cancelled'
                              : 'Arriving by ${Fmt.date(order.expectedDelivery)}',
                      style: t.bodySmall,
                    ),
                  ),
                  if (!order.status.isTerminal)
                    TextButton(
                      onPressed: () => Navigator.pushNamed(
                          context, Routes.trackOrder,
                          arguments: OrderArgs(order.id)),
                      child: const Text('Track'),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
