# Changelog

All notable changes to `local_nextpool` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] (version 2026112100)

### Changed — repository layout (eLeDia), Moodle 5.1 or later
- The plugin now lives in `public/local/nextpool/` of the Nextpool repository, as the eLeDia repository layout requires;
  README, LICENSE, `.gitattributes` and `.gitignore` are in the plugin folder. The suite's DevFlow master, the CI and release scripts (`bin/`) moved here; the finished SCORM spike was removed.
- `requires` raised to Moodle 5.1 (`2025100600`), `supported` is `[501, 502]`: the `public/` layout exists from 5.1.

### Fixed — queued update rules (bug240)
- Queued automatic updates recheck the placement's current rule under the same lock used by rule changes.
  Switching to a fixed version or notification keeps the existing course content; explicit manual updates remain available.

### Fixed — quiz package bootstrap (bug239)
- Quiz package validation explicitly loads Moodle's question bank, so imports and previews work outside
  PHPUnit as well as in tests.

### Added — catalogue previews (F16)
- Published content opens in a shared preview/feedback dialog or a standalone page. Files, pages, books, links,
  H5P and question lists use their own safe preview; xAPI and SCORM use the installed player provider without
  creating learner launches, statements, state, completion or grades. Preview addresses expire within two hours
  or the configured session lifetime. Opening a preview creates a normal Moodle preview event.
- Preview, catalogue cards and headers share licence, author, version and update information. Course catalogue
  filters survive feedback return links; feedback refreshes preserve the open preview. Access is checked in the
  course context and embedded package content remains sandboxed.

### Added — guided tours (task177)
- Five optional Core tours introduce the catalogue, course placements, content entry page and editorial item
  list/detail pages to the appropriate roles. Installation and upgrades preserve administrator changes,
  disabled/deleted tours and each person's completed steps.

### Fixed — full browser regression
- Row actions wrap on narrow viewports while retaining 44-pixel targets. The catalogue keyboard test follows
  the real number of visible tab stops after preview and feedback added actions to each card.
- Update-notice tests explicitly run background distribution, and the SCORM learner rejection test checks the
  approved plain-language notice. The version bump refreshes Moodle's cached plugin styles during upgrade.

### Fixed — review, actions and accessibility
- The editorial item list counts submitted versions and storage-location drafts consistently with the review
  queue, and names pending reviews beside the independent item status with singular/plural labels.
- Submitting an item for review also submits its newest draft version (history, notification, review queue).
  An item submitted without such a draft waits in its own section of "Waiting for review" and counts once.
- Course placements use a Core action menu for update rules, update their status through AJAX, and retain an
  explicit POST form without JavaScript. Proposal actions share the final action column (task175).
- Destructive confirmations name the action, storage setup distinguishes a missing selection from unavailable
  locations, and import actions say what they do. Table row headers/roles, content language, current-page
  navigation and 44-pixel action targets improve keyboard and assistive-technology access.
- Repeated feedback submission is blocked while a request or confirmation is pending, then released on success,
  failure or cancellation (bug171). Core files are declared in privacy metadata; compatibility metadata lists
  Moodle 5.0–5.2 (bug172). Cross-version CI for this change remains a release check.

### Security — anonymous H5P previews
- H5P previews use a guest-owned content cache, a synthetic original identity and a catalogue-checked media
  proxy. Player settings contain no viewer identity or reporting/state endpoints, and no H5P viewed event is
  created. Library installation still requires the publication option and the publisher's current permission.
  Older Core builds without content/export access guards cannot start H5P previews. Upgrade 2026111591
  removes legacy preview cache deployments.
- Polling skips unchanged revisions that failed package validation for up to one day (bug163). New revisions,
  manual imports or clearing the application cache retry immediately; transfer failures are never cached.

### Security — audit corrections
- Re-submission checks the current proposal permission, withdrawn content is omitted from publication claims,
  and learner version-change signals use an opaque HMAC with an unexported session secret. Quiz markup is
  purified; package metadata reads are bounded and restore accepts only validated published content of the
  expected type. Random high-entropy credentials use SHA-256 digests, with legacy bcrypt upgraded on use.
- Graph token caches are encrypted; the OneDrive upgrade to 2026111550 purges old cached values. Nextcloud
  XML parsing blocks network access and missing child folders return an empty listing. SCORM provenance is
  recorded against the pinned upstream artifact.

### Fixed — catalogue audit events (bug170)
- Successful item/version deletion, withdrawal and proposal cancellation emit lifecycle events. Existing
  item, version and comment events link to their corresponding catalogue page. Rolled-back changes do not
  create log entries.

### Fixed — background distribution (bug164)
- Publishing queues one background distribution after commit. Per-item locking prevents older jobs from
  overwriting newer offers; deleted, withdrawn or superseded versions are ignored. Offers, notifications and
  automatic-update jobs now depend on Moodle cron; explicit application and policy changes remain immediate.

### Fixed — bug169
- Catalogue notifications are built in each recipient's language, restoring the prior language even if a
  message fails. Plain-text subjects keep literal ampersands instead of HTML entities. Covers comments,
  review assignment/decisions, proposals, published versions and pending course updates.


### Documentation
- Dokumentation (task129/task149/task206): Vorschau ohne Lerndaten, Auswertung, Sicherheitsgrenzen und Betriebshinweise mit dem implementierten Stand abgeglichen.

### Fixed — course placement compatibility
- Inserting an upload before an existing activity uses the supported Core move API on Moodle 5.0 and 5.1;
  Moodle 5.2 retains its course-format action. Position and visibility checks remain unchanged.

