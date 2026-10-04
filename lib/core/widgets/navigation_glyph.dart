import 'package:flutter/material.dart';

/// Consistent, hand-drawn line symbols for the floating navigation.
class NavigationGlyph extends StatelessWidget {
  const NavigationGlyph({
    super.key,
    required this.label,
    required this.color,
    this.selected = false,
  });
  final String label;
  final Color color;
  final bool selected;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 26,
    height: 26,
    child: CustomPaint(painter: _GlyphPainter(label, color, selected)),
  );
}

class _GlyphPainter extends CustomPainter {
  const _GlyphPainter(this.label, this.color, this.selected);
  final bool selected;
  final String label;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.scale(size.width / 24, size.height / 24);
    final pen = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.65
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..isAntiAlias = true;
    final fill = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [
          color.withValues(alpha: selected ? .36 : .22),
          color.withValues(alpha: .035),
        ],
      ).createShader(const Rect.fromLTWH(0, 0, 24, 24));
    final path = Path();
    switch (label) {
      case 'Home':
        canvas.drawPath(
          Path()
            ..moveTo(5, 10)
            ..lineTo(12, 4)
            ..lineTo(19, 10)
            ..lineTo(19, 20)
            ..lineTo(5, 20)
            ..close(),
          fill,
        );
        path.moveTo(3, 10);
        path.lineTo(12, 3);
        path.lineTo(21, 10);
        path.moveTo(5, 9);
        path.lineTo(5, 20);
        path.lineTo(10, 20);
        path.lineTo(10, 14);
        path.quadraticBezierTo(12, 12, 14, 14);
        path.lineTo(14, 20);
        path.lineTo(19, 20);
        path.lineTo(19, 9);
      case 'Catalog':
        for (final rect in [
          const Rect.fromLTWH(3, 3, 7, 10),
          const Rect.fromLTWH(14, 3, 7, 6),
          const Rect.fromLTWH(3, 17, 7, 4),
          const Rect.fromLTWH(14, 13, 7, 8),
        ]) {
          canvas.drawRRect(
            RRect.fromRectAndRadius(rect, const Radius.circular(1.8)),
            fill,
          );
          canvas.drawRRect(
            RRect.fromRectAndRadius(rect, const Radius.circular(1.8)),
            pen,
          );
        }
      case 'Room View':
        canvas.drawPath(
          Path()
            ..moveTo(12, 12)
            ..lineTo(21, 7)
            ..lineTo(21, 17)
            ..lineTo(12, 22)
            ..close(),
          fill,
        );
        path.moveTo(12, 2);
        path.lineTo(21, 7);
        path.lineTo(21, 17);
        path.lineTo(12, 22);
        path.lineTo(3, 17);
        path.lineTo(3, 7);
        path.close();
        path.moveTo(3, 7);
        path.lineTo(12, 12);
        path.lineTo(21, 7);
        path.moveTo(12, 12);
        path.lineTo(12, 22);
      case 'Cart':
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            const Rect.fromLTWH(4, 8, 16, 13),
            const Radius.circular(3),
          ),
          fill,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            const Rect.fromLTWH(4, 8, 16, 13),
            const Radius.circular(3),
          ),
          pen,
        );
        path.moveTo(8, 9);
        path.lineTo(8, 6);
        path.cubicTo(8, 1, 16, 1, 16, 6);
        path.lineTo(16, 9);
      case 'Account':
        canvas.drawCircle(const Offset(12, 7), 4, fill);
        canvas.drawPath(
          Path()
            ..moveTo(4, 21)
            ..lineTo(4, 19)
            ..cubicTo(4, 12, 20, 12, 20, 19)
            ..lineTo(20, 21)
            ..close(),
          fill,
        );
        canvas.drawCircle(const Offset(12, 7), 4, pen);
        path.moveTo(4, 21);
        path.lineTo(4, 19);
        path.cubicTo(4, 12, 20, 12, 20, 19);
        path.lineTo(20, 21);
    }
    canvas.drawPath(path, pen);
  }

  @override
  bool shouldRepaint(_GlyphPainter oldDelegate) =>
      oldDelegate.label != label ||
      oldDelegate.color != color ||
      oldDelegate.selected != selected;
}
