# Accessibility Tests für Changelog

Diese Test-Suite überprüft die Barrierefreiheit der Changelog-Seite nach WCAG 2.1 Level AA Standards.

## Was wird getestet?

### ✅ Automatische WCAG-Prüfungen
- **WCAG 2.1 Level A & AA** Konformität
- **Farbkontraste** (WCAG 1.4.3)
- **ARIA-Attribute** korrekte Verwendung
- **Tastaturnavigation** Zugänglichkeit

### ✅ Spezifische Implementierungen
1. **Live Regions (WCAG 4.1.3)**
   - Screenreader-Announcements bei Expand/Collapse
   - `aria-live="polite"` Region vorhanden
   - Korrekte Ansagen in deutscher Sprache

2. **Tastaturnavigation**
   - ↑/↓ Pfeiltasten Navigation
   - J/K Vim-Style Navigation
   - Home/End für erste/letzte Version
   - Enter/Space zum Erweitern/Einklappen
   - Sichtbare Focus-Indikatoren

3. **Semantisches HTML**
   - Korrekte Heading-Hierarchie (h1 → h3)
   - Liste (`<ul>`) für Timeline
   - `<button>` für interaktive Elemente
   - Proper ARIA-Attribute (aria-expanded, aria-controls, etc.)

4. **Error Handling**
   - `role="alert"` für Fehlermeldungen
   - Strukturierte Fehlerdarstellung
   - Reload-Button mit Keyboard-Zugriff

5. **Responsive & Mobile**
   - Mobile Timeline-Navigation
   - Accessible Floating Button
   - Proper Labels auf allen Breakpoints

6. **Motion Preferences**
   - `prefers-reduced-motion` Support
   - Animations werden deaktiviert wenn gewünscht

## Tests ausführen

```bash
# Alle Accessibility-Tests
npm run test:e2e:a11y

# Mit UI (interaktiv)
npm run test:e2e:ui -- accessibility

# Im Browser sichtbar (headed mode)
npm run test:e2e:headed -- accessibility/changelog-a11y
```

## Test-Struktur

```
tests/playwright/accessibility/
└── changelog-a11y.spec.ts    # Alle Barrierefreiheit-Tests
```

## Verwendete Tools

- **@axe-core/playwright**: Automatische WCAG-Prüfung
- **Playwright**: E2E Testing Framework
- **WCAG 2.1 Level AA**: Compliance Standard

## Wichtige Test-Cases

| Test | WCAG Kriterium | Beschreibung |
|------|----------------|--------------|
| No WCAG violations | Various | Automatische axe-core Prüfung |
| Color contrast | 1.4.3 | Mindestens 4.5:1 für Text |
| ARIA attributes | 4.1.2 | Korrekte Verwendung von ARIA |
| Live regions | 4.1.3 | Screenreader Announcements |
| Keyboard navigation | 2.1.1 | Vollständige Keyboard-Kontrolle |
| Focus visible | 2.4.7 | Sichtbare Focus-Indikatoren |
| Heading hierarchy | 1.3.1 | Logische Struktur |
| Error identification | 3.3.1 | Klare Fehlermeldungen |
| Reduced motion | 2.3.3 | Animation-Präferenzen |

## CI/CD Integration

Die Tests werden automatisch bei jedem Pull Request ausgeführt und prüfen:
- ✅ Keine WCAG-Violations
- ✅ Tastaturnavigation funktioniert
- ✅ Screenreader-Support vorhanden
- ✅ Mobile Accessibility gewährleistet

## Bekannte Einschränkungen

- **prefers-reduced-motion**: Browser-Emulation in Tests ist limitiert
- **Screenreader-Testing**: Nur strukturelle Tests, keine echten Screenreader
- **Color Vision**: Keine Tests für Farbenblindheit (wird durch Kontrast-Tests abgedeckt)

## Weiterführende Ressourcen

- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [BITV 2.0 (Deutschland)](https://www.bitvtest.de/)
- [axe-core Rules](https://github.com/dequelabs/axe-core/blob/develop/doc/rule-descriptions.md)
- [Playwright Accessibility Testing](https://playwright.dev/docs/accessibility-testing)
