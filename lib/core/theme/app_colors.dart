import 'package:flutter/material.dart';

/// Palette lifted from the Maa Sarada logo: teal/cyan emblem, deep petrol
/// shadows, warm gold accent for premium/offer cues.
class AppColors {
  const AppColors._();

  static const Color ice = Color(0xFFD0F0F8);
  static const Color cyan = Color(0xFF48D0D8);
  static const Color teal = Color(0xFF2BB8CC);
  static const Color deep = Color(0xFF0E6C86);
  static const Color ink = Color(0xFF061826);
  static const Color inkSoft = Color(0xFF0E2534);

  static const Color gold = Color(0xFFC9A24B);
  static const Color goldSoft = Color(0xFFF0E2C0);

  static const Color surface = Color(0xFFF6F9FB);
  static const Color surfaceAlt = Color(0xFFFFFFFF);
  static const Color line = Color(0xFFE2EAEF);
  static const Color muted = Color(0xFF6C7F8A);
  static const Color mutedSoft = Color(0xFF9AAAB4);

  static const Color success = Color(0xFF1E9E6A);
  static const Color warning = Color(0xFFE08A1E);
  static const Color danger = Color(0xFFD9484F);

  static const Color darkSurface = Color(0xFF0B1B26);
  static const Color darkCard = Color(0xFF122836);

  static const LinearGradient brandGradient = LinearGradient(
    colors: [Color(0xFF7FE7F2), teal, deep],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient inkGradient = LinearGradient(
    colors: [inkSoft, ink],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );

  static const LinearGradient goldGradient = LinearGradient(
    colors: [Color(0xFFE7CE8F), gold],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Scrim used over photography so white text stays readable.
  static const LinearGradient photoScrim = LinearGradient(
    colors: [Color(0x00061826), Color(0x99061826), Color(0xE6061826)],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    stops: [0.35, 0.72, 1],
  );
}
