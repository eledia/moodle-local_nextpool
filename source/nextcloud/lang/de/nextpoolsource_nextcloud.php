<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for nextpoolsource_nextcloud.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apppassword'] = 'App-Passwort';
$string['apppassword_help'] = 'App-Passwort eines eigenen Nextcloud-Kontos ohne Administrationsrechte (Nextcloud: Persönliche Einstellungen → Sicherheit → Geräte & Sitzungen). Beim Bearbeiten leer lassen, um das gespeicherte Passwort zu behalten.';
$string['apppassword_warning'] = '<strong>Sicherheitshinweis:</strong> App-Passwörter werden als Ausweichlösung unterstützt, ein OAuth-2-Systemkonto ist aber vorzuziehen. Ein App-Passwort gibt Zugriff auf das gesamte Konto und wird nicht automatisch erneuert. Verwenden Sie ein eigenes Konto, das nur Kataloginhalte enthält, nutzen Sie HTTPS und wechseln Sie das Passwort regelmäßig.';
$string['authmode'] = 'Authentifizierung';
$string['authmode_apppassword'] = 'App-Passwort (WebDAV Basic Auth, Ausweichlösung)';
$string['authmode_oauth2'] = 'OAuth-2-Systemkonto (empfohlen)';
$string['error_apppasswordreenter'] = 'Die Basis-URL wurde geändert. Geben Sie das App-Passwort erneut ein, damit es nicht an einen anderen Server gesendet wird.';
$string['error_serverurl'] = 'Geben Sie die Basis-URL des Nextcloud-Servers ein, beginnend mit https://.';
$string['error_serverurlhttps'] = 'App-Passwörter werden mit jeder Anfrage gesendet. Die Basis-URL muss daher mit https:// beginnen.';
$string['issuerid'] = 'OAuth-2-Dienst';
$string['issuerid_help'] = 'Ein Nextcloud-OAuth-2-Dienst aus Website-Administration → Server → OAuth-2-Dienste mit verbundenem Systemkonto. Inhalte werden mit diesem Konto gelesen und geschrieben.';
$string['pluginname'] = 'Nextcloud';
$string['privacy:metadata:nextcloud'] = 'Kataloginhalte werden mit einem Systemkonto von einem Nextcloud-Server gelesen und dorthin geschrieben. Es werden keine personenbezogenen Daten der handelnden Person übertragen.';
$string['privacy:metadata:nextcloud:content'] = 'Inhaltsdateien von Katalogeinträgen.';
$string['serverurl'] = 'Basis-URL der Nextcloud';
$string['serverurl_help'] = 'Geben Sie die Basis-URL Ihrer Nextcloud ein, zum Beispiel https://cloud.example.org. Tragen Sie hier nicht den WebDAV-Pfad ein.';
$string['username'] = 'Nextcloud-Nutzername';
$string['username_help'] = 'Verwenden Sie die Nutzer-ID aus der Nextcloud-WebDAV-Adresse unter Dateien > Dateieinstellungen. Sie kann vom Namen für die Anmeldung abweichen.';
