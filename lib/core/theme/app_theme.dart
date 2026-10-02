import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app_colors.dart';
import 'app_dimens.dart';

/// Material 3 theme built around the logo palette.
class AppTheme {
  const AppTheme._();

  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.teal,
      brightness: Brightness.light,
    ).copyWith(
      primary: AppColors.teal,
      onPrimary: Colors.white,
      secondary: AppColors.deep,
      tertiary: AppColors.gold,
      surface: AppColors.surfaceAlt,
      onSurface: AppColors.ink,
      error: AppColors.danger,
    );
    return _base(scheme, AppColors.surface);
  }

  static ThemeData dark() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.teal,
      brightness: Brightness.dark,
    ).copyWith(
      primary: AppColors.cyan,
      onPrimary: AppColors.ink,
      secondary: AppColors.ice,
      tertiary: AppColors.gold,
      surface: AppColors.darkCard,
      onSurface: Colors.white,
      error: AppColors.danger,
    );
    return _base(scheme, AppColors.darkSurface);
  }

  static ThemeData _base(ColorScheme scheme, Color scaffold) {
    final dark = scheme.brightness == Brightness.dark;
    final onSurface = scheme.onSurface;
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);

    TextStyle t(double size, FontWeight w, {double? h, Color? c, double? ls}) =>
        TextStyle(
          fontSize: size,
          fontWeight: w,
          height: h,
          letterSpacing: ls,
          color: c ?? onSurface,
        );

    return base.copyWith(
      scaffoldBackgroundColor: scaffold,
      splashFactory: InkSparkle.splashFactory,
      textTheme: base.textTheme.copyWith(
        displaySmall: t(30, FontWeight.w700, h: 1.15, ls: -0.6),
        headlineMedium: t(24, FontWeight.w700, h: 1.2, ls: -0.4),
        headlineSmall: t(20, FontWeight.w700, h: 1.22, ls: -0.3),
        titleLarge: t(17, FontWeight.w700, h: 1.25),
        titleMedium: t(15, FontWeight.w600, h: 1.3),
        titleSmall: t(13.5, FontWeight.w600, h: 1.3),
        bodyLarge: t(15, FontWeight.w400, h: 1.45),
        bodyMedium: t(13.5, FontWeight.w400, h: 1.45),
        bodySmall: t(12, FontWeight.w400, h: 1.4, c: dark ? AppColors.mutedSoft : AppColors.muted),
        labelLarge: t(14, FontWeight.w600, ls: 0.1),
        labelMedium: t(12, FontWeight.w600, ls: 0.2),
        labelSmall: t(11, FontWeight.w600, ls: 0.3, c: dark ? AppColors.mutedSoft : AppColors.muted),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: dark ? AppColors.darkSurface : Colors.white,
        foregroundColor: onSurface,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: false,
        titleTextStyle: t(17, FontWeight.w700),
        systemOverlayStyle:
            dark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      ),
      cardTheme: CardThemeData(
        color: scheme.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          side: BorderSide(color: dark ? Colors.white10 : AppColors.line),
        ),
      ),
      dividerTheme: DividerThemeData(
        color: dark ? Colors.white12 : AppColors.line,
        thickness: 1,
        space: 1,
      ),
      chipTheme: ChipThemeData(
        backgroundColor: dark ? Colors.white10 : AppColors.surface,
        selectedColor: AppColors.teal.withValues(alpha: 0.14),
        side: BorderSide(color: dark ? Colors.white12 : AppColors.line),
        labelStyle: t(12.5, FontWeight.w600),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppDimens.radiusSm),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 50),
          textStyle: t(15, FontWeight.w700, c: Colors.white),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, 50),
          foregroundColor: scheme.primary,
          side: BorderSide(color: scheme.primary.withValues(alpha: 0.5)),
          textStyle: t(15, FontWeight.w700),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: scheme.primary,
          textStyle: t(14, FontWeight.w700),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: dark ? Colors.white10 : Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        hintStyle: t(14, FontWeight.w400, c: AppColors.mutedSoft),
        border: _field(AppColors.line),
        enabledBorder: _field(dark ? Colors.white24 : AppColors.line),
        focusedBorder: _field(scheme.primary, width: 1.6),
        errorBorder: _field(AppColors.danger),
        focusedErrorBorder: _field(AppColors.danger, width: 1.6),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: dark ? AppColors.darkCard : Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(AppDimens.radiusLg),
          ),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.inkSoft,
        contentTextStyle: t(13.5, FontWeight.w600, c: Colors.white),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: dark ? AppColors.darkCard : Colors.white,
        indicatorColor: AppColors.teal.withValues(alpha: 0.14),
        elevation: 0,
        height: AppDimens.bottomBarHeight,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        labelTextStyle: WidgetStatePropertyAll(t(11, FontWeight.w600)),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.teal,
        linearMinHeight: 3,
      ),
    );
  }

  static OutlineInputBorder _field(Color c, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        borderSide: BorderSide(color: c, width: width),
      );
}
