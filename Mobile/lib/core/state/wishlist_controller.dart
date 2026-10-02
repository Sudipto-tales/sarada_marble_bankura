
import '../store/local_store.dart';
import 'safe_notifier.dart';

class WishlistController extends SafeNotifier {
  WishlistController(this._store) {
    final raw = _store.read<List<dynamic>>(StoreKeys.wishlist);
    _ids = raw == null
        ? {
            // Demo seed so the wishlist is not empty on first run.
            'p_calacatta_gold',
            'p_statuario',
            'p_onyx_honey',
            'p_black_galaxy',
            'p_grey_william',
          }
        : raw.map((e) => e.toString()).toSet();
    if (raw == null) _persist();
  }

  final LocalStore _store;
  late Set<String> _ids;

  List<String> get ids => _ids.toList();
  int get count => _ids.length;
  bool contains(String id) => _ids.contains(id);

  /// Returns true if the product ended up in the wishlist.
  bool toggle(String id) {
    final added = !_ids.contains(id);
    if (added) {
      _ids.add(id);
    } else {
      _ids.remove(id);
    }
    _persist();
    notifyListeners();
    return added;
  }

  void remove(String id) {
    if (_ids.remove(id)) {
      _persist();
      notifyListeners();
    }
  }

  void clear() {
    _ids.clear();
    _persist();
    notifyListeners();
  }

  void _persist() => _store.write(StoreKeys.wishlist, _ids.toList());
}
