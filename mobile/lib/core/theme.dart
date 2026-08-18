import 'package:flutter/material.dart';

/// Compact campus-utility theme for Checker flows.
class AppTheme {
  AppTheme._();

  static const Color brand = Color(0xFF0B6E6E);

  static ThemeData light() {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: brand,
        brightness: Brightness.light,
      ),
    );
    return base.copyWith(
      appBarTheme: AppBarTheme(
        backgroundColor: brand,
        foregroundColor: Colors.white,
        centerTitle: false,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(48),
        ),
      ),
    );
  }
}
