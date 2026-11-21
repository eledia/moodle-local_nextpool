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
$string['appintro'] = 'Nextpool signs in to Microsoft 365 as an app, without a user account or licence. Your IT registers the app in Microsoft Entra with the application permission Sites.Selected and grants it read and write access to each SharePoint site and personal OneDrive Nextpool may use.';
$string['clientid'] = 'Application (client) ID';
$string['clientid_help'] = 'The application (client) ID from the overview page of the app registration in Microsoft Entra.';
$string['clientsecret'] = 'Client secret';
$string['clientsecret_help'] = 'The value of a client secret of the app (Certificates & secrets), not its ID. It is stored encrypted and never shown again. Leave empty when editing to keep the stored secret.';
$string['driveheader'] = 'Library';
$string['driveid'] = 'Library';
$string['driveid_help'] = 'The document library or personal OneDrive that holds the catalogue files. Paste an address above and select "Load libraries" to fill this list.';
$string['error_basefoldernotfound'] = 'The base folder "{$a}" does not exist in this library. Create it in SharePoint or OneDrive first.';
$string['error_clientid'] = 'Enter the application (client) ID, e.g. 6bb5e8d9-efa9-455a-8548-3ac82d948080.';
$string['error_credentials'] = 'Microsoft rejected the app credentials. Check the application ID and the client secret; the secret may have expired.';
$string['error_drivenotfound'] = 'The library was not found, or the app has no access to it. Load the libraries again and select one.';
$string['error_forbidden'] = 'The app has no access to this site or OneDrive. Your IT has to grant the app access to it (Sites.Selected).';
$string['error_locationurl'] = 'Enter the address of a SharePoint site, library or personal OneDrive, starting with https:// and ending in sharepoint.com.';
$string['error_nodrive'] = 'Select a library. Paste the address and select "Load libraries" first.';
$string['error_notafolder'] = '"{$a}" is a file, not a folder.';
$string['error_onedrivenotfound'] = 'The folder "{$a}" was not found in the library.';
$string['error_quota'] = 'The library or OneDrive is full.';
$string['error_secretreenter'] = 'The tenant or the application ID has changed. Enter the client secret again.';
$string['error_sharinglink'] = 'This is a sharing link. Open it in the browser and paste the address of the library or folder instead.';
$string['error_sitenotfound'] = 'No site or OneDrive was found at this address, or the app has no access to it.';
$string['error_tenant'] = 'Microsoft does not know this directory (tenant). Check the directory (tenant) ID.';
$string['error_tenantid'] = 'Enter the directory (tenant) ID or the primary domain of your Microsoft 365 organisation.';
$string['error_throttled'] = 'Microsoft asked to wait {$a} seconds before the next request. Nextpool will try again later.';
$string['loaddrives'] = 'Load libraries';
$string['locationurl'] = 'Address of site, library or OneDrive';
$string['locationurl_help'] = 'Copy the address from the browser: a SharePoint site (then select one of its libraries), a library or a folder in it, a Teams files library or a personal OneDrive (https://…-my.sharepoint.com/personal/…). If an address is not recognised, enter the site ID from Microsoft Graph in the form host,site-id,web-id.';
$string['pluginname'] = 'OneDrive/SharePoint';
$string['privacy:metadata:microsoft365'] = 'Catalogue content is read from and written to SharePoint or OneDrive in Microsoft 365, signed in as an app. No personal data of the acting user is sent.';
$string['privacy:metadata:microsoft365:content'] = 'Content files of catalogue items.';
$string['secretexpires'] = 'Client secret expires on';
$string['secretexpires_help'] = 'The expiry date shown next to the secret in Microsoft Entra. The system status check warns 30 days before it.';
$string['secretexpireson'] = 'Client secret expires on {$a}';
$string['tenantid'] = 'Directory (tenant) ID';
$string['tenantid_help'] = 'The directory (tenant) ID from the overview page of the app registration, or the primary domain of your organisation, e.g. contoso.onmicrosoft.com.';
$string['warning_secretexpired'] = 'The client secret of the app expired on {$a}. Create a new secret in Microsoft Entra and enter it here.';
$string['warning_secretexpires'] = 'The client secret of the app expires on {$a}. Create a new secret in Microsoft Entra and enter it here before then.';
