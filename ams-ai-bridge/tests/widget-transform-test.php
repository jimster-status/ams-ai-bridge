<?php
define('ABSPATH', '/tmp/');
// Isolated algorithm tests. No live site interaction.
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
require dirname(__DIR__) . '/ams-ai-bridge.php';
$reflect = new ReflectionClass('AMS_AI_Bridge');
$collect = $reflect->getMethod('collect_widgets');
$collect->setAccessible(true);
$change = $reflect->getMethod('change_widget');
$change->setAccessible(true);
$hash = $reflect->getMethod('hash');
$hash->setAccessible(true);
$data = array(array('id'=>'container123','elType'=>'container','elements'=>array(
    array('id'=>'heading123','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'Old Title','size'=>'xl')),
    array('id'=>'button123','elType'=>'widget','widgetType'=>'button','settings'=>array('text'=>'Submit')),
    array('id'=>'html123','elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>'<script>danger</script>')),
)));
$widgets = $collect->invoke(null, $data);
if (count($widgets) !== 2) { throw new Exception('Editable widget list mismatch'); }
$before = $hash->invoke(null, $data);
$matches = 0;
$args = array(&$data, 'heading123', 'title', 'New Title', &$matches);
$change->invokeArgs(null, $args);
if ($matches !== 1 || $data[0]['elements'][0]['settings']['title'] !== 'New Title') { throw new Exception('Heading edit failed'); }
if ($data[0]['elements'][0]['settings']['size'] !== 'xl') { throw new Exception('Non-text setting unexpectedly changed'); }
if ($hash->invoke(null, $data) === $before) { throw new Exception('Hash did not change'); }
$matches = 0;
$args = array(&$data, 'html123', 'html', 'bad', &$matches);
$change->invokeArgs(null, $args);
if ($matches !== 0) { throw new Exception('Unsupported widget unexpectedly changed'); }
echo "PASS: editable widget filtering, single-field mutation, unchanged style, hash, unsupported widget block\n";
