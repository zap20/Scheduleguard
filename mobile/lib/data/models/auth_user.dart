/// Subset of `user` returned by `/api/auth/login.php` / `/api/auth/me.php`.
class AuthUser {
  const AuthUser({
    required this.uid,
    required this.email,
    required this.role,
    required this.firstName,
    required this.lastName,
    required this.status,
    this.schoolId = '',
    this.departmentId,
  });

  final String uid;
  final String email;
  final String role;
  final String firstName;
  final String lastName;
  final String status;
  final String schoolId;
  final String? departmentId;

  String get fullName => '$firstName $lastName'.trim();

  bool get isChecker => role == 'Checker';
  bool get isDean => role == 'Dean';
  bool get isHr => role == 'HR';
  bool get isFaculty => role == 'Faculty';
  bool get isProgramHead => role == 'ProgramHead';
  bool get isStudent => role == 'Student';

  bool get canViewAttendanceReports =>
      isDean || isHr || isChecker || isFaculty;

  bool get canViewRoomSchedule =>
      isDean || isChecker || isProgramHead || isHr;

  bool get canViewEnrolledStudents => isDean;

  bool get canViewOwnSchedule => isStudent;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      uid: (json['uid'] ?? '').toString(),
      email: (json['email'] ?? '').toString(),
      role: (json['role'] ?? '').toString(),
      firstName: (json['firstName'] ?? '').toString(),
      lastName: (json['lastName'] ?? '').toString(),
      status: (json['status'] ?? 'Active').toString(),
      schoolId: (json['schoolId'] ?? '').toString(),
      departmentId: json['departmentId']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
        'uid': uid,
        'email': email,
        'role': role,
        'firstName': firstName,
        'lastName': lastName,
        'status': status,
        'schoolId': schoolId,
        'departmentId': departmentId,
      };
}