### Added — M08 direct uploads
- Teachers can check and upload xAPI, SCORM, H5P or individual files from the Nextpool entry page. The content
  creates the appropriate Moodle activity and a course-bound catalogue item together, preserving the chosen
  section, position, visibility, licence and author. Invalid archives and missing activity permissions are
  explained before saving; required H5P libraries need library-update permission.
- New versions of course-bound SCORM, H5P and file activities have a separate upload page. Unsafe updates are
  published and offered with a reason, preserving the existing activity and learner data. Changed content in
  the course is identified. Course backups carry the type; older archives infer it from the activity.
- Direct uploads use the activity defaults; additional activity settings are edited afterwards. Returning to a
  version before M08 after uploading one of the additional types is unsupported because older code rejects
  those types in a course context. No database migration is required.


### Security
- New version files belong to the guest user, so Moodle's H5P player (`/h5p/embed.php`) no longer shows them and no
  longer installs the libraries in them (bug234, version 2026111540). Before, every logged-in person who knew a
  version id and file name could view H5P versions of any item, drafts and proposals included, and trigger the
  site-wide installation of the H5P libraries in the package. The upgrade to version 2026111542 protects existing
  version files the same way: they get the guest user as owner, and H5P contents that Moodle had already deployed
  from them are deleted, because Moodle kept showing those without any check. Libraries installed that way are not
  uninstalled; if the upgrade removed such contents, it says so (also in the upgrade log) and asks admins to check
  the installed H5P content types.
- The same for the storage location "Moodle files" (`nextpoolsource_moodle` 2026111405, bug238): its files live in
  the system context without an owner too, so everybody who knew a storage location id, folder and file name could
  have an `.h5p` file shown, without login unless the site forces it, and its libraries installed. New files belong
  to the guest user; the upgrade to 2026111405 protects existing files and deletes contents Moodle had deployed from
  them, with the same notice. The guest owner and the upgrade routine are shared in `local\h5p_guard` (version
  2026111543); unpacked xAPI packages are protected by `mod_nextpool` 2026111510.

### Added
- Public interface for other plugins (version 2026111539): `api::get_placements_by_uuid()` lists the placements of
  catalogue items by item uuid (task181); `api::can_open_editorial()` and `api::get_editorial_url()` for a launcher
  entry (task179). The events `version_published`, `placement_created` and `placement_updated` are documented as
  a contract in `public/local/nextpool/docs/03-dev-doc.md` §9.18 (task182). The entry "Nextpool" in the user menu and in the top
  navigation disappears while the LernHive launcher reports that it shows Nextpool.
- Anonymous statistics per catalogue item, calculation and storage (F15, task126, version 2026111530): table
  `local_nextpool_item_stats`, daily scheduled task "compute item statistics", figures per version and in total
  from the content type handler, already anonymised (at least 5 people per figure, derivation guard). No user
  interface yet (task127).
- Tab "Statistics" on catalogue items (F15, task127, version 2026111532): placements, key figures in total and per
  version as an accessible table, data as of, hints for small groups and missing values. For everybody who may
  edit or review the item; no course names, no people.

### Changed
- New items, proposals and direct uploads get "All rights reserved" as the licence when the site has no default
  licence (task169, version 2026111538). The item page uses the status pills and the send icon of the lists and
  shows a single action as an icon button instead of a menu (task166, task167).
- Catalogue browser: the feedback icon on a tile opens an item dialogue instead of leaving the page; comments are
  added there, a badge shows the number of open comments (task173, version 2026111537). The link to the feedback
  page stays as the fallback without JavaScript.
- The editorial item list sorts by title, status, catalogue category and last change, in both directions and over
  all pages; the sort order survives paging and filtering. On narrow screens a "Sort by" menu replaces the header
  links (task168, version 2026111536).
- Item page (task165, version 2026111535): the "Details" card is gone; update mode as an icon and storage location,
  availability, licence and last change as pills sit in the card of the newest version, "Technical details" stays
  there as a collapsible.
- A missing licence shows as "All rights reserved" (© icon) on the item page; stored data is unchanged (task169,
  q133).
- Editorial item list (task166, version 2026111534): content type as an icon next to the title, origin course and
  catalogue category as pills, status pills with an icon each, a single row action as an icon button instead of a
  menu with one entry; "Submit for review" has a send icon instead of the upload icon (bug232).
- Catalogue categories (task167): the number of items links to the filtered item list; "Add subcategory" and
  "Delete" are icon buttons in the row.
- The entry in the user menu and in the top navigation is called "Nextpool" instead of "Learning content
  catalogue" (task178, PO 2026-11-15, version 2026111533).
- Catalogue browser: hints above the tiles are info alerts with the link as their action; the filter fields have
  the same width and look (task172, version 2026111531).
- Files are checked against the content type of the item (task157, bug205, version 2026111428): link, H5P, SCORM,
  book and page items refuse a file that does not fit, on import, upload, proposal and in the web service
  `import_version`; existing versions are not re-checked. The import page names the expected file and marks files
  that do not fit (bug206). The form "Add item" offers only types that can be placed, shows the expected file and
  marks authors as required (bug207). An upload on the import page no longer overwrites a foreign file and takes its
  write back when the version cannot be created (bug162).
- The item form shows licence and authors in the first section with the other required fields (PO 2026-11-15,
  version 2026111429).

### Fixed
- OneDrive: the stored library stays selectable when a storage location is edited (found by the task140 test,
  version 2026111541).
- Nextcloud: the first direct upload into a new folder no longer fails; Nextcloud 32 answers a PUT into a missing
  folder with 404 instead of 409 (bug231, `nextpoolsource_nextcloud` 2026111404).
