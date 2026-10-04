import 'dart:ui' as ui;

import 'package:flutter/services.dart';

/// Decodes marble tiles from local assets once and keeps them for the session.
/// v1 never fetches an image over the network.
class TextureCache {
  TextureCache._();

  static final TextureCache instance = TextureCache._();

  final Map<String, ui.Image> _images = {};
  final Map<String, Future<ui.Image>> _inFlight = {};

  ui.Image? peek(String asset) => _images[asset];

  Future<ui.Image> load(String asset, {int targetWidth = 512}) {
    final cached = _images[asset];
    if (cached != null) return Future.value(cached);
    return _inFlight[asset] ??= _decode(asset, targetWidth)
        .then((image) {
          _images[asset] = image;
          _inFlight.remove(asset);
          return image;
        })
        .whenComplete(() => _inFlight.remove(asset));
  }

  Future<List<ui.Image>> loadAll(
    Iterable<String> assets, {
    int targetWidth = 512,
  }) => Future.wait(assets.map((a) => load(a, targetWidth: targetWidth)));

  Future<ui.Image> _decode(String asset, int targetWidth) async {
    final data = await rootBundle.load(asset);
    final codec = await ui.instantiateImageCodec(
      data.buffer.asUint8List(),
      targetWidth: targetWidth,
    );
    try {
      final frame = await codec.getNextFrame();
      return frame.image;
    } finally {
      codec.dispose();
    }
  }

  void evict(String asset) => _images.remove(asset)?.dispose();

  void clear() {
    for (final image in _images.values) {
      image.dispose();
    }
    _images.clear();
  }
}
