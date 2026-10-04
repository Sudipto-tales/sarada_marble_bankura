import 'package:flutter/material.dart';

import '../../../core/bridge/module_bridge.dart';
import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../core/widgets/feedback.dart';
import '../../../core/widgets/state_views.dart';
import '../../../data/models/cart_item.dart';
import '../../../data/models/product.dart';
import '../models/calculation.dart';
import '../services/calculator_service.dart';

/// Marble quantity + cost calculator.
///
/// Completely independent of the shop: it takes an optional product id, and
/// hands back a [CalculationResult]. Removing this screen removes the module.
class CalculatorScreen extends StatefulWidget {
  const CalculatorScreen({super.key, required this.args});

  final CalculatorArgs args;

  @override
  State<CalculatorScreen> createState() => _CalculatorScreenState();
}

class _CalculatorScreenState extends State<CalculatorScreen> {
  static const _service = CalculatorService();

  late Future<List<Product>> _future = AppScope.read(context).products.all();
  Product? _product;
  List<AreaEntry> _entries = _service.presetFor('Living room');
  String _preset = 'Living room';
  double _wastage = 8;
  bool _polishing = false;
  bool _installation = false;
  int _seq = 2;

  static const _presets = [
    'Living room',
    'Bedroom',
    'Kitchen',
    'Bathroom',
    'Staircase',
  ];

  Estimate get _estimate => _service.estimate(
    entries: _entries,
    pricePerSqFt: _product?.pricePerSqFt ?? 0,
    wastagePercent: _wastage,
    slabSqFt: _product?.slabSqFt ?? 31,
    includePolishing: _polishing,
    includeInstallation: _installation,
  );

  void _usePreset(String preset) {
    setState(() {
      _preset = preset;
      _entries = _service.presetFor(preset);
      _wastage = _service.suggestedWastage(_entries);
    });
  }

  void _addEntry() {
    setState(() {
      _entries = [
        ..._entries,
        AreaEntry(
          id: 'e${_seq++}',
          label: 'Surface ${_entries.length + 1}',
          length: 10,
          width: 10,
        ),
      ];
    });
  }

  void _updateEntry(int index, AreaEntry entry) {
    setState(() {
      final next = [..._entries];
      next[index] = entry;
      _entries = next;
    });
  }

  void _removeEntry(int index) {
    setState(() {
      final next = [..._entries]..removeAt(index);
      _entries = next;
    });
  }

  Future<void> _addToCart() async {
    final product = _product;
    if (product == null) return;
    final result = _service.toResult(product.id, _estimate);
    await AppScope.read(context).cart.setQuantity(
      product,
      result.requiredSqFt,
      source: CartSource.calculator,
      note:
          '$_preset · ${result.slabCount} slabs incl. ${_wastage.toStringAsFixed(0)}% wastage',
    );
    if (!mounted) return;
    Toast.success(
      context,
      '${Fmt.sqft(result.requiredSqFt)} added to cart',
      actionLabel: 'View cart',
      onAction: () => Navigator.pushNamed(context, Routes.cart),
    );
  }

