import 'package:flutter/material.dart';

import 'core/config/app_config.dart';
import 'core/routing/router.dart';
import 'core/routing/routes.dart';
import 'core/state/app_scope.dart';
import 'core/theme/app_theme.dart';

class MaaSaradaApp extends StatefulWidget {
  const MaaSaradaApp({super.key, required this.deps});

  final AppDependencies deps;

  @override
  State<MaaSaradaApp> createState() => _MaaSaradaAppState();
}

class _MaaSaradaAppState extends State<MaaSaradaApp> {
  @override
  void dispose() {
    widget.deps.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AppScope(
      deps: widget.deps,
      child: Observer(
        listenable: widget.deps.theme,
        builder: (context, theme) => MaterialApp(
          title: '${AppConfig.appName} · ${AppConfig.tagline}',
          debugShowCheckedModeBanner: false,
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          themeMode: theme.mode,
          initialRoute: Routes.splash,
          onGenerateRoute: AppRouter.generate,
        ),
      ),
    );
  }
}
