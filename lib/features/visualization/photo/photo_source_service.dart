import 'dart:typed_data';
import 'dart:ui' as ui;
import 'package:file_selector/file_selector.dart';

class PhotoSourceService {
  static Future<Uint8List?> choose() async {
    final file = await openFile(
      acceptedTypeGroups: const [
        XTypeGroup(
          label: 'Room photos',
          extensions: ['jpg', 'jpeg', 'png', 'webp'],
          uniformTypeIdentifiers: [
            'public.jpeg',
            'public.png',
            'org.webmproject.webp',
          ],
        ),
      ],
    );
    if (file == null) return null;
    if (await file.length() > 15 * 1024 * 1024) {
      throw const FormatException('Choose a photo smaller than 15 MB.');
    }
    return normalize(await file.readAsBytes());
  }

  /// Decode/re-encode so a saved design owns its photo and drops source metadata.
  static Future<Uint8List> normalize(Uint8List bytes) async {
    final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
    ui.ImageDescriptor? descriptor;
    ui.Codec? codec;
    ui.Image? image;
    try {
      descriptor = await ui.ImageDescriptor.encoded(buffer);
      if (descriptor.width * descriptor.height > 40000000) {
        throw const FormatException(
          'Choose a photo smaller than 40 megapixels.',
        );
      }
      final longest = descriptor.width > descriptor.height
          ? descriptor.width
          : descriptor.height;
      final scale = longest > 1200 ? 1200 / longest : 1.0;
      codec = await descriptor.instantiateCodec(
        targetWidth: (descriptor.width * scale).round(),
        targetHeight: (descriptor.height * scale).round(),
      );
      image = (await codec.getNextFrame()).image;
      final png = await image.toByteData(format: ui.ImageByteFormat.png);
      if (png == null) {
        throw const FormatException('Could not read this photo.');
      }
      return png.buffer.asUint8List();
    } finally {
      image?.dispose();
      codec?.dispose();
      descriptor?.dispose();
      buffer.dispose();
    }
  }
}
