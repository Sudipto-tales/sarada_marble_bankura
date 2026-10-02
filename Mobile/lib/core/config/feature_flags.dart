/// Switches that let optional modules be removed without touching the
/// e-commerce core. Turning either of these off must never break browsing,
/// cart, checkout, orders, wishlist or search.
class FeatureFlags {
  const FeatureFlags._();

  /// 3D / VR room visualization module.
  static const bool visualizationEnabled = true;

  /// Marble quantity + cost calculator module.
  static const bool calculatorEnabled = true;

  /// Product comparison (3-4 products side by side).
  static const bool compareEnabled = true;

  /// Static demo authentication (no auth server in v1).
  static const bool authEnabled = true;
}
