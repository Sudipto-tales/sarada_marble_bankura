import 'package:flutter/material.dart';

import '../../core/config/feature_flags.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../account/account_screen.dart';
import '../cart/cart_screen.dart';
import '../catalog/catalog_screen.dart';
import '../home/home_screen.dart';
import '../visualization/visualizer_entry_screen.dart';

/// Bottom-nav host. The 3D tab disappears entirely when the visualization
/// module is switched off — nothing else in the shell changes.
class AppShell extends StatefulWidget {
  const AppShell({super.key, this.initialIndex = 0});

  final int initialIndex;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  late int _index = widget.initialIndex;

  late final List<_Tab> _tabs = [
    const _Tab(
      icon: Icons.home_outlined,
      activeIcon: Icons.home_rounded,
      label: 'Home',
      child: HomeScreen(),
    ),
    const _Tab(
      icon: Icons.grid_view_outlined,
      activeIcon: Icons.grid_view_rounded,
      label: 'Catalog',
      child: CatalogScreen(embedded: true),
    ),
    if (FeatureFlags.visualizationEnabled)
      const _Tab(
        icon: Icons.view_in_ar_outlined,
        activeIcon: Icons.view_in_ar_rounded,
        label: '3D Room',
        child: VisualizerEntryScreen(embedded: true),
      ),
    const _Tab(
      icon: Icons.shopping_cart_outlined,
      activeIcon: Icons.shopping_cart_rounded,
      label: 'Cart',
      child: CartScreen(embedded: true),
    ),
    const _Tab(
      icon: Icons.person_outline_rounded,
      activeIcon: Icons.person_rounded,
      label: 'Account',
      child: AccountScreen(embedded: true),
    ),
  ];

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [
          for (var i = 0; i < _tabs.length; i++)
            TickerMode(enabled: _index == i, child: _tabs[i].child),
        ],
      ),
      bottomNavigationBar: Observer(
        listenable: deps.cart,
        builder: (context, cart) => SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
            child: Container(
              key: const ValueKey('stone-navigation'),
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: BorderRadius.circular(36),
                border: Border.all(
                  color: AppColors.sage.withValues(alpha: 0.2),
                ),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.ink.withValues(alpha: 0.08),
                    blurRadius: 24,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Row(
                children: [
                  for (var i = 0; i < _tabs.length; i++)
                    TweenAnimationBuilder<double>(
                      tween: Tween<double>(end: i == _index ? 17 : 10),
                      duration: Duration(
                        milliseconds: MediaQuery.disableAnimationsOf(context)
                            ? 0
                            : 320,
                      ),
                      curve: Curves.easeOutCubic,
                      builder: (context, value, child) =>
                          Expanded(flex: (value * 100).round(), child: child!),
                      child: Semantics(
                        selected: i == _index,
                        button: true,
                        label: _tabs[i].label,
                        child: Tooltip(
                          message: _tabs[i].label,
                          child: InkWell(
                            borderRadius: BorderRadius.circular(28),
                            onTap: () => setState(() => _index = i),
                            child: AnimatedContainer(
                              duration: Duration(
                                milliseconds:
                                    MediaQuery.disableAnimationsOf(context)
                                    ? 0
                                    : 320,
                              ),
                              curve: Curves.easeOutCubic,
                              constraints: const BoxConstraints(minHeight: 56),
                              padding: const EdgeInsets.symmetric(
                                vertical: 8,
                                horizontal: 4,
                              ),
                              decoration: BoxDecoration(
                                color: i == _index
                                    ? AppColors.deep
                                    : Colors.transparent,
                                borderRadius: BorderRadius.circular(28),
                              ),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (_tabs[i].label == 'Cart' &&
                                      cart.count > 0)
                                    Badge.count(
                                      count: cart.count,
                                      child: Icon(
                                        i == _index
                                            ? _tabs[i].activeIcon
                                            : _tabs[i].icon,
                                        size: 21,
                                        color: i == _index
                                            ? Colors.white
                                            : AppColors.muted,
                                      ),
                                    )
                                  else
                                    Icon(
                                      i == _index
                                          ? _tabs[i].activeIcon
                                          : _tabs[i].icon,
                                      size: 21,
                                      color: i == _index
                                          ? Colors.white
                                          : AppColors.muted,
                                    ),
                                  const SizedBox(height: 3),
                                  Text(
                                    _tabs[i].label,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      fontSize: 10,
                                      fontWeight: FontWeight.w600,
                                      color: i == _index
                                          ? Colors.white
                                          : Theme.of(
                                              context,
                                            ).colorScheme.onSurface,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Tab {
  const _Tab({
    required this.icon,
    required this.activeIcon,
    required this.label,
    required this.child,
  });

  final IconData icon;
  final IconData activeIcon;
  final String label;
  final Widget child;
}
