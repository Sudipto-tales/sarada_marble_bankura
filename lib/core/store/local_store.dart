import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';

/// Tiny JSON key/value store backed by files in the app documents directory.
///
/// Deliberately dependency-free (no shared_preferences): v1 must run on local
/// assets and local storage only. On web, or if the platform channel is
/// unavailable, it silently degrades to an in-memory map so the UI still works.
class LocalStore {
  LocalStore._(this._dir);

  final Directory? _dir;
  final Map<String, Object?> _memory = {};

  static LocalStore? _instance;
  static LocalStore get instance =>
      _instance ?? (throw StateError('LocalStore.init() not called'));

  static Future<LocalStore> init() async {
    if (_instance != null) return _instance!;
    Directory? dir;
    if (!kIsWeb) {
      try {
        dir = await _documentsDir();
        if (dir != null && !dir.existsSync()) dir.createSync(recursive: true);
      } catch (_) {
        dir = null;
      }
    }
    _instance = LocalStore._(dir);
    await _instance!._load();
    return _instance!;
  }

  static Future<Directory?> _documentsDir() async {
    // Resolve without plugins: use the platform's conventional locations.
    final env = Platform.environment;
    String? base;
    if (Platform.isAndroid) {
      base = '/data/data/com.maasarada.maa_sarada/files';
    } else if (Platform.isIOS || Platform.isMacOS) {
      base = env['HOME'] == null ? null : '${env['HOME']}/Documents';
    } else {
      base = env['HOME'] == null ? null : '${env['HOME']}/.maa_sarada';
    }
    if (base == null) return null;
    return Directory('$base/maa_sarada_store');
  }

  File? get _file => _dir == null ? null : File('${_dir.path}/store.json');

  Future<void> _load() async {
    final f = _file;
    if (f == null || !f.existsSync()) return;
    try {
      final raw = await f.readAsString();
      final map = jsonDecode(raw);
      if (map is Map<String, dynamic>) _memory.addAll(map);
    } catch (_) {
      // Corrupt store: start clean rather than crashing the app.
      _memory.clear();
    }
  }

  Timer? _flush;

  void _scheduleFlush() {
    _flush?.cancel();
    _flush = Timer(const Duration(milliseconds: 220), _write);
  }

  Future<void> _write() async {
    final f = _file;
    if (f == null) return;
    try {
      await f.writeAsString(jsonEncode(_memory), flush: true);
    } catch (_) {
      // Read-only sandbox: keep running from memory.
    }
  }

  T? read<T>(String key) {
    final v = _memory[key];
    return v is T ? v : null;
  }

  List<Map<String, dynamic>> readList(String key) {
    final v = _memory[key];
    if (v is List) {
      return v.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    }
    return const [];
  }

  Map<String, dynamic> readMap(String key) {
    final v = _memory[key];
    if (v is Map) return v.cast<String, dynamic>();
    return <String, dynamic>{};
  }

  void write(String key, Object? value) {
    if (value == null) {
      _memory.remove(key);
    } else {
      _memory[key] = value;
    }
    _scheduleFlush();
  }

  Future<void> clear() async {
    _memory.clear();
    await _write();
  }

  /// Test/bootstrap hook.
  @visibleForTesting
  static void overrideInstance(LocalStore store) => _instance = store;

  /// In-memory store with no file behind it — used by tests and by any host
  /// (web, read-only sandbox) where the documents directory is unavailable.
  @visibleForTesting
  static LocalStore memory() => LocalStore._(null);
}

/// Storage keys in one place so no widget invents its own string.
class StoreKeys {
  const StoreKeys._();

  static const String user = 'user';
  static const String cart = 'cart';
  static const String wishlist = 'wishlist';
  static const String addresses = 'addresses';
  static const String orders = 'orders';
  static const String recentlyViewed = 'recently_viewed';
  static const String searchHistory = 'search_history';
  static const String savedDesigns = 'saved_designs';
  static const String notifications = 'notifications_read';
  static const String themeMode = 'theme_mode';
  static const String seeded = 'seeded_v1';
}
