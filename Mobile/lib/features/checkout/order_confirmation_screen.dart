import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/order.dart';

class OrderConfirmationScreen extends StatefulWidget {
  const OrderConfirmationScreen({super.key, required this.args});

  final OrderArgs args;

  @override
  State<OrderConfirmationScreen> createState() =>
      _OrderConfirmationScreenState();
}

class _OrderConfirmationScreenState extends State<OrderConfirmationScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..forward();

  late Future<Order?> _future =
      AppScope.read(context).orders.byId(widget.args.orderId);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) {
          Navigator.pushNamedAndRemoveUntil(
              context, Routes.shell, (route) => false);
        }
      },
      child: Scaffold(
        body: FutureBuilder<Order?>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const LoadingView(label: 'Confirming your order…');
            }
            final order = snap.data;
            if (order == null) {
              return ErrorView(
                onRetry: () => setState(() => _future =
                    AppScope.read(context).orders.byId(widget.args.orderId)),
                title: 'Order not found',
              );
            }
            return ListView(
              padding: const EdgeInsets.all(AppDimens.xl),
              children: [
                const SizedBox(height: AppDimens.xl),
                ScaleTransition(
                  scale: CurvedAnimation(
                      parent: _c, curve: Curves.easeOutBack),
                  child: Center(
                    child: Container(
                      width: 96,
                      height: 96,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: AppColors.brandGradient,
                      ),
                      child: const Icon(Icons.check_rounded,
                          size: 52, color: Colors.white),
                    ),
                  ),
                ),
                const SizedBox(height: AppDimens.xl),
                Text('Order placed',
                    textAlign: TextAlign.center, style: t.displaySmall),
                const SizedBox(height: AppDimens.sm),
                Text(
                  'Order ${order.id} · ${Fmt.rupees(order.total)}',
                  textAlign: TextAlign.center,
                  style: t.bodyLarge?.copyWith(color: AppColors.muted),
                ),
                const SizedBox(height: AppDimens.xxl),
                Container(
                  padding: const EdgeInsets.all(AppDimens.lg),
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.surface,
                    borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _row(context, Icons.event_available_rounded,
                          'Expected delivery', Fmt.date(order.expectedDelivery)),
                      const Divider(height: AppDimens.xl),
                      _row(context, Icons.place_outlined, 'Delivering to',
                          order.address.formatted),
                      const Divider(height: AppDimens.xl),
                      _row(context, Icons.payments_outlined, 'Payment',
                          order.paymentMethod.label),
                      const Divider(height: AppDimens.xl),
                      _row(context, Icons.inventory_2_outlined, 'Items',
                          '${Fmt.plural(order.itemCount, 'product')} · ${Fmt.sqft(order.totalSqFt)}'),
                    ],
                  ),
                ),
                const SizedBox(height: AppDimens.xl),
                GradientButton(
                  label: 'Track order',
                  icon: Icons.local_shipping_rounded,
                  onPressed: () => Navigator.pushNamed(
                      context, Routes.trackOrder,
                      arguments: OrderArgs(order.id)),
                ),
                const SizedBox(height: AppDimens.md),
                OutlinedButton(
                  onPressed: () => Navigator.pushNamedAndRemoveUntil(
                      context, Routes.shell, (route) => false),
                  child: const Text('Continue shopping'),
                ),
                const SizedBox(height: AppDimens.xl),
                Text(
                  'An invoice has been generated (${order.invoiceNo}). '
                  'Our co-ordinator will call before dispatch to confirm site access.',
                  textAlign: TextAlign.center,
                  style: t.bodySmall,
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _row(BuildContext context, IconData icon, String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 19, color: AppColors.deep),
        const SizedBox(width: AppDimens.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: Theme.of(context).textTheme.labelSmall),
              const SizedBox(height: 2),
              Text(value,
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w600, height: 1.4)),
            ],
          ),
        ),
      ],
    );
  }
}
