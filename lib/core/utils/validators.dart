/// Form validation used by auth, address and quote forms.
class Validators {
  const Validators._();

  static String? required(String? v, [String field = 'This field']) =>
      (v == null || v.trim().isEmpty) ? '$field is required' : null;

  static String? email(String? v) {
    if (v == null || v.trim().isEmpty) return 'Email is required';
    final ok = RegExp(r'^[\w.\-+]+@[\w\-]+\.[\w.\-]+$').hasMatch(v.trim());
    return ok ? null : 'Enter a valid email';
  }

  static String? password(String? v) {
    if (v == null || v.isEmpty) return 'Password is required';
    if (v.length < 6) return 'Use at least 6 characters';
    return null;
  }

  static String? phone(String? v) {
    if (v == null || v.trim().isEmpty) return 'Phone number is required';
    final digits = v.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 10) return 'Enter a valid 10-digit number';
    return null;
  }

  static String? pincode(String? v) {
    if (v == null || v.trim().isEmpty) return 'PIN code is required';
    if (!RegExp(r'^\d{6}$').hasMatch(v.trim())) return 'PIN code must be 6 digits';
    return null;
  }

  static String? positiveNumber(String? v, [String field = 'Value']) {
    if (v == null || v.trim().isEmpty) return '$field is required';
    final d = double.tryParse(v.trim());
    if (d == null) return 'Enter a number';
    if (d <= 0) return '$field must be greater than 0';
    return null;
  }
}
