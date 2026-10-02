
import '../../data/models/app_notification.dart';
import '../../data/repositories/repositories.dart';
import 'safe_notifier.dart';

class NotificationController extends SafeNotifier {
  NotificationController(this._repo) {
    load();
  }

  final NotificationRepository _repo;

  List<AppNotification> _items = const [];
  bool _loading = true;

  List<AppNotification> get items => List.unmodifiable(_items);
  bool get isLoading => _loading;
  int get unread => _items.where((n) => !n.read).length;

  Future<void> load() async {
    _loading = true;
    notifyListeners();
    _items = await _repo.all();
    _loading = false;
    notifyListeners();
  }

  Future<void> markRead(String id) async {
    await _repo.markRead(id);
    _items = await _repo.all();
    notifyListeners();
  }

  Future<void> markAllRead() async {
    await _repo.markAllRead();
    _items = await _repo.all();
    notifyListeners();
  }
}
