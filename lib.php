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
 * Library callbacks for local_nextpool.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Font Awesome icons of the plugin: the © of "All rights reserved" (task169), which core has no icon for.
 *
 * @return array<string,string> Icon => Font Awesome classes.
 */
function local_nextpool_get_fontawesome_icon_map(): array {
    return [
        'local_nextpool:i/copyright' => 'fa-regular fa-copyright',
    ];
}

/**
 * Add the catalogue to the course navigation ("More" menu) for teachers (F05).
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course.
 * @param context_course $context Course context.
 */
function local_nextpool_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (!has_capability('local/nextpool:viewcatalogue', $context)) {
        return;
    }
    $navigation->add(
        get_string('coursecatalogue', 'local_nextpool'),
        new moodle_url('/local/nextpool/catalogue.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_nextpool_catalogue',
        new pix_icon('i/search', '')
    );
}

/**
 * Offer "Propose a new version" or "Propose as new catalogue item" in the menu of an activity (F07, task82).
 *
 * Activities placed from the catalogue get "Propose a new version" if the item is available in the course
 * (F07.AC18); other activities of a kind the catalogue can take get "Propose as new catalogue item" (F07.AC10).
 * Activities whose package the teacher still uploads directly (M06) get neither. Only for users with
 * local/nextpool:proposeversions in the course.
 *
 * @param settings_navigation $settingsnav Settings navigation.
 * @param context $context Page context.
 */
function local_nextpool_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $PAGE;
    if ($context->contextlevel != CONTEXT_MODULE || !$PAGE->cm || during_initial_install()) {
        return;
    }
    $coursecontext = $context->get_course_context(false);
    if (!$coursecontext) {
        return;
    }
    $node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$node) {
        return;
    }
    $cm = $PAGE->cm;
    if (\local_nextpool\local\direct_upload::can_upload($cm)) {
        if ($cm->modname !== 'nextpool' && has_capability('moodle/course:manageactivities', $context)) {
            $node->add(
                get_string('uploadnewversion', 'local_nextpool'),
                new moodle_url('/local/nextpool/uploadversion.php', ['cmid' => $cm->id]),
                navigation_node::TYPE_SETTING,
                null,
                'local_nextpool_uploadversion',
                new pix_icon('i/upload', '')
            );
        }
        return;
    }
    if (!has_capability(\local_nextpool\local\access::PROPOSE, $coursecontext)) {
        return;
    }
    $url = \local_nextpool\api::get_propose_url((int) $cm->id);
    $label = get_string('proposeversion', 'local_nextpool');
    if ($url === null && \local_nextpool\local\proposal_manager::can_propose_item($cm)) {
        $url = new moodle_url('/local/nextpool/propose.php', ['cmid' => $cm->id, 'new' => 1]);
        $label = get_string('proposeitem', 'local_nextpool');
    }
    if ($url === null) {
        return;
    }
    $node->add($label, $url, navigation_node::TYPE_SETTING, null, 'local_nextpool_propose', new pix_icon('i/upload', ''));
}

/**
 * Link the catalogue items from the settings of a course category for its editors (F01, bug23).
 *
 * Editors with rights only in a category do not see the admin entry, which
 * requires the capabilities in the system context.
 *
 * @param navigation_node $navigation Category settings node.
 * @param context_coursecat $context Category context.
 */
function local_nextpool_extend_navigation_category_settings(navigation_node $navigation, context_coursecat $context): void {
    if (!has_any_capability([\local_nextpool\local\access::MANAGE, \local_nextpool\local\access::REVIEW], $context)) {
        return;
    }
    $navigation->add(
        get_string('items', 'local_nextpool'),
        new moodle_url('/local/nextpool/items.php'),
        navigation_node::TYPE_SETTING,
        null,
        'local_nextpool_items',
        new pix_icon('i/search', '')
    );
}

/**
 * Serve version snapshots to the editorial team.
 *
 * Snapshots live in the system context (file area 'version', itemid = version
 * id). Access is decided per item: the user needs manageitems or
 * reviewversions in the item's visibility context. Delivery to learners goes
 * through mod_nextpool (M02), not through this callback. Unpacked xAPI packages
 * (file area 'xapipackage') are never served here, only by mod/nextpool/content.php.
 *
 * @param stdClass $course Course (site course here).
 * @param stdClass|null $cm Course module (null here).
 * @param context $context File context.
 * @param string $filearea File area.
 * @param array $args Remaining path: itemid, filepath..., filename.
 * @param bool $forcedownload Ignored: snapshots are always sent as a download (bug38).
 * @param array $options Send options.
 * @return bool False if the file is not found or not accessible.
 */
