/// Local read-only cache of a Faculty user (server `user` role=Faculty).
class Faculty {
  const Faculty({
    required this.id,
    required this.name,
    this.firstName = '',
    this.lastName = '',
    this.email = '',
    this.schoolId = '',
    this.departmentId,
    this.status = 'Active',
    this.employmentType,
    required this.cachedAt,
  });

  final String id;
  final String name;
  final String firstName;
  final String lastName;
  final String email;
  final String schoolId;
  final String? departmentId;
  final String status;
  final String? employmentType;
  final String cachedAt;

  Map<String, dynamic> toMap() => {
        'id': id,
        'name': name,
        'first_name': firstName,
        'last_name': lastName,
        'email': email,
        'school_id': schoolId,
        'department_id': departmentId,
        'status': status,
        'employment_type': employmentType,
        'cached_at': cachedAt,
      };

  factory Faculty.fromMap(Map<String, dynamic> map) {
    return Faculty(
      id: (map['id'] ?? '').toString(),
      name: (map['name'] ?? '').toString(),
      firstName: (map['first_name'] ?? '').toString(),
      lastName: (map['last_name'] ?? '').toString(),
      email: (map['email'] ?? '').toString(),
      schoolId: (map['school_id'] ?? '').toString(),
      departmentId: map['department_id']?.toString(),
      status: (map['status'] ?? 'Active').toString(),
      employmentType: map['employment_type']?.toString(),
      cachedAt: (map['cached_at'] ?? '').toString(),
    );
  }

  factory Faculty.fromRemoteJson(Map<String, dynamic> json, {String? cachedAt}) {
    final first = (json['firstName'] ?? '').toString();
    final last = (json['lastName'] ?? '').toString();
    final combined = '$first $last'.trim();
    final name = combined.isNotEmpty
        ? combined
        : (json['name'] ?? json['fullName'] ?? '').toString();
    return Faculty(
      id: (json['uid'] ?? json['id'] ?? '').toString(),
      name: name,
      firstName: first,
      lastName: last,
      email: (json['email'] ?? '').toString(),
      schoolId: (json['schoolId'] ?? '').toString(),
      departmentId: json['departmentId']?.toString(),
      status: (json['status'] ?? 'Active').toString(),
      employmentType: (json['employmentType'] ?? json['employment_type'])
          ?.toString(),
      cachedAt: cachedAt ?? DateTime.now().toUtc().toIso8601String(),
    );
  }
}
