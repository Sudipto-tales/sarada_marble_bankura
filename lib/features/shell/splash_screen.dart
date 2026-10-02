import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/routing/routes.dart';
import '../../core/theme/app_colors.dart';
import '../../core/widgets/app_image.dart';

/// Brand splash. Also gives the local store a beat to finish loading.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..forward();

  late final Animation<double> _fade =
      CurvedAnimation(parent: _c, curve: const Interval(0, 0.6, curve: Curves.easeOut));
  late final Animation<double> _scale =
      Tween(begin: 0.88, end: 1.0).animate(CurvedAnimation(
    parent: _c,
    curve: const Interval(0, 0.75, curve: Curves.easeOutBack),
  ));

  @override
  void initState() {
    super.initState();
    Future.delayed(const Duration(milliseconds: 1500), () {
      if (mounted) {
        Navigator.pushReplacementNamed(context, Routes.shell);
      }
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ink,
      body: Stack(
        fit: StackFit.expand,
        children: [
          Opacity(
            opacity: 0.42,
            child: AppImage('assets/brand/splash.webp', fit: BoxFit.cover),
          ),
          const DecoratedBox(
            decoration: BoxDecoration(gradient: AppColors.inkGradient),
            child: SizedBox.expand(),
          ),
          Center(
            child: FadeTransition(
              opacity: _fade,
              child: ScaleTransition(
                scale: _scale,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    AppImage('assets/brand/logo_mark.webp', width: 132, height: 132),
                    const SizedBox(height: 22),
                    Text(
                      AppConfig.appName.toUpperCase(),
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 26,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 6,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      AppConfig.tagline.toUpperCase(),
                      style: TextStyle(
                        color: AppColors.cyan.withValues(alpha: 0.9),
                        fontSize: 11.5,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 3.4,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 54,
            child: Column(
              children: [
                const SizedBox(
                  width: 26,
                  height: 26,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: AppColors.cyan),
                ),
                const SizedBox(height: 18),
                Text(
                  'Premium marble, seen before you buy',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.6),
                    fontSize: 12,
                    letterSpacing: 0.3,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
