enum NotificationKind { order, offer, delivery, design, general }

class AppNotification {
  const AppNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.at,
    required this.kind,
    this.orderId,
    this.productId,
    this.read = false,
  });

  final String id;
  final String title;
  final String body;
  final DateTime at;
  final NotificationKind kind;
  final String? orderId;
  final String? productId;
  final bool read;

  AppNotification markRead() => AppNotification(
        id: id,
        title: title,
        body: body,
        at: at,
        kind: kind,
        orderId: orderId,
        productId: productId,
        read: true,
      );
}
