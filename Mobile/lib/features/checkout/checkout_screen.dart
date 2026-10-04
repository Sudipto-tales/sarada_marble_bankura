import 'package:flutter/material.dart';

import '../../core/theme/glossy_surface.dart';

import '../../core/config/app_config.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/state/cart_controller.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/address.dart';
import '../../data/models/cart_item.dart';
import '../../data/models/product.dart';
import 'widgets/price_breakdown.dart';

/// Buy-now / cart checkout.
///
/// Flow: check login → address → delivery slot → order summary → payment.
/// Payment is a simulated screen; no gateway is integrated in this version.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, required this.args});

  final CheckoutArgs args;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  int _step = 0;
  Address? _address;
  int _slot = 0;
  bool _checkingAuth = true;

  static const _slots = [
    ('Standard', '5-9 days · included', 0.0),
    ('Express', '3-4 days · ₹2,400', 2400.0),
    ('Scheduled', 'Pick a date on call · ₹900', 900.0),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoggedIn());
  }

  /// Buy Now → check login → (login/register) → address.
  Future<void> _ensureLoggedIn() async {
    final session = AppScope.read(context).session;
    if (!session.isLoggedIn) {
      final result = await Navigator.pushNamed(context, Routes.login);
      if (!mounted) return;
      if (result != true && !session.isLoggedIn) {
        Navigator.pop(context);
        return;
      }
    }
    await session.loadAddresses();
    if (!mounted) return;
    setState(() {
      _address = session.defaultAddress;
      _checkingAuth = false;
    });
  }

  List<CartItem> get _items {
    final deps = AppScope.read(context);
    final buyNowId = widget.args.buyNowProductId;
    if (buyNowId == null) return deps.cart.items;
    return deps.cart.items.where((i) => i.productId == buyNowId).toList();
  }

  double get _deliveryExtra => _slots[_slot].$3;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    if (_checkingAuth) {
      return const Scaffold(body: LoadingView(label: 'Preparing checkout…'));
    }
    return Scaffold(
      appBar: AppBar(
        title: const Text('Checkout'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(44),
          child: _StepBar(step: _step),
        ),
      ),
      body: Observer(
        listenable: deps.cart,
        builder: (context, cart) {
          if (_items.isEmpty) {
            return EmptyView(
              icon: Icons.shopping_bag_outlined,
              title: 'Nothing to check out',
              message: 'Your cart is empty.',
              actionLabel: 'Browse marble',
              onAction: () => Navigator.pushReplacementNamed(
                context,
                Routes.catalog,
                arguments: const CatalogArgs(),
              ),
            );
          }
          return switch (_step) {
            0 => _AddressStep(
              selected: _address,
              onSelect: (a) => setState(() => _address = a),
            ),
            1 => _DeliveryStep(
              slots: _slots,
              selected: _slot,
              onSelect: (i) => setState(() => _slot = i),
              address: _address,
            ),
            _ => _SummaryStep(
              items: _items,
              cart: cart,
              address: _address!,
              slotLabel: _slots[_slot].$1,
              deliveryExtra: _deliveryExtra,
            ),
          };
        },
      ),
      bottomNavigationBar: SafeArea(
        child: Observer(
          listenable: deps.cart,
          builder: (context, cart) {
            if (_items.isEmpty) return const SizedBox.shrink();
            final subtotal = _items.fold(
              0.0,
              (sum, item) => sum + cart.lineTotal(item),
            );
            return Container(
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
                  if (_step > 0)
                    IconButton(
                      onPressed: () => setState(() => _step--),
                      icon: const Icon(Icons.arrow_back_rounded),
                    ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          Fmt.rupees(subtotal),
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text(
                          'before tax & delivery',
                          style: Theme.of(context).textTheme.labelSmall,
                        ),
                      ],
                    ),
                  ),
                  SizedBox(
                    width: 190,
                    child: GradientButton(
                      label: _step == 2 ? 'Continue to payment' : 'Continue',
                      icon: _step == 2 ? Icons.lock_rounded : null,
                      onPressed: () => _next(cart, subtotal),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  void _next(CartController cart, double subtotal) {
    if (_step == 0) {
      if (_address == null) {
        Toast.error(context, 'Select a delivery address first');
        return;
      }
      setState(() => _step = 1);
    } else if (_step == 1) {
      setState(() => _step = 2);
    } else {
      Navigator.pushNamed(
        context,
        Routes.payment,
        arguments: PaymentArgs(
          items: _items,
          address: _address!,
          deliveryExtra: _deliveryExtra,
          slotLabel: _slots[_slot].$1,
        ),
      );
    }
  }
}

/// Arguments for the payment screen. Declared here because checkout owns the
/// order-building flow.
class PaymentArgs {
  const PaymentArgs({
    required this.items,
    required this.address,
    required this.deliveryExtra,
    required this.slotLabel,
  });

  final List<CartItem> items;
  final Address address;
  final double deliveryExtra;
  final String slotLabel;
}

class _StepBar extends StatelessWidget {
  const _StepBar({required this.step});

  final int step;

  static const _labels = ['Address', 'Delivery', 'Summary', 'Payment'];

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppDimens.lg,
        0,
        AppDimens.lg,
        AppDimens.md,
      ),
      child: Row(
        children: [
          for (var i = 0; i < _labels.length; i++) ...[
            if (i > 0)
              Expanded(
                child: Container(
                  height: 2,
                  color: i <= step ? AppColors.teal : AppColors.line,
                ),
              ),
            Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 20,
                  height: 20,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: i <= step ? AppColors.teal : AppColors.line,
                  ),
                  child: Icon(
                    i < step ? Icons.check_rounded : Icons.circle,
                    size: i < step ? 13 : 7,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  _labels[i],
                  style: TextStyle(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w600,
                    color: i <= step ? AppColors.deep : AppColors.mutedSoft,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _AddressStep extends StatelessWidget {
  const _AddressStep({required this.selected, required this.onSelect});

  final Address? selected;
  final ValueChanged<Address> onSelect;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Observer(
      listenable: deps.session,
      builder: (context, session) => ListView(
        padding: const EdgeInsets.all(AppDimens.lg),
        children: [
          Text('Deliver to', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppDimens.md),
          if (session.addresses.isEmpty)
            EmptyView(
              compact: true,
              icon: Icons.location_off_outlined,
              title: 'No address yet',
              message: 'Add where the slabs should be delivered.',
              actionLabel: 'Add address',
              onAction: () => Navigator.pushNamed(
                context,
                Routes.addressForm,
                arguments: const AddressFormArgs(),
              ),
            )
          else
            for (final address in session.addresses)
              Container(
                margin: const EdgeInsets.only(bottom: AppDimens.md),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surface,
                  borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                  border: Border.all(
                    color: selected?.id == address.id
                        ? AppColors.teal
                        : AppColors.line,
                    width: selected?.id == address.id ? 1.8 : 1,
                  ),
                ),
                child: RadioGroup<String>(
                  groupValue: selected?.id,
                  onChanged: (_) => onSelect(address),
                  child: Material(
                    color: Colors.transparent,
                    borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                    clipBehavior: Clip.antiAlias,
                    child: ListTile(
                      onTap: () => onSelect(address),
                      leading: Radio<String>(value: address.id),
                      title: Row(
                        children: [
                          Text(
                            address.name,
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          const SizedBox(width: 8),
                          TagChip(label: address.label, dense: true),
                        ],
                      ),
                      subtitle: Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          '${address.formatted}\n${address.phone}',
                          style: Theme.of(
                            context,
                          ).textTheme.bodySmall?.copyWith(height: 1.4),
                        ),
                      ),
                      isThreeLine: true,
                    ),
                  ),
                ),
              ),
          OutlinedButton.icon(
            onPressed: () => Navigator.pushNamed(
              context,
              Routes.addressForm,
              arguments: const AddressFormArgs(),
            ),
            icon: const Icon(Icons.add_rounded, size: 18),
            label: const Text('Add a new address'),
          ),
        ],
      ),
    );
  }
}

class _DeliveryStep extends StatelessWidget {
  const _DeliveryStep({
    required this.slots,
    required this.selected,
    required this.onSelect,
    required this.address,
  });

  final List<(String, String, double)> slots;
  final int selected;
  final ValueChanged<int> onSelect;
  final Address? address;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return ListView(
      padding: const EdgeInsets.all(AppDimens.lg),
      children: [
        Container(
          padding: const EdgeInsets.all(AppDimens.md),
          decoration: BoxDecoration(
            color: AppColors.ice.withValues(alpha: 0.4),
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          ),
          child: Row(
            children: [
              const Icon(Icons.place_outlined, size: 19, color: AppColors.deep),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  address?.formatted ?? '—',
                  style: t.bodySmall?.copyWith(color: AppColors.inkSoft),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppDimens.lg),
        Text('Delivery option', style: t.titleMedium),
        const SizedBox(height: AppDimens.md),
        for (var i = 0; i < slots.length; i++)
          Container(
            margin: const EdgeInsets.only(bottom: AppDimens.sm),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              border: Border.all(
                color: selected == i ? AppColors.teal : AppColors.line,
                width: selected == i ? 1.8 : 1,
              ),
            ),
            child: Material(
              color: Colors.transparent,
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              clipBehavior: Clip.antiAlias,
              child: ListTile(
                onTap: () => onSelect(i),
                leading: Icon(
                  selected == i
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_off_rounded,
                  color: selected == i ? AppColors.teal : AppColors.mutedSoft,
                ),
                title: Text(slots[i].$1, style: t.titleSmall),
                subtitle: Text(slots[i].$2, style: t.bodySmall),
              ),
            ),
          ),
        const SizedBox(height: AppDimens.lg),
        Text('Handling', style: t.titleMedium),
        const SizedBox(height: AppDimens.sm),
        Text(
          'Slabs travel upright in A-frame crates. Our crew assists with '
          'unloading; site access for a 20-foot truck is required. Damage in '
          'transit is covered — report within 48 hours of delivery.',
          style: t.bodyMedium?.copyWith(height: 1.5, color: AppColors.muted),
        ),
      ],
    );
  }
}

class _SummaryStep extends StatelessWidget {
  const _SummaryStep({
    required this.items,
    required this.cart,
    required this.address,
    required this.slotLabel,
    required this.deliveryExtra,
  });

  final List<CartItem> items;
  final CartController cart;
  final Address address;
  final String slotLabel;
  final double deliveryExtra;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final subtotal = items.fold(0.0, (sum, item) => sum + cart.lineTotal(item));
    final savings = items.fold(
      0.0,
      (sum, item) => sum + cart.lineSavings(item),
    );
    final couponDiscount = cart.couponDiscount;
    final delivery =
        (subtotal >= AppConfig.freeDeliveryAbove
            ? 0.0
            : AppConfig.deliveryCharge) +
        deliveryExtra;
    final tax = (subtotal - couponDiscount) * AppConfig.gstPercent / 100;

    return ListView(
      padding: const EdgeInsets.only(bottom: AppDimens.xxl),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppDimens.lg,
            AppDimens.lg,
            AppDimens.lg,
            AppDimens.sm,
          ),
          child: Text('Order summary', style: t.titleMedium),
        ),
        for (final item in items)
          _SummaryLine(item: item, product: cart.product(item.productId)),
        const SizedBox(height: AppDimens.md),
        Padding(
          padding: AppDimens.screenPad,
          child: Container(
            padding: const EdgeInsets.all(AppDimens.md),
            decoration: glossySurface(context),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Delivering to', style: t.labelSmall),
                const SizedBox(height: 4),
                Text(address.name, style: t.titleSmall),
                Text(
                  address.formatted,
                  style: t.bodySmall?.copyWith(height: 1.4),
                ),
                const Divider(height: AppDimens.xl),
                Row(
                  children: [
                    const Icon(
                      Icons.local_shipping_outlined,
                      size: 17,
                      color: AppColors.deep,
                    ),
                    const SizedBox(width: 8),
                    Text('$slotLabel delivery', style: t.titleSmall),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: AppDimens.md),
        PriceBreakdown(
          subtotal: subtotal,
          savings: savings,
          couponDiscount: couponDiscount,
          delivery: delivery,
          tax: tax,
          total: subtotal - couponDiscount + delivery + tax,
        ),
      ],
    );
  }
}

class _SummaryLine extends StatelessWidget {
  const _SummaryLine({required this.item, required this.product});

  final CartItem item;
  final Product? product;

  @override
  Widget build(BuildContext context) {
    final p = product;
    if (p == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppDimens.lg,
        0,
        AppDimens.lg,
        AppDimens.md,
      ),
      child: Row(
        children: [
          AppImage(p.image, width: 54, height: 54, radius: AppDimens.radiusSm),
          const SizedBox(width: AppDimens.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(p.name, style: Theme.of(context).textTheme.titleSmall),
                Text(
                  '${Fmt.sqft(item.sqFt)} · ${Fmt.rupees(p.pricePerSqFt)}/sq.ft',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
          Text(
            Fmt.rupees(p.pricePerSqFt * item.sqFt),
            style: Theme.of(context).textTheme.titleSmall,
          ),
        ],
      ),
    );
  }
}
