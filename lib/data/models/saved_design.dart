/// A room + surface/texture assignment the user saved from the visualizer.
class SavedDesign {
  const SavedDesign({
    required this.id,
    required this.name,
    required this.roomId,
    required this.assignments,
    required this.createdOn,
    this.note,
  });

  final String id;
  final String name;
  final String roomId;

  /// surfaceId -> productId
  final Map<String, String> assignments;
  final DateTime createdOn;
  final String? note;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'roomId': roomId,
        'assignments': assignments,
        'createdOn': createdOn.toIso8601String(),
        'note': note,
      };

  factory SavedDesign.fromJson(Map<String, dynamic> j) => SavedDesign(
        id: j['id'] as String,
        name: j['name'] as String? ?? 'Design',
        roomId: j['roomId'] as String? ?? '',
        assignments: (j['assignments'] as Map? ?? {})
            .map((k, v) => MapEntry(k.toString(), v.toString())),
        createdOn:
            DateTime.tryParse(j['createdOn'] as String? ?? '') ?? DateTime.now(),
        note: j['note'] as String?,
      );
}
