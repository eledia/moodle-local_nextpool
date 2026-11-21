# Nextpool (`local_nextpool`)

Katalog für Microlearning-Inhalte in Moodle: Einträge mit Metadaten, Lizenz und Versionen pflegen, in Kurse platzieren und neue Versionen nach einer Update-Regel (automatisch, mit Hinweis, fest) übernehmen. Speicher-Backends sind Subplugins (`nextpoolsource_moodle`, `nextpoolsource_nextcloud`, `nextpoolsource_onedrive`). Update an Ort und Stelle für Datei, Seite, Link, H5P, SCORM, Buch und Test (xAPI über `mod_nextpool`).

`local_nextpool` ist der Kern der Nextpool-Suite. Die Suite liegt in einem Repository:

| Ordner | Komponente | Rolle |
|---|---|---|
| `public/local/nextpool/` | `local_nextpool` mit Subplugins `nextpoolsource_*` | Katalog, Versionen, Redaktion, Platzieren |
| `public/local/eledia_lrs/` | `local_eledia_lrs` | Learning Record Store (xAPI) |
| `public/mod/nextpool/` | `mod_nextpool` | xAPI-Player, Fortschrittsübersicht, Direkt-Upload |

Jedes Plugin hat ein eigenes Release-ZIP.

## Requirements

- Moodle 5.1 or later (`2025100600`), tested on 5.1 and 5.2
- PHP 8.2 or later
- H5P catalogue previews require Core H5P content/export access guards (verified in 5.1.9 and 5.2.5); older Core builds show an unavailable-preview message.

## Documentation

- Projekt-Doku (DevFlow-Master der Suite): [docs/00-master.md](docs/00-master.md), Produktkonzept: [docs/KONZEPT.md](docs/KONZEPT.md)
- Anleitung: [docs/02-user-doc.de.md](docs/02-user-doc.de.md)
- Betriebs- und Sicherheitsgrenzen: [security.md](security.md)

## Development

Lokale Tests (dockerFlow) laufen in einer isolierten Kopie mit absoluten Plugin-Pfaden:
`moodlectl --instance npui52 test-isolated phpunit /pfad/nextpool/public/mod/nextpool --with /pfad/nextpool/public/local/nextpool --with /pfad/nextpool/public/local/eledia_lrs`. Für Browser-Tests `behat` statt `phpunit`. Die drei Source-Subplugins haben eigene PHPUnit-Testsuites.

CI: `.gitlab-ci.yml` im Wurzelverzeichnis prüft jedes Plugin statisch (moodle-plugin-ci auf Moodle 5.2) und führt PHPUnit in der vollständigen Pipeline auf Moodle 5.1 und 5.2 aus; schlanke Branch-Pipelines prüfen statisch und auf 5.2. Die Job-Logik liegt in `bin/ci/` dieses Plugins und läuft lokal im selben Image (Aufruf im Kopf von `.gitlab-ci.yml`).

Release-ZIP eines Plugins, aus dem Wurzelverzeichnis des Repositorys:

```
public/local/nextpool/bin/release.sh public/local/nextpool /tmp
```

## License

GPL v3 or later — see [LICENSE](LICENSE).