- Transactions and writes to the storage location (task146, version 2026111427): a failed in-place update no longer
  leaves a transaction open (bug160); publishing runs under a lock per item and takes its write back when the
  database step fails (bug161); transfers to Nextcloud and OneDrive have a time limit and a failing storage location
  is asked once per polling run (bug163); distributing a published version runs after the commit (bug164); deleting
  a proposal rolls back on errors (bug165); a policy change offers no older version (bug166); restore with open
  proposals no longer raises a debugging message (bug167); competency identifiers are compared case-insensitively
  (bug168).

### Added
- Retention of proposals, privacy and H5P take-over (F07, task86, q64, q67, q70, version 2026111423): rejected and
  cancelled proposals are deleted 12 months after the decision with their file, history and comment links (scheduled
  task "Delete rejected and cancelled proposals after 12 months"); a proposed new item without any other version goes
  with them. The editorial team deletes them earlier with "Delete proposal" on the review page. The history on the
  review page names the person a review was assigned to and shows removed assignments and removed reasons. The
  privacy provider now also describes the remaining review fields and the notifications (`core_message`), exports
  the user's own proposals with their description and file, and keeps assignments of deleted people apart from
  removed ones. H5P activities can be taken over as the content of a proposal: the package as the activity has it
  now; an unchanged activity is recognised as unchanged.
- "Waiting for review" with assignment (F07, task85, TD-309, TD-310, q72, q73, q77, version 2026111422): reviewers find
  the versions in review and the drafts the storage location check imported in an overview (`reviews.php`, user menu
  entry and tab "Waiting for review (N)" with the number of reviews assigned to them or to nobody). It lists the items
  of the course categories where they may review and all items available site-wide, oldest submission first, with
  kind, submitter, waiting time, availability, outdated base and assignment, filters for "assigned to me / not
  assigned / assigned to someone else", kind (including "New from the storage location") and availability, and a
  download. Row actions "Review", "I review" and "Assign" (only people who may review the item). New notifications
  "Versions submitted for review of items you may review" (to every reviewer of the item until the review is assigned,
  then to the assigned person) and "Reviews assigned to you". "Completed reviews" lists the review decisions of the
  last 90 days with who decided and "self-approved". Reviewers of a course category may open site-wide items they
  may review.
- Take over an activity as the content of a proposal (F07, task83, TD-308, q64, version 2026111421): when proposing a
  new version or a new item from a page, URL, book or quiz, teachers choose "Take over from the activity" instead of
  uploading a file. The page becomes an HTML file (with embedded files a ZIP with `index.html`, which the page type
  now also places), the URL a link file, the book a ZIP in the format of the core book import (visible chapters,
  subchapters, chapter files) and the quiz a quiz package with the question versions the slots use, order, marks and
  pages. Random questions and question types the catalogue does not take stop the take-over with their names. The
  file is checked like an upload. New content type contract `supports_extract()`/`extract()`.
- Proposals from a course (F07, task82, TD-301, TD-304, q62, q65, version 2026111419): teachers with "Propose new
  versions to the catalogue" propose a new version of an item available in their course from the activity menu
  ("Propose a new version"), the placements page or the course view of the item, with a file checked like an import,
  a required description of the change and optionally the open comments it takes up. Other activities of a kind the
  catalogue can take are proposed with "Propose as new catalogue item" (title, description, licence, author); the item
  waits in review in the course category and reaches course catalogues once a reviewer publishes it. Proposals change
  no placement until they are published. "My proposals" (new tab of the course catalogue) lists the user's proposals
  with status and the latest reason; returned proposals are changed and submitted again, open ones cancelled. The
  review page shows the comments a proposal takes up, ticks them to be marked as done on publishing, and offers the
  proposer "Change and submit again" and "Cancel proposal". New notification "Proposals for catalogue items you
  subscribed to"; returned and rejected versions are announced to subscribers who see the item editorially, published
  versions to subscribers by the rules of the feedback. New public interface `\local_nextpool\api` (`can_propose()`,
  `get_propose_url()`) for other plugins.
- Item editors and editing together (F08, task84, adr25, q68, q69): the editorial team adds any active account of the
  site as item editor in a new section "Item editors" on the item page and removes them there. Item editors are
  subscribed automatically and get the notification "You were added as item editor of a catalogue item"; without
  editorial rights they reach their items from "My items" in the user menu, change the metadata and drafts, import
  versions and submit them for review, but cannot publish, delete the item, change its availability or add editors.
  Saving an item or importing a version after someone else did so since the form was opened is refused with that
  person's name, and the entries stay in the form. Items in Nextcloud or OneDrive get the icon "Open in storage
  location" for item editors and the editorial team. The person fields (item editors, authors) search accounts with
  the right to edit the item instead of a site-wide capability. Privacy export and deletion cover item editors.
- Feedback on catalogue items (F08, task81, TD-304, TD-307, q70, q76, version 2026111417): a section "Feedback" on the item page and a
  course view of the item (`feedback.php`, linked from the catalogue cards and the placements page) where everyone who
  can see the item in the catalogue, item editors and the editorial team comment on the item or one of its versions,
  reply (one level), mark comments as done (collapsed with name and time) and open them again, edit their own comments
  ("edited") and remove them; a removed comment with replies stays as "Comment removed". New comments in a course
  default to the version applied there. Subscribing and unsubscribing with a button; people who create an item or
  comment are subscribed automatically, an unsubscribe stays. New notification "Comments on catalogue items you
  subscribed to", web services for posting, editing, removing, marking as done and subscribing, and a JavaScript module
  that does all this without reloading the page and announces the result. Privacy export and deletion cover comments and
  subscriptions.
