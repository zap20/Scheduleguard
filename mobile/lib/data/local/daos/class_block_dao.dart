import 'package:sqflite/sqflite.dart';

import '../../models/class_block.dart';
import '../app_database.dart';

/// Read-only denormalized class-block cache. Pull only — never pushed.
class ClassBlockDao {
  ClassBlockDao(this._db);
  final AppDatabase _db;

  Future<void> replaceAll(List<ClassBlock> blocks) async {
    final db = await _db.database;
    await db.transaction((txn) async {
      await txn.delete('class_block');
      final batch = txn.batch();
      for (final b in blocks) {
        batch.insert(
          'class_block',
          b.toMap(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  Future<List<ClassBlock>> listAll() async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      orderBy: 'day ASC, start_time ASC, subject ASC',
    );
    return rows.map(ClassBlock.fromMap).toList();
  }

  Future<List<ClassBlock>> listByDay(String day) async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      where: 'LOWER(day) = LOWER(?)',
      whereArgs: [day],
      orderBy: 'start_time ASC, subject ASC',
    );
    return rows.map(ClassBlock.fromMap).toList();
  }

  Future<List<ClassBlock>> listByRoomAndDay(String roomId, String day) async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      where: 'room_id = ? AND LOWER(day) = LOWER(?)',
      whereArgs: [roomId, day],
      orderBy: 'start_time ASC, subject ASC',
    );
    return rows.map(ClassBlock.fromMap).toList();
  }

  Future<List<ClassBlock>> listByFacultyAndDay(
    String facultyId,
    String day,
  ) async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      where: 'faculty_id = ? AND LOWER(day) = LOWER(?)',
      whereArgs: [facultyId, day],
      orderBy: 'start_time ASC, subject ASC',
    );
    return rows.map(ClassBlock.fromMap).toList();
  }

  Future<ClassBlock?> getById(String id) async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return ClassBlock.fromMap(rows.first);
  }

  Future<String?> roomLabel(String roomId) async {
    final db = await _db.database;
    final rows = await db.query(
      'class_block',
      columns: ['room'],
      where: 'room_id = ? AND room != ?',
      whereArgs: [roomId, ''],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return rows.first['room']?.toString();
  }
}
