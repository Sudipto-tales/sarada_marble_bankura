import 'package:flutter/material.dart';

import '../store/local_store.dart';
import 'safe_notifier.dart';

class ThemeController extends SafeNotifier {
  ThemeController(this._store) {
    final saved = _store.read<String>(StoreKeys.themeMode);
    _mode = ThemeMode.values.firstWhere(
      (m) => m.name == saved,
      orElse: () => ThemeMode.light,
    );
  }

  final LocalStore _store;
  ThemeMode _mode = ThemeMode.light;

  ThemeMode get mode => _mode;
  bool get isDark => _mode == ThemeMode.dark;

  void set(ThemeMode mode) {
    _mode = mode;
    _store.write(StoreKeys.themeMode, mode.name);
    notifyListeners();
  }

  void toggle() => set(isDark ? ThemeMode.light : ThemeMode.dark);
}