- Review page for versions in review (F07, task80, TD-306, q74, q75, task104): `review.php` shows the version with
  kind, creator, course, file, the comparison with the version it is based on, the hint "Based on version N, current
  is version M" with a compare link, and the review history with reasons and "Self-approved". Reviewers publish,
  return for revision or reject there, one labelled button each; returning and rejecting need a reason, and the change
  log can be adjusted before publishing. Publishing writes the version into the item's storage location first
  (Moodle file storage, Nextcloud, OneDrive) and records the written file, so the next storage location check imports
  nothing; an unreachable or read-only storage location stops publishing with the reason, and a file changed in the
  storage location since the latest version stops it with "Check storage location now", which turns the change into a
  draft. A proposed new item goes to the storage location for direct uploads, folder `vorschlaege/kurs-<id>/`, or to
  another storage location and folder the reviewer chooses. Publishing a version also publishes an item in draft or
  review; a withdrawn item stays withdrawn. New notifications "Review decisions" (to the person who created or
  submitted the version, with the reason) and "New versions of subscribed items". The item page offers "Review version
  N" instead of the publish, back-to-draft and reject buttons, and lists proposals in review; the interim reason form
  of task79 is gone. The badge "In review" is yellow with dark text instead of light blue (colour contrast).
- Data model and status logic for review and collaboration (F07, F08, task79, TD-301–TD-303, TD-310, adr24, version
  2026111414): versions record their origin (editors, storage location check, proposal from a course, direct upload),
  the course of a proposal, the assigned reviewer, when they were submitted and decided, and whether the publisher
  approved their own version. Proposals get their version number only when they are published, so rejected ones leave
  no gap. New final statuses "Rejected" and "Cancelled by proposer" for versions. Returning a version in review to
  draft and rejecting it need a reason and, like publishing, the review capability; the item page asks for the
  reason, and every status change is kept in the review history of the version. For items available site-wide, the review capability in any course category is enough to
  publish, return or reject (q77). New capability `local/nextpool:proposeversions` (course; editing teachers and
  managers) and events for submitted, returned and rejected versions. Tables for comments, subscriptions and item
  editors are created for the next steps. The upgrade sets the origin of existing versions to "storage location"
  where the storage location check created them and to "editorial" otherwise; numbers stay as they are.
- Course backups carry the package of items available only in their course (M06 task78f, TD-408, adr31, q56, q57,
  q91, q92, version 2026111412): the placement backup adds the item's metadata, its authors as display names and the
  snapshot of the applied version. On restore, an existing published item with that version is linked again even if
  it is not available in the target course (course copy, import, duplicate); otherwise a new item available only in
  the target course is created with the same version number, free-text authors and a placement on the restored
  activity. If the storage location for direct uploads cannot be written to, the package goes to the first Moodle
  file storage location; without one, or without the package, the activity is restored without catalogue item. Both
  cases are noted in the restore log. Items that are not course-bound are backed up without package as before. The
  restore reads the package straight from the backup directory by its content hash, because core does not put files
  of other contexts into the file pool.
- A file can be uploaded while creating an item (task101, F02, version 2026111411): the optional field "File for
  version 1" saves the file under its name in the base folder of the storage location and stores it as draft version
  1; the item, tags, competencies, custom fields and version are created in one transaction, and a written file is
  deleted again if anything fails. An existing file of the same name is never replaced, and a storage location that
  cannot be written to is refused; both show as a field error. New: `version_manager::create_from_upload()`.
- Storage location for direct uploads and the service `local\direct_upload` (M06 task78c, TD-404, TD-405, version
  2026111410): administrators choose an enabled storage location that can be written to below the list of storage
  locations (setting `uploadsourceid`; default: the first Moodle file storage). It cannot be deleted while chosen. The
  service turns a package into an item available only in its course, a file `kurs-<course id>/<item id>-<file name>`,
  a published version and a placement on an existing course module, or creates nothing: everything runs in one
  transaction, and a file written before a failure is deleted again (a new version writes the previous content
  back). New: `base::delete()` in the storage location contract, implemented by Moodle file storage, Nextcloud and
  OneDrive/SharePoint (`nextpoolsource_moodle` 2026111401, `nextpoolsource_nextcloud` 2026111402,
  `nextpoolsource_onedrive` 2026111402); `version_manager::create_from_file()`, `placement_manager::register()` and
  `placement_manager::apply(..., $force)`. Also in the service: `add_version()` (a new version of an item uploaded
  from the course: refuses a package that is unchanged or whose item is no longer available only in the course,
  writes the file, publishes the version and applies it at once) and `can_upload()` (the rule behind it). The
  activity form follows in task78d and task78e.
- Items uploaded from a course survive the deletion of that course (M06 task78b, TD-409, q56, version 2026111409):
  before core deletes the course, its items move to the course category with their unpacked packages, tags and
  custom field data, and published ones are withdrawn. The same happens to items of courses inside a course category
  that is deleted with its content. The origin course stays recorded; the list and the item page show "Uploaded from
  course: Deleted course". Deleting the activity keeps the item, which the list marks as "Without placement".
- Items can be available only in the course they were uploaded from (M06 direct upload core, task78a, adr30,
  version 2026111408): new field `origincourseid`; such items are xAPI items in the course context. The own course
  finds them in the catalogue (q90), other courses do not. Editors of the course categories above that course and of
  the site see them in the list of catalogue items with the badge "Uploaded from course" and a link to the course, and
  can filter by origin and by "without placement". "Available in" offers "Only course" plus the categories above the
  course and the whole site where the editor may review versions (q54); an item cannot be bound to a course
  afterwards. The privacy provider finds, exports and deletes item references in course contexts too. Downgrading
  below this version is not supported once items live in course contexts (older versions reject them).
