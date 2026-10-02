import 'package:flutter/material.dart';

import '../../core/state/app_scope.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/validators.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';

class ProfileEditScreen extends StatefulWidget {
  const ProfileEditScreen({super.key});

  @override
  State<ProfileEditScreen> createState() => _ProfileEditScreenState();
}

class _ProfileEditScreenState extends State<ProfileEditScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(
      text: AppScope.read(context).session.user?.name ?? '');
  late final _email = TextEditingController(
      text: AppScope.read(context).session.user?.email ?? '');
  late final _phone = TextEditingController(
      text: AppScope.read(context).session.user?.phone ?? '');
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final session = AppScope.read(context).session;
    final user = session.user;
    if (user == null) return;
    setState(() => _saving = true);
    await session.updateProfile(user.copyWith(
      name: _name.text.trim(),
      email: _email.text.trim(),
      phone: _phone.text.trim(),
    ));
    if (!mounted) return;
    setState(() => _saving = false);
    Toast.success(context, 'Profile updated');
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Edit profile')),
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
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'Email'),
              validator: Validators.email,
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone'),
              validator: Validators.phone,
            ),
            const SizedBox(height: AppDimens.xl),
            GradientButton(
              label: 'Save changes',
              busy: _saving,
              onPressed: _saving ? null : _save,
            ),
          ],
        ),
      ),
    );
  }
}
