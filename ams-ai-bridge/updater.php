<?php

if (!defined('ABSPATH')) { exit; }

final class AMS_AI_Bridge_Updater {
    const REPOSITORY = 'https://github.com/jimster-status/ams-ai-bridge';
    const MANIFEST = 'https://raw.githubusercontent.com/jimster-status/ams-ai-bridge/main/update.json';
    const SLUG = 'ams-ai-bridge';
    const CACHE = 'ams_ai_bridge_update_manifest';

    private static function plugin_file() {
        return plugin_basename(__DIR__ . '/ams-ai-bridge.php');
    }

    public static function clear_cache() {
        delete_site_transient(self::CACHE);
    }

    private static function manifest() {
        $cached = get_site_transient(self::CACHE);
        if ($cached !== false) { return $cached ?: null; }
        $response = wp_safe_remote_get(self::MANIFEST, array('timeout' => 10, 'redirection' => 0,
            'limit_response_size' => 65536, 'headers' => array('Accept' => 'application/json')));
        $info = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data) && isset($data['version'], $data['download_url'], $data['sha256'])
                && is_string($data['version']) && preg_match('/\A\d+\.\d+\.\d+\z/', $data['version'])
                && $data['download_url'] === self::REPOSITORY . '/raw/main/releases/' . self::SLUG . '-' . $data['version'] . '.zip'
                && is_string($data['sha256']) && preg_match('/\A[0-9a-f]{64}\z/', $data['sha256'])) {
                $info = $data;
            }
        }
        set_site_transient(self::CACHE, $info ?: 0, 6 * HOUR_IN_SECONDS);
        return $info;
    }

    public static function offer_update($transient) {
        if (!is_object($transient)) { return $transient; }
        $file = self::plugin_file();
        $info = self::manifest();
        $item = (object) array('id' => self::REPOSITORY, 'slug' => self::SLUG, 'plugin' => $file,
            'url' => self::REPOSITORY, 'new_version' => AMS_AI_Bridge::VERSION,
            'package' => '', 'requires_php' => '7.4', 'icons' => array(), 'banners' => array());
        if ($info && version_compare($info['version'], AMS_AI_Bridge::VERSION, '>')) {
            $item->new_version = $info['version'];
            $item->package = $info['download_url'];
            $transient->response[$file] = $item;
            unset($transient->no_update[$file]);
        } else {
            $transient->no_update[$file] = $item;
            unset($transient->response[$file]);
        }
        return $transient;
    }

    public static function native_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== self::plugin_file()) { return $update; }
        $info = self::manifest();
        if (!$info) { return $update; }
        return array('id' => self::REPOSITORY, 'slug' => self::SLUG, 'version' => $info['version'],
            'url' => self::REPOSITORY, 'package' => $info['download_url'], 'requires_php' => '7.4');
    }

    public static function refresh_admin_updates() {
        self::clear_cache();
        if (!empty($_GET['force-check']) && current_user_can('update_plugins')) {
            delete_site_transient('update_plugins');
        }
    }

    public static function details($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) { return $result; }
        $info = self::manifest();
        if (!$info) { return $result; }
        return (object) array('name' => 'AMS AI Bridge', 'slug' => self::SLUG,
            'version' => $info['version'], 'author' => 'Awaken Media Solutions', 'homepage' => self::REPOSITORY,
            'requires_php' => '7.4', 'download_link' => $info['download_url'],
            'sections' => array('description' => 'Controlled Elementor working drafts for AMS.',
                'changelog' => wp_kses_post(is_string($info['changelog_html'] ?? null) ? $info['changelog_html'] : '')));
    }

    public static function verify_package($reply, $package, $upgrader, $context) {
        if (($context['plugin'] ?? '') !== self::plugin_file() || $reply !== false) { return $reply; }
        $info = self::manifest();
        if (!$info || $package !== $info['download_url']) {
            return new WP_Error('ams_ai_bridge_package_untrusted', 'The Bridge package does not match its release manifest.');
        }
        $file = download_url($package, 60);
        if (is_wp_error($file)) { return $file; }
        $hash = hash_file('sha256', $file);
        if (!$hash || !hash_equals($info['sha256'], $hash)) {
            wp_delete_file($file);
            return new WP_Error('ams_ai_bridge_bad_checksum', 'The Bridge download failed its SHA-256 check.');
        }
        return $file;
    }
}

add_filter('pre_set_site_transient_update_plugins', array('AMS_AI_Bridge_Updater', 'offer_update'));
add_filter('update_plugins_github.com', array('AMS_AI_Bridge_Updater', 'native_update'), 10, 3);
add_filter('plugins_api', array('AMS_AI_Bridge_Updater', 'details'), 10, 3);
add_filter('upgrader_pre_download', array('AMS_AI_Bridge_Updater', 'verify_package'), 10, 4);
add_action('load-update-core.php', array('AMS_AI_Bridge_Updater', 'refresh_admin_updates'), 1);
add_action('upgrader_process_complete', array('AMS_AI_Bridge_Updater', 'clear_cache'));
