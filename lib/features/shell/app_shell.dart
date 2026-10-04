import 'package:flutter/material.dart';

import '../../core/config/feature_flags.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/glossy_surface.dart';
import '../../core/widgets/navigation_glyph.dart';
import '../account/account_screen.dart';
import '../cart/cart_screen.dart';
import '../catalog/catalog_screen.dart';
import '../home/home_screen.dart';
import '../visualization/visualizer_entry_screen.dart';

/// Bottom-nav host. The room visualization tab disappears entirely when the visualization
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
        label: 'Room View',
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
      extendBody: true,
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
              decoration: glossySurface(context, radius: 36),
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
                              constraints: const BoxConstraints(minHeight: 48),
                              padding: const EdgeInsets.symmetric(
                                vertical: 8,
                                horizontal: 4,
                              ),
                              decoration: BoxDecoration(
                                gradient: i == _index
                                    ? LinearGradient(
                                        begin: Alignment.topLeft,
                                        end: Alignment.bottomRight,
                                        stops: const [0, .48, .5, 1],
                                        colors: [
                                          Color.lerp(
                                            Theme.of(
                                              context,
                                            ).colorScheme.primary,
                                            Colors.white,
                                            .2,
                                          )!,
                                          Theme.of(context).colorScheme.primary,
                                          Theme.of(context).colorScheme.primary,
                                          Color.lerp(
                                            Theme.of(
                                              context,
                                            ).colorScheme.primary,
                                            Colors.black,
                                            .22,
                                          )!,
                                        ],
                                      )
                                    : null,
                                border: Border.all(
                                  color: i == _index
                                      ? Colors.white.withValues(alpha: .35)
                                      : Colors.transparent,
                                ),
                                boxShadow: i == _index
                                    ? [
                                        BoxShadow(
                                          color: Theme.of(context)
                                              .colorScheme
                                              .primary
                                              .withValues(alpha: .25),
                                          blurRadius: 12,
                                          offset: const Offset(0, 4),
                                        ),
                                      ]
                                    : [],
                                borderRadius: BorderRadius.circular(28),
                              ),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (_tabs[i].label == 'Cart' &&
                                      cart.count > 0)
                                    Badge.count(
                                      count: cart.count,
                                      child: NavigationGlyph(
                                        label: _tabs[i].label,
                                        selected: i == _index,
                                        color: i == _index
                                            ? Theme.of(
                                                context,
                                              ).colorScheme.onPrimary
                                            : Theme.of(
                                                context,
                                              ).colorScheme.onSurfaceVariant,
                                      ),
                                    )
                                  else
                                    NavigationGlyph(
                                      label: _tabs[i].label,
                                      selected: i == _index,
                                      color: i == _index
                                          ? Theme.of(
                                              context,
                                            ).colorScheme.onPrimary
                                          : Theme.of(
                                              context,
                                            ).colorScheme.onSurfaceVariant,
                                    ),
                                  const SizedBox(height: 3),
                                  Text(
                                    _tabs[i].label,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      fontSize: 9,
                                      fontWeight: FontWeight.w600,
                                      color: i == _index
                                          ? Theme.of(
                                              context,
                                            ).colorScheme.onPrimary
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
