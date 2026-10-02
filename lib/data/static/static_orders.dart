import '../models/address.dart';
import '../models/cart_item.dart';
import '../models/order.dart';
import 'static_user.dart';

/// Five demo orders spread across the lifecycle so the tracking timeline,
/// returns and reorder flows all have something real to show.
final List<Order> kOrders = [
  _order(
    id: 'MS2408417',
    daysAgo: 2,
    status: OrderStatus.processing,
    items: const [
      CartItem(productId: 'p_statuario', sqFt: 180),
      CartItem(productId: 'p_calacatta_gold', sqFt: 42),
    ],
    address: kDemoAddresses[0],
    payment: PaymentMethod.upi,
    coupon: 'ITALIAN15',
    discount: 18000,
    etaDays: 6,
  ),
  _order(
    id: 'MS2408122',
    daysAgo: 6,
    status: OrderStatus.outForDelivery,
    items: const [
      CartItem(productId: 'p_black_galaxy', sqFt: 64, addedFrom: CartSource.calculator),
    ],
    address: kDemoAddresses[1],
    payment: PaymentMethod.card,
    discount: 0,
    etaDays: 0,
  ),
  _order(
    id: 'MS2407988',
    daysAgo: 21,
    status: OrderStatus.delivered,
    items: const [
      CartItem(productId: 'p_ambaji_white', sqFt: 940),
      CartItem(productId: 'p_emperador_dark', sqFt: 60),
    ],
    address: kDemoAddresses[2],
    payment: PaymentMethod.netBanking,
    coupon: 'BULK20',
    discount: 32000,
    etaDays: -14,
  ),
  _order(
    id: 'MS2407611',
    daysAgo: 48,
    status: OrderStatus.delivered,
    items: const [
      CartItem(productId: 'p_crema_marfil', sqFt: 320, addedFrom: CartSource.visualizer),
    ],
    address: kDemoAddresses[0],
    payment: PaymentMethod.cod,
    discount: 0,
    etaDays: -41,
  ),
  _order(
    id: 'MS2407104',
    daysAgo: 75,
    status: OrderStatus.cancelled,
    items: const [
      CartItem(productId: 'p_onyx_honey', sqFt: 28),
    ],
    address: kDemoAddresses[1],
    payment: PaymentMethod.upi,
    discount: 0,
    etaDays: -68,
  ),
];

Order _order({
  required String id,
  required int daysAgo,
  required OrderStatus status,
  required List<CartItem> items,
  required Address address,
  required PaymentMethod payment,
  required double discount,
  required int etaDays,
  String? coupon,
}) {
  final placed = DateTime.now().subtract(Duration(days: daysAgo));
  final subtotal = _subtotal(items);
  final delivery = subtotal >= 25000 ? 0.0 : 1200.0;
  final tax = (subtotal - discount) * 0.18;
  return Order(
    id: id,
    items: items,
    address: address,
    placedOn: placed,
    status: status,
    timeline: _timeline(placed, status),
    subtotal: subtotal,
    discount: discount,
    deliveryFee: delivery,
    tax: tax,
    paymentMethod: payment,
    expectedDelivery: DateTime.now().add(Duration(days: etaDays)),
    couponCode: coupon,
    invoiceNo: 'INV-$id',
  );
}

/// Rough prices kept local so orders never import the product catalog just to
/// compute a historical total (real orders snapshot the price at purchase).
const Map<String, double> _priceSnapshot = {
  'p_statuario': 640,
  'p_calacatta_gold': 980,
  'p_black_galaxy': 290,
  'p_ambaji_white': 145,
  'p_emperador_dark': 480,
  'p_crema_marfil': 310,
  'p_onyx_honey': 1450,
};

double _subtotal(List<CartItem> items) => items.fold(
      0.0,
      (sum, i) => sum + (_priceSnapshot[i.productId] ?? 250) * i.sqFt,
    );

List<OrderEvent> _timeline(DateTime placed, OrderStatus status) {
  final events = <OrderEvent>[
    OrderEvent(
      status: OrderStatus.placed,
      at: placed,
      note: 'Order placed successfully',
      location: 'Online',
    ),
  ];
  if (status == OrderStatus.cancelled) {
    events.add(OrderEvent(
      status: OrderStatus.cancelled,
      at: placed.add(const Duration(hours: 20)),
      note: 'Order cancelled. Refund initiated to source account.',
    ));
    return events;
  }
  const flow = [
    (OrderStatus.confirmed, 'Payment confirmed, slabs reserved', 'Kishangarh warehouse', 8),
    (OrderStatus.processing, 'Slabs cut, edge-polished and crated', 'Kishangarh warehouse', 36),
    (OrderStatus.shipped, 'Dispatched from warehouse', 'Kishangarh, RJ', 72),
    (OrderStatus.outForDelivery, 'Out for delivery with the site crew', 'Local hub', 120),
    (OrderStatus.delivered, 'Delivered and signed for', 'Delivery address', 132),
  ];
  for (final (s, note, loc, hours) in flow) {
    if (s.step <= status.step) {
      events.add(OrderEvent(
        status: s,
        at: placed.add(Duration(hours: hours)),
        note: note,
        location: loc,
      ));
    }
  }
  return events;
}
