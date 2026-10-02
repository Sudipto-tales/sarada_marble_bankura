/// Prototype-only user record. Local auth, no server, no tokens.
class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.phone,
    this.avatarInitials,
    this.memberSince,
    this.isGuest = false,
  });

  final String id;
  final String name;
  final String email;
  final String phone;
  final String? avatarInitials;
  final DateTime? memberSince;
  final bool isGuest;

  AppUser copyWith({String? name, String? email, String? phone}) => AppUser(
        id: id,
        name: name ?? this.name,
        email: email ?? this.email,
        phone: phone ?? this.phone,
        avatarInitials: avatarInitials,
        memberSince: memberSince,
        isGuest: isGuest,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'phone': phone,
        'memberSince': memberSince?.toIso8601String(),
        'isGuest': isGuest,
      };

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: j['id'] as String,
        name: j['name'] as String? ?? '',
        email: j['email'] as String? ?? '',
        phone: j['phone'] as String? ?? '',
        memberSince: DateTime.tryParse(j['memberSince'] as String? ?? ''),
        isGuest: j['isGuest'] as bool? ?? false,
      );
}
