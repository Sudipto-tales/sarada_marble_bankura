import 'photo_surface.dart';
import 'surface_style.dart';

/// A room + surface/texture assignment the user saved from the visualizer.
class SavedDesign {
  const SavedDesign({
    required this.id,
    required this.name,
    required this.roomId,
    required this.assignments,
    required this.createdOn,
    this.note,
    this.mode = 'threeD',
    this.selectedSurface = 'floor',
    this.styles = const {},
    this.photoSurfaces = const {},
    this.photoPng,
    this.camera = const {},
  });

  final String id;
  final String name;
  final String roomId;

  /// surfaceId -> productId
  final Map<String, String> assignments;
  final DateTime createdOn;
  final String? note;
  final String mode;
  final String selectedSurface;
  final Map<String, SurfaceStyle> styles;
  final Map<String, PhotoSurface> photoSurfaces;

  /// A normalized, size-limited local copy; never a temporary picker path.
  final String? photoPng;
  final Map<String, double> camera;

  Map<String, dynamic> toJson() => {
    'schemaVersion': 2,
    'mode': mode,
    'selectedSurface': selectedSurface,
    'styles': styles.map((k, v) => MapEntry(k, v.toJson())),
    'photoSurfaces': photoSurfaces.map((k, v) => MapEntry(k, v.toJson())),
    'photoPng': photoPng,
    'camera': camera,
    'id': id,
    'name': name,
    'roomId': roomId,
    'assignments': assignments,
    'createdOn': createdOn.toIso8601String(),
    'note': note,
  };

  factory SavedDesign.fromJson(Map<String, dynamic> j) => SavedDesign(
    mode: j['mode'] == 'twoD' ? 'twoD' : 'threeD',
    selectedSurface: j['selectedSurface'] as String? ?? 'floor',
    styles: (j['styles'] as Map? ?? {}).map(
      (k, v) => MapEntry(
        k.toString(),
        SurfaceStyle.fromJson(Map<String, dynamic>.from(v as Map)),
      ),
    ),
    photoSurfaces: (j['photoSurfaces'] as Map? ?? {}).map(
      (k, v) => MapEntry(
        k.toString(),
        PhotoSurface.fromJson(Map<String, dynamic>.from(v as Map)),
      ),
    ),
    photoPng: j['photoPng'] as String?,
    camera: (j['camera'] as Map? ?? {}).map(
      (k, v) => MapEntry(k.toString(), (v as num).toDouble()),
    ),
    id: j['id'] as String,
    name: j['name'] as String? ?? 'Design',
    roomId: j['roomId'] as String? ?? '',
    assignments: (j['assignments'] as Map? ?? {}).map(
      (k, v) => MapEntry(k.toString(), v.toString()),
    ),
    createdOn:
        DateTime.tryParse(j['createdOn'] as String? ?? '') ?? DateTime.now(),
    note: j['note'] as String?,
  );
}
