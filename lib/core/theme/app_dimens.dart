import 'package:flutter/widgets.dart';

/// Spacing / radius scale. Keeps every screen on the same rhythm.
class AppDimens {
  const AppDimens._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 20;
  static const double xxl = 28;
  static const double xxxl = 40;

  static const double radiusSm = 8;
  static const double radiusMd = 14;
  static const double radiusLg = 20;
  static const double radiusXl = 28;

  static const EdgeInsets screenPad = EdgeInsets.symmetric(horizontal: lg);
  static const EdgeInsets cardPad = EdgeInsets.all(md);

  static const double bottomBarHeight = 64;
  static const double stickyBarHeight = 76;

  /// Breakpoint above which grids get an extra column.
  static const double wideBreakpoint = 640;
  static const double tabletBreakpoint = 900;

  static int gridColumns(double width) {
    if (width >= tabletBreakpoint) return 4;
    if (width >= wideBreakpoint) return 3;
    return 2;
  }
}
