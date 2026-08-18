import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';

import '../../core/constants.dart';

/// Local SQLite for offline-first Checker flows.
///
/// - [faculty] / [class_block]: read-only caches (pull only).
/// - [attendance_record]: the only writable / upstream-synced table.
class AppDatabase {
  AppDatabase._();
  static final AppDatabase instance = AppDatabase._();

  Database? _db;

  Future<Database> get database async {
    if (_db != null) return _db!;
    _db = await _open();
    return _db!;
  }

  Future<Database> _open() async {
    final dir = await getApplicationDocumentsDirectory();
    final path = p.join(dir.path, AppConstants.localDbName);
    return openDatabase(
      path,
      version: AppConstants.localDbVersion,
      onCreate: (db, version) async {
        await _createSchema(db);
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        await db.execute('DROP TABLE IF EXISTS attendance_record');
        await db.execute('DROP TABLE IF EXISTS class_block');
        await db.execute('DROP TABLE IF EXISTS faculty');
        await db.execute('DROP TABLE IF EXISTS schedule');
        await db.execute('DROP TABLE IF EXISTS app_session');
        await _createSchema(db);
      },
    );
  }

  Future<void> _createSchema(Database db) async {
    await db.execute('''
      CREATE TABLE app_session (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        token TEXT NOT NULL,
        userJson TEXT NOT NULL,
        updatedAt TEXT NOT NULL
      )
    ''');

    await db.execute('''
      CREATE TABLE faculty (
        id TEXT PRIMARY KEY NOT NULL,
        name TEXT NOT NULL,
        first_name TEXT NOT NULL DEFAULT '',
        last_name TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        school_id TEXT NOT NULL DEFAULT '',
        department_id TEXT,
        status TEXT NOT NULL DEFAULT 'Active',
        employment_type TEXT,
        cached_at TEXT NOT NULL
      )
    ''');

    // Denormalized meeting cache (schedule ⋈ classBlock ⋈ subject ⋈ room).
    await db.execute('''
      CREATE TABLE class_block (
        id TEXT PRIMARY KEY NOT NULL,
        subject TEXT NOT NULL DEFAULT '',
        room TEXT NOT NULL DEFAULT '',
        room_id TEXT,
        schedule_time TEXT NOT NULL DEFAULT '',
        faculty_id TEXT,
        day TEXT NOT NULL DEFAULT '',
        start_time TEXT NOT NULL DEFAULT '',
        end_time TEXT NOT NULL DEFAULT '',
        block_name TEXT NOT NULL DEFAULT '',
        department_id TEXT,
        year_level TEXT,
        academic_year INTEGER,
        semester TEXT,
        status TEXT NOT NULL DEFAULT 'Open',
        schedule_id TEXT,
        cached_at TEXT NOT NULL
      )
    ''');
    await db.execute(
      'CREATE INDEX idx_class_block_day ON class_block(day)',
    );
    await db.execute(
      'CREATE INDEX idx_class_block_faculty ON class_block(faculty_id)',
    );
    await db.execute(
      'CREATE INDEX idx_class_block_room ON class_block(room_id)',
    );

    await db.execute('''
      CREATE TABLE attendance_record (
        local_id TEXT PRIMARY KEY NOT NULL,
        server_id TEXT,
        faculty_id TEXT NOT NULL,
        class_block_id TEXT NOT NULL,
        checker_id TEXT NOT NULL,
        status TEXT NOT NULL,
        recorded_at TEXT NOT NULL,
        device_meta TEXT,
        sync_status TEXT NOT NULL DEFAULT 'pending',
        sync_error TEXT
      )
    ''');
    await db.execute(
      'CREATE INDEX idx_att_sync ON attendance_record(sync_status)',
    );
    await db.execute(
      'CREATE INDEX idx_att_block ON attendance_record(class_block_id)',
    );
  }

  Future<void> close() async {
    await _db?.close();
    _db = null;
  }
}
