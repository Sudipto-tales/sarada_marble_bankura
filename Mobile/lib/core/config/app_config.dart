/// App-wide static configuration. No network, no secrets: v1 is fully offline.
class AppConfig {
  const AppConfig._();

  static const String appName = 'Maa Sarada';
  static const String tagline = 'Marble & Sanitation';
  static const String supportPhone = '+91 98290 00000';
  static const String supportEmail = 'care@maasarada.example';

  /// Demo credentials (prototype-only local auth).
  static const String demoEmail = 'demo@maasarada.com';
  static const String demoPassword = 'demo1234';

  /// Wastage percentage suggested by the calculator by default.
  static const double defaultWastagePercent = 8;

  static const double freeDeliveryAbove = 25000;
  static const double deliveryCharge = 1200;
  static const double gstPercent = 18;
}
