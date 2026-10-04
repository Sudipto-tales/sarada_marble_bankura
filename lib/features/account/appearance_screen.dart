import 'package:flutter/material.dart';
import '../../core/state/app_scope.dart';

class AppearanceScreen extends StatelessWidget {
  const AppearanceScreen({super.key});
  static const colors = <String, Color>{
    'Jade': Color(0xFF356859),
    'Ocean': Color(0xFF245EA8),
    'Rose': Color(0xFF9B4563),
    'Amethyst': Color(0xFF7451A8),
    'Terracotta': Color(0xFF9B5034),
    'Graphite': Color(0xFF505863),
  };
  @override
  Widget build(BuildContext context) {
    final controller = AppScope.of(context).theme;
    return Scaffold(
      appBar: AppBar(title: const Text('Appearance')),
      body: Observer(
        listenable: controller,
        builder: (context, theme) => ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'Make it yours',
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            const SizedBox(height: 8),
            const Text(
              'A little color, beautifully polished. Your choices are saved on this device.',
            ),
            const SizedBox(height: 24),
            Text('Theme', style: Theme.of(context).textTheme.titleMedium),
            for (final mode in ThemeMode.values)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(
                  {
                    'system': 'Follow device',
                    'light': 'Light',
                    'dark': 'Dark',
                  }[mode.name]!,
                ),
                trailing: Icon(
                  theme.mode == mode
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                  color: Theme.of(context).colorScheme.primary,
                ),
                onTap: () => theme.set(mode),
              ),
            const SizedBox(height: 20),
            Text(
              'Accent color',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                for (final entry in colors.entries)
                  ChoiceChip(
                    avatar: CircleAvatar(
                      backgroundColor: entry.value,
                      radius: 10,
                    ),
                    label: Text(entry.key),
                    selected: theme.accent == entry.value,
                    onSelected: (_) => theme.setAccent(entry.value),
                  ),
              ],
            ),
            const SizedBox(height: 28),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Your showroom, your style',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Preview your selected accent across buttons and highlights.',
                    ),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () => Navigator.pop(context),
                      icon: const Icon(Icons.check),
                      label: const Text('Looks good'),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
