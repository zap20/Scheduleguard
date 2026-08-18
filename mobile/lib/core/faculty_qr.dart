/// Parses faculty QR payloads that carry the human ID number (`user.schoolId`).
///
/// Accepts bare `2020-501`, or prefixed `SGFAC:2020-501` / `SGFACULTY:2020-501`.
class FacultyQr {
  FacultyQr._();

  static const prefix = 'SGFAC:';

  static String encode(String schoolId) => '$prefix$schoolId';

  static String? parse(String? raw) {
    if (raw == null) return null;
    var text = raw.trim();
    if (text.isEmpty) return null;

    final upper = text.toUpperCase();
    for (final p in [prefix, 'SGFACULTY:', 'FACULTY:']) {
      final idx = upper.indexOf(p);
      if (idx >= 0) {
        text = text.substring(idx + p.length);
        break;
      }
    }

    text = text.split(RegExp(r'[?\s#]')).first.trim();
    if (text.isEmpty) return null;
    return text;
  }
}