- The system status check warns about storage locations that need attention although they work (task73, version
  2026111407): OneDrive/SharePoint warns from 30 days before the client secret expires and after it has expired, with
  name and date (`nextpoolsource_onedrive` 2026111401). The expiry date is entered with the secret, because Microsoft
  does not reveal it to the app (q61). The list of storage locations shows the library and expiry date under the type
  and the warning under the status. Contract: `get_status_warnings()` and `get_summary()`.
- New storage location type "OneDrive/SharePoint" (`nextpoolsource_onedrive` 2026111400, F03-onedrive, task72):
  a SharePoint document library or a personal OneDrive, reached as an app registered in Microsoft Entra with
  Sites.Selected (client credentials, no user account or licence). Admins paste the address of a site, library or
  OneDrive and pick the library with "Load libraries"; saving checks token, library and base folder and names the
  cause at the field. Changes are detected by cTag, files are followed by their driveItem id. Downloads follow the
  pre-authenticated redirect without the token, uploads above 4 MiB use an upload session in 10 MiB chunks that
  resumes after errors. Throttling (429/503) is respected with Retry-After. Not yet tested against a real tenant.
- Storage location errors can come from the storage location's own language file (`source_exception` takes a
  component, version 2026111406).
- Items follow their file (q46, task71, version 2026111405): each version stores the stable id of its file in the
  storage location (`sourceexternalid`). If the file is no longer at its path, the check looks it up by this id inside
  the base folder. A file that was only moved keeps the item connected without a new version; a renamed file creates a
  draft version, even with identical content (q50). A file that is deleted or moved out of the base folder marks the
  item "File not found" (`timesourcemissing`) on the item page and in the list of catalogue items; versions and
  placements stay, and the mark clears once the file is back. Polling imports from the file's current path. Versions
  from before this change learn the id on their next check. Storage location contract: `find_by_externalid()` (Moodle
  files: file id; Nextcloud: WebDAV `SEARCH` for `oc:fileid` limited to the base folder, `nextpoolsource_nextcloud`
  2026111401) and the form hook `definition_after_data()`.

### Security
- Item editors without editorial rights work on the file of their item only (bug102, review 2026-11-14, F08.AC08,
  q68, q69): "Import new version" no longer lets them browse the storage location, import other files or upload into
  other folders. They import the file of their item again or upload a new file that replaces it; the web service
  `local_nextpool_import_version` follows the same rule. The editorial team keeps the full storage location browser.
- Restoring a backup no longer trusts the course-bound flag in the backup file (bug104): only items that really live
  in a course context count as course-bound, and an existing one is only linked in its own course or by people who
  may enter that course; everyone else gets a new course-bound item from the package in the backup.
- Taking over an activity as the content of a proposal needs the right to manage that activity (bug108).
- Text pages as ZIP are refused if they are larger unpacked than the site's maximum upload size, or 500 MB without
  one, before anything is read into memory (bug109).
- Status changes from the "More actions" menus are links without sesskey to a confirmation page that posts the change
  (bug112, t105-h).
- Cross-site scripting closed (task140, audit 2026-11-15, version 2026111426; `nextpoolsource_nextcloud` and
  `nextpoolsource_onedrive` 2026111403): the comments a proposal can take up show the commenter's text and name as
  text (bug120); Nextcloud and OneDrive errors escape the account and the path, which can come from a link to
  "Import new version" (bug121); names of people are escaped in the headings of the version details and the
  comparison, in the edit-lock and import-conflict messages and in the author and reviewer menus (bug122); OneDrive
  library names from SharePoint reach the library menu as text (bug123). The status check of the storage locations
  no longer escapes the error messages a second time.

### Fixed
- The review page showed `[[reviewoutdated]]` for a proposal with an outdated base (F07.AC07); the string is back,
  and the link to the comparison only shows for people who may open it (bug103).
- No "Propose a new version" for items uploaded in a course and available only there; they take new versions in the
  activity settings (bug105, M06.AC08, t82-d).
- Version names with names of people are escaped in headings, confirmations and notifications; no double escaping
  on the assignment page (bug106).
