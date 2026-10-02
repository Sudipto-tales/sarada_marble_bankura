import 'package:flutter/material.dart';

import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/validators.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../data/models/product.dart';

/// Free-sample request. Static form — the submission is acknowledged locally.
class RequestSampleScreen extends StatefulWidget {
  const RequestSampleScreen({super.key, this.productId});

  final String? productId;

  @override
  State<RequestSampleScreen> createState() => _RequestSampleScreenState();
}

class _RequestSampleScreenState extends State<RequestSampleScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _pincode = TextEditingController();
  final _selected = <String>{};
  bool _sending = false;
  late final Future<List<Product>> _future = AppScope.read(context).products.all();

  @override
  void initState() {
    super.initState();
    final user = AppScope.read(context).session.user;
    _name.text = user?.name ?? '';
    _phone.text = user?.phone ?? '';
    if (widget.productId != null) _selected.add(widget.productId!);
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _pincode.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selected.isEmpty) {
      Toast.error(context, 'Pick at least one marble');
      return;
    }
    setState(() => _sending = true);
    await Future<void>.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() => _sending = false);
    Toast.success(context,
        'Sample request received — we will call to confirm the address');
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Request a sample')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppDimens.lg),
          children: [
            Container(
              padding: const EdgeInsets.all(AppDimens.md),
              decoration: BoxDecoration(
                color: AppColors.ice.withValues(alpha: 0.45),
                borderRadius: BorderRadius.circular(AppDimens.radiusMd),
              ),
              child: Text(
                'Up to 3 free 4×4 inch swatches per household. Samples show '
                'true colour and finish — screens never do.',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: AppColors.inkSoft, height: 1.45),
              ),
            ),
            const SizedBox(height: AppDimens.lg),
            Text('Choose swatches (max 3)',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: AppDimens.sm),
            FutureBuilder<List<Product>>(
              future: _future,
              builder: (context, snap) {
                final products = snap.data ?? const <Product>[];
                if (products.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.all(AppDimens.lg),
                    child: LinearProgressIndicator(),
                  );
                }
                return Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final p in products)
                      FilterChip(
                        label: Text(p.name),
                        selected: _selected.contains(p.id),
                        onSelected: (sel) => setState(() {
                          if (sel) {
                            if (_selected.length >= 3) {
                              Toast.error(context, 'Up to 3 swatches');
                              return;
                            }
                            _selected.add(p.id);
                          } else {
                            _selected.remove(p.id);
                          }
                        }),
                      ),
                  ],
                );
              },
            ),
            const SizedBox(height: AppDimens.xl),
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Your name'),
              validator: (v) => Validators.required(v, 'Name'),
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone'),
              validator: Validators.phone,
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _pincode,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(
                  labelText: 'Delivery PIN code', counterText: ''),
              validator: Validators.pincode,
            ),
            const SizedBox(height: AppDimens.lg),
            GradientButton(
              label: 'Request samples',
              busy: _sending,
              onPressed: _sending ? null : _submit,
            ),
          ],
        ),
      ),
    );
  }
}

/// Project quote request for bulk / site work.
class RequestQuoteScreen extends StatefulWidget {
  const RequestQuoteScreen({super.key});

  @override
  State<RequestQuoteScreen> createState() => _RequestQuoteScreenState();
}

class _RequestQuoteScreenState extends State<RequestQuoteScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _city = TextEditingController();
  final _area = TextEditingController();
  final _notes = TextEditingController();
  String _type = 'Residential';
  String _timeline = 'Within a month';
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    final user = AppScope.read(context).session.user;
    _name.text = user?.name ?? '';
    _phone.text = user?.phone ?? '';
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _city, _area, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _sending = true);
    await Future<void>.delayed(const Duration(milliseconds: 800));
    if (!mounted) return;
    setState(() => _sending = false);
    Toast.success(context, 'Quote request sent — expect a call within 24 hours');
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Request a quote')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppDimens.lg),
          children: [
            Text('Project details',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: AppDimens.md),
            Wrap(
              spacing: 8,
              children: [
                for (final type in [
                  'Residential',
                  'Commercial',
                  'Hospitality',
                  'Temple'
                ])
                  ChoiceChip(
                    label: Text(type),
                    selected: _type == type,
                    onSelected: (_) => setState(() => _type = type),
                  ),
              ],
            ),
            const SizedBox(height: AppDimens.lg),
            TextFormField(
              controller: _area,
              keyboardType: TextInputType.number,
              decoration:
                  const InputDecoration(labelText: 'Approximate area (sq.ft)'),
              validator: (v) => Validators.positiveNumber(v, 'Area'),
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _city,
              decoration: const InputDecoration(labelText: 'Site city'),
              validator: (v) => Validators.required(v, 'City'),
            ),
            const SizedBox(height: AppDimens.md),
            Text('Timeline', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: AppDimens.sm),
            Wrap(
              spacing: 8,
              children: [
                for (final option in [
                  'Immediately',
                  'Within a month',
                  '1-3 months',
                  'Planning stage'
                ])
                  ChoiceChip(
                    label: Text(option),
                    selected: _timeline == option,
                    onSelected: (_) => setState(() => _timeline = option),
                  ),
              ],
            ),
            const SizedBox(height: AppDimens.lg),
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Your name'),
              validator: (v) => Validators.required(v, 'Name'),
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone'),
              validator: Validators.phone,
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _notes,
              maxLines: 4,
              decoration: const InputDecoration(
                labelText: 'What do you need? (optional)',
                alignLabelWithHint: true,
                hintText: 'Rooms, finishes, deadlines, drawings available…',
              ),
            ),
            const SizedBox(height: AppDimens.lg),
            GradientButton(
              label: 'Send request',
              busy: _sending,
              onPressed: _sending ? null : _submit,
            ),
            const SizedBox(height: AppDimens.md),
            Text(
              'Bulk projects above ₹2 lakh qualify for project pricing and a '
              'free site measurement.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}
