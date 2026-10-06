import 'dart:async';

import 'package:flutter/material.dart';
import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
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
  final _pages = PageController(viewportFraction: .9);
  Timer? _timer;
  int _page = 0;
  bool _touching = false;
  bool _resumed = true;
  int get _index => widget.banners.isEmpty ? 0 : _page % widget.banners.length;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    _resumed = state == null || state == AppLifecycleState.resumed;
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
    _timer = Timer(const Duration(seconds: 4), () {
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
    if (MediaQuery.disableAnimationsOf(context) ||
        !TickerMode.valuesOf(context).enabled) {
      _pages.jumpToPage(page);
      _schedule();
      return;
    }
    _pages
        .animateToPage(
          page,
          duration: const Duration(milliseconds: 650),
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
      padding: const EdgeInsets.only(top: 18, bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 12, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    '#SpecialForYou',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                TextButton(
                  onPressed: () => Navigator.pushNamed(
                    context,
                    Routes.catalog,
                    arguments: const CatalogArgs(
                      title: 'Special offers',
                      onlyOffers: true,
                    ),
                  ),
                  style: TextButton.styleFrom(foregroundColor: AppColors.coral),
                  child: const Text('See all'),
                ),
              ],
            ),
          ),
          SizedBox(
            height: 184 + (scale - 1).clamp(0, 2) * 150,
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
              child: PageView.builder(
                controller: _pages,
                itemCount: widget.banners.length == 1 ? 1 : null,
                onPageChanged: (value) {
                  setState(() => _page = value);
                  _schedule();
                },
                itemBuilder: (context, i) => Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 5),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(18),
                    child: _DealCard(
                      banner: widget.banners[i % widget.banners.length],
                      active: i == _page,
                    ),
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
                                      color: AppColors.coral.withValues(
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
    return Stack(
      fit: StackFit.expand,
      children: [
        image,
        const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xF21C201D), Color(0xB31C201D), Color(0x221C201D)],
              stops: [0, .52, 1],
              begin: Alignment.centerLeft,
              end: Alignment.centerRight,
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Expanded(
                flex: 7,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (banner.badge.isNotEmpty)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: .94),
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: Text(
                          banner.badge,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: AppColors.ink,
                            fontSize: 10,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    const SizedBox(height: 8),
                    Text(
                      banner.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        height: 1.08,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    if (banner.subtitle.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        banner.subtitle,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white70,
                          fontSize: 11,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                flex: 3,
                child: Align(
                  alignment: Alignment.bottomRight,
                  child: FilledButton(
                    onPressed: () => _open(context),
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.coral,
                      foregroundColor: Colors.white,
                      minimumSize: const Size(0, 44),
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    child: Text(
                      banner.ctaLabel.isEmpty ? 'Explore' : banner.ctaLabel,
                      maxLines: 2,
                      textAlign: TextAlign.center,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