- Undoing a failed direct upload no longer deletes the item's file when the previous version has no snapshot (bug107).
- Adding and removing comments run in one transaction; notifications are sent after the commit (bug110).
- "My proposals" and the list of reviewers no longer run one query per row or person (bug111).
- The editorial item list stacks its rows below 768 px, with the column name before each value, instead of
  needing horizontal scrolling (bug113, pipeline #66719).
- Nextcloud storage location with an app password: "Test connection" and every file access stopped with `Class "curl" not found`; the core curl class is now loaded first (`nextpoolsource_nextcloud` 2026111400).

### Changed
- Acceptance of review and collaboration (F07/F08, task87, test69, version 2026111425): Behat with Axe (including
  contrast) and tab-key paths for the feedback area, the proposal form, the review form, the assignment form and the
  "Waiting for review" overview (`review_accessibility.feature`). The "newer version available" badge on the
  placements page no longer uses `bg-info text-dark` (contrast 3.53). German strings: "Trainer/innen" instead of
  "Lehrende", "das Admin-Team" instead of "die Administration"; removed the unused string
  `versiontransition_cancelled`. User guide and developer documentation completed.
- Compact item page, next step in the item list and catalogue hints (task102, task104, task105, version 2026111424):
  the item page shows only what the item has. The header keeps "Import new version" and at most one labelled
  button for the next due step (`output\next_step`: the latest version while it is a draft or in review, otherwise
  the item status; a version in review opens the review page); the other status changes are in "More actions".
  Version rows are one line with status, a star for the current version and icon actions (submit, review, edit
  label and changelog, download, "More actions" with withdraw or publish again), then file and size, created and
  published as icon, name (profile link only with the right to see it) and a short date with the time in the
  tooltip, the compare link, and the changelog in one line. Withdrawing an item or a version asks for confirmation. A notice
  says when a version is published but the item is not, with the item step next to it. The details leave out empty
  fields, the content type, empty authors and competencies; the update mode is an icon with "Direct"/"Replace";
  identifier and file path are under "Technical details". The item list shows the next step as an icon and returns
  to the same filters; withdrawing is in the row menu. The course catalogue tells editors how many matching items
  are not published yet and tells everyone when other categories have results. The import page explains itself
  behind a help icon and drops its "Back" button.
- Automatic version label and no import form (task103, F02, F08.AC09): importing a file from the storage location
  or uploading one creates the draft version at once, labelled `YYYY-MM_<file name without extension>` (month of the
  import; also for version 1 from the item form and for drafts from the storage location check, central method
  `version_manager::build_label()`). The intermediate form with label and changelog is gone (`form\version_form`
  removed). The edit lock now works on the import page: its buttons carry the id of the latest version, and an import
  after someone else imported one is refused with their name. Label and changelog can be changed afterwards with the
  new icon "Edit label and changelog" in the version row of the item page (`versionedit.php`,
  `form\version_details_form`, `version_manager::update_details()`), for every version except rejected and cancelled
  ones; the form has its own edit lock. Removed strings: `fileuploaded`, `selectfilenamed`, `newversion`,
  `error_editconflictversion`, `error_editconflictversionauto`.
- Compact cards in the course catalogue (task106, version 2026111415): type, version, update variant (icon and short
  label "Direct" or "Replace", explanation in the tooltip) and licence as small badges (no badge without a licence),
  authors as one chip ("Sam Chen +2", all names in the tooltip), category, duration and tags in one small line, and
  "Place in course" as an icon at the top right of the card (accessible name "Place in course: <title>", tooltip; a
  short label appears next to the icon on touch devices). The long introduction moved into a help icon.
- Calmer row actions in the editorial lists (version 2026111404): catalogue categories show Edit, Move up and Move down
  as round icon buttons with tooltips, and "Add subcategory" and Delete in a "More actions" menu. Catalogue items get an
  actions column with Edit and View. View opens what the item shows to learners in a new window: the address of a link
  item, or else the newest placement in a course the user can see. The catalogue has no player of its own, so items
  without such a placement have no View action.
- The item page has a header with content type, title, status and key facts as chips (current version, placements,
  language, learning time, catalogue category), the labelled workflow buttons below it, and Edit, View and "More
  actions" as icons. Description and versions sit beside details, authors and competencies; empty details are left out.
- Shown as "eLeDia | Nextpool" in the site administration, like the other eLeDia plugins.
- Editors and reviewers find "Learning content catalogue" in the top navigation (same audience as the user menu entry).
- "Content catalogue" is a visible tab of the course navigation, right after "Participants", instead of under "More". Core still shows at most five tabs, so the last one moves to "More".
- New plugin icon in the eLeDia line style (book stack, from PluginFlow `assets/plugin-icons`).
- One catalogue area instead of four separate admin pages: a single entry *eLeDia | Nextpool* under Local plugins
  opens the first page the user may use, and tabs below the heading switch between catalogue items, catalogue
  categories, storage locations and item custom fields. The course catalogue and "Catalogue content in this course"
  are tabs as well. Each tab is shown only with the capability its page checks; capabilities and behaviour are
  unchanged. Page action next to the heading, filter bar and tables styled with eLeDia UX tokens (task64,
  version 2026111402).
- An H5P version published with "Allow H5P library updates" cannot be placed either while the site lacks H5P
  libraries it needs and the person who published it may not update H5P libraries; core would not install them
  (bug98).
- "Place version N as a new quiz" also takes the overall feedback (with its files), the completion conditions of
  the activity (including passing grade and minimum attempts) and the grade items of the old quiz; each question
  keeps the grade item it had in the old quiz (bug91, version 2026111201).
- An H5P version published without "Allow H5P library updates" cannot be placed while the site lacks H5P libraries
  it needs: teachers see a hint to inform the editorial team instead of the placement form, and the item page names
  the missing libraries for the editorial team. Missing libraries are now determined through the core H5P framework
  (same major and minor version, enabled), as core does when it deploys the package (bug88, version 2026111200).
- H5P packages from the catalogue install or update H5P libraries only if the version was published with the new
  option "Allow H5P library updates" (default off). Without it, courses get the content without the library folders
  and use the libraries installed on the site; a version that needs missing libraries is then offered to the teacher
  instead of being applied. Publishing an H5P version asks for the option, and the review names new or changed
  libraries of the package. Existing versions count as published without the option (q39, new field
  `local_nextpool_version.h5plibraries`, version 2026110700).
- Catalogue states (editorial list, placements of a course) are shown as Moodle core badges with the state as text
  and readable colours in every theme (U-26).
- Plugin name "Nextpool Catalogue" (German "Nextpool-Katalog"), so it can be told apart from the activity "Nextpool" in plugin lists, permissions and logs (q07).

### Security
- HTML of the content type "page" is cleaned before it is written into the course page, since mod_page shows
  its content without cleaning.
- Version snapshots are always sent as a download, whatever the URL asks for, and their MIME type follows the
  file name instead of what the storage location reports (bug38).
- The item description is converted to HTML and cleaned before it becomes the intro of a placed activity, since
  core shows intros without cleaning (bug41).

### Added
- Tests for the Nextcloud storage location (`nextpoolsource_nextcloud`): downloads stream into a file and uploads
  from a file through core curl, never as a string in memory (task18, limit05); app password mode sends exactly one
  Basic Auth header with every request and none without an app password, the client uses the configured
  credentials, server URL, user and password show only for this mode and the edit form never shows the stored
  password (task23). No code change.
- Web service `local_nextpool_import_version`: optional parameter `h5plibraries` (default false) publishes an H5P
  version with H5P library updates allowed, like the option in the publish form; only with `targetstatus` published
  and for H5P items (bug89).
- `item_manager::get_author_names()`: display names of the authors of an item, used for file attributions and by `mod_nextpool` (bug63, Q-13).
- Content type "quiz" (F04-quiz, task63): a catalogue quiz is a ZIP with the questions in Moodle XML
  (`questions.xml`, every question with an ID number) and `quiz.json` with order, marks and pages (q28). Only
  multiple choice, true/false, short answer, numerical, matching, essay, select missing words and drag and drop into
  text are accepted; other packages are refused when the version is imported, with the reason (q30). Placing creates
  a quiz with the questions in its own question bank and the site defaults of mod_quiz for all settings (q31).
  Updates without attempts are applied completely; with attempts only corrected questions are applied, as new
  question versions for new attempts. Changes of the structure, of a question type, or questions changed in the
  course wait for the teacher, who can place the version as a new quiz next to the old one; the old quiz is hidden
  with its attempts and grades, never deleted (q29). Version 2026110701.
- Content type handlers can check a file when it is imported (`validate_package()`) and place a version as a new
  activity next to the placed one (`can_place_alongside()`, `place_alongside()`).
- User menu entry "Learning content catalogue" for everyone who may edit or review catalogue items, also when the
  right is limited to a course category (U-20).
- Competencies of an item can be proposed by AI (core_ai "generate text"): only competencies of visible frameworks, with level, didactic function and learning objectives; editors pick what to keep (F14).
- When LernHive Skills offers its public API, placing an item proposes the level of each linked competency there (F14, Skills feat28).
  The proposals carry the source `nextpool`; a Skills release that does not know the source refuses them, which is logged and does not stop the placement (q34).
- Hook `\local_nextpool\hook\collect_content_types`: other plugins register content type handlers (first use: type xAPI package by `mod_nextpool`). A handler can add its own fields to the placement form and receives them as options in `place()`. `placement_manager::get_by_cmid()` returns the placement of a course module. The file area `xapipackage` keeps unpacked packages per version; it is deleted and moved with the item and never delivered by the catalogue (task45).
- Course catalogue: filter by tag (only tags of items available in the course) and tags on the item cards (F10).
- Scheduled task "Import changed files from storage locations as draft versions" (hourly): a changed file of a published item becomes a draft version based on the current one, shown as created by the storage location check; items with an open draft or review are skipped (F06 polling).
- Web service function `local_nextpool_import_version`: a build or another system imports the current file of an item from its storage location as a new version and can submit it for review or publish it, with the same rights as in the editorial pages; an unchanged file returns the latest version (F06).
- Content types H5P, SCORM and book can be placed in courses and are updated in the same activity. H5P and SCORM
  keep attempts and ask the teacher instead when an update would lose learner data or saved progress; a book is a
  ZIP with one HTML file per chapter, cleaned on import (task24/task25, q16, q18-q20).
- Competencies on catalogue items (F14): table `local_nextpool_competency` with competency idnumber, Skills level 1–5,
  didactic function (explain, example, practice, check, apply) and learning objectives (JSON, nugget format 2);
  maintained in the item form, shown on the item page with a notice for idnumbers that do not resolve. Placing an
  item links each resolvable competency with the new course module through core_competency and reports the others;
  the events `placement_created` and `placement_updated` carry `other[competencies]`. Privacy provider covers the table.
- Version comparison: each version links to a comparison with the one before it, showing which metadata and whether the content changed, and the changelogs in between (F02; content diff is R2).
- Custom fields for catalogue items (core custom fields): admins define them under Nextpool Catalogue > Item custom fields, editors fill them in the item form, the item page shows them, they are deleted with the item (F01).
- Tags for catalogue items in their own tag collection "nextpool": item form, item page, removed with the item, follow the item when its visibility changes (F01, F10).
- Message provider `updateavailable` and adhoc task `send_update_notice`: teachers are notified when a
  version waits for their decision (policy "Ask me first" or a failed automatic update, F06).
- German language strings (`lang/de`) for `local_nextpool`, `nextpoolsource_moodle` and `nextpoolsource_nextcloud`.
- Catalogue items with metadata, licence, authors, visibility context and editorial status (F01).
- Item versions as immutable snapshots with changelog, review workflow and change detection (F02).
- Storage backend subplugin type `nextpoolsource` with base-folder confinement and file-based transfer (F03).
- Backends `nextpoolsource_moodle` (Moodle file API) and `nextpoolsource_nextcloud` (WebDAV, OAuth 2 system account or app password).
- Admin page to manage storage locations, including a connection test.
- Privacy provider, events `item_created`, `item_updated`, `version_created`, `version_published`.
- Catalogue category tree with admin page (F10); capability `local/nextpool:managecategories`.
- Editorial pages: item list with filters, item form with authors, licence and visibility, item page with
  versions and workflow actions, version import by browsing the storage location (F01, F02).
- Version snapshots can be downloaded by content editors (`pluginfile`).
- Placing catalogue items in courses (F05): course catalogue page, placement dialog with update policy
  (update automatically / ask me first / keep this version), page "Catalogue content in this course".
- Update engine (F06): publishing a version distributes it per policy; automatic updates run as adhoc task with a
  lock per placement and are written into the existing course module (content type `file` as File resource), so
  completion and logs stay. Course banner when updates wait for the teacher; log per placement.
- Upload into a writable storage location from the version import page.
- Events `placement_created`, `placement_updated`; privacy provider covers placements and their log.
- Content types `page` (HTML file as Page) and `url` (link file as URL) can be placed and are updated in place.
- System status check "Nextpool storage locations" (Check API): every enabled storage location is reachable.
- Placements are part of course backups: restore, course copy, import and duplicate link the activity to the catalogue item again.

### Changed
- Usability pass after the review of 2026-10-21 (task55): the update banner sits in the notification area of the
  course page and is no longer announced as an alert on every visit (bug64); every field of a repeated author or
  competency row names its row, and rows can be removed (bug65, bug71); actions in the category, storage location
  and placement tables are POST buttons with a success message and name their row for screen readers, the first
  cell is a row header (bug66, bug67); the course catalogue is paged (24 per page) and empty lists say whether the
  filter or the catalogue is empty, with a link to clear the filter (bug68, bug77); the AI proposal asks for the
  policy with its own checkbox and shows that it is working (bug69); competency warnings say what to do (bug70);
  the item page shows "Import version" and "Edit" as buttons and the other actions in a menu, Delete last (bug72).
- German terms follow q40: Eintrag, Aktualisierung, Änderungsprotokoll, „Aktualisierungen ansehen“ (bug74).

### Fixed
- The action menu of the last custom field is no longer cut off: catalogue tables round their corner cells instead of hiding overflow (version 2026111403).
- At 320 px width the item page and the filter bars of the course catalogue and the editorial list no longer need horizontal scrolling (bug85).
  Behat feature `task55_acceptance.feature` checks keyboard use, visible focus, Axe and reflow of the task55 pages.
- `nextpoolsource_nextcloud` passes the core privacy compliance check: besides declaring Nextcloud as an external
  location, its provider now implements the request providers, which find, export and delete nothing because the
  plugin stores no personal data (bug84).
- Robustness pass after the review of 2026-10-21 (task54, version 2026110510):
  - Deleting a course category moves its catalogue items, with tags, unpacked packages and custom field data, to
    the parent category or the system context instead of leaving them in a dead context (bug33).
  - Polling records a new revision of unchanged content, so such a file is downloaded once and not every hour
    (bug36); a failing storage location of any kind no longer stops the run (bug60).
  - The AI vocabulary leaves out ambiguous idnumbers before its limit and the page says when competencies are cut
    off (bug51); the item form offers competencies of visible frameworks only (bug52).
  - Withdrawing a version takes back its offers in courses (bug53); publishing a version again repeats neither
    offers nor notices (bug54).
  - Placing removes the new activity again when a later step fails, e.g. the chapter import of a book (bug55).
  - Placements page, course catalogue tags, competency lookups and polling no longer query per row (bug56).
  - Placement events keep every linked competency of an idnumber in `competencyids` (bug57).
  - A SCORM version core cannot parse is refused before the package is replaced (bug58).
  - Automatic updates check a version once and read H5P/SCORM packages without copying them (bug59).
  - Smaller items from the review notes (bug63): "Ask me first" logs its offer, updates of a deleted activity
    end without retries, SCORM settings fall back to core defaults, Bloom levels show by name, unused strings
    are gone.
- Rest of the robustness pass (task54, version 2026110600):
  - The competency field of the item form searches the competencies of visible frameworks while the editor
    types (new web service `local_nextpool_search_competencies`, AMD module `competency_selector`) instead of
    putting every competency of the site into each row; chosen competencies keep their label (bug52).
  - All editorial pages show the same breadcrumb, Catalogue items › item › page; the item list had none and the
    edit page skipped the item (bug63, U-20).
- Card titles in the course catalogue were escaped twice.
- The AI proposal form showed `[[competencysuggestionapply]]` instead of its button label and note (bug30).
- Withdrawing the last published version of a published item is refused before anything is written, instead
  of leaving a changed record in an open database transaction until the end of the request (bug34).
- New items propose the type "File" again (bug61, regression of bug27).
- Messages for one storage location or one waiting update use the singular (bug79).
- Nextcloud storage locations require a base folder; an empty one exposed the whole account (bug26).
- New items propose a type that can be placed in courses; types without placement say so (bug27).
- Author blocks in the item form are numbered instead of showing "Author {no}" (bug28).
- Nextcloud errors name the account and path and point to the user ID in the WebDAV settings (task41).
- Nextcloud storage locations failed on a real server with "Call to protected method curl::request()"; PROPFIND and MKCOL now go through the public curl API (bug25).
- Category settings link the catalogue items for editors whose rights are limited to a course category (bug23).
- Category-level editors can open the editorial pages; the item list shows only items they may manage or review (bug07).
- Updating a placement checks it inside the placement lock and never goes back to an older version (bug14).
- Restore links an activity to its catalogue item only if the item is published and available in the course (bug15).
- The course banner for waiting updates also requires `local/nextpool:viewcatalogue`, like the page it links to (bug16).
- The course catalogue ignores an unknown category in the filter instead of failing (bug17).
- Nextcloud app passwords require an https base URL and must be entered again when the base URL changes (bug22).
