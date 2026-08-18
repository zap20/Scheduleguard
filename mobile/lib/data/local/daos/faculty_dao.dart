import 'package:sqflite/sqflite.dart';

import '../../models/faculty.dart';
import '../app_database.dart';

/// Read-only local cache of Faculty. Replaced wholesale on pull — never pushed.
class FacultyDao {
  FacultyDao(this._db);
  final AppDatabase _db;

  Future<void> replaceAll(List<Faculty> faculty) async {
    final db = await _db.database;
    await db.transaction((txn) async {
      await txn.delete('faculty');
      final batch = txn.batch();
      for (final f in faculty) {
        batch.insert(
          'faculty',
          f.toMap(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  Future<List<Faculty>> listAll() async {
    final db = await _db.database;
    final rows = await db.query('faculty', orderBy: 'name COLLATE NOCASE ASC');
    return rows.map(Faculty.fromMap).toList();
  }

  Future<Faculty?> getById(String id) async {
    final db = await _db.database;
    final rows = await db.query(
      'faculty',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return Faculty.fromMap(rows.first);
  }

  /// Human-facing ID number (`user.schoolId`), case-insensitive.
  Future<Faculty?> getBySchoolId(String schoolId) async {
    final db = await _db.database;
    final rows = await db.query(
      'faculty',
      where: 'LOWER(school_id) = LOWER(?)',
      whereArgs: [schoolId.trim()],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return Faculty.fromMap(rows.first);
  }
}
