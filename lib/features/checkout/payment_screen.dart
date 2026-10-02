import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../data/models/order.dart';
import 'checkout_screen.dart';

/// Simulated payment. No gateway, no card data, no network — the method is
/// recorded on the order and the flow continues.
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, required this.args});

  final PaymentArgs args;

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  PaymentMethod _method = PaymentMethod.upi;
  bool _placing = false;

  static const _subtitles = {
    PaymentMethod.upi: 'GPay, PhonePe, Paytm — pay on delivery confirmation',
    PaymentMethod.card: 'Visa, Mastercard, RuPay',
    PaymentMethod.netBanking: 'All major banks',
    PaymentMethod.emi: '3 / 6 / 9 months, from ₹4,200 per month',
    PaymentMethod.cod: 'Pay the crew when the slabs arrive',
  };

  static const _icons = {
    PaymentMethod.upi: Icons.qr_code_2_rounded,
    PaymentMethod.card: Icons.credit_card_rounded,
    PaymentMethod.netBanking: Icons.account_balance_rounded,
    PaymentMethod.emi: Icons.calendar_month_rounded,
    PaymentMethod.cod: Icons.payments_outlined,
  };

  Future<void> _placeOrder() async {
    setState(() => _placing = true);
    final deps = AppScope.read(context);
    final cart = deps.cart;
    final items = widget.args.items;

    final subtotal = items.fold(0.0, (s, i) => s + cart.lineTotal(i));
    final discount = cart.couponDiscount;
    final delivery =
        (subtotal >= AppConfig.freeDeliveryAbove ? 0.0 : AppConfig.deliveryCharge) +
            widget.args.deliveryExtra;
    final tax = (subtotal - discount) * AppConfig.gstPercent / 100;
    final now = DateTime.now();
    final id = 'MS${now.millisecondsSinceEpoch.toString().substring(6)}';

    final order = Order(
      id: id,
      items: items,
      address: widget.args.address,
      placedOn: now,
      status: OrderStatus.placed,
      timeline: [
        OrderEvent(
          status: OrderStatus.placed,
          at: now,
          note: 'Order placed successfully',
          location: 'Online',
        ),
      ],
      subtotal: subtotal,
      discount: discount,
      deliveryFee: delivery,
      tax: tax,
      paymentMethod: _method,
      expectedDelivery: now.add(Duration(
          days: widget.args.slotLabel == 'Express' ? 4 : 8)),
      couponCode: cart.coupon?.code,
      invoiceNo: 'INV-$id',
    );

    await deps.orders.place(order);
    for (final item in items) {
      cart.remove(item.productId);
    }
    cart.applyCoupon(null);

    if (!mounted) return;
    setState(() => _placing = false);
    Navigator.pushNamedAndRemoveUntil(
      context,
      Routes.orderConfirmation,
      (route) => route.settings.name == Routes.shell,
      arguments: OrderArgs(order.id),
    );
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final cart = deps.cart;
    final subtotal =
        widget.args.items.fold(0.0, (s, i) => s + cart.lineTotal(i));
    final delivery =
        (subtotal >= AppConfig.freeDeliveryAbove ? 0.0 : AppConfig.deliveryCharge) +
            widget.args.deliveryExtra;
    final tax = (subtotal - cart.couponDiscount) * AppConfig.gstPercent / 100;
    final total = subtotal - cart.couponDiscount + delivery + tax;

    return Scaffold(
      appBar: AppBar(title: const Text('Payment')),
      body: ListView(
        padding: const EdgeInsets.all(AppDimens.lg),
        children: [
          Container(
            padding: const EdgeInsets.all(AppDimens.md),
            decoration: BoxDecoration(
              color: AppColors.ice.withValues(alpha: 0.45),
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            ),
            child: Row(
              children: [
                const Icon(Icons.info_outline_rounded,
                    size: 18, color: AppColors.deep),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Prototype build: payment is simulated. No card details are '
                    'collected and no gateway is contacted.',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: AppColors.inkSoft, height: 1.4),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppDimens.lg),
          Text('Choose a payment method',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppDimens.md),
          for (final method in PaymentMethod.values)
            Container(
              margin: const EdgeInsets.only(bottom: AppDimens.sm),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                border: Border.all(
                  color: _method == method ? AppColors.teal : AppColors.line,
                  width: _method == method ? 1.8 : 1,
                ),
              ),
              child: ListTile(
                onTap: () => setState(() => _method = method),
                leading: Icon(_icons[method], color: AppColors.deep),
                title: Text(method.label,
                    style: Theme.of(context).textTheme.titleSmall),
                subtitle: Text(_subtitles[method] ?? '',
                    style: Theme.of(context).textTheme.bodySmall),
                trailing: Icon(
                  _method == method
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_off_rounded,
                  color:
                      _method == method ? AppColors.teal : AppColors.mutedSoft,
                  size: 20,
                ),
              ),
            ),
          const SizedBox(height: AppDimens.lg),
          Row(
            children: [
              const Icon(Icons.lock_rounded, size: 15, color: AppColors.success),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Your order is protected by our damage-in-transit cover.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ],
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Container(
          padding: const EdgeInsets.all(AppDimens.lg),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            border: const Border(top: BorderSide(color: AppColors.line)),
          ),
          child: Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(Fmt.rupees(total),
                      style: Theme.of(context).textTheme.titleLarge),
                  Text('incl. GST', style: Theme.of(context).textTheme.labelSmall),
                ],
              ),
              const SizedBox(width: AppDimens.lg),
              Expanded(
                child: GradientButton(
                  label: _method == PaymentMethod.cod
                      ? 'Place order'
                      : 'Pay & place order',
                  icon: Icons.check_circle_outline_rounded,
                  busy: _placing,
                  onPressed: _placing ? null : _placeOrder,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Small helper so other screens can show a "payment failed" style message
/// without duplicating copy.
void showPaymentError(BuildContext context) =>
    Toast.error(context, 'Payment could not be completed. Try another method.');
