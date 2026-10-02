
import '../store/local_store.dart';
import 'safe_notifier.dart';

/// Recently viewed products, search history and the compare tray. All light
/// client-side state that several features read but none of them owns.
class BrowsingController extends SafeNotifier {
  BrowsingController(this._store) {
    final recent = _store.read<List<dynamic>>(StoreKeys.recentlyViewed);
    _recent = recent == null
        ? [
            'p_statuario',
            'p_black_galaxy',
            'p_crema_marfil',
            'p_nero_marquina',
            'p_makrana_white',
          ]
        : recent.map((e) => e.toString()).toList();
    if (recent == null) _persistRecent();
    _searches = (_store.read<List<dynamic>>(StoreKeys.searchHistory) ?? const [])
        .map((e) => e.toString())
        .toList();
  }

  static const int maxRecent = 12;
  static const int maxSearches = 8;
  static const int maxCompare = 4;

  final LocalStore _store;
  late List<String> _recent;
  late List<String> _searches;
  final List<String> _compare = [];

  List<String> get recentlyViewed => List.unmodifiable(_recent);
  List<String> get searchHistory => List.unmodifiable(_searches);
  List<String> get compareIds => List.unmodifiable(_compare);
  bool get canCompare => _compare.length >= 2;
  bool isComparing(String id) => _compare.contains(id);

  void markViewed(String productId) {
    _recent.remove(productId);
    _recent.insert(0, productId);
    if (_recent.length > maxRecent) _recent = _recent.sublist(0, maxRecent);
    _persistRecent();
    notifyListeners();
  }

  void clearRecent() {
    _recent = [];
    _persistRecent();
    notifyListeners();
  }

  void recordSearch(String term) {
    final t = term.trim();
    if (t.isEmpty) return;
    _searches.removeWhere((s) => s.toLowerCase() == t.toLowerCase());
    _searches.insert(0, t);
    if (_searches.length > maxSearches) {
      _searches = _searches.sublist(0, maxSearches);
    }
    _store.write(StoreKeys.searchHistory, _searches);
    notifyListeners();
  }

  void removeSearch(String term) {
    _searches.remove(term);
    _store.write(StoreKeys.searchHistory, _searches);
    notifyListeners();
  }

  void clearSearches() {
    _searches = [];
    _store.write(StoreKeys.searchHistory, _searches);
    notifyListeners();
  }

  /// Returns false when the compare tray is already full.
  bool toggleCompare(String productId) {
    if (_compare.contains(productId)) {
      _compare.remove(productId);
      notifyListeners();
      return true;
    }
    if (_compare.length >= maxCompare) return false;
    _compare.add(productId);
    notifyListeners();
    return true;
  }

  void clearCompare() {
    _compare.clear();
    notifyListeners();
  }

  void _persistRecent() => _store.write(StoreKeys.recentlyViewed, _recent);
}
