<?php
define('ABSPATH', '/tmp/');
define('HOUR_IN_SECONDS', 3600);
function add_action() {}
function add_filter() {}
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function get_site_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; check($ttl === 21600); }
function delete_site_transient($key) { unset($GLOBALS['cache'][$key]); }
function wp_safe_remote_get($url, $args) { return json_encode($GLOBALS['manifest']); }
function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_body($response) { return $response; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_delete_file($file) { unlink($file); }
function wp_kses_post($html) { return strip_tags($html, '<p>'); }
function download_url($package, $timeout) {
    $file = tempnam(sys_get_temp_dir(), 'bridge');
    file_put_contents($file, $GLOBALS['download']);
    return $file;
}
function check($value) { if (!$value) { throw new RuntimeException('Updater check failed.'); } }
class WP_Error { public function __construct($code, $message) {} }
class AMS_AI_Bridge { const VERSION = '0.1.2'; }
require dirname(__DIR__) . '/updater.php';

$file = 'ams-ai-bridge/ams-ai-bridge.php';
$zip = AMS_AI_Bridge_Updater::REPOSITORY . '/raw/main/releases/ams-ai-bridge-0.1.3.zip';
$download = 'test zip bytes';
$manifest = array('version' => '0.1.3', 'download_url' => $zip, 'sha256' => hash('sha256', $download),
    'changelog_html' => '<p>Patch release</p>');
$other = (object) array('new_version' => '7.8.9');
$state = (object) array('response' => array('other/other.php' => $other));
AMS_AI_Bridge_Updater::offer_update($state);
check($state->response['other/other.php'] === $other);
check($state->response[$file]->new_version === '0.1.3');
check($state->response[$file]->package === $zip);
check(AMS_AI_Bridge_Updater::details(false, 'plugin_information', (object) array('slug' => 'another')) === false);
check(AMS_AI_Bridge_Updater::details(false, 'plugin_information', (object) array('slug' => 'ams-ai-bridge'))->sections['changelog'] === '<p>Patch release</p>');
$verified = AMS_AI_Bridge_Updater::verify_package(false, $zip, null, array('plugin' => $file));
check(is_string($verified) && is_file($verified));
unlink($verified);
$download = 'tampered bytes';
check(is_wp_error(AMS_AI_Bridge_Updater::verify_package(false, $zip, null, array('plugin' => $file))));
$manifest['download_url'] = 'https://github.com/jimster-status/ams-ai-bridge-copy/raw/main/releases/ams-ai-bridge-0.1.3.zip';
AMS_AI_Bridge_Updater::clear_cache();
AMS_AI_Bridge_Updater::offer_update($state);
check(!isset($state->response[$file]) && isset($state->no_update[$file]));
echo "PASS: update notice, details, repository validation, checksum and no-update entry.\n";
