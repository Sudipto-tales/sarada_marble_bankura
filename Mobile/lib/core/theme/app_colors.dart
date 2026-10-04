import 'package:flutter/material.dart';

/// Stone Atelier: warm limestone, quiet sage and fired-clay actions.
class AppColors {
  const AppColors._();

  static const Color ice = Color(0xFFE4E8DA);
  static const Color cyan = Color(0xFFC0C9AE);
  static const Color teal = Color(0xFF647158);
  static const Color deep = Color(0xFF49553F);
  static const Color ink = Color(0xFF292D29);
  static const Color inkSoft = Color(0xFF3B4036);
  static const Color clay = Color(0xFFAD573C);
  static const Color sage = Color(0xFF929D89);

  static const Color gold = Color(0xFFC9A24B);
  static const Color goldSoft = Color(0xFFF0E2C0);

  static const Color surface = Color(0xFFF6F3EC);
  static const Color surfaceAlt = Color(0xFFFFFDF8);
  static const Color line = Color(0xFFE5E0D6);
  static const Color muted = Color(0xFF706E65);
  static const Color mutedSoft = Color(0xFF89877D);

  static const Color success = Color(0xFF1E9E6A);
  static const Color warning = Color(0xFFE08A1E);
  static const Color danger = Color(0xFFD9484F);

  static const Color darkSurface = Color(0xFF1E211C);
  static const Color darkCard = Color(0xFF2A2E26);

  static const LinearGradient brandGradient = LinearGradient(
    colors: [Color(0xFFB96B4D), clay, Color(0xFF96482F)],
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
