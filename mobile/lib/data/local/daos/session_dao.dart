import 'dart:convert';

import 'package:sqflite/sqflite.dart';

import '../../models/auth_user.dart';
import '../app_database.dart';

class SessionDao {
  SessionDao(this._db);
  final AppDatabase _db;

  Future<void> saveSession({
    required String token,
    required AuthUser user,
  }) async {
    final db = await _db.database;
    await db.insert(
      'app_session',
      {
        'id': 1,
        'token': token,
        'userJson': jsonEncode(user.toJson()),
        'updatedAt': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<({String token, AuthUser user})?> readSession() async {
    final db = await _db.database;
    final rows = await db.query('app_session', where: 'id = 1', limit: 1);
    if (rows.isEmpty) return null;
    final token = (rows.first['token'] ?? '').toString();
    final raw = (rows.first['userJson'] ?? '{}').toString();
    final map = jsonDecode(raw) as Map<String, dynamic>;
    return (token: token, user: AuthUser.fromJson(map));
  }

  Future<void> clear() async {
    final db = await _db.database;
    await db.delete('app_session');
  }
}
