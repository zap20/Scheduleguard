/// Local read-only denormalized cache for Checker offline capture.
///
/// Distinct from student hold table `block`. Server `classBlock` does not store
/// subject/room/time — those live on `schedule`. This local row is a pull-cache
/// shaped for the checker UI (see OPEN_QUESTIONS.md).
class ClassBlock {
  const ClassBlock({
    required this.id,
    this.subject = '',
    this.room = '',
    this.roomId,
    this.scheduleTime = '',
    this.facultyId,
    this.day = '',
    this.startTime = '',
    this.endTime = '',
    this.blockName = '',
    this.departmentId,
    this.yearLevel,
    this.academicYear,
    this.semester,
    this.status = 'Open',
    this.scheduleId,
    required this.cachedAt,
  });

  final String id;
  final String subject;
  final String room;

  /// Server `room.uid` — used for QR room lookup.
  final String? roomId;
  final String scheduleTime;
  final String? facultyId;
  final String day;
  final String startTime;
  final String endTime;
  final String blockName;
  final String? departmentId;
  final String? yearLevel;
  final int? academicYear;
  final String? semester;
  final String status;

  /// Server `schedule.uid` when the pull payload includes it.
  final String? scheduleId;
  final String cachedAt;

  String get displayTitle {
    if (subject.isNotEmpty && blockName.isNotEmpty) {
      return '$subject · $blockName';
    }
    if (subject.isNotEmpty) return subject;
    if (blockName.isNotEmpty) return blockName;
    return id;
  }

  Map<String, dynamic> toMap() => {
        'id': id,
        'subject': subject,
        'room': room,
        'room_id': roomId,
        'schedule_time': scheduleTime,
        'faculty_id': facultyId,
        'day': day,
        'start_time': startTime,
        'end_time': endTime,
        'block_name': blockName,
        'department_id': departmentId,
        'year_level': yearLevel,
        'academic_year': academicYear,
        'semester': semester,
        'status': status,
        'schedule_id': scheduleId,
        'cached_at': cachedAt,
      };

  factory ClassBlock.fromMap(Map<String, dynamic> map) {
    return ClassBlock(
      id: (map['id'] ?? '').toString(),
      subject: (map['subject'] ?? '').toString(),
      room: (map['room'] ?? '').toString(),
      roomId: map['room_id']?.toString(),
      scheduleTime: (map['schedule_time'] ?? '').toString(),
      facultyId: map['faculty_id']?.toString(),
      day: (map['day'] ?? '').toString(),
      startTime: (map['start_time'] ?? '').toString(),
      endTime: (map['end_time'] ?? '').toString(),
      blockName: (map['block_name'] ?? '').toString(),
      departmentId: map['department_id']?.toString(),
      yearLevel: map['year_level']?.toString(),
      academicYear: (map['academic_year'] as num?)?.toInt(),
      semester: map['semester']?.toString(),
      status: (map['status'] ?? 'Open').toString(),
      scheduleId: map['schedule_id']?.toString(),
      cachedAt: (map['cached_at'] ?? '').toString(),
    );
  }

  factory ClassBlock.fromRemoteJson(
    Map<String, dynamic> json, {
    String? cachedAt,
  }) {
    final start = (json['startTime'] ?? json['start_time'] ?? '').toString();
    final end = (json['endTime'] ?? json['end_time'] ?? '').toString();
    final scheduleTime = (json['scheduleTime'] ?? json['schedule_time'] ?? '')
        .toString();
    final timeLabel = scheduleTime.isNotEmpty
        ? scheduleTime
        : (start.isEmpty && end.isEmpty ? '' : '$start–$end');

    final subjectCode = (json['subjectCode'] ?? '').toString();
    final subjectName = (json['subjectName'] ?? json['subject'] ?? '').toString();
    final subject = subjectCode.isNotEmpty && subjectName.isNotEmpty
        ? '$subjectCode — $subjectName'
        : (subjectName.isNotEmpty ? subjectName : subjectCode);

    final roomName = (json['roomName'] ?? '').toString();
    final roomBuilding = (json['roomBuilding'] ?? '').toString();
    final room = (json['room'] ?? '').toString().isNotEmpty
        ? (json['room'] ?? '').toString()
        : (roomBuilding.isEmpty
            ? roomName
            : (roomName.isEmpty ? roomBuilding : '$roomBuilding / $roomName'));

    final scheduleId =
        (json['scheduleId'] ?? json['schedule_id'] ?? '').toString();
    final cacheId = (json['uid'] ?? json['id'] ?? '').toString().isNotEmpty
        ? (json['uid'] ?? json['id'] ?? '').toString()
        : (scheduleId.isNotEmpty
            ? scheduleId
            : (json['classBlockId'] ?? json['class_block_id'] ?? '').toString());

    return ClassBlock(
      id: cacheId,
      subject: subject,
      room: room,
      roomId: (json['roomId'] ?? json['room_id'])?.toString(),
      scheduleTime: timeLabel,
      facultyId: (json['facultyId'] ?? json['faculty_id'])?.toString(),
      day: (json['day'] ?? '').toString(),
      startTime: start,
      endTime: end,
      blockName: (json['blockName'] ?? json['name'] ?? json['block_name'] ?? '')
          .toString(),
      departmentId: (json['departmentId'] ?? json['department_id'])?.toString(),
      yearLevel: (json['yearLevel'] ?? json['year_level'])?.toString(),
      academicYear: (json['academicYear'] as num?)?.toInt() ??
          (json['academic_year'] as num?)?.toInt(),
      semester: (json['semester'])?.toString(),
      status: (json['status'] ?? 'Open').toString(),
      scheduleId: scheduleId.isNotEmpty ? scheduleId : cacheId,
      cachedAt: cachedAt ?? DateTime.now().toUtc().toIso8601String(),
    );
  }
}
