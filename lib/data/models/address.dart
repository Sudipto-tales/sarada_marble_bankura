class Address {
  const Address({
    required this.id,
    required this.name,
    required this.phone,
    required this.line1,
    required this.line2,
    required this.city,
    required this.state,
    required this.pincode,
    this.label = 'Home',
    this.isDefault = false,
  });

  final String id;
  final String name;
  final String phone;
  final String line1;
  final String line2;
  final String city;
  final String state;
  final String pincode;
  final String label;
  final bool isDefault;

  String get formatted =>
      [line1, if (line2.trim().isNotEmpty) line2, '$city, $state $pincode']
          .join(', ');

  Address copyWith({
    String? name,
    String? phone,
    String? line1,
    String? line2,
    String? city,
    String? state,
    String? pincode,
    String? label,
    bool? isDefault,
  }) =>
      Address(
        id: id,
        name: name ?? this.name,
        phone: phone ?? this.phone,
        line1: line1 ?? this.line1,
        line2: line2 ?? this.line2,
        city: city ?? this.city,
        state: state ?? this.state,
        pincode: pincode ?? this.pincode,
        label: label ?? this.label,
        isDefault: isDefault ?? this.isDefault,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'phone': phone,
        'line1': line1,
        'line2': line2,
        'city': city,
        'state': state,
        'pincode': pincode,
        'label': label,
        'isDefault': isDefault,
      };

  factory Address.fromJson(Map<String, dynamic> j) => Address(
        id: j['id'] as String,
        name: j['name'] as String? ?? '',
        phone: j['phone'] as String? ?? '',
        line1: j['line1'] as String? ?? '',
        line2: j['line2'] as String? ?? '',
        city: j['city'] as String? ?? '',
        state: j['state'] as String? ?? '',
        pincode: j['pincode'] as String? ?? '',
        label: j['label'] as String? ?? 'Home',
        isDefault: j['isDefault'] as bool? ?? false,
      );
}
