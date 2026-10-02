import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/validators.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _accepted = true;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (!_accepted) {
      Toast.error(context, 'Please accept the terms to continue');
      return;
    }
    final session = AppScope.read(context).session;
    final ok = await session.register(
        _name.text, _email.text, _phone.text, _password.text);
    if (!mounted) return;
    if (ok) {
      Toast.success(context, 'Account created');
      Navigator.pop(context, true);
    } else {
      Toast.error(context, session.error ?? 'Could not create the account');
    }
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final t = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(),
      body: Observer(
        listenable: deps.session,
        builder: (context, session) => Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(AppDimens.xl),
            children: [
              Text('Create account', style: t.displaySmall),
              const SizedBox(height: 6),
              Text(
                'One account for orders, saved designs and quotes.',
                style: t.bodyMedium?.copyWith(color: AppColors.muted),
              ),
              const SizedBox(height: AppDimens.xxl),
              TextFormField(
                controller: _name,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                  labelText: 'Full name',
                  prefixIcon: Icon(Icons.person_outline_rounded, size: 20),
                ),
                validator: (v) => Validators.required(v, 'Name'),
              ),
              const SizedBox(height: AppDimens.md),
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'Email',
                  prefixIcon: Icon(Icons.mail_outline_rounded, size: 20),
                ),
                validator: Validators.email,
              ),
              const SizedBox(height: AppDimens.md),
              TextFormField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'Phone',
                  prefixIcon: Icon(Icons.phone_outlined, size: 20),
                ),
                validator: Validators.phone,
              ),
              const SizedBox(height: AppDimens.md),
              TextFormField(
                controller: _password,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Password',
                  prefixIcon: Icon(Icons.lock_outline_rounded, size: 20),
                ),
                validator: Validators.password,
              ),
              const SizedBox(height: AppDimens.sm),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _accepted,
                onChanged: (v) => setState(() => _accepted = v ?? false),
                title: Text(
                  'I agree to the terms of sale and the returns policy.',
                  style: t.bodySmall,
                ),
              ),
              const SizedBox(height: AppDimens.md),
              GradientButton(
                label: 'Create account',
                busy: session.isBusy,
                onPressed: session.isBusy ? null : _submit,
              ),
              const SizedBox(height: AppDimens.lg),
              Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text('Already registered?', style: t.bodyMedium),
                  TextButton(
                    onPressed: () =>
                        Navigator.pushReplacementNamed(context, Routes.login),
                    child: const Text('Sign in'),
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
