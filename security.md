# Sicherheitsgrenzen des Katalogs

Stand: 2026-11-15. Dies beschreibt die implementierten Sicherheitsgrenzen und Betriebsanforderungen; es ist keine Produktions- oder Konformitätsfreigabe. Verbindliche Datenschutzentscheidungen und offene Abnahmen stehen in [Datenschutzkonzept](../eledia_lrs/docs/11-datenschutzkonzept-lrs.md) und [Tasks](docs/04-tasks.md).

## Inhalte, Speicherorte und Veröffentlichung

Katalogrechte, Kurskontext und Sichtbarkeit werden serverseitig geprüft. Veröffentlichen macht aktive Inhalte zugänglich; Pakete und externe Ziele bleiben nicht vertrauenswürdiger Inhalt. Rechte zum Importieren, Prüfen und Veröffentlichen bewusst vergeben. Grenzen der Paketarchive und Dateitypen ersetzen keine fachliche Prüfung. Vom Katalog platzierte Core-Aktivitäten behalten deren eigene Berechtigungs- und Ergebnisregeln.

Konfiguration und Zugangsdaten der Speicher-Backends werden über Moodle-Core-Verschlüsselung geschützt. Der OneDrive-Graph-Token-Cache speichert Token verschlüsselt, alte Klartext-Cachewerte werden verworfen. Dateien, Datenbank und Moodle-Verschlüsselungsschlüssel gemeinsam sichern und Administratorzugriff begrenzen. Ein kompromittiertes Moodle-System mit Schlüsselzugriff kann diese Secrets verwenden.

## Vorschau und Auswertung

Vorschau verlangt Kurszugang und Katalogsichtbarkeit. Gemeinsame Paket-, HTML-Asset- und H5P-Kopien sind an die Katalogversion gebunden und enthalten keine personenbezogenen Lernergebnisse der Vorschau. Metadaten und Inhalte können die von der Redaktion veröffentlichten Personenangaben enthalten. H5P verwendet keine Aktivitäts-Komponente, keine übermittelte Nutzerkennung und kein `h5p_viewed`-Ereignis. Der Gastcache und ein synthetischer Originalpfad sperren native Core-Inhalts-/Exportadressen; der Medienproxy prüft Katalogrechte bei jeder Anfrage. Ohne die Core-Schutzmethoden `can_access_content` und `can_access_export` bleibt H5P-Vorschau gesperrt (geprüfte offizielle Releases: 5.0.12, 5.1.9, 5.2.5). Vorschau legt keine Aktivitäten, Registrierungen, States, Statements, Versuche, Noten oder Abschlüsse an. Neuladen beginnt neu; beim Bereichswechsel wird Wiedergabe pausiert. Externe Links werden ausdrücklich geöffnet und unterliegen den Regeln des Zielservers.

Optionale xAPI-/SCORM-Provider aus `mod_nextpool` verwenden einen signierten anonymen Vorschau-Token. Er läuft spätestens nach 2 Stunden bzw. Moodle-Sitzungsdauer ab. Solche URLs nicht weitergeben und aus Webserver-/Proxy-/Fehlerlogs redigieren. Das Ereignis `item_previewed` protokolliert die handelnde Person. Details: [Datenschutzkonzept §14](../eledia_lrs/docs/11-datenschutzkonzept-lrs.md#14-vorschau-ohne-lerndaten-f16-task206), [Player-Grenzen](../../mod/nextpool/security.md).

Der Reiter „Auswertung“ ist für Bearbeitende und Prüfende des Eintrags sichtbar. Nur anonymisierte Kennzahlen (Mindestgröße 5 plus Ableitungsschutz), keine Personenfelder oder Kursaufschlüsselung. Aktualisierung erfolgt täglich; gelöschte Lerndaten wirken mit der nächsten Berechnung. Die Fortschrittsübersicht und Care-Hinweise bleiben getrennte personenbezogene Nutzungen mit eigenen Rechten.
