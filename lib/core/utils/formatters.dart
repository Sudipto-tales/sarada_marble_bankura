/// Formatting helpers. Pure functions, no widget or repository knowledge.
class Fmt {
  const Fmt._();

  /// Indian digit grouping: 12,34,567.
  static String rupees(num value, {bool decimals = false}) {
    final neg = value < 0;
    final v = value.abs();
    final whole = v.floor();
    final frac = decimals ? (v - whole) : 0;
    final digits = whole.toString();
    String out;
    if (digits.length <= 3) {
      out = digits;
    } else {
      final last3 = digits.substring(digits.length - 3);
      var rest = digits.substring(0, digits.length - 3);
      final parts = <String>[];
      while (rest.length > 2) {
        parts.insert(0, rest.substring(rest.length - 2));
        rest = rest.substring(0, rest.length - 2);
      }
      if (rest.isNotEmpty) parts.insert(0, rest);
      out = '${parts.join(',')},$last3';
    }
    if (decimals) out = '$out.${(frac * 100).round().toString().padLeft(2, '0')}';
    return '${neg ? '-' : ''}₹$out';
  }

  static String compact(num value) {
    if (value >= 10000000) return '${(value / 10000000).toStringAsFixed(1)}Cr';
    if (value >= 100000) return '${(value / 100000).toStringAsFixed(1)}L';
    if (value >= 1000) return '${(value / 1000).toStringAsFixed(1)}K';
    return value.toStringAsFixed(0);
  }

  static String sqft(double value) =>
      '${value.toStringAsFixed(value >= 100 ? 0 : 1)} sq.ft';

  static String rating(double value) => value.toStringAsFixed(1);

  static const List<String> _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static String date(DateTime d) => '${d.day} ${_months[d.month - 1]} ${d.year}';

  static String dateTime(DateTime d) {
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final m = d.minute.toString().padLeft(2, '0');
    return '${date(d)}, $h:$m ${d.hour < 12 ? 'AM' : 'PM'}';
  }

  static String shortDate(DateTime d) => '${d.day} ${_months[d.month - 1]}';

  static String relative(DateTime d, {DateTime? now}) {
    final diff = (now ?? DateTime.now()).difference(d);
    if (diff.inMinutes < 1) return 'Just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    if (diff.inDays < 60) return '${(diff.inDays / 7).floor()}w ago';
    return date(d);
  }

  static String initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first.substring(0, 1).toUpperCase();
    return (parts.first.substring(0, 1) + parts.last.substring(0, 1))
        .toUpperCase();
  }

  static String plural(int n, String one, [String? many]) =>
      n == 1 ? '$n $one' : '$n ${many ?? '${one}s'}';
}
