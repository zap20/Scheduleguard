import 'package:flutter_test/flutter_test.dart';
import 'package:schedule_guard_mobile/core/constants.dart';
import 'package:schedule_guard_mobile/data/models/auth_user.dart';

void main() {
  test('Checker role gate matches STACK.md role enum', () {
    const checker = AuthUser(
      uid: '1',
      email: 'c@test',
      role: AppConstants.roleChecker,
      firstName: 'C',
      lastName: 'H',
      status: 'Active',
    );
    expect(checker.isChecker, isTrue);
    expect(checker.canViewRoomSchedule, isTrue);
    const dean = AuthUser(
      uid: '2',
      email: 'd@test',
      role: 'Dean',
      firstName: 'D',
      lastName: 'E',
      status: 'Active',
    );
    expect(dean.canViewEnrolledStudents, isTrue);
    expect(dean.canViewRoomSchedule, isTrue);
    expect(AppConstants.attendanceStatuses, contains('Present'));
    expect(AppConstants.attendanceStatuses, isNot(contains('Excused')));
  });
}
