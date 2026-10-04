import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import 'app_image.dart';

/// Each mounted card owns its own random clock; duplicate photos never rotate.
class ProductImageGallery extends StatefulWidget {
  const ProductImageGallery({
    super.key,
    required this.images,
    this.placeholderColor,
  });

  final List<String> images;
  final Color? placeholderColor;

  @override
  State<ProductImageGallery> createState() => _ProductImageGalleryState();
}

class _ProductImageGalleryState extends State<ProductImageGallery>
    with WidgetsBindingObserver {
  final _random = Random();
  Timer? _timer;
  late List<String> _images;
  int _index = 0;
  bool _enabled = false;
  bool _foreground = true;
  bool _hovered = false;

  List<String> get _uniqueImages =>
      widget.images.where((path) => path.trim().isNotEmpty).toSet().toList();

  @override
  void initState() {
    super.initState();
    _images = _uniqueImages;
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    _foreground = state == null || state == AppLifecycleState.resumed;
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _enabled =
        !MediaQuery.disableAnimationsOf(context) &&
        TickerMode.valuesOf(context).enabled;
    _schedule();
  }

  @override
  void didUpdateWidget(covariant ProductImageGallery oldWidget) {
    super.didUpdateWidget(oldWidget);
    final images = _uniqueImages;
    if (!listEquals(_images, images)) {
      _images = images;
      _index = 0;
      _schedule();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    if (!_enabled || !_foreground || _hovered || _images.length < 2) return;
    // Decode the next local photo during the dwell, before the slide starts.
    precacheImage(
      AssetImage(_images[(_index + 1) % _images.length]),
      context,
      onError: (_, _) {},
    );
    _timer = Timer(Duration(milliseconds: 1000 + _random.nextInt(2001)), () {
      if (!mounted) return;
      setState(() => _index = (_index + 1) % _images.length);
      _schedule();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MouseRegion(
    onEnter: (_) {
      _hovered = true;
      _schedule();
    },
    onExit: (_) {
      _hovered = false;
      _schedule();
    },
    child: ClipRect(
      child: Stack(
        fit: StackFit.expand,
        children: [
          AnimatedSwitcher(
            duration: Duration(milliseconds: _enabled ? 550 : 0),
            switchInCurve: Curves.easeInOutCubic,
            switchOutCurve: Curves.easeInOutCubic,
            layoutBuilder: (current, previous) =>
                Stack(fit: StackFit.expand, children: [...previous, ?current]),
            transitionBuilder: (child, animation) {
              // Outgoing photo moves left; the new photo enters from the right.
              final incoming =
                  _images.isNotEmpty && child.key == ValueKey(_images[_index]);
              return SlideTransition(
                position: Tween<Offset>(
                  begin: Offset(incoming ? 1 : -1, 0),
                  end: Offset.zero,
                ).animate(animation),
                child: child,
              );
            },
            child: _images.isEmpty
                ? const SizedBox.shrink()
                : AppImage(
                    _images[_index],
                    key: ValueKey(_images[_index]),
                    fit: BoxFit.cover,
                    fadeIn: false,
                    placeholderColor: widget.placeholderColor,
                  ),
          ),
          if (_images.length > 1)
            Positioned(
              left: 0,
              right: 0,
              bottom: 8,
              child: Center(
                child: Semantics(
                  label: 'Photo ${_index + 1} of ${_images.length}',
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 7,
                      vertical: 5,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: .38),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: List.generate(
                        _images.length,
                        (i) => Container(
                          width: i == _index ? 12 : 4,
                          height: 4,
                          margin: const EdgeInsets.symmetric(horizontal: 2),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(
                              alpha: i == _index ? 1 : .5,
                            ),
                            borderRadius: BorderRadius.circular(3),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    ),
  );
}
