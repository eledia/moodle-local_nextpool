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
 * Language strings for nextpoolsource_onedrive.
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appheader'] = 'App in Microsoft Entra';
$string['appintro'] = 'Nextpool meldet sich bei Microsoft 365 als App an, ohne Nutzerkonto und Lizenz. Ihre IT registriert die App in Microsoft Entra mit der Anwendungsberechtigung Sites.Selected und gibt ihr Lese- und Schreibzugriff auf jede SharePoint-Site und jedes persönliche OneDrive, das Nextpool nutzen soll.';
$string['clientid'] = 'Anwendungs-ID (Client)';
$string['clientid_help'] = 'Die Anwendungs-ID (Client) von der Übersichtsseite der App-Registrierung in Microsoft Entra.';
$string['clientsecret'] = 'Geheimer Clientschlüssel';
$string['clientsecret_help'] = 'Der Wert eines geheimen Clientschlüssels der App (Zertifikate & Geheimnisse), nicht seine ID. Er wird verschlüsselt gespeichert und nie wieder angezeigt. Lassen Sie das Feld beim Bearbeiten leer, um den gespeicherten Schlüssel zu behalten.';
$string['driveheader'] = 'Bibliothek';
$string['driveid'] = 'Bibliothek';
$string['driveid_help'] = 'Die Dokumentbibliothek oder das persönliche OneDrive mit den Katalogdateien. Fügen Sie oben eine Adresse ein und wählen Sie „Bibliotheken laden“, um diese Liste zu füllen.';
$string['error_basefoldernotfound'] = 'Den Basisordner „{$a}“ gibt es in dieser Bibliothek nicht. Legen Sie ihn zuerst in SharePoint oder OneDrive an.';
$string['error_clientid'] = 'Geben Sie die Anwendungs-ID (Client) ein, z. B. 6bb5e8d9-efa9-455a-8548-3ac82d948080.';
$string['error_credentials'] = 'Microsoft hat die Zugangsdaten der App abgelehnt. Prüfen Sie Anwendungs-ID und geheimen Clientschlüssel; der Schlüssel ist eventuell abgelaufen.';
$string['error_drivenotfound'] = 'Die Bibliothek wurde nicht gefunden, oder die App hat keinen Zugriff darauf. Laden Sie die Bibliotheken erneut und wählen Sie eine aus.';
$string['error_forbidden'] = 'Die App hat keinen Zugriff auf diese Site oder dieses OneDrive. Ihre IT muss der App den Zugriff darauf freigeben (Sites.Selected).';
$string['error_locationurl'] = 'Geben Sie die Adresse einer SharePoint-Site, einer Bibliothek oder eines persönlichen OneDrives ein, beginnend mit https:// und endend auf sharepoint.com.';
$string['error_nodrive'] = 'Wählen Sie eine Bibliothek. Fügen Sie dazu zuerst die Adresse ein und wählen Sie „Bibliotheken laden“.';
$string['error_notafolder'] = '„{$a}“ ist eine Datei, kein Ordner.';
$string['error_onedrivenotfound'] = 'Der Ordner „{$a}“ wurde in der Bibliothek nicht gefunden.';
$string['error_quota'] = 'Die Bibliothek oder das OneDrive ist voll.';
$string['error_secretreenter'] = 'Mandant oder Anwendungs-ID haben sich geändert. Geben Sie den geheimen Clientschlüssel erneut ein.';
$string['error_sharinglink'] = 'Das ist ein Freigabelink. Öffnen Sie ihn im Browser und fügen Sie stattdessen die Adresse der Bibliothek oder des Ordners ein.';
$string['error_sitenotfound'] = 'Unter dieser Adresse wurde keine Site und kein OneDrive gefunden, oder die App hat keinen Zugriff darauf.';
$string['error_tenant'] = 'Microsoft kennt dieses Verzeichnis (Mandant) nicht. Prüfen Sie die Verzeichnis-ID (Mandant).';
$string['error_tenantid'] = 'Geben Sie die Verzeichnis-ID (Mandant) oder die primäre Domain Ihrer Microsoft-365-Organisation ein.';
$string['error_throttled'] = 'Microsoft bittet, {$a} Sekunden bis zur nächsten Anfrage zu warten. Nextpool versucht es später erneut.';
$string['loaddrives'] = 'Bibliotheken laden';
$string['locationurl'] = 'Adresse von Site, Bibliothek oder OneDrive';
$string['locationurl_help'] = 'Kopieren Sie die Adresse aus dem Browser: eine SharePoint-Site (dann eine ihrer Bibliotheken auswählen), eine Bibliothek oder einen Ordner darin, die Dateien eines Teams oder ein persönliches OneDrive (https://…-my.sharepoint.com/personal/…). Wird eine Adresse nicht erkannt, geben Sie die Site-ID aus Microsoft Graph im Format host,site-id,web-id ein.';
$string['pluginname'] = 'OneDrive/SharePoint';
$string['privacy:metadata:microsoft365'] = 'Kataloginhalte werden aus SharePoint oder OneDrive in Microsoft 365 gelesen und dorthin geschrieben, angemeldet als App. Es werden keine personenbezogenen Daten der handelnden Person übertragen.';
$string['privacy:metadata:microsoft365:content'] = 'Inhaltsdateien von Katalogeinträgen.';
$string['secretexpires'] = 'Geheimer Clientschlüssel läuft ab am';
$string['secretexpires_help'] = 'Das Ablaufdatum, das Microsoft Entra neben dem Schlüssel anzeigt. Die Statusprüfung warnt 30 Tage vorher.';
$string['secretexpireson'] = 'Geheimer Clientschlüssel läuft ab am {$a}';
$string['tenantid'] = 'Verzeichnis-ID (Mandant)';
$string['tenantid_help'] = 'Die Verzeichnis-ID (Mandant) von der Übersichtsseite der App-Registrierung oder die primäre Domain Ihrer Organisation, z. B. contoso.onmicrosoft.com.';
$string['warning_secretexpired'] = 'Der geheime Clientschlüssel der App ist am {$a} abgelaufen. Erstellen Sie in Microsoft Entra einen neuen Schlüssel und tragen Sie ihn hier ein.';
$string['warning_secretexpires'] = 'Der geheime Clientschlüssel der App läuft am {$a} ab. Erstellen Sie vorher in Microsoft Entra einen neuen Schlüssel und tragen Sie ihn hier ein.';
