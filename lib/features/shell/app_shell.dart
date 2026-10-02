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
        children: [for (final t in _tabs) t.child],
      ),
      bottomNavigationBar: Observer(
        listenable: deps.cart,
        builder: (context, cart) => DecoratedBox(
          decoration: BoxDecoration(
            border: Border(
              top: BorderSide(
                color: Theme.of(context).brightness == Brightness.dark
                    ? Colors.white12
                    : AppColors.line,
              ),
            ),
          ),
          child: NavigationBar(
            selectedIndex: _index,
            onDestinationSelected: (i) => setState(() => _index = i),
            destinations: [
              for (final t in _tabs)
                NavigationDestination(
                  icon: t.label == 'Cart' && cart.count > 0
                      ? Badge.count(count: cart.count, child: Icon(t.icon))
                      : Icon(t.icon),
                  selectedIcon: t.label == 'Cart' && cart.count > 0
                      ? Badge.count(count: cart.count, child: Icon(t.activeIcon))
                      : Icon(t.activeIcon),
                  label: t.label,
                ),
            ],
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
