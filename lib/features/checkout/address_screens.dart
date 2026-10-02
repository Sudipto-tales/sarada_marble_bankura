import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/validators.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/address.dart';

/// Address book. Doubles as the "select address" step of checkout when
/// [selectMode] is set.
class AddressListScreen extends StatelessWidget {
  const AddressListScreen({super.key, this.selectMode = false});

  final bool selectMode;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(selectMode ? 'Select delivery address' : 'Addresses'),
      ),
      body: Observer(
        listenable: deps.session,
        builder: (context, session) {
          if (session.addresses.isEmpty) {
            return EmptyView(
              icon: Icons.location_off_outlined,
              title: 'No saved addresses',
              message: 'Add a delivery or site address to place orders.',
              actionLabel: 'Add address',
              onAction: () => Navigator.pushNamed(context, Routes.addressForm,
                  arguments: const AddressFormArgs()),
            );
          }
          return ListView(
            padding: const EdgeInsets.only(bottom: 96),
            children: [
              for (final address in session.addresses)
                _AddressCard(
                  address: address,
                  onTap: selectMode
                      ? () => Navigator.pop(context, address)
                      : null,
                  onEdit: () => Navigator.pushNamed(
                    context,
                    Routes.addressForm,
                    arguments: AddressFormArgs(addressId: address.id),
                  ),
                  onDelete: () async {
                    final ok = await confirmDialog(
                      context,
                      title: 'Delete address?',
                      message: address.formatted,
                      confirmLabel: 'Delete',
                      destructive: true,
                    );
                    if (ok) await session.deleteAddress(address.id);
                  },
                  onSetDefault: () => session.setDefaultAddress(address.id),
                ),
            ],
          );
        },
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.pushNamed(context, Routes.addressForm,
            arguments: const AddressFormArgs()),
        icon: const Icon(Icons.add_location_alt_outlined),
        label: const Text('Add address'),
      ),
    );
  }
}

class _AddressCard extends StatelessWidget {
  const _AddressCard({
    required this.address,
    required this.onEdit,
    required this.onDelete,
    required this.onSetDefault,
    this.onTap,
  });

  final Address address;
  final VoidCallback onEdit;
  final VoidCallback onDelete;
  final VoidCallback onSetDefault;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Container(
      margin: const EdgeInsets.fromLTRB(
          AppDimens.lg, AppDimens.md, AppDimens.lg, 0),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        border: Border.all(
            color: address.isDefault ? AppColors.teal : AppColors.line),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppDimens.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  TagChip(label: address.label.toUpperCase(), dense: true),
                  if (address.isDefault) ...[
                    const SizedBox(width: 6),
                    const TagChip(
                        label: 'DEFAULT',
                        color: AppColors.success,
                        dense: true),
                  ],
                  const Spacer(),
                  if (onTap != null)
                    const Icon(Icons.chevron_right_rounded, size: 20),
                ],
              ),
              const SizedBox(height: AppDimens.sm),
              Text(address.name, style: t.titleSmall),
              const SizedBox(height: 2),
              Text(address.formatted,
                  style: t.bodyMedium?.copyWith(height: 1.4)),
              const SizedBox(height: 4),
              Text(address.phone, style: t.bodySmall),
              const Divider(height: AppDimens.xl),
              Row(
                children: [
                  TextButton.icon(
                    onPressed: onEdit,
                    icon: const Icon(Icons.edit_outlined, size: 16),
                    label: const Text('Edit'),
                  ),
                  if (!address.isDefault)
                    TextButton.icon(
                      onPressed: onSetDefault,
                      icon: const Icon(Icons.push_pin_outlined, size: 16),
                      label: const Text('Set default'),
                    ),
                  const Spacer(),
                  IconButton(
                    onPressed: onDelete,
                    icon: const Icon(Icons.delete_outline_rounded,
                        size: 19, color: AppColors.danger),
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

class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({super.key, required this.args});

  final AddressFormArgs args;

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _pincode = TextEditingController();
  String _label = 'Home';
  bool _isDefault = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final id = widget.args.addressId;
    if (id != null) {
      final existing = AppScope.read(context)
          .session
          .addresses
          .where((a) => a.id == id)
          .firstOrNull;
      if (existing != null) {
        _name.text = existing.name;
        _phone.text = existing.phone;
        _line1.text = existing.line1;
        _line2.text = existing.line2;
        _city.text = existing.city;
        _state.text = existing.state;
        _pincode.text = existing.pincode;
        _label = existing.label;
        _isDefault = existing.isDefault;
      }
    } else {
      final user = AppScope.read(context).session.user;
      _name.text = user?.name ?? '';
      _phone.text = user?.phone ?? '';
    }
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _line1, _line2, _city, _state, _pincode]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final address = Address(
      id: widget.args.addressId ?? 'a_${DateTime.now().millisecondsSinceEpoch}',
      name: _name.text.trim(),
      phone: _phone.text.trim(),
      line1: _line1.text.trim(),
      line2: _line2.text.trim(),
      city: _city.text.trim(),
      state: _state.text.trim(),
      pincode: _pincode.text.trim(),
      label: _label,
      isDefault: _isDefault,
    );
    await AppScope.read(context).session.saveAddress(address);
    if (!mounted) return;
    setState(() => _saving = false);
    Toast.success(context, 'Address saved');
    Navigator.pop(context, address);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.args.addressId == null
            ? 'Add address'
            : 'Edit address'),
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppDimens.lg),
          children: [
            TextFormField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Full name'),
              validator: (v) => Validators.required(v, 'Name'),
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone number'),
              validator: Validators.phone,
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _line1,
              decoration: const InputDecoration(
                  labelText: 'Flat, house no., building'),
              validator: (v) => Validators.required(v, 'Address'),
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _line2,
              decoration:
                  const InputDecoration(labelText: 'Area, street, landmark'),
            ),
            const SizedBox(height: AppDimens.md),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _city,
                    decoration: const InputDecoration(labelText: 'City'),
                    validator: (v) => Validators.required(v, 'City'),
                  ),
                ),
                const SizedBox(width: AppDimens.md),
                Expanded(
                  child: TextFormField(
                    controller: _state,
                    decoration: const InputDecoration(labelText: 'State'),
                    validator: (v) => Validators.required(v, 'State'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _pincode,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(
                  labelText: 'PIN code', counterText: ''),
              validator: Validators.pincode,
            ),
            const SizedBox(height: AppDimens.md),
            Text('Save as', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: AppDimens.sm),
            Wrap(
              spacing: 8,
              children: [
                for (final label in ['Home', 'Work', 'Site', 'Other'])
                  ChoiceChip(
                    label: Text(label),
                    selected: _label == label,
                    onSelected: (_) => setState(() => _label = label),
                  ),
              ],
            ),
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              value: _isDefault,
              onChanged: (v) => setState(() => _isDefault = v),
              title: const Text('Make this my default address'),
            ),
            const SizedBox(height: AppDimens.lg),
            GradientButton(
              label: 'Save address',
              busy: _saving,
              onPressed: _saving ? null : _save,
            ),
          ],
        ),
      ),
    );
  }
}