  void _handBack() {
    final product = _product;
    if (product == null) return;
    Navigator.pop(context, _service.toResult(product.id, _estimate));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Cost calculator'),
        actions: [
          IconButton(
            tooltip: 'How this is calculated',
            onPressed: _showMethod,
            icon: const Icon(Icons.help_outline_rounded),
          ),
        ],
      ),
      body: FutureBuilder<List<Product>>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const LoadingView();
          }
          if (snap.hasError) {
            return ErrorView(
              onRetry: () => setState(
                () => _future = AppScope.read(context).products.all(),
              ),
            );
          }
          final products = snap.data ?? const <Product>[];
          _product ??=
              products
                  .where((p) => p.id == widget.args.productId)
                  .firstOrNull ??
              (products.isEmpty ? null : products.first);
          final estimate = _estimate;

          return ListView(
            padding: const EdgeInsets.only(bottom: 120),
            children: [
              _ProductPicker(
                product: _product,
                products: products,
                onChanged: (p) => setState(() => _product = p),
              ),
              const SectionHeader(
                title: 'Measure the area',
                subtitle: 'Add every surface that needs stone',
              ),
              SizedBox(
                height: 46,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: AppDimens.lg),
                  children: [
                    for (final preset in _presets)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(preset),
                          selected: _preset == preset,
                          onSelected: (_) => _usePreset(preset),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: AppDimens.md),
              for (var i = 0; i < _entries.length; i++)
                _EntryCard(
                  entry: _entries[i],
                  onChanged: (e) => _updateEntry(i, e),
                  onRemove: _entries.length == 1 ? null : () => _removeEntry(i),
                ),
              Padding(
                padding: AppDimens.screenPad,
                child: OutlinedButton.icon(
                  onPressed: _addEntry,
                  icon: const Icon(Icons.add_rounded, size: 18),
                  label: const Text('Add another surface'),
                ),
              ),
              const SectionGap(),
              _Options(
                wastage: _wastage,
                onWastage: (v) => setState(() => _wastage = v),
                polishing: _polishing,
                onPolishing: (v) => setState(() => _polishing = v),
                installation: _installation,
                onInstallation: (v) => setState(() => _installation = v),
                suggested: _service.suggestedWastage(_entries),
              ),
              const SectionGap(),
              _EstimateCard(
                estimate: estimate,
                product: _product,
                leftover: _service.leftoverArea(
                  estimate,
                  _product?.slabSqFt ?? 31,
                ),
              ),
            ],
          );
        },
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
              Expanded(
                child: OutlinedButton(
                  onPressed: _product == null ? null : _handBack,
                  child: const Text('Use quantity'),
                ),
              ),
              const SizedBox(width: AppDimens.md),
              Expanded(
                flex: 2,
                child: GradientButton(
                  label: 'Add to cart',
                  icon: Icons.shopping_cart_rounded,
                  onPressed: _product == null ? null : _addToCart,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showMethod() {
    showModalBottomSheet<void>(
      context: context,
      builder: (context) => Padding(
        padding: const EdgeInsets.all(AppDimens.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'How the estimate works',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: AppDimens.md),
            Text(
              'Net area = Σ (length × width × count) − cut-outs\n'
              'Order area = net area × (1 + wastage %)\n'
              'Slabs = ceil(order area ÷ coverage per slab)\n'
              'Material = order area × price per sq.ft\n'
              'Polishing = order area × ₹28 · Installation = net area × ₹65\n'
              'GST of 18% applies to the total.',
              style: Theme.of(
                context,
              ).textTheme.bodyMedium?.copyWith(height: 1.7),
            ),
            const SizedBox(height: AppDimens.md),
            Text(
              'Wastage covers edge cuts, breakage and pattern matching. Book-'
              'matched or diagonal layouts usually need 12-15%.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _ProductPicker extends StatelessWidget {
  const _ProductPicker({
    required this.product,
    required this.products,
    required this.onChanged,
  });

  final Product? product;
  final List<Product> products;
  final ValueChanged<Product> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppDimens.lg,
        AppDimens.lg,
        AppDimens.lg,
        0,
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: () async {
          final picked = await showModalBottomSheet<Product>(
            context: context,
            isScrollControlled: true,
            builder: (context) => _ProductSheet(products: products),
          );
          if (picked != null) onChanged(picked);
        },
        child: Container(
          padding: const EdgeInsets.all(AppDimens.md),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            border: Border.all(color: AppColors.line),
          ),
          child: Row(
            children: [
              if (product != null)
                AppImage(
                  product!.image,
                  width: 52,
                  height: 52,
                  radius: AppDimens.radiusSm,
                )
              else
                const Icon(Icons.grid_view_rounded, size: 28),
              const SizedBox(width: AppDimens.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Marble',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                    Text(
                      product?.name ?? 'Select a marble',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    if (product != null)
                      Text(
                        '${Fmt.rupees(product!.pricePerSqFt)}/sq.ft · '
                        '${product!.slabSqFt.toStringAsFixed(0)} sq.ft per slab',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                  ],
                ),
              ),
              const Icon(Icons.unfold_more_rounded, size: 20),
            ],
          ),
        ),
      ),
    );
  }
}

