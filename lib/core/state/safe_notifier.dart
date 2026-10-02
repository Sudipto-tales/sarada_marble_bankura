import 'package:flutter/foundation.dart';

/// A [ChangeNotifier] that ignores notifications raised after disposal.
///
/// Controllers here load from repositories asynchronously, so a screen can be
/// torn down while a request is still in flight. Notifying a disposed listener
/// is a hard error in Flutter, and it is never something the caller can fix at
/// the call site — so swallow it once, here.
abstract class SafeNotifier extends ChangeNotifier {
  bool _disposed = false;

  bool get isDisposed => _disposed;

  @override
  void notifyListeners() {
    if (_disposed) return;
    super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
