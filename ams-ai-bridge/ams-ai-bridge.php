<?php
/**
 * Plugin Name: AMS AI Bridge
 * Description: Controlled Elementor draft editing for the AMS Wren and Quill agents. Pilot: roofing page only.
 * Version: 0.1.3
 * Update URI: https://github.com/jimster-status/ams-ai-bridge
 * Requires PHP: 7.4
 * Text Domain: ams-ai-bridge
 */

if (!defined('ABSPATH')) { exit; }

final class AMS_AI_Bridge {
    const VERSION = '0.1.3';
    const NS = 'ams-ai-bridge/v1';
    const SOURCE_SLUG = 'roofing-contractor-marketing';
    const META_SOURCE = '_ams_ai_bridge_source_id';
    const META_BASE = '_ams_ai_bridge_base_hash';
    const META_LIVE_BASE = '_ams_ai_bridge_live_base';
    const META_BACKUP_SOURCE = '_ams_ai_bridge_backup_source';
    const META_BACKUP_DATA = '_ams_ai_bridge_backup_data';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_ams_ai_bridge_create_draft', array(__CLASS__, 'create_draft_action'));
        add_action('admin_post_ams_ai_bridge_apply', array(__CLASS__, 'apply_action'));
        add_action('admin_post_ams_ai_bridge_restore', array(__CLASS__, 'restore_action'));
        add_action('init', array(__CLASS__, 'register_backup_type'));
    }

    public static function activate() {
        add_role('ams_ai_bridge_agent', 'AMS AI Bridge Agent', array(
            'read' => true, 'ams_ai_bridge_read' => true, 'ams_ai_bridge_draft' => true,
        ));
        $admin = get_role('administrator');
        if ($admin) {
            foreach (array('ams_ai_bridge_read', 'ams_ai_bridge_draft', 'ams_ai_bridge_apply') as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    public static function register_backup_type() {
        register_post_type('ams_ai_backup', array(
            'public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'supports' => array('title', 'editor'), 'capability_type' => 'post',
        ));
    }

    private static function permission($cap) {
        return current_user_can($cap);
    }

    public static function read_permission() { return self::permission('ams_ai_bridge_read'); }
    public static function draft_permission() { return self::permission('ams_ai_bridge_draft'); }

    public static function routes() {
        register_rest_route(self::NS, '/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array(__CLASS__, 'read_permission'),
        ));
        register_rest_route(self::NS, '/pages/(?P<id>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'page'),
            'permission_callback' => array(__CLASS__, 'read_permission'),
        ));
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/draft', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'make_draft'),
            'permission_callback' => array(__CLASS__, 'draft_permission'),
        ));
        register_rest_route(self::NS, '/drafts/(?P<id>\d+)/widgets', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'widgets'),
            'permission_callback' => array(__CLASS__, 'read_permission'),
        ));
        register_rest_route(self::NS, '/drafts/(?P<id>\d+)/widgets/(?P<widget_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PATCH', 'callback' => array(__CLASS__, 'patch_widget'),
            'permission_callback' => array(__CLASS__, 'draft_permission'),
            'args' => array(
                'field' => array('required' => true, 'type' => 'string'),
                'value' => array('required' => true, 'type' => 'string'),
                'expected_hash' => array('required' => true, 'type' => 'string'),
            ),
        ));
    }

    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => $status));
    }

    private static function source_page() {
        return get_page_by_path(self::SOURCE_SLUG, OBJECT, 'page');
    }

    private static function allowed_source($id) {
        $source = self::source_page();
        return $source && (int)$source->ID === (int)$id && $source->post_status === 'publish';
    }

    private static function decode_data($post_id) {
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (is_array($raw)) { $parsed = $raw; }
        elseif (is_string($raw) && $raw !== '') { $parsed = json_decode($raw, true); }
        else { return self::error('no_elementor_data', 'This page does not have Elementor data.', 409); }
        if (!is_array($parsed) || !self::is_list($parsed)) {
            return self::error('invalid_elementor_data', 'Elementor data is not a valid element list.', 409);
        }
        return $parsed;
    }

    private static function is_list($arr) {
        if (function_exists('array_is_list')) { return array_is_list($arr); }
        return array_keys($arr) === range(0, count($arr) - 1) || $arr === array();
    }

    private static function encode_data($data) {
        return wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function hash($data) {
        return hash('sha256', self::encode_data($data));
    }

    private static function draft_source($post_id) {
        $draft = get_post($post_id);
        if (!$draft || $draft->post_type !== 'page' || $draft->post_status !== 'draft') { return 0; }
        $source = (int)get_post_meta($draft->ID, self::META_SOURCE, true);
        if (!$source || !self::allowed_source($source)) { return 0; }
        return $source;
    }

    private static function deny_unless_editable($id) {
        if (!current_user_can('edit_post', $id) && !current_user_can('ams_ai_bridge_draft')) {
            return self::error('forbidden_page', 'Not authorized for this page.', 403);
        }
        return true;
    }

    public static function status() {
        $p = self::source_page();
        return rest_ensure_response(array(
            'name' => 'AMS AI Bridge', 'version' => self::VERSION,
            'api_version' => 'v1', 'elementor_active' => did_action('elementor/loaded') > 0 || defined('ELEMENTOR_VERSION'),
            'pilot_source_id' => $p ? (int)$p->ID : null,
            'features' => array('read_page', 'draft_clone', 'read_widgets', 'patch_text_widget'),
            'publication' => 'wordpress_admin_only',
        ));
    }

    public static function page($req) {
        $id = absint($req['id']);
        if (!self::allowed_source($id)) { return self::error('not_allowlisted', 'This page is not enabled for the pilot.', 403); }
        $permission = self::deny_unless_editable($id);
        if (is_wp_error($permission)) { return $permission; }
        $data = self::decode_data($id);
        if (is_wp_error($data)) { return $data; }
        $post = get_post($id);
        return rest_ensure_response(array(
            'id' => $id, 'title' => get_the_title($id), 'url' => get_permalink($id),
            'status' => $post->post_status, 'elementor_hash' => self::hash($data),
            'widget_count' => count(self::collect_widgets($data)),
            'note' => 'Read-only summary. Copy to a draft before editing.',
        ));
    }

    public static function make_draft($req) {
        $source_id = absint($req['id']);
        if (!self::allowed_source($source_id)) { return self::error('not_allowlisted', 'Source is not enabled.', 403); }
        $permission = self::deny_unless_editable($source_id);
        if (is_wp_error($permission)) { return $permission; }
        $data = self::decode_data($source_id);
        if (is_wp_error($data)) { return $data; }
        if (!defined('ELEMENTOR_VERSION') && !did_action('elementor/loaded')) {
            return self::error('elementor_not_active', 'Elementor must be active before creating an Elementor draft.', 409);
        }
        $source = get_post($source_id);
        $live_baseline = self::live_baseline($source);
        $draft_id = wp_insert_post(array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => $source->post_title . ' — AMS AI Working Draft',
            'post_content' => $source->post_content, 'post_excerpt' => $source->post_excerpt,
            'post_parent' => (int)$source->post_parent, 'post_author' => get_current_user_id(),
            'comment_status' => 'closed', 'ping_status' => 'closed',
        ), true);
        if (is_wp_error($draft_id)) { return $draft_id; }
        foreach (array('_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_page_settings', '_elementor_version', '_wp_page_template') as $key) {
            if (metadata_exists('post', $source_id, $key)) {
                $value = get_post_meta($source_id, $key, true);
                update_post_meta($draft_id, $key, is_string($value) ? wp_slash($value) : $value);
            }
        }
        update_post_meta($draft_id, self::META_SOURCE, $source_id);
        update_post_meta($draft_id, self::META_BASE, self::hash($data));
        update_post_meta($draft_id, self::META_LIVE_BASE, $live_baseline);
        return rest_ensure_response(array('draft_id' => $draft_id, 'source_id' => $source_id,
            'status' => 'draft', 'draft_hash' => self::hash($data),
            'edit_url' => admin_url('post.php?post=' . $draft_id . '&action=elementor'),
            'warning' => 'This is a standalone draft page, not a WordPress revision. SEO fields are intentionally not copied.',
        ));
    }

    private static function editable_fields($widget) {
        $map = array(
            'heading' => array('title'),
            'text-editor' => array('editor'),
            'button' => array('text'),
            'icon-box' => array('title_text', 'description_text'),
            'image-box' => array('title_text', 'description_text'),
        );
        return isset($map[$widget]) ? $map[$widget] : array();
    }

    private static function collect_widgets($elements, &$out = array()) {
        foreach ($elements as $item) {
            if (!is_array($item)) { continue; }
            if (isset($item['id'], $item['elType']) && $item['elType'] === 'widget') {
                $type = isset($item['widgetType']) ? $item['widgetType'] : '';
                $fields = self::editable_fields($type);
                if ($fields) {
                    $settings = isset($item['settings']) && is_array($item['settings']) ? $item['settings'] : array();
                    $values = array();
                    foreach ($fields as $field) {
                        if (isset($settings[$field]) && is_string($settings[$field])) { $values[$field] = $settings[$field]; }
                    }
                    $out[] = array('id' => $item['id'], 'type' => $type, 'fields' => $values);
                }
            }
            if (isset($item['elements']) && is_array($item['elements'])) { self::collect_widgets($item['elements'], $out); }
        }
        return $out;
    }

    public static function widgets($req) {
        $id = absint($req['id']);
        $source = self::draft_source($id);
        if (!$source) { return self::error('not_bridge_draft', 'Not an AMS Bridge draft.', 403); }
        $permission = self::deny_unless_editable($id);
        if (is_wp_error($permission)) { return $permission; }
        $data = self::decode_data($id);
        if (is_wp_error($data)) { return $data; }
        return rest_ensure_response(array('draft_id' => $id, 'source_id' => $source,
            'hash' => self::hash($data), 'widgets' => self::collect_widgets($data)));
    }

    private static function change_widget(&$elements, $id, $field, $value, &$matches) {
        foreach ($elements as &$item) {
            if (!is_array($item)) { continue; }
            if (isset($item['id'], $item['elType']) && (string)$item['id'] === $id && $item['elType'] === 'widget') {
                $type = isset($item['widgetType']) ? $item['widgetType'] : '';
                if (!in_array($field, self::editable_fields($type), true)) { continue; }
                if (!isset($item['settings']) || !is_array($item['settings'])) { continue; }
                $item['settings'][$field] = $value;
                $matches++;
            }
            if (isset($item['elements']) && is_array($item['elements'])) {
                self::change_widget($item['elements'], $id, $field, $value, $matches);
            }
        }
        unset($item);
    }

    public static function patch_widget($req) {
        $id = absint($req['id']);
        if (!self::draft_source($id)) { return self::error('not_bridge_draft', 'Not an AMS Bridge draft.', 403); }
        $permission = self::deny_unless_editable($id);
        if (is_wp_error($permission)) { return $permission; }
        $data = self::decode_data($id);
        if (is_wp_error($data)) { return $data; }
        $current_hash = self::hash($data);
        $expected = (string)$req->get_param('expected_hash');
        if (!hash_equals($current_hash, $expected)) {
            return self::error('stale_draft', 'Draft changed since last read. Re-fetch and review.', 409);
        }
        $field = sanitize_key((string)$req->get_param('field'));
        $widget_id = (string)$req['widget_id'];
        $value = (string)$req->get_param('value');
        if (strlen($value) > 15000) { return self::error('too_long', 'Widget text exceeds the 15,000-byte limit.', 400); }
        $value = $field === 'editor' || $field === 'description_text' ? wp_kses_post($value) : sanitize_text_field($value);
        $matches = 0;
        self::change_widget($data, $widget_id, $field, $value, $matches);
        if ($matches !== 1) { return self::error('ambiguous_widget', 'Widget missing, unsupported or duplicate ID.', 409); }
        $encoded = self::encode_data($data);
        if (!$encoded) { return self::error('encode_failed', 'Could not encode updated Elementor structure.', 500); }
        $saved = update_post_meta($id, '_elementor_data', wp_slash($encoded));
        if ($saved === false) { return self::error('save_failed', 'Unable to save widget data.', 500); }
        self::clear_elementor_cache();
        return rest_ensure_response(array('draft_id' => $id, 'widget_id' => $widget_id,
            'field' => $field, 'new_hash' => self::hash($data), 'status' => 'draft_modified',
            'edit_url' => admin_url('post.php?post=' . $id . '&action=elementor')));
    }

    private static function clear_elementor_cache() {
        if (class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::instance();
            if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                $plugin->files_manager->clear_cache();
            }
        }
    }

    public static function admin_menu() {
        add_management_page('AMS AI Bridge', 'AMS AI Bridge', 'ams_ai_bridge_apply', 'ams-ai-bridge', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!current_user_can('ams_ai_bridge_apply') || !current_user_can('manage_options')) { wp_die('Not authorized.'); }
        $source = self::source_page();
        echo '<div class="wrap"><h1>AMS AI Bridge — Review &amp; Apply</h1>';
        echo '<p>Pilot access is limited to the published roofing-contractor-marketing page. Review a proposed draft in Elementor and compare it with the original before applying.</p>';
        if (!$source) { echo '<p><strong>Pilot source page not found.</strong></p></div>'; return; }
        echo '<p><strong>Live source:</strong> ' . esc_html($source->post_title) . ' (ID ' . absint($source->ID) . ')</p>';
        echo '<p>Create an unpublished Elementor draft of this page for testing. This does not modify the live source.</p>';
        if (current_user_can('edit_post', $source->ID)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ams_bridge_create_' . $source->ID);
            echo '<input type="hidden" name="action" value="ams_ai_bridge_create_draft">';
            echo '<input type="hidden" name="source_id" value="' . absint($source->ID) . '">';
            echo '<button type="submit" class="button button-secondary">Create working draft (no live changes)</button>';
            echo '</form>';
        }
        if (isset($_GET['ams_bridge_result'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['ams_bridge_result']))) . '</p></div>';
        }
        $drafts = get_posts(array('post_type'=>'page', 'post_status'=>'draft', 'posts_per_page'=>50,
            'meta_key'=>self::META_SOURCE, 'meta_value'=>(string)$source->ID));
        echo '<h2>Working drafts</h2><table class="widefat striped"><thead><tr><th>Draft</th><th>Review</th><th>Apply</th></tr></thead><tbody>';
        foreach ($drafts as $d) {
            if (!current_user_can('edit_post', $d->ID)) { continue; }
            echo '<tr><td>' . esc_html($d->post_title) . ' (#' . absint($d->ID) . ')</td><td><a href="' . esc_url(admin_url('post.php?post='.$d->ID.'&action=elementor')) . '">Open in Elementor</a></td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ams_bridge_apply_' . $d->ID);
            echo '<input type="hidden" name="action" value="ams_ai_bridge_apply"><input type="hidden" name="draft_id" value="' . absint($d->ID) . '">';
            echo '<button class="button button-primary" type="submit" onclick="return confirm(\'Apply this draft to the LIVE roofing page? A backup will be created first.\')">Apply to live page</button></form></td></tr>';
        }
        echo '</tbody></table><h2>Backups</h2>';
        $backups = get_posts(array('post_type'=>'ams_ai_backup', 'post_status'=>'private', 'posts_per_page'=>20,
            'meta_key'=>self::META_BACKUP_SOURCE, 'meta_value'=>(string)$source->ID));
        foreach ($backups as $b) {
            echo '<p>' . esc_html($b->post_title) . ' <form style="display:inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ams_bridge_restore_' . $b->ID);
            echo '<input type="hidden" name="action" value="ams_ai_bridge_restore"><input type="hidden" name="backup_id" value="' . absint($b->ID) . '">';
            echo '<button type="submit" class="button" onclick="return confirm(\'Restore this older Elementor snapshot to LIVE?\')">Restore</button></form></p>';
        }
        echo '</div>';
    }

    private static function admin_guard($id, $nonce_action, $nonce) {
        if (!current_user_can('ams_ai_bridge_apply') || !current_user_can('manage_options') || !wp_verify_nonce($nonce, $nonce_action . $id)) {
            wp_die('Not authorized or invalid nonce.', '', array('response' => 403));
        }
    }

    private static function redirect_message($message) {
        wp_safe_redirect(add_query_arg('ams_bridge_result', rawurlencode($message), admin_url('tools.php?page=ams-ai-bridge')));
        exit;
    }

    private static function create_backup($source_id) {
        $raw = get_post_meta($source_id, '_elementor_data', true);
        $backup_id = wp_insert_post(array('post_type'=>'ams_ai_backup', 'post_status'=>'private',
            'post_title'=>'AMS roofing snapshot '.gmdate('Y-m-d H:i:s').' UTC',
            'post_content'=>''), true);
        if (is_wp_error($backup_id)) { return $backup_id; }
        update_post_meta($backup_id, self::META_BACKUP_SOURCE, $source_id);
        update_post_meta($backup_id, self::META_BACKUP_DATA, is_string($raw) ? wp_slash($raw) : wp_slash(self::encode_data($raw)));
        return $backup_id;
    }

    public static function create_draft_action() {
        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        self::admin_guard($source_id, 'ams_bridge_create_', $nonce);
        if (!self::allowed_source($source_id) || !current_user_can('edit_post', $source_id)) {
            wp_die('Invalid or unauthorized source page.', '', array('response' => 403));
        }
        $response = self::make_draft(array('id' => $source_id));
        if (is_wp_error($response)) {
            wp_die(esc_html($response->get_error_message()), 'Unable to create draft', array('response' => 400));
        }
        if (!($response instanceof WP_REST_Response)) {
            wp_die('Unexpected draft response.', '', array('response' => 500));
        }
        $result = $response->get_data();
        $draft_id = isset($result['draft_id']) ? absint($result['draft_id']) : 0;
        if (!$draft_id) { wp_die('Draft creation did not return an ID.', '', array('response' => 500)); }
        self::redirect_message('Created unpublished Elementor working draft #' . $draft_id . '. The live roofing page was not changed.');
    }

    private static function live_baseline($post) {
        $fields = array($post->post_modified_gmt, $post->post_title, $post->post_content,
            $post->post_excerpt, $post->post_name, $post->post_status);
        foreach (array('_elementor_page_settings', '_wp_page_template', '_thumbnail_id',
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw') as $field) {
            $fields[] = get_post_meta($post->ID, $field, true);
        }
        return hash('sha256', serialize($fields));
    }

    public static function apply_action() {
        $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        self::admin_guard($draft_id, 'ams_bridge_apply_', $nonce);
        $source_id = self::draft_source($draft_id);
        if (!$source_id || !current_user_can('edit_post', $source_id)) { wp_die('Invalid source or draft.'); }
        $live = self::decode_data($source_id);
        $draft = self::decode_data($draft_id);
        if (is_wp_error($live) || is_wp_error($draft)) { wp_die('Elementor data is invalid.'); }
        $base_hash = (string)get_post_meta($draft_id, self::META_BASE, true);
        $live_baseline = (string)get_post_meta($draft_id, self::META_LIVE_BASE, true);
        if (!$base_hash || !hash_equals(self::hash($live), $base_hash) || !$live_baseline ||
            !hash_equals($live_baseline, self::live_baseline(get_post($source_id)))) {
            wp_die('The live page changed or this draft predates live-page checks. Create a fresh working draft.', '', array('response'=>409));
        }
        if (self::hash($live) === self::hash($draft)) { self::redirect_message('No Elementor changes to apply.'); }
        $backup = self::create_backup($source_id);
        if (is_wp_error($backup)) { wp_die('Backup creation failed; nothing was applied.'); }
        $ok = update_post_meta($source_id, '_elementor_data', wp_slash(self::encode_data($draft)));
        if ($ok === false) { wp_die('Could not apply draft. Backup ID: ' . absint($backup)); }
        self::clear_elementor_cache();
        self::redirect_message('Applied draft #' . $draft_id . ' to roofing page; backup #' . $backup . ' created. Check the live page.');
    }

    public static function restore_action() {
        $backup_id = isset($_POST['backup_id']) ? absint($_POST['backup_id']) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        self::admin_guard($backup_id, 'ams_bridge_restore_', $nonce);
        $backup = get_post($backup_id);
        $source_id = (int)get_post_meta($backup_id, self::META_BACKUP_SOURCE, true);
        if (!$backup || $backup->post_type !== 'ams_ai_backup' || !self::allowed_source($source_id) || !current_user_can('edit_post', $source_id)) {
            wp_die('Invalid backup.');
        }
        $decoded = json_decode((string)get_post_meta($backup_id, self::META_BACKUP_DATA, true), true);
        if (!is_array($decoded) || !self::is_list($decoded)) { wp_die('Backup invalid.'); }
        $new_backup = self::create_backup($source_id);
        if (is_wp_error($new_backup)) { wp_die('Could not back up current live page.'); }
        $ok = update_post_meta($source_id, '_elementor_data', wp_slash(self::encode_data($decoded)));
        if ($ok === false) { wp_die('Restore failed.'); }
        self::clear_elementor_cache();
        self::redirect_message('Restored snapshot #' . $backup_id . '; pre-restore backup #' . $new_backup . ' retained.');
    }
}
register_activation_hook(__FILE__, array('AMS_AI_Bridge', 'activate'));
AMS_AI_Bridge::init();
require_once __DIR__ . '/updater.php';
