import 'package:flutter/material.dart';

/// A restrained entrance and touch response, with no perpetual grid tickers.
class StoneMotion extends StatefulWidget {
  const StoneMotion({super.key, required this.child});
  final Widget child;

  @override
  State<StoneMotion> createState() => _StoneMotionState();
}

class _StoneMotionState extends State<StoneMotion> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.disableAnimationsOf(context);
    return Listener(
      onPointerDown: (_) => setState(() => _pressed = true),
      onPointerUp: (_) => setState(() => _pressed = false),
      onPointerCancel: (_) => setState(() => _pressed = false),
      child: AnimatedScale(
        scale: _pressed && !reduce ? 0.975 : 1,
        duration: Duration(milliseconds: reduce ? 0 : 160),
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: reduce ? 1 : 0, end: 1),
          duration: Duration(milliseconds: reduce ? 0 : 420),
          curve: Curves.easeOutCubic,
          builder: (context, value, child) => Transform.translate(
            offset: Offset(0, 12 * (1 - value)),
            child: Opacity(opacity: value, child: child),
          ),
          child: widget.child,
        ),
      ),
    );
  }
}

/// Tints come from catalog metadata; the stone photograph is never recolored.
Color stoneTint(String color, Brightness brightness) {
  final name = color.toLowerCase();
  final Color tint;
  if (name.contains('green')) {
    tint = const Color(0xFFDDE4D5);
  } else if (name.contains('black') ||
      name.contains('grey') ||
      name.contains('gray')) {
    tint = const Color(0xFFDEDFDD);
  } else if (name.contains('pink') || name.contains('red')) {
    tint = const Color(0xFFEEDBD4);
  } else if (name.contains('beige') ||
      name.contains('brown') ||
      name.contains('gold')) {
    tint = const Color(0xFFECE0CB);
  } else {
    tint = const Color(0xFFEDEBE4);
  }
  return brightness == Brightness.dark
      ? Color.lerp(const Color(0xFF252920), tint, 0.14)!
      : tint;
}

/// Only the hero floats continuously, and only while its page is visible.
class FloatingStone extends StatefulWidget {
  const FloatingStone({super.key, required this.child});
  final Widget child;
  @override
  State<FloatingStone> createState() => _FloatingStoneState();
}

class _FloatingStoneState extends State<FloatingStone>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 2800),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context) ||
        !TickerMode.valuesOf(context).enabled) {
      _controller.stop();
    } else if (!_controller.isAnimating) {
      _controller.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.disableAnimationsOf(context);
    return AnimatedBuilder(
      animation: _controller,
      child: widget.child,
      builder: (_, child) => Transform.translate(
        offset: Offset(
          0,
          reduce ? 0 : -4 * Curves.easeInOut.transform(_controller.value),
        ),
        child: child,
      ),
    );
  }
}
