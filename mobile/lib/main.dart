import 'package:flutter/material.dart';

import 'app_scope.dart';
import 'core/result.dart';
import 'core/theme.dart';
import 'features/auth/login_screen.dart';
import 'features/shell/app_shell.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(AppScope(child: const ScheduleGuardApp()));
}

class ScheduleGuardApp extends StatefulWidget {
  const ScheduleGuardApp({super.key});

  @override
  State<ScheduleGuardApp> createState() => _ScheduleGuardAppState();
}

class _ScheduleGuardAppState extends State<ScheduleGuardApp> {
  bool _booting = true;
  bool _signedIn = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    final scope = AppScope.of(context);
    final restored = await scope.authRepository.restoreSession();
    if (!mounted) return;
    final signedIn = restored is Success;
    setState(() {
      _signedIn = signedIn;
      _booting = false;
    });
    if (signedIn && scope.authRepository.currentUser?.isChecker == true) {
      scope.syncController.start();
    }
  }

  void _onLoggedIn() {
    final scope = AppScope.of(context);
    if (scope.authRepository.currentUser?.isChecker == true) {
      scope.syncController.start();
    } else {
      scope.syncController.stop();
    }
    setState(() => _signedIn = true);
  }

  Future<void> _onLogout() async {
    final scope = AppScope.of(context);
    scope.syncController.stop();
    await scope.authRepository.logout();
    if (!mounted) return;
    setState(() => _signedIn = false);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'ScheduleGuard',
      theme: AppTheme.light(),
      home: _booting
          ? const Scaffold(
              body: Center(child: CircularProgressIndicator()),
            )
          : _signedIn
              ? AppShell(onLogout: _onLogout)
              : LoginScreen(onLoggedIn: _onLoggedIn),
    );
  }
}
