import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/validators.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';

/// Static/local login for the prototype. Credentials are checked against
/// AppConfig on-device; nothing is transmitted and no token is stored.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController(text: AppConfig.demoEmail);
  final _password = TextEditingController(text: AppConfig.demoPassword);
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final session = AppScope.read(context).session;
    final ok = await session.signIn(_email.text, _password.text);
    if (!mounted) return;
    if (ok) {
      Toast.success(context, 'Welcome back');
      Navigator.pop(context, true);
    } else {
      Toast.error(context, session.error ?? 'Sign in failed');
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
              AppImage('assets/brand/logo_mark.webp', width: 68, height: 68),
              const SizedBox(height: AppDimens.lg),
              Text('Sign in', style: t.displaySmall),
              const SizedBox(height: 6),
              Text(
                'Track orders, save designs and check out faster.',
                style: t.bodyMedium?.copyWith(color: AppColors.muted),
              ),
              const SizedBox(height: AppDimens.xxl),
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
                controller: _password,
                obscureText: _obscure,
                decoration: InputDecoration(
                  labelText: 'Password',
                  prefixIcon: const Icon(Icons.lock_outline_rounded, size: 20),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscure
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                      size: 19,
                    ),
                    onPressed: () => setState(() => _obscure = !_obscure),
                  ),
                ),
                validator: Validators.password,
              ),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  onPressed: () => Toast.show(context,
                      'Password reset is not part of this prototype build'),
                  child: const Text('Forgot password?'),
                ),
              ),
              const SizedBox(height: AppDimens.sm),
              GradientButton(
                label: 'Sign in',
                busy: session.isBusy,
                onPressed: session.isBusy ? null : _submit,
              ),
              const SizedBox(height: AppDimens.lg),
              // Wrap, not Row: the prompt and the action must not collide at
              // large text scales or on narrow phones.
              Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text('New here?', style: t.bodyMedium),
                  TextButton(
                    onPressed: () =>
                        Navigator.pushReplacementNamed(context, Routes.register),
                    child: const Text('Create an account'),
                  ),
                ],
              ),
              const SizedBox(height: AppDimens.lg),
              const _DemoCredentials(),
            ],
          ),
        ),
      ),
    );
  }
}

class _DemoCredentials extends StatelessWidget {
  const _DemoCredentials();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppDimens.md),
      decoration: BoxDecoration(
        color: AppColors.ice.withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.info_outline_rounded,
                  size: 17, color: AppColors.deep),
              const SizedBox(width: 8),
              Text('Demo credentials',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(color: AppColors.ink)),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            '${AppConfig.demoEmail} / ${AppConfig.demoPassword}\n'
            'This build uses local authentication only — no server, no payment gateway.',
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: AppColors.inkSoft, height: 1.4),
          ),
        ],
      ),
    );
  }
}
