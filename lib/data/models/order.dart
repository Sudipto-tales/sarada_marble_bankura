import 'cart_item.dart';
import 'address.dart';

enum OrderStatus { placed, confirmed, processing, shipped, outForDelivery, delivered, cancelled, returned }

extension OrderStatusInfo on OrderStatus {
  String get label => switch (this) {
        OrderStatus.placed => 'Order placed',
        OrderStatus.confirmed => 'Confirmed',
        OrderStatus.processing => 'Processing',
        OrderStatus.shipped => 'Shipped',
        OrderStatus.outForDelivery => 'Out for delivery',
        OrderStatus.delivered => 'Delivered',
        OrderStatus.cancelled => 'Cancelled',
        OrderStatus.returned => 'Returned',
      };

  bool get isTerminal =>
      this == OrderStatus.delivered ||
      this == OrderStatus.cancelled ||
      this == OrderStatus.returned;

  /// Index within the happy-path timeline; -1 for cancelled/returned.
  int get step => switch (this) {
        OrderStatus.placed => 0,
        OrderStatus.confirmed => 1,
        OrderStatus.processing => 2,
        OrderStatus.shipped => 3,
        OrderStatus.outForDelivery => 4,
        OrderStatus.delivered => 5,
        _ => -1,
      };
}

class OrderEvent {
  const OrderEvent({
    required this.status,
    required this.at,
    required this.note,
    this.location,
  });

  final OrderStatus status;
  final DateTime at;
  final String note;
  final String? location;

  Map<String, dynamic> toJson() => {
        'status': status.name,
        'at': at.toIso8601String(),
        'note': note,
        'location': location,
      };

  factory OrderEvent.fromJson(Map<String, dynamic> j) => OrderEvent(
        status: OrderStatus.values
            .firstWhere((e) => e.name == j['status'], orElse: () => OrderStatus.placed),
        at: DateTime.tryParse(j['at'] as String? ?? '') ?? DateTime.now(),
        note: j['note'] as String? ?? '',
        location: j['location'] as String?,
      );
}

enum PaymentMethod { cod, upi, card, netBanking, emi }

extension PaymentMethodLabel on PaymentMethod {
  String get label => switch (this) {
        PaymentMethod.cod => 'Cash on delivery',
        PaymentMethod.upi => 'UPI',
        PaymentMethod.card => 'Credit / Debit card',
        PaymentMethod.netBanking => 'Net banking',
        PaymentMethod.emi => 'EMI',
      };
}

class Order {
  const Order({
    required this.id,
    required this.items,
    required this.address,
    required this.placedOn,
    required this.status,
    required this.timeline,
    required this.subtotal,
    required this.discount,
    required this.deliveryFee,
    required this.tax,
    required this.paymentMethod,
    required this.expectedDelivery,
    this.couponCode,
    this.invoiceNo,
  });

  final String id;
  final List<CartItem> items;
  final Address address;
  final DateTime placedOn;
  final OrderStatus status;
  final List<OrderEvent> timeline;
  final double subtotal;
  final double discount;
  final double deliveryFee;
  final double tax;
  final PaymentMethod paymentMethod;
  final DateTime expectedDelivery;
  final String? couponCode;
  final String? invoiceNo;

  double get total => subtotal - discount + deliveryFee + tax;
  int get itemCount => items.length;
  double get totalSqFt => items.fold(0.0, (s, i) => s + i.sqFt);

  bool get isCancellable =>
      status.step >= 0 && status.step < OrderStatus.shipped.step;
  bool get isReturnable => status == OrderStatus.delivered;

  Order copyWith({OrderStatus? status, List<OrderEvent>? timeline}) => Order(
        id: id,
        items: items,
        address: address,
        placedOn: placedOn,
        status: status ?? this.status,
        timeline: timeline ?? this.timeline,
        subtotal: subtotal,
        discount: discount,
        deliveryFee: deliveryFee,
        tax: tax,
        paymentMethod: paymentMethod,
        expectedDelivery: expectedDelivery,
        couponCode: couponCode,
        invoiceNo: invoiceNo,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'items': items.map((e) => e.toJson()).toList(),
        'address': address.toJson(),
        'placedOn': placedOn.toIso8601String(),
        'status': status.name,
        'timeline': timeline.map((e) => e.toJson()).toList(),
        'subtotal': subtotal,
        'discount': discount,
        'deliveryFee': deliveryFee,
        'tax': tax,
        'paymentMethod': paymentMethod.name,
        'expectedDelivery': expectedDelivery.toIso8601String(),
        'couponCode': couponCode,
        'invoiceNo': invoiceNo,
      };

  factory Order.fromJson(Map<String, dynamic> j) => Order(
        id: j['id'] as String,
        items: (j['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => CartItem.fromJson(e.cast<String, dynamic>()))
            .toList(),
        address: Address.fromJson(
            (j['address'] as Map? ?? {}).cast<String, dynamic>()),
        placedOn: DateTime.tryParse(j['placedOn'] as String? ?? '') ?? DateTime.now(),
        status: OrderStatus.values.firstWhere(
            (e) => e.name == j['status'], orElse: () => OrderStatus.placed),
        timeline: (j['timeline'] as List? ?? [])
            .whereType<Map>()
            .map((e) => OrderEvent.fromJson(e.cast<String, dynamic>()))
            .toList(),
        subtotal: (j['subtotal'] as num?)?.toDouble() ?? 0,
        discount: (j['discount'] as num?)?.toDouble() ?? 0,
        deliveryFee: (j['deliveryFee'] as num?)?.toDouble() ?? 0,
        tax: (j['tax'] as num?)?.toDouble() ?? 0,
        paymentMethod: PaymentMethod.values.firstWhere(
            (e) => e.name == j['paymentMethod'], orElse: () => PaymentMethod.cod),
        expectedDelivery:
            DateTime.tryParse(j['expectedDelivery'] as String? ?? '') ??
                DateTime.now(),
        couponCode: j['couponCode'] as String?,
        invoiceNo: j['invoiceNo'] as String?,
      );
}
