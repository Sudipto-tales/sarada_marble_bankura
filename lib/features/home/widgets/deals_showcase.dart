import 'dart:async';

import 'package:flutter/material.dart';
import '../../../core/routing/routes.dart';
import '../../../core/widgets/app_image.dart';
import '../../../data/models/coupon.dart';

/// Repository-driven promotions; no layout or copy is tied to a product list.
class DealsShowcase extends StatefulWidget {
  const DealsShowcase({super.key, required this.banners});
  final List<PromoBanner> banners;
  @override
  State<DealsShowcase> createState() => _DealsShowcaseState();
}

class _DealsShowcaseState extends State<DealsShowcase>
    with WidgetsBindingObserver {
  final _pages = PageController();
  Timer? _timer;
  int _page = 0;
  bool _touching = false;
  bool _resumed = true;
  int get _index => widget.banners.isEmpty ? 0 : _page % widget.banners.length;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  void _schedule() {
    _timer?.cancel();
    if (!mounted ||
        widget.banners.length < 2 ||
        _touching ||
        !_resumed ||
        MediaQuery.disableAnimationsOf(context) ||
        !TickerMode.valuesOf(context).enabled) {
      return;
    }
    _timer = Timer(const Duration(seconds: 3), () {
      if (!mounted || !_pages.hasClients) return;
      if (ModalRoute.of(context)?.isCurrent == false ||
          _pages.position.isScrollingNotifier.value) {
        _schedule();
        return;
      }
      _goTo(_page + 1);
    });
  }

  void _goTo(int page) {
    _timer?.cancel();
    if (!_pages.hasClients) return;
    _pages
        .animateToPage(
          page,
          duration: Duration(
            milliseconds: MediaQuery.disableAnimationsOf(context) ? 0 : 650,
          ),
          curve: Curves.easeInOutCubic,
        )
        .then((_) {
          if (mounted) _schedule();
        });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _schedule();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _resumed = state == AppLifecycleState.resumed;
    _schedule();
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _pages.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant DealsShowcase oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.banners.length != widget.banners.length) {
      _page = 0;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && _pages.hasClients) _pages.jumpToPage(0);
      });
    }
    _schedule();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.banners.isEmpty) return const SizedBox.shrink();
    final scale = MediaQuery.textScalerOf(context).scale(14) / 14;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [

          SizedBox(
            height: 288 + (scale - 1) * 210,
            child: Listener(
              onPointerDown: (_) {
                _touching = true;
                _timer?.cancel();
              },
              onPointerUp: (_) {
                _touching = false;
                _schedule();
              },
              onPointerCancel: (_) {
                _touching = false;
                _schedule();
              },
              child: ClipRRect(
                borderRadius: BorderRadius.circular(24),
                child: PageView.builder(
                  controller: _pages,
                  itemCount: widget.banners.length == 1 ? 1 : null,
                  onPageChanged: (value) {
                    setState(() => _page = value);
                    _schedule();
                  },
                  itemBuilder: (context, i) => _DealCard(
                    banner: widget.banners[i % widget.banners.length],
                    active: i == _page,
                  ),
                ),
              ),
            ),
          ),
          if (widget.banners.length > 1)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Wrap(
                alignment: WrapAlignment.center,
                children: [
                  SizedBox(
                    width: double.infinity,
                    child: Wrap(
                      alignment: WrapAlignment.center,
                      spacing: 4,
                      children: [
                        for (var i = 0; i < widget.banners.length; i++)
                          Semantics(
                            label: 'Deal ${i + 1} of ${widget.banners.length}',
                            selected: i == _index,
                            button: true,
                            child: InkWell(
                              borderRadius: BorderRadius.circular(24),
                              onTap: () => _goTo(_page - _index + i),
                              child: SizedBox(
                                width: 44,
                                height: 44,
                                child: Center(
                                  child: AnimatedContainer(
                                    duration: Duration(
                                      milliseconds:
                                          MediaQuery.disableAnimationsOf(
                                            context,
                                          )
                                          ? 0
                                          : 300,
                                    ),
                                    curve: Curves.easeInOutCubic,
                                    width: i == _index ? 24 : 7,
                                    height: 7,
                                    decoration: BoxDecoration(
                                      borderRadius: BorderRadius.circular(8),
                                      color: Theme.of(context)
                                          .colorScheme
                                          .primary
                                          .withValues(
                                            alpha: i == _index ? 1 : .22,
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
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _DealCard extends StatelessWidget {
  const _DealCard({required this.banner, required this.active});
  final bool active;
  final PromoBanner banner;
  void _open(BuildContext context) {
    if (banner.productId != null) {
      Navigator.pushNamed(
        context,
        Routes.productDetails,
        arguments: ProductArgs(banner.productId!),
      );
    } else {
      Navigator.pushNamed(
        context,
        Routes.catalog,
        arguments: CatalogArgs(
          categoryId: banner.categoryId,
          title: banner.title.isEmpty ? 'Top deals' : banner.title,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final background = Color(banner.backgroundColor);
    final foreground =
        ThemeData.estimateBrightnessForColor(background) == Brightness.dark
        ? Colors.white
        : const Color(0xFF18271C);
    final animate =
        active &&
        !MediaQuery.disableAnimationsOf(context) &&
        TickerMode.valuesOf(context).enabled;
    final image = AppImage(
      animate ? banner.animatedImage ?? banner.image : banner.image,
      fit: BoxFit.cover,
    );
    if (banner.imageOnly) {
      return Semantics(
        label: banner.title.isEmpty ? 'Explore deal' : banner.title,
        button: true,
        child: GestureDetector(
          onTap: () => _open(context),
          child: SizedBox.expand(child: image),
        ),
      );
    }
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color.lerp(background, Colors.white, .35)!,
            background,
            Color.lerp(background, Colors.black, .06)!,
          ],
          stops: const [0, .5, 1],
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 16, 20),
        child: Row(
          children: [
            Expanded(
              flex: 6,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  if (banner.badge.isNotEmpty)
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 9,
                        vertical: 5,
                      ),
                      decoration: BoxDecoration(
                        color: foreground,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        banner.badge,
                        style: TextStyle(
                          color: background,
                          fontSize: 10,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  const SizedBox(height: 10),
                  Text(
                    banner.title,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foreground,
                      fontSize: 22,
                      height: 1.08,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -.6,
                    ),
                  ),
                  if (banner.subtitle.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(
                      banner.subtitle,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: foreground.withValues(alpha: .8),
                        fontSize: 11,
                      ),
                    ),
                  ],
                  if (banner.ctaLabel.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: () => _open(context),
                      style: FilledButton.styleFrom(
                        backgroundColor: foreground,
                        foregroundColor: background,
                        minimumSize: const Size(0, 44),
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        shape: const StadiumBorder(),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Flexible(
                            child: Text(
                              banner.ctaLabel,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                          const SizedBox(width: 8),
                          const Icon(Icons.north_east_rounded, size: 18),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 4,
              child: Transform.rotate(
                angle: .035,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: SizedBox.expand(child: image),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
