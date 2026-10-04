import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/config/feature_flags.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !embedded,
        title: const Text('Account'),
        actions: [
          Observer(
            listenable: deps.theme,
            builder: (context, theme) => IconButton(
              tooltip: theme.isDark ? 'Light theme' : 'Dark theme',
              onPressed: theme.toggle,
              icon: Icon(
                theme.isDark
                    ? Icons.light_mode_rounded
                    : Icons.dark_mode_outlined,
              ),
            ),
          ),
        ],
      ),
      body: Observer(
        listenable: deps.session,
        builder: (context, session) => ListView(
          padding: const EdgeInsets.only(bottom: AppDimens.xxxl),
          children: [
            _ProfileHeader(),
            const SizedBox(height: AppDimens.lg),
            _Group(
              title: 'Orders & requests',
              tiles: [
                _Tile(
                  icon: Icons.receipt_long_rounded,
                  label: 'My orders',
                  subtitle: 'Track, return or reorder',
                  route: Routes.orders,
                ),
                _Tile(
                  icon: Icons.favorite_border_rounded,
                  label: 'Wishlist',
                  subtitle: '${deps.wishlist.count} saved',
                  route: Routes.wishlist,
                ),
                const _Tile(
                  icon: Icons.science_outlined,
                  label: 'Request a sample',
                  subtitle: 'Get a physical swatch before ordering',
                  route: Routes.requestSample,
                ),
                const _Tile(
                  icon: Icons.request_quote_outlined,
                  label: 'Request a project quote',
                  subtitle: 'For bulk and site work',
                  route: Routes.requestQuote,
                ),
              ],
            ),
            _Group(
              title: 'Saved',
              tiles: [
                const _Tile(
                  icon: Icons.location_on_outlined,
                  label: 'Addresses',
                  subtitle: 'Delivery and site locations',
                  route: Routes.addressList,
                ),
                const _Tile(
                  icon: Icons.local_offer_outlined,
                  label: 'Coupons',
                  subtitle: 'Available offers',
                  route: Routes.coupons,
                ),
                if (FeatureFlags.visualizationEnabled)
                  const _Tile(
                    icon: Icons.view_in_ar_outlined,
                    label: 'Saved room designs',
                    subtitle: 'Your 3D room combinations',
                    route: Routes.savedDesigns,
                  ),
              ],
            ),
            const _Group(
              title: 'Support',
              tiles: [
                _Tile(
                  icon: Icons.notifications_none_rounded,
                  label: 'Notifications',
                  route: Routes.notifications,
                ),
                _Tile(
                  icon: Icons.help_outline_rounded,
                  label: 'Help centre',
                  subtitle: 'Delivery, returns, installation',
                  route: Routes.help,
                ),
                _Tile(
                  icon: Icons.info_outline_rounded,
                  label: 'About Maa Sarada',
                  route: Routes.about,
                ),
              ],
            ),
            const SizedBox(height: AppDimens.sm),
            Padding(
              padding: AppDimens.screenPad,
              child: session.isLoggedIn
                  ? OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppColors.danger,
                        side: BorderSide(
                          color: AppColors.danger.withValues(alpha: 0.4),
                        ),
                      ),
                      onPressed: () async {
                        final ok = await confirmDialog(
                          context,
                          title: 'Sign out?',
                          message:
                              'Your cart and wishlist stay on this device.',
                          confirmLabel: 'Sign out',
                          destructive: true,
                        );
                        if (ok && context.mounted) {
                          await session.signOut();
                          if (context.mounted) {
                            Toast.show(context, 'Signed out');
                          }
                        }
                      },
                      icon: const Icon(Icons.logout_rounded, size: 18),
                      label: const Text('Sign out'),
                    )
                  : GradientButton(
                      label: 'Login or create an account',
                      icon: Icons.login_rounded,
                      onPressed: () =>
                          Navigator.pushNamed(context, Routes.login),
                    ),
            ),
            const SizedBox(height: AppDimens.xl),
            Center(
              child: Text(
                '${AppConfig.appName} · ${AppConfig.tagline}\nPrototype build · static demo data',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ProfileHeader extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Observer(
      listenable: deps.session,
      builder: (context, session) {
        final user = session.user;
        return Container(
          margin: AppDimens.screenPad,
          padding: const EdgeInsets.all(AppDimens.lg),
          decoration: BoxDecoration(
            gradient: AppColors.brandGradient,
            borderRadius: BorderRadius.circular(AppDimens.radiusLg),
          ),
          child: Row(
            children: [
              CircleAvatar(
                radius: 27,
                backgroundColor: Colors.white,
                child: Text(
                  user == null ? '?' : Fmt.initials(user.name),
                  style: const TextStyle(
                    color: AppColors.deep,
                    fontWeight: FontWeight.w800,
                    fontSize: 18,
                  ),
                ),
              ),
              const SizedBox(width: AppDimens.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      user?.name ?? 'Guest',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      user?.email ?? 'Sign in to track orders and save designs',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.85),
                        fontSize: 12.5,
                      ),
                    ),
                    if (user?.memberSince != null) ...[
                      const SizedBox(height: 6),
                      TagChip(
                        label: 'Member since ${Fmt.date(user!.memberSince!)}',
                        color: AppColors.ink,
                        background: Colors.white.withValues(alpha: 0.85),
                        dense: true,
                      ),
                    ],
                  ],
                ),
              ),
              if (user != null)
                IconButton(
                  onPressed: () =>
                      Navigator.pushNamed(context, Routes.profileEdit),
                  icon: const Icon(Icons.edit_outlined, color: Colors.white),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _Group extends StatelessWidget {
  const _Group({required this.title, required this.tiles});

  final String title;
  final List<_Tile> tiles;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppDimens.lg,
            AppDimens.lg,
            AppDimens.lg,
            AppDimens.sm,
          ),
          child: Text(title, style: Theme.of(context).textTheme.labelMedium),
        ),
        Container(
          margin: AppDimens.screenPad,
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            border: Border.all(color: AppColors.line),
          ),
          child: Column(
            children: [
              for (var i = 0; i < tiles.length; i++) ...[
                if (i > 0) const Divider(height: 1, indent: 52),
                tiles[i],
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.label,
    required this.route,
    this.subtitle,
  });

  final IconData icon;
  final String label;
  final String route;
  final String? subtitle;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    borderRadius: BorderRadius.circular(AppDimens.radiusMd),
    clipBehavior: Clip.antiAlias,
    child: ListTile(
      leading: Icon(icon, size: 21, color: AppColors.deep),
      title: Text(label, style: Theme.of(context).textTheme.titleSmall),
      subtitle: subtitle == null
          ? null
          : Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
      trailing: const Icon(Icons.chevron_right_rounded, size: 20),
      onTap: () => Navigator.pushNamed(context, route),
    ),
  );
}
