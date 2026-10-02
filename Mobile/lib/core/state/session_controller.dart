
import '../../data/models/address.dart';
import '../../data/models/app_user.dart';
import '../../data/repositories/repositories.dart';
import 'safe_notifier.dart';

/// Prototype auth + address book. Static/local only: no auth server, no tokens.
class SessionController extends SafeNotifier {
  SessionController(this._repo) {
    restore();
  }

  final UserRepository _repo;

  AppUser? _user;
  List<Address> _addresses = const [];
  bool _busy = false;
  bool _restored = false;
  String? _error;

  AppUser? get user => _user;
  bool get isLoggedIn => _user != null;
  bool get isBusy => _busy;
  bool get isRestored => _restored;
  String? get error => _error;
  List<Address> get addresses => List.unmodifiable(_addresses);

  Address? get defaultAddress {
    if (_addresses.isEmpty) return null;
    return _addresses.firstWhere((a) => a.isDefault,
        orElse: () => _addresses.first);
  }

  Future<void> restore() async {
    _user = await _repo.current();
    if (_user != null) await loadAddresses();
    _restored = true;
    notifyListeners();
  }

  Future<bool> signIn(String email, String password) async {
    _busy = true;
    _error = null;
    notifyListeners();
    try {
      _user = await _repo.signIn(email, password);
      await loadAddresses();
      return true;
    } on RepositoryException catch (e) {
      _error = e.message;
      return false;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  Future<bool> register(
      String name, String email, String phone, String password) async {
    _busy = true;
    _error = null;
    notifyListeners();
    try {
      _user = await _repo.register(name, email, phone, password);
      await loadAddresses();
      return true;
    } on RepositoryException catch (e) {
      _error = e.message;
      return false;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  Future<void> signOut() async {
    await _repo.signOut();
    _user = null;
    notifyListeners();
  }

  Future<void> updateProfile(AppUser user) async {
    _user = await _repo.updateProfile(user);
    notifyListeners();
  }

  Future<void> loadAddresses() async {
    _addresses = await _repo.addresses();
    notifyListeners();
  }

  Future<void> saveAddress(Address address) async {
    await _repo.saveAddress(address);
    await loadAddresses();
  }

  Future<void> deleteAddress(String id) async {
    await _repo.deleteAddress(id);
    await loadAddresses();
  }

  Future<void> setDefaultAddress(String id) async {
    await _repo.setDefaultAddress(id);
    await loadAddresses();
  }

  void clearError() {
    _error = null;
    notifyListeners();
  }
}