function local_nextpool_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;
    if ($context->contextlevel == CONTEXT_SYSTEM && $filearea === \local_nextpool\local\h5p_preview::MEDIA_AREA) {
        $versionid = (int) array_shift($args);
        $courseid = (int) array_shift($args);
        require_login(get_course($courseid));
        $file = \local_nextpool\local\h5p_preview::find_media($versionid, $courseid, implode('/', $args));
        if (!$file) {
            return false;
        }
        \core\di::get(\local_nextpool\local\h5p_preview_sender::class)->send($file, $options);
        return true;
    }
    if ($context->contextlevel == CONTEXT_SYSTEM && $filearea === 'preview') {
        $versionid = (int) array_shift($args);
        $courseid = (int) array_shift($args);
        require_login(get_course($courseid));
        $file = \local_nextpool\local\preview_assets::find($versionid, $courseid, implode('/', $args));
        if (!$file) {
            return false;
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        send_stored_file($file, 0, 0, false, ['cacheability' => 'private']);
    }
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== \local_nextpool\local\version_manager::FILEAREA) {
        return false;
    }
    require_login(null, false);

    $versionid = (int) array_shift($args);
    $version = $DB->get_record(\local_nextpool\local\version_manager::TABLE, ['id' => $versionid]);
    if (!$version) {
        return false;
    }
    $item = \local_nextpool\local\item_manager::get((int) $version->itemid);
    // The person who proposed a version may download it too (F07, task82).
    if (!\local_nextpool\local\access::can_view($item) && !\local_nextpool\local\proposal_manager::is_own($version)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_nextpool', $filearea, $versionid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    \core\di::get(\local_nextpool\local\snapshot_sender::class)->send($file, $options);
}

/**
 * Move the catalogue items of a course category and its subcategories before core deletes them (bug33).
 *
 * Core deletes the subcategories first and then each category context with its files. The items go to the
 * parent category, or to the system context for a top-level category, together with their tags, unpacked
 * packages and custom field data. Items uploaded from a course below are withdrawn, because core deletes
 * that course next (M06 TD-409).
 *
 * @param stdClass $category Record of the category being deleted.
 */
