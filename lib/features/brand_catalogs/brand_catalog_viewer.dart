import 'dart:async';

import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:turnable_page/turnable_page.dart';

import '../../core/state/app_scope.dart';
import '../../data/models/brand_catalog.dart';

/// Owns PDF resources and page navigation; entry points only pass a catalog ID.
class BrandCatalogViewer extends StatefulWidget {
  const BrandCatalogViewer({super.key, required this.catalogId});
  final String catalogId;

  @override
  State<BrandCatalogViewer> createState() => _BrandCatalogViewerState();
}

class _BrandCatalogViewerState extends State<BrandCatalogViewer> {
  final _controller = PageFlipController();
  BrandCatalog? _catalog;
  PdfDocument? _document;
  String? _error;
  int _page = 0;
  bool _busy = false;

  String get _positionKey =>
      'brand_catalog_position_${_catalog!.id}_${_catalog!.version}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final deps = AppScope.read(context);
    try {
      final catalog = await deps.brandCatalogs.byId(widget.catalogId);
      if (catalog == null) throw StateError('Catalog unavailable');
      await pdfrxFlutterInitialize();
      if (!mounted) return;
      final document = await PdfDocument.openAsset(catalog.pdfAsset);
      if (!mounted) {
        await document.dispose();
        return;
      }
      if (document.pages.isEmpty) {
        await document.dispose();
        throw StateError('Empty catalog');
      }
      setState(() {
        _catalog = catalog;
        _document = document;
        _page = (deps.store.read<int>(_positionKey) ?? 0).clamp(
          0,
          document.pages.length - 1,
        );
        _error = null;
      });
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = 'This catalog could not be opened. Please try again.',
        );
      }
    }
  }

  @override
  void dispose() {
    final document = _document;
    if (document != null) unawaited(document.dispose());
    super.dispose();
  }

  void _onPageChanged(int left, int right) {
    if (!mounted || _document == null) return;
    final page = left.clamp(0, _document!.pages.length - 1);
    setState(() => _page = page);
    AppScope.read(context).store.write(_positionKey, page);
  }

  Future<void> _turn(bool forward, bool spread) async {
    if (_busy) return;
    final allowed = forward
        ? _controller.hasNextPage
        : _controller.hasPreviousPage;
    if (!allowed) return;
    final reduceMotion = MediaQuery.disableAnimationsOf(context);
    setState(() => _busy = true);
    _controller.resetZoom();
    try {
      if (reduceMotion) {
        final target = (_page + (forward ? 1 : -1) * (spread ? 2 : 1)).clamp(
          0,
          _document!.pages.length - 1,
        );
        _controller.jumpToPage(target);
      } else {
        await (forward ? _controller.nextPage() : _controller.previousPage())
            .timeout(const Duration(seconds: 3), onTimeout: () => false);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _selectPage() async {
    final target = await showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: ListView.builder(
          itemCount: _document!.pages.length,
          itemBuilder: (context, index) => ListTile(
            title: Text('Page ${index + 1}'),
            selected: index == _page,
            trailing: index == _page ? const Icon(Icons.check) : null,
            onTap: () => Navigator.pop(context, index),
          ),
        ),
      ),
    );
    if (!mounted || target == null) return;
    _controller.resetZoom();
    _controller.jumpToPage(target);
  }

  @override
  Widget build(BuildContext context) {
    final document = _document;
    return Scaffold(
      appBar: AppBar(
        title: Text(_catalog?.title ?? 'Brand catalog'),
        actions: [
          if (document != null)
            IconButton(
              tooltip: 'Reset zoom',
              onPressed: () => _controller.resetZoom(),
              icon: const Icon(Icons.zoom_out_map),
            ),
        ],
      ),
      body: _error != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_error!, textAlign: TextAlign.center),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () {
                        setState(() => _error = null);
                        _load();
                      },
                      icon: const Icon(Icons.refresh),
                      label: const Text('Retry'),
                    ),
                  ],
                ),
              ),
            )
          : document == null
          ? const Center(child: CircularProgressIndicator())
          : SafeArea(
              top: false,
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final spread = constraints.maxWidth >= 600;
                  final lastVisible = (_page + (spread ? 1 : 0)).clamp(
                    0,
                    document.pages.length - 1,
                  );
                  final pageLabel = spread && lastVisible != _page
                      ? 'Pages ${_page + 1}–${lastVisible + 1} of ${document.pages.length}'
                      : 'Page ${_page + 1} of ${document.pages.length}';
                  return Column(
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 4,
                        ),
                        child: Text(
                          _catalog!.brandName,
                          style: Theme.of(context).textTheme.labelMedium,
                        ),
                      ),
                      Expanded(
                        child: Padding(
                          padding: const EdgeInsets.all(8),
                          child: IgnorePointer(
                            ignoring: _busy,
                            child: Center(
                              child: TurnablePage(
                                key: ValueKey(spread),
                                controller: _controller,
                                pageCount: document.pages.length,
                                pageViewMode: spread
                                    ? PageViewMode.double
                                    : PageViewMode.single,
                                aspectRatio:
                                    document.pages.first.width /
                                    document.pages.first.height *
                                    (spread ? 2 : 1),
                                textDirection: TextDirection.ltr,
                                paperBoundaryDecoration:
                                    PaperBoundaryDecoration.none,
                                enableZoom: true,
                                maxScale: 3.5,
                                settings: FlipSettings(
                                  startPageIndex: _page,
                                  flippingTime:
                                      MediaQuery.disableAnimationsOf(context)
                                      ? 1
                                      : 750,
                                ),
                                onPageChanged: _onPageChanged,
                                builder: (context, index, constraints) =>
                                    PdfPageView(
                                      key: ValueKey(index),
                                      document: document,
                                      pageNumber: index + 1,
                                      maximumDpi: 180,
                                      decoration: const BoxDecoration(
                                        color: Colors.white,
                                      ),
                                    ),
                              ),
                            ),
                          ),
                        ),
                      ),
                      Text(
                        'Swipe to turn • Pinch or double-tap to zoom',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
                        child: Row(
                          children: [
                            IconButton(
                              tooltip: 'Previous page',
                              onPressed: _busy || _page == 0
                                  ? null
                                  : () => _turn(false, spread),
                              icon: const Icon(Icons.chevron_left),
                            ),
                            Expanded(
                              child: TextButton(
                                onPressed: _busy ? null : _selectPage,
                                child: Text(
                                  pageLabel,
                                  textAlign: TextAlign.center,
                                ),
                              ),
                            ),
                            IconButton(
                              tooltip: 'Next page',
                              onPressed:
                                  _busy ||
                                      lastVisible >= document.pages.length - 1
                                  ? null
                                  : () => _turn(true, spread),
                              icon: const Icon(Icons.chevron_right),
                            ),
                          ],
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
    );
  }
}
