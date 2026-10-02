import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// Asset-only image with a tinted placeholder and a graceful error box.
/// v1 never loads remote images, so there is no network path here at all.
class AppImage extends StatelessWidget {
  const AppImage(
    this.asset, {
    super.key,
    this.fit = BoxFit.cover,
    this.width,
    this.height,
    this.radius = 0,
    this.placeholderColor,
    this.fadeIn = true,
  });

  final String asset;
  final BoxFit fit;
  final double? width;
  final double? height;
  final double radius;
  final Color? placeholderColor;
  final bool fadeIn;

  @override
  Widget build(BuildContext context) {
    Widget image = Image.asset(
      asset,
      fit: fit,
      width: width,
      height: height,
      gaplessPlayback: true,
      frameBuilder: (context, child, frame, wasSync) {
        if (!fadeIn || wasSync) return child;
        return AnimatedOpacity(
          opacity: frame == null ? 0 : 1,
          duration: const Duration(milliseconds: 260),
          curve: Curves.easeOut,
          child: child,
        );
      },
      errorBuilder: (context, _, _) => _fallback(context),
    );
    if (radius > 0) {
      image = ClipRRect(borderRadius: BorderRadius.circular(radius), child: image);
    }
    return Container(
      width: width,
      height: height,
      color: placeholderColor ?? AppColors.line.withValues(alpha: 0.6),
      child: image,
    );
  }

  Widget _fallback(BuildContext context) => Container(
        width: width,
        height: height,
        color: AppColors.line,
        alignment: Alignment.center,
        child: const Icon(Icons.image_not_supported_outlined,
            color: AppColors.mutedSoft, size: 22),
      );
}
