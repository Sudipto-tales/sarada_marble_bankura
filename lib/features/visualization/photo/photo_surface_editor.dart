import 'dart:math' as math;
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import '../../../data/models/photo_surface.dart';
import 'perspective_mapper.dart';

enum _Tool { corners, protect }

class PhotoSurfaceEditor extends StatefulWidget {
  const PhotoSurfaceEditor({
    super.key,
    required this.photo,
    required this.label,
    this.initial,
  });
  final ui.Image photo;
  final String label;
  final PhotoSurface? initial;
  @override
  State<PhotoSurfaceEditor> createState() => _PhotoSurfaceEditorState();
}

class _PhotoSurfaceEditorState extends State<PhotoSurfaceEditor> {
  late final List<Offset> _corners = List.of(widget.initial?.corners ?? []);
  late final List<Offset> _protected = List.of(widget.initial?.exclusions ?? []);
  late final _width = TextEditingController(
    text: widget.initial?.widthMetres?.toString() ?? '',
  );
  late final _height = TextEditingController(
    text: widget.initial?.heightMetres?.toString() ?? '',
  );
  _Tool _tool = _Tool.corners;
  int? _dragCorner;
  String? _error;
  @override
  void dispose() {
    _width.dispose();
    _height.dispose();
    super.dispose();
  }

  Offset _normal(Offset p, Size size) =>
      Offset((p.dx / size.width).clamp(0, 1), (p.dy / size.height).clamp(0, 1));
  void _stamp(Offset p) {
    if (_protected.length >= 2500) return;
    if (_protected.isEmpty || (_protected.last - p).distance > .007) {
      _protected.add(p);
    }
  }

  void _save() {
    if (!PerspectiveMapper.isValid(_corners)) {
      setState(
        () => _error = 'Mark four corners clockwise, without crossing edges.',
      );
      return;
    }
    final w = double.tryParse(_width.text), h = double.tryParse(_height.text);
    if ((_width.text.isNotEmpty || _height.text.isNotEmpty) &&
        (w == null ||
            h == null ||
            !w.isFinite ||
            !h.isFinite ||
            w <= 0 ||
            h <= 0 ||
            w > 100 ||
            h > 100)) {
      setState(
        () => _error =
            'Enter both measured dimensions between 0 and 100 metres, or leave both blank.',
      );
      return;
    }
    Navigator.pop(
      context,
      PhotoSurface(
        corners: _corners,
        exclusions: _protected,
        widthMetres: w,
        heightMetres: h,
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text('Mark ${widget.label}')),
    body: SafeArea(
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Text(
              _tool == _Tool.corners
                  ? 'Tap top-left, top-right, bottom-right, bottom-left of the surface. Drag a corner to adjust.'
                  : 'Brush over furniture and fixtures to keep the original photo visible.',
            ),
          ),
          Expanded(
            child: LayoutBuilder(
              builder: (context, box) {
                final source = Size(
                  widget.photo.width.toDouble(),
                  widget.photo.height.toDouble(),
                );
                final fitted = applyBoxFit(
                  BoxFit.contain,
                  source,
                  box.biggest,
                ).destination;
                return Center(
                  child: SizedBox(
                    width: fitted.width,
                    height: fitted.height,
                    child: GestureDetector(
                      onTapDown: (d) => setState(() {
                        final p = _normal(d.localPosition, fitted);
                        if (_tool == _Tool.protect) {
                          _stamp(p);
                        } else if (_corners.length < 4) {
                          _corners.add(p);
                        }
                      }),
                      onPanStart: (d) {
                        if (_tool == _Tool.protect) {
                          setState(
                            () => _stamp(_normal(d.localPosition, fitted)),
                          );
                          return;
                        }
                        var distance = 36.0;
                        _dragCorner = null;
                        for (var i = 0; i < _corners.length; i++) {
                          final p = Offset(
                            _corners[i].dx * fitted.width,
                            _corners[i].dy * fitted.height,
                          );
                          final delta = (p - d.localPosition).distance;
                          if (delta < distance) {
                            distance = delta;
                            _dragCorner = i;
                          }
                        }
                      },
                      onPanUpdate: (d) => setState(() {
                        final p = _normal(d.localPosition, fitted);
                        if (_tool == _Tool.protect) {
                          _stamp(p);
                        } else if (_dragCorner != null) {
                          _corners[_dragCorner!] = p;
                        }
                      }),
                      onPanEnd: (_) => _dragCorner = null,
                      child: CustomPaint(
                        painter: _EditorPainter(
                          widget.photo,
                          List.of(_corners),
                          List.of(_protected),
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
          ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.sizeOf(context).height * .38,
            ),
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  SegmentedButton<_Tool>(
                    segments: const [
                      ButtonSegment(
                        value: _Tool.corners,
                        label: Text('Corners'),
                      ),
                      ButtonSegment(
                        value: _Tool.protect,
                        label: Text('Protect furniture'),
                      ),
                    ],
                    selected: {_tool},
                    onSelectionChanged: (v) => setState(() => _tool = v.single),
                  ),
                  Wrap(
                    spacing: 12,
                    children: [
                      TextButton(
                        onPressed: () => setState(() {
                          if (_tool == _Tool.corners && _corners.isNotEmpty) {
                            _corners.removeLast();
                          }
                          if (_tool == _Tool.protect && _protected.isNotEmpty) {
                            _protected.removeLast();
                          }
                        }),
                        child: const Text('Undo'),
                      ),
                      TextButton(
                        onPressed: () => setState(() {
                          if (_tool == _Tool.corners) {
                            _corners.clear();
                          } else {
                            _protected.clear();
                          }
                        }),
                        child: const Text('Reset tool'),
                      ),
                    ],
                  ),
                  const Text(
                    'Optional: measured surface size (metres). Leave blank for a visual preview only.',
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _width,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: const InputDecoration(
                            labelText: 'Width (m)',
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: TextField(
                          controller: _height,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: const InputDecoration(
                            labelText: 'Length / height (m)',
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.all(8),
                      child: Text(
                        _error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: _save,
                      child: const Text('Apply surface'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class _EditorPainter extends CustomPainter {
  _EditorPainter(this.photo, this.corners, this.protected);
  final ui.Image photo;
  final List<Offset> corners, protected;
  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawImageRect(
      photo,
      Rect.fromLTWH(0, 0, photo.width.toDouble(), photo.height.toDouble()),
      Offset.zero & size,
      Paint(),
    );
    final points = [
      for (final p in corners) Offset(p.dx * size.width, p.dy * size.height),
    ];
    if (points.length > 1) {
      final path = Path()..addPolygon(points, points.length == 4);
      canvas.drawPath(
        path,
        Paint()..color = Colors.tealAccent.withValues(alpha: .18),
      );
      canvas.drawPath(
        path,
        Paint()
          ..color = Colors.tealAccent
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2,
      );
    }
    for (final p in protected) {
      canvas.drawCircle(
        Offset(p.dx * size.width, p.dy * size.height),
        math.min(size.width, size.height) * .025,
        Paint()..color = Colors.orange.withValues(alpha: .35),
      );
    }
    for (var i = 0; i < points.length; i++) {
      canvas.drawCircle(points[i], 12, Paint()..color = Colors.white);
      final text = TextPainter(
        text: TextSpan(
          text: '${i + 1}',
          style: const TextStyle(
            color: Colors.black,
            fontSize: 13,
            fontWeight: FontWeight.bold,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      text.paint(canvas, points[i] - Offset(text.width / 2, text.height / 2));
    }
  }

  @override
  bool shouldRepaint(covariant _EditorPainter old) => true;
}
