import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/widgets/app_image.dart';
import '../../../data/models/product.dart';

/// Large sliding photo with a selectable filmstrip and an uninterrupted loop.
class ProductGallery extends StatefulWidget {
  const ProductGallery({super.key, required this.product});

  final Product product;

  @override
  State<ProductGallery> createState() => _ProductGalleryState();
}

class _ProductGalleryState extends State<ProductGallery>
    with WidgetsBindingObserver {
  final _controller = PageController();
  final _thumbnails = ScrollController();
  Timer? _timer;
  late List<String> _images;
  int _page = 0;
  bool _foreground = true;
  bool _touching = false;
  bool _hovered = false;

  List<String> get _photos => [
    widget.product.image,
    ...widget.product.gallery,
  ].where((path) => path.trim().isNotEmpty).toSet().toList();
  int get _index => _images.isEmpty ? 0 : _page % _images.length;
  bool get _animate =>
      !MediaQuery.disableAnimationsOf(context) &&
      TickerMode.valuesOf(context).enabled;

  @override
  void initState() {
    super.initState();
    _images = _photos;
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    _foreground = state == null || state == AppLifecycleState.resumed;
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _schedule();
  }

  @override
  void didUpdateWidget(covariant ProductGallery oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!listEquals(_images, _photos)) {
      _images = _photos;
      _page = 0;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        if (_controller.hasClients) _controller.jumpToPage(0);
        if (_thumbnails.hasClients) _thumbnails.jumpTo(0);
      });
    }
    _schedule();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    if (!mounted ||
        _images.length < 2 ||
        !_foreground ||
        _touching ||
        _hovered ||
        MediaQuery.disableAnimationsOf(context) ||
        !TickerMode.valuesOf(context).enabled) {
      return;
    }
    precacheImage(
      AssetImage(_images[(_index + 1) % _images.length]),
      context,
      onError: (_, _) {},
    );
    _timer = Timer(const Duration(seconds: 5), () {
      if (!mounted || !_controller.hasClients) return;
      if (ModalRoute.of(context)?.isCurrent == false ||
          _controller.position.isScrollingNotifier.value) {
        _schedule();
        return;
      }
      _goTo(_page + 1);
    });
  }

  void _goTo(int page) {
    if (!_controller.hasClients) return;
    _schedule();
    if (_animate) {
      _controller.animateToPage(
        page,
        duration: const Duration(milliseconds: 500),
        curve: Curves.easeInOutCubic,
      );
    } else {
      _controller.jumpToPage(page);
    }
  }

  void _changed(int page) {
    setState(() => _page = page);
    if (_thumbnails.hasClients) {
      // Keep the selected thumbnail visible, even for longer product galleries.
      final target =
          (_index * 76.0 - (_thumbnails.position.viewportDimension - 68) / 2)
              .clamp(0.0, _thumbnails.position.maxScrollExtent);
      if (_animate) {
        _thumbnails.animateTo(
          target,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      } else {
        _thumbnails.jumpTo(target);
      }
    }
    _schedule();
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _controller.dispose();
    _thumbnails.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_images.isEmpty) return const SizedBox.shrink();
    return MouseRegion(
      onEnter: (_) {
        _hovered = true;
        _schedule();
      },
      onExit: (_) {
        _hovered = false;
        _schedule();
      },
      child: Stack(
        fit: StackFit.expand,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(
              16,
              MediaQuery.paddingOf(context).top + 64,
              16,
              106,
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(24),
              child: Listener(
                onPointerDown: (_) {
                  _touching = true;
                  _schedule();
                },
                onPointerUp: (_) {
                  _touching = false;
                  _schedule();
                },
                onPointerCancel: (_) {
                  _touching = false;
                  _schedule();
                },
                child: NotificationListener<ScrollEndNotification>(
                  onNotification: (_) {
                    // Give each photo a full dwell after automatic or manual slides.
                    _schedule();
                    return false;
                  },
                  child: PageView.builder(
                    controller: _controller,
                    itemCount: _images.length == 1 ? 1 : null,
                    onPageChanged: _changed,
                    itemBuilder: (context, page) {
                      final index = page % _images.length;
                      return Semantics(
                        label:
                            'Product photo ${index + 1} of ${_images.length}. Tap to zoom.',
                        button: true,
                        child: GestureDetector(
                          onTap: () => Navigator.pushNamed(
                            context,
                            Routes.gallery,
                            arguments: GalleryArgs(
                              images: _images,
                              initialIndex: index,
                              title: widget.product.name,
                            ),
                          ),
                          child: AppImage(
                            _images[index],
                            fit: BoxFit.cover,
                            fadeIn: false,
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),
            ),
          ),
          Positioned(
            left: 20,
            right: 20,
            bottom: 12,
            child: Column(
              children: [
                SizedBox(
                  height: 68,
                  child: ListView.separated(
                    controller: _thumbnails,
                    scrollDirection: Axis.horizontal,
                    itemCount: _images.length,
                    separatorBuilder: (_, _) => const SizedBox(width: 8),
                    itemBuilder: (context, i) => Semantics(
                      label: 'Select photo ${i + 1}',
                      selected: _index == i,
                      button: true,
                      child: InkWell(
                        key: ValueKey('product-thumbnail-$i'),
                        borderRadius: BorderRadius.circular(12),
                        onTap: () => _goTo(_page - _index + i),
                        child: AnimatedContainer(
                          duration: Duration(
                            milliseconds:
                                MediaQuery.disableAnimationsOf(context)
                                ? 0
                                : 200,
                          ),
                          width: 68,
                          padding: const EdgeInsets.all(3),
                          decoration: BoxDecoration(
                            color: Theme.of(context).colorScheme.surface,
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(
                              color: _index == i
                                  ? AppColors.coral
                                  : Colors.transparent,
                              width: 2,
                            ),
                          ),
                          child: AppImage(_images[i], radius: 7, fadeIn: false),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  '${_index + 1} / ${_images.length}',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Full-screen pinch-zoom viewer.
class GalleryScreen extends StatefulWidget {
  const GalleryScreen({super.key, required this.args});

  final GalleryArgs args;

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  late final PageController _controller = PageController(
    initialPage: widget.args.initialIndex,
  );
  late int _index = widget.args.initialIndex;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ink,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        foregroundColor: Colors.white,
        title: Text(
          widget.args.title ?? 'Gallery',
          style: const TextStyle(color: Colors.white),
        ),
        actions: [
          Center(
            child: Padding(
              padding: const EdgeInsets.only(right: 16),
              child: Text(
                '${_index + 1} / ${widget.args.images.length}',
                style: const TextStyle(color: Colors.white70, fontSize: 12.5),
              ),
            ),
          ),
        ],
      ),
      body: PageView.builder(
        controller: _controller,
        itemCount: widget.args.images.length,
        onPageChanged: (i) => setState(() => _index = i),
        itemBuilder: (context, i) => InteractiveViewer(
          minScale: 1,
          maxScale: 4,
          child: Center(
            child: AppImage(
              widget.args.images[i],
              fit: BoxFit.contain,
              placeholderColor: AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}