function local_nextpool_pre_course_category_delete(stdClass $category): void {
    global $DB;
    $context = context_coursecat::instance((int) $category->id, IGNORE_MISSING);
    if (!$context) {
        return;
    }
    $contextids = $DB->get_fieldset_select(
        'context',
        'id',
        'id = :id OR ' . $DB->sql_like('path', ':path'),
        ['id' => $context->id, 'path' => $context->path . '/%']
    );
    $parent = (int) $category->parent ? context_coursecat::instance((int) $category->parent, IGNORE_MISSING) : null;
    $target = $parent ?: context_system::instance();
    // Courses below go with the category: their own items are withdrawn as on a course deletion (TD-409).
    [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
    $coursecontextids = $DB->get_fieldset_select(
        'context',
        'id',
        "id $insql AND contextlevel = :courselevel",
        $params + ['courselevel' => CONTEXT_COURSE]
    );
    \local_nextpool\hook_callbacks::release_course_bound_items($coursecontextids, $target);
    \local_nextpool\local\item_manager::move_items_from_contexts($contextids, $target);
}

/**
 * Move the catalogue items of a course category to the category that receives its content (bug33).
 *
 * Subcategories move with their context, so only the items of the category itself change context.
 *
 * @param core_course_category $category Category being deleted.
 * @param core_course_category $newparent Category that receives the content.
 */
function local_nextpool_pre_course_category_delete_move(core_course_category $category, core_course_category $newparent): void {
    \local_nextpool\local\item_manager::move_items_from_contexts([$category->get_context()->id], $newparent->get_context());
}

/**
 * System status checks (Site administration > Reports > System status).
 *
 * @return \core\check\check[]
 */
function local_nextpool_status_checks(): array {
    return [new \local_nextpool\check\sources()];
}

/**
 * Fragment "feedback": the content of the section "Feedback", reloaded by local_nextpool/feedback (F08, task81).
 *
 * Arguments: context (course context for the course view, system context for the editorial view), itemid,
 * courseid and filter. Access is checked like on the page (TD-304).
 *
 * @param array $args Fragment arguments.
 * @return string HTML
 */
function local_nextpool_output_fragment_feedback(array $args): string {
    global $PAGE;
    $itemid = clean_param($args['itemid'] ?? 0, PARAM_INT);
    $courseid = clean_param($args['courseid'] ?? 0, PARAM_INT);
    $filter = clean_param($args['filter'] ?? '', PARAM_ALPHANUM) ?: \local_nextpool\output\feedback_section::FILTER_ALL;
    $context = $args['context'];
    $expected = $courseid > 0 ? context_course::instance($courseid) : context_system::instance();
    if ((int) $context->id !== (int) $expected->id) {
        throw new moodle_exception('invalidcontext', 'error');
    }
    $item = \local_nextpool\local\item_manager::get($itemid);
    \local_nextpool\local\access::require_comment($item, $courseid);
    $section = new \local_nextpool\output\feedback_section($item, $courseid, $filter);
    $output = $PAGE->get_renderer('core');
    return $output->render_from_template('local_nextpool/feedback_content', $section->export_for_template($output));
}

/**
 * Fragment "itemdialog_feedback": the area "Feedback" of the item dialog in the catalogue browser (task173).
 *
 * Arguments: context (course context, system context for the editorial view), itemid and courseid. Access as on
 * feedback.php (TD-304).
 *
 * @param array $args Fragment arguments.
 * @return string HTML
 */
function local_nextpool_output_fragment_itemdialog_feedback(array $args): string {
    global $PAGE;
    $itemid = clean_param($args['itemid'] ?? 0, PARAM_INT);
    $courseid = clean_param($args['courseid'] ?? 0, PARAM_INT);
    $context = $args['context'];
    $expected = $courseid > 0 ? context_course::instance($courseid) : context_system::instance();
    if ((int) $context->id !== (int) $expected->id) {
        throw new moodle_exception('invalidcontext', 'error');
    }
    $item = \local_nextpool\local\item_manager::get($itemid);
    \local_nextpool\local\access::require_comment($item, $courseid);
    $area = new \local_nextpool\output\item_dialog_feedback($item, $courseid);
    $output = $PAGE->get_renderer('core');
    return $output->render_from_template('local_nextpool/item_dialog_feedback', $area->export_for_template($output));
}

/**
 * Shared dialog header in the authenticated course context (F16).
 *
 * @param array $args Fragment arguments.
 * @return string
 */
function local_nextpool_output_fragment_itemdialog_header(array $args): string {
    global $PAGE;
    [$item, $courseid] = local_nextpool_preview_fragment_target($args);
    $output = $PAGE->get_renderer('core');
    $data = \local_nextpool\output\item_preview::header(
        $item,
        $courseid,
        $output,
        \local_nextpool\local\catalogue_return::read($args)
    );
    return $output->render_from_template('local_nextpool/item_dialog_header', $data);
}

/**
 * Preview panel, with no activity launch or learning records (F16).
 *
 * @param array $args Fragment arguments.
 * @return string
 */
function local_nextpool_output_fragment_itemdialog_preview(array $args): string {
    global $PAGE;
    [$item, $courseid] = local_nextpool_preview_fragment_target($args);
    $output = $PAGE->get_renderer('core');
    $chapter = max(0, (int) clean_param($args['chapter'] ?? 0, PARAM_INT));
    $data = \local_nextpool\output\item_preview::data(
        $item,
        $courseid,
        $output,
        \local_nextpool\local\catalogue_return::read($args),
        $chapter
    );
    if (empty($args['chapterchange'])) {
        \local_nextpool\output\item_preview::log($item, $courseid, 'dialog');
    }
    return $output->render_from_template('local_nextpool/item_preview', $data);
}

/**
 * Shared permission and context check for both preview fragments.
 *
 * @param array $args Fragment arguments.
 * @return array Item and course id.
 */
function local_nextpool_preview_fragment_target(array $args): array {
    $courseid = (int) clean_param($args['courseid'] ?? 0, PARAM_INT);
    $context = context_course::instance($courseid);
    if ((int) $args['context']->id !== (int) $context->id) {
        throw new moodle_exception('invalidcontext', 'error');
    }
    require_capability('local/nextpool:viewcatalogue', $context);
    $item = \local_nextpool\local\item_manager::get((int) clean_param($args['itemid'] ?? 0, PARAM_INT));
    \local_nextpool\local\access::require_preview($item, $courseid);
    return [$item, $courseid];
}