class _ProductSheet extends StatelessWidget {
  const _ProductSheet({required this.products});

  final List<Product> products;

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.7,
      expand: false,
      builder: (context, controller) => ListView.separated(
        controller: controller,
        itemCount: products.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (context, i) {
          final p = products[i];
          return ListTile(
            leading: AppImage(
              p.image,
              width: 46,
              height: 46,
              radius: AppDimens.radiusSm,
            ),
            title: Text(p.name, style: Theme.of(context).textTheme.titleSmall),
            subtitle: Text(
              '${Fmt.rupees(p.pricePerSqFt)}/sq.ft',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            onTap: () => Navigator.pop(context, p),
          );
        },
      ),
    );
  }
}

class _EntryCard extends StatelessWidget {
  const _EntryCard({
    required this.entry,
    required this.onChanged,
    required this.onRemove,
  });

  final AreaEntry entry;
  final ValueChanged<AreaEntry> onChanged;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(
        AppDimens.lg,
        0,
        AppDimens.lg,
        AppDimens.md,
      ),
      padding: const EdgeInsets.all(AppDimens.md),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: TextFormField(
                  initialValue: entry.label,
                  decoration: const InputDecoration(
                    labelText: 'Surface',
                    isDense: true,
                  ),
                  onChanged: (v) => onChanged(entry.copyWith(label: v)),
                ),
              ),
              if (onRemove != null)
                IconButton(
                  onPressed: onRemove,
                  icon: const Icon(
                    Icons.delete_outline_rounded,
                    size: 20,
                    color: AppColors.danger,
                  ),
                ),
            ],
          ),
          const SizedBox(height: AppDimens.sm),
          Row(
            children: [
              Expanded(
                child: _NumberField(
                  label: 'Length',
                  value: entry.length,
                  onChanged: (v) => onChanged(entry.copyWith(length: v)),
                ),
              ),
              const SizedBox(width: AppDimens.sm),
              Expanded(
                child: _NumberField(
                  label: 'Width',
                  value: entry.width,
                  onChanged: (v) => onChanged(entry.copyWith(width: v)),
                ),
              ),
              const SizedBox(width: AppDimens.sm),
              SizedBox(
                width: 96,
                child: DropdownButtonFormField<MeasureUnit>(
                  initialValue: entry.unit,
                  isDense: true,
                  decoration: const InputDecoration(
                    labelText: 'Unit',
                    isDense: true,
                  ),
                  items: [
                    for (final unit in MeasureUnit.values)
                      DropdownMenuItem(value: unit, child: Text(unit.short)),
                  ],
                  onChanged: (v) =>
                      v == null ? null : onChanged(entry.copyWith(unit: v)),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppDimens.sm),
          Row(
            children: [
              Expanded(
                child: _NumberField(
                  label: 'Count',
                  value: entry.count.toDouble(),
                  onChanged: (v) =>
                      onChanged(entry.copyWith(count: v.round().clamp(1, 200))),
                ),
              ),
              const SizedBox(width: AppDimens.sm),
              Expanded(
                child: _NumberField(
                  label: 'Cut-outs (sq.ft)',
                  value: entry.deduction,
                  onChanged: (v) => onChanged(entry.copyWith(deduction: v)),
                ),
              ),
              const SizedBox(width: AppDimens.md),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text('Area', style: Theme.of(context).textTheme.labelSmall),
                  Text(
                    Fmt.sqft(entry.areaSqFt),
                    style: Theme.of(
                      context,
                    ).textTheme.titleSmall?.copyWith(color: AppColors.deep),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _NumberField extends StatelessWidget {
  const _NumberField({
    required this.label,
    required this.value,
    required this.onChanged,
  });

  final String label;
  final double value;
  final ValueChanged<double> onChanged;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      initialValue: value % 1 == 0
          ? value.toStringAsFixed(0)
          : value.toStringAsFixed(1),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      decoration: InputDecoration(labelText: label, isDense: true),
      onChanged: (v) => onChanged(double.tryParse(v.trim()) ?? 0),
    );
  }
}

class _Options extends StatelessWidget {
  const _Options({
    required this.wastage,
    required this.onWastage,
    required this.polishing,
    required this.onPolishing,
    required this.installation,
    required this.onInstallation,
    required this.suggested,
  });

  final double wastage;
  final ValueChanged<double> onWastage;
  final bool polishing;
  final ValueChanged<bool> onPolishing;
  final bool installation;
  final ValueChanged<bool> onInstallation;
  final double suggested;

  @override
  Widget build(BuildContext context) {
    return Padding(
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
            Row(
              children: [
                Text(
                  'Wastage allowance',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const Spacer(),
                Text(
                  '${wastage.toStringAsFixed(0)}%',
                  style: Theme.of(
                    context,
                  ).textTheme.titleSmall?.copyWith(color: AppColors.deep),
                ),
              ],
            ),
            Slider(
              value: wastage,
              min: 0,
              max: 20,
              divisions: 20,
              label: '${wastage.toStringAsFixed(0)}%',
              onChanged: onWastage,
            ),
            Text(
              'Suggested for this layout: ${suggested.toStringAsFixed(0)}%',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const Divider(height: AppDimens.xl),
            Material(
              color: Colors.transparent,
              child: SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                value: polishing,
                onChanged: onPolishing,
                title: const Text('Include edge polishing (₹28 / sq.ft)'),
              ),
            ),
            Material(
              color: Colors.transparent,
              child: SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                value: installation,
                onChanged: onInstallation,
                title: const Text('Include installation (₹65 / sq.ft)'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EstimateCard extends StatelessWidget {
  const _EstimateCard({
    required this.estimate,
    required this.product,
    required this.leftover,
  });

  final Estimate estimate;
  final Product? product;
  final double leftover;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: AppDimens.screenPad,
      child: Container(
        padding: const EdgeInsets.all(AppDimens.lg),
        decoration: BoxDecoration(
          gradient: AppColors.brandGradient,
          borderRadius: BorderRadius.circular(AppDimens.radiusLg),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Estimated total',
              style: TextStyle(
                color: Colors.white70,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              Fmt.rupees(estimate.grandTotal),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 30,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.6,
              ),
            ),
            const SizedBox(height: AppDimens.md),
            _line(context, 'Net area', Fmt.sqft(estimate.netAreaSqFt)),
            _line(
              context,
              'With ${estimate.wastagePercent.toStringAsFixed(0)}% wastage',
              Fmt.sqft(estimate.totalAreaSqFt),
            ),
            _line(
              context,
              'Slabs needed',
              '${estimate.slabCount} (${Fmt.sqft(leftover)} spare)',
            ),
            const Divider(color: Colors.white24, height: AppDimens.xl),
            _line(context, 'Material', Fmt.rupees(estimate.materialCost)),
            if (estimate.polishingCost > 0)
              _line(context, 'Polishing', Fmt.rupees(estimate.polishingCost)),
            if (estimate.installationCost > 0)
              _line(
                context,
                'Installation',
                Fmt.rupees(estimate.installationCost),
              ),
            _line(context, 'GST 18%', Fmt.rupees(estimate.tax)),
            const SizedBox(height: AppDimens.md),
            Text(
              'Indicative only. Final quantity is confirmed after site measurement.',
              style: t.bodySmall?.copyWith(color: Colors.white70),
            ),
          ],
        ),
      ),
    );
  }

  Widget _line(BuildContext context, String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 3),
    child: Row(
      children: [
        Text(
          label,
          style: const TextStyle(color: Colors.white70, fontSize: 12.5),
        ),
        const Spacer(),
        Text(
          value,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 13,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}
