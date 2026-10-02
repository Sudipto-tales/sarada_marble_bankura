import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';

class ReturnRequestScreen extends StatefulWidget {
  const ReturnRequestScreen({super.key, required this.args});

  final OrderArgs args;

  @override
  State<ReturnRequestScreen> createState() => _ReturnRequestScreenState();
}

class _ReturnRequestScreenState extends State<ReturnRequestScreen> {
  final _notes = TextEditingController();
  String _reason = 'Slab damaged in transit';
  bool _replacement = true;
  bool _saving = false;

  static const _reasons = [
    'Slab damaged in transit',
    'Shade differs from the sample',
    'Wrong finish or thickness delivered',
    'Quantity short of the order',
    'Changed my mind',
  ];

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _saving = true);
    final reason = _notes.text.trim().isEmpty
        ? _reason
        : '$_reason — ${_notes.text.trim()}';
    await AppScope.read(context)
        .orders
        .requestReturn(widget.args.orderId, reason);
    if (!mounted) return;
    setState(() => _saving = false);
    Toast.success(context,
        _replacement ? 'Replacement requested' : 'Return requested');
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: const Text('Return or replace')),
      body: ListView(
        padding: const EdgeInsets.all(AppDimens.lg),
        children: [
          Container(
            padding: const EdgeInsets.all(AppDimens.md),
            decoration: BoxDecoration(
              color: AppColors.ice.withValues(alpha: 0.45),
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            ),
            child: Text(
              'Stone is a natural material — shade and vein variation between '
              'slabs is normal and not a defect. Damage, wrong finish or short '
              'quantity are covered for 7 days from delivery.',
              style: t.bodySmall?.copyWith(color: AppColors.inkSoft, height: 1.45),
            ),
          ),
          const SizedBox(height: AppDimens.lg),
          Text('What went wrong?', style: t.titleMedium),
          const SizedBox(height: AppDimens.sm),
          for (final reason in _reasons)
            ListTile(
              contentPadding: EdgeInsets.zero,
              onTap: () => setState(() => _reason = reason),
              leading: Icon(
                _reason == reason
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_off_rounded,
                color: _reason == reason ? AppColors.teal : AppColors.mutedSoft,
              ),
              title: Text(reason, style: t.bodyMedium),
            ),
          const SizedBox(height: AppDimens.md),
          TextField(
            controller: _notes,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Additional details (optional)',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: AppDimens.lg),
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: _replacement,
            onChanged: (v) => setState(() => _replacement = v),
            title: const Text('Send a replacement instead of a refund'),
            subtitle: Text(
              'Replacements ship from the same lot where possible.',
              style: t.bodySmall,
            ),
          ),
          const SizedBox(height: AppDimens.lg),
          GradientButton(
            label: 'Submit request',
            busy: _saving,
            onPressed: _saving ? null : _submit,
          ),
        ],
      ),
    );
  }
}
