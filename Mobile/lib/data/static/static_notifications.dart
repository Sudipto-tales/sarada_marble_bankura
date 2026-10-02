import '../models/app_notification.dart';

final List<AppNotification> kNotifications = [
  AppNotification(
    id: 'n1',
    title: 'Out for delivery',
    body: 'Order MS2408122 is out for delivery and will arrive today.',
    at: _hoursAgo(3),
    kind: NotificationKind.delivery,
    orderId: 'MS2408122',
  ),
  AppNotification(
    id: 'n2',
    title: 'Slabs cut and crated',
    body: 'Your Statuario slabs for MS2408417 have been polished and crated.',
    at: _hoursAgo(26),
    kind: NotificationKind.order,
    orderId: 'MS2408417',
  ),
  AppNotification(
    id: 'n3',
    title: 'ITALIAN15 expires soon',
    body: '15% off imported Italian marble ends in 12 days.',
    at: _hoursAgo(50),
    kind: NotificationKind.offer,
    read: true,
  ),
  AppNotification(
    id: 'n4',
    title: 'Design saved',
    body: 'Your Luxury Living Room design with Calacatta Gold was saved.',
    at: _hoursAgo(96),
    kind: NotificationKind.design,
    read: true,
  ),
  AppNotification(
    id: 'n5',
    title: 'Price drop on Grey William',
    body: 'Now ₹355 / sq.ft, down from ₹470. Limited lot.',
    at: _hoursAgo(140),
    kind: NotificationKind.offer,
    productId: 'p_grey_william',
    read: true,
  ),
  AppNotification(
    id: 'n6',
    title: 'Order delivered',
    body: 'MS2407988 was delivered. Rate the stone to help other buyers.',
    at: _hoursAgo(330),
    kind: NotificationKind.order,
    orderId: 'MS2407988',
    read: true,
  ),
];

DateTime _hoursAgo(int h) => DateTime.now().subtract(Duration(hours: h));
