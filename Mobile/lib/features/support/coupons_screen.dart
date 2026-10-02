import 'package:flutter/material.dart';

import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/coupon.dart';

/// Coupon list. Popping with a code applies it in the cart.
class CouponsScreen extends StatefulWidget {
  const CouponsScreen({super.key});

  @override
  State<CouponsScreen> createState() => _CouponsScreenState();
}

class _CouponsScreenState extends State<CouponsScreen> {
  late Future<List<Coupon>> _future = AppScope.read(context).promos.coupons();
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cart = AppScope.of(context).cart;
    return Scaffold(
      appBar: AppBar(title: const Text('Coupons & offers')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppDimens.lg),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _controller,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      hintText: 'Enter coupon code',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: AppDimens.sm),
                FilledButton(
                  style: FilledButton.styleFrom(minimumSize: const Size(0, 46)),
                  onPressed: () {
                    final code = _controller.text.trim().toUpperCase();
                    if (code.isEmpty) return;
                    Navigator.pop(context, code);
                  },
                  child: const Text('Apply'),
                ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<List<Coupon>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const LoadingView();
                }
                if (snap.hasError) {
                  return ErrorView(
                    onRetry: () => setState(() =>
                        _future = AppScope.read(context).promos.coupons()),
                  );
                }
                final coupons = snap.data ?? const <Coupon>[];
                if (coupons.isEmpty) {
                  return const EmptyView(
                    icon: Icons.local_offer_outlined,
                    title: 'No coupons right now',
                    message: 'Check back during the next sale.',
                  );
                }
                return ListView.builder(
                  padding: const EdgeInsets.only(bottom: AppDimens.xl),
                  itemCount: coupons.length,
                  itemBuilder: (context, i) {
                    final c = coupons[i];
                    final applicable = c.isApplicable(cart.subtotal);
                    return Container(
                      margin: const EdgeInsets.fromLTRB(
                          AppDimens.lg, 0, AppDimens.lg, AppDimens.md),
                      padding: const EdgeInsets.all(AppDimens.md),
                      decoration: BoxDecoration(
                        color: Theme.of(context).colorScheme.surface,
                        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                        border: Border.all(
                            color: applicable ? AppColors.teal : AppColors.line),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 10, vertical: 5),
                                decoration: BoxDecoration(
                                  color: AppColors.goldSoft,
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(
                                      color: AppColors.gold.withValues(alpha: 0.5)),
                                ),
                                child: Text(
                                  c.code,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                    letterSpacing: 1,
                                    fontSize: 12.5,
                                    color: AppColors.ink,
                                  ),
                                ),
                              ),
                              const Spacer(),
                              Text('Expires ${Fmt.shortDate(c.expiresOn)}',
                                  style: Theme.of(context).textTheme.labelSmall),
                            ],
                          ),
                          const SizedBox(height: AppDimens.sm),
                          Text(c.title,
                              style: Theme.of(context).textTheme.titleSmall),
                          const SizedBox(height: 2),
                          Text(c.description,
                              style: Theme.of(context).textTheme.bodySmall),
                          const SizedBox(height: AppDimens.sm),
                          Row(
                            children: [
                              Icon(
                                applicable
                                    ? Icons.check_circle_rounded
                                    : Icons.info_outline_rounded,
                                size: 15,
                                color: applicable
                                    ? AppColors.success
                                    : AppColors.muted,
                              ),
                              const SizedBox(width: 6),
                              Expanded(
                                child: Text(
                                  applicable
                                      ? 'Saves ${Fmt.rupees(c.discountFor(cart.subtotal))} on your cart'
                                      : 'Add items worth ${Fmt.rupees(c.minOrder)} to use this',
                                  style:
                                      Theme.of(context).textTheme.bodySmall,
                                ),
                              ),
                              TextButton(
                                onPressed: applicable
                                    ? () => Navigator.pop(context, c.code)
                                    : () => Toast.show(context,
                                        'Minimum order ${Fmt.rupees(c.minOrder)}'),
                                child: const Text('Apply'),
                              ),
                            ],
                          ),
                        ],
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
