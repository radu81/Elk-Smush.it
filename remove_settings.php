<?php

/**
 * @name      Smush.it
 * @copyright Spuds
 * @license   MPL 1.1 http://mozilla.org/MPL/1.1/
 *
 * @version 0.1
 *
 */

// If we have found SSI.php and we are outside of ELK, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('ELK')) // If we are outside ELK and can't find SSI.php, then throw an error
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as Elkarte\'s SSI.php.');

global $modSettings;

$db = database();
$dbtbl = db_table();

// List all mod settingss here to REMOVE
$mod_settings_to_remove = array(
	'smushit_attachments_age',
	'smushit_attachments_png',
	'smushit_attachment_size',
);

// REMOVE entire tables...
$tables = array();

// REMOVE columns from an existing table
$columns = array();
$columns[] = array(
	'table_name' => '{db_prefix}attachments',
	'column_name' => 'smushit',
	'parameters' => array(),
	'error' => 'fatal',
);

// REMOVE rows from an existing table
$db->query('', '
	DELETE FROM {db_prefix}scheduled_tasks
	WHERE task = {string:name}',
	array(
		'name' => 'smushit',
	)
);

if (count($mod_settings_to_remove) > 0) {

	// Remove the mod_settings if applicable, first the session
	foreach ($mod_settings_to_remove as $setting)
		if (isset($modSettings[$setting]))
			unset($modSettings[$setting]);

	// And now the database values
	$db->query('', '
		DELETE FROM {db_prefix}settings
		WHERE variable IN ({array_string:settings})',
		array(
			'settings' => $mod_settings_to_remove,
		)
	);

	// Make sure the cache is reset as well
	updateSettings(array(
		'settings_updated' => time(),
	));
}

foreach ($tables as $table)
  $dbtbl->db_drop_table($table['table_name'], $table['parameters'], $table['error']);

foreach ($columns as $column)
  $dbtbl->db_remove_column($column['table_name'], $column['column_name'], $column['parameters'], $column['error']);

if (ELK == 'SSI')
   echo 'Congratulations! You have successfully removed the integration hooks.';