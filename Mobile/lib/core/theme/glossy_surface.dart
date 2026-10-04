import 'package:flutter/material.dart';

/// Pearl highlights and a shaded lower edge, shared by showroom surfaces.
BoxDecoration glossySurface(BuildContext context, {double radius = 24}) {
  final scheme = Theme.of(context).colorScheme;
  final dark = scheme.brightness == Brightness.dark;
  return BoxDecoration(
    gradient: LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      stops: const [0, .42, .46, 1],
      colors: [
        Color.lerp(scheme.surface, Colors.white, dark ? .12 : .85)!,
        scheme.surface,
        Color.alphaBlend(
          scheme.primary.withValues(alpha: .045),
          scheme.surface,
        ),
        Color.alphaBlend(scheme.primary.withValues(alpha: .1), scheme.surface),
      ],
    ),
    borderRadius: BorderRadius.circular(radius),
    border: Border.all(color: Colors.white.withValues(alpha: dark ? .16 : .9)),
    boxShadow: [
      BoxShadow(
        color: Colors.black.withValues(alpha: dark ? .18 : .065),
        blurRadius: 18,
        offset: const Offset(0, 6),
      ),
    ],
  );
}

class GlossyBackdrop extends StatelessWidget {
  const GlossyBackdrop({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final base = theme.scaffoldBackgroundColor;
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          stops: const [0, .4, .7, 1],
          colors: [
            Color.lerp(base, Colors.white, dark ? .035 : .75)!,
            base,
            Color.alphaBlend(
              theme.colorScheme.primary.withValues(alpha: .07),
              base,
            ),
            base,
          ],
        ),
      ),
      child: Theme(
        data: theme.copyWith(scaffoldBackgroundColor: Colors.transparent),
        child: child,
      ),
    );
  }
}
