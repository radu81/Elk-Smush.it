<?php

/**
 * @package "Smush.it" Addon for ElkArte
 * @author Spuds
 * @copyright (c) 2014 Spuds
 * @license Mozilla Public License version 1.1 http://www.mozilla.org/MPL/1.1/.
 *
 * @version 0.1
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Batch processing of attachments from the attachment file maintenance section
 *
 * - runs as a paused loop to prevent overload
 * - can be slow ;)
 */
function smushitAttachments()
{
	global $txt, $context, $modSettings;

	// Going to need these to communicate
	loadLanguage('smushit');
	loadTemplate('smushit');

	// Make sure the session is valid
	checkSession('get');

	// You have to be able to admin the forum to do this.
	isAllowedTo('admin_forum');

	// Batch size -- how many attachments to process per loop
	$chunk_size = 5;

	// On first entry we need to set some parameters
	if (empty($_GET['step']))
	{
		$context['smushit_results'] = array();
		$_GET['step'] = 0;

		// Find out how many images we are going to process
		$images = smushit_getNumFiles(false);
		$_SESSION['smushit_images'] = $images;

		// Save the form post values for future loops
		$_SESSION['smushitage'] = (time() - 24 * 60 * 60 * (int) $_POST['smushitage']);
		$_SESSION['smushitsize'] = (!empty($modSettings['smushit_attachments_size']) ? 1024 * $modSettings['smushit_attachments_size'] : 0);
	}

	// Set up this pass through the loop so we know which data chunk to work on
	$images = (isset($_SESSION['smushit_images'])) ? $_SESSION['smushit_images'] : 0;
	if (isset($_SESSION['smushit_results']))
		$context['smushit_results'] = $_SESSION['smushit_results'];

	// Get the next group of attachments that meet our criteria
	$files = smushit_getFiles((int) $_GET['step'], $chunk_size, '', '', $_SESSION['smushitsize'], $_SESSION['smushitage']);

	// While we have attachments that have not been smushed yet the we .... smush.em
	foreach ($files as $row)
	{
		if (empty($row['smushit']))
			smushitMain($row);
	}

	// Update the pointer and see if we have more to do ....
	$_GET['step'] += $chunk_size;
	if ($_GET['step'] < $images)
		pauseAttachmentSmushit($images);

	// Got here we must be doing well, well as in we did something, first lets clean up
	unset($_GET['step'], $_SESSION['smushit_results'], $_SESSION['smushit_images'], $_SESSION['smushitage'], $_SESSION['smushitsize']);

	// Do a final exit to the sub template to show what we did
	$context['page_title'] = $txt['smushit_attachments'];
	$context[$context['admin_menu_name']]['current_subsection'] = 'maintenance';
	$context['sub_template'] = 'attachment_smushit';
	$context['completed'] = true;
}

/**
 * Sets up for the next loop
 *
 * @param int $max_steps
 */
function pauseAttachmentSmushit($max_steps = 0)
{
	global $context, $txt, $time_start;

	// Try get more time...
	@set_time_limit(600);
	if (function_exists('apache_reset_timeout'))
		@apache_reset_timeout();

	// Have we already used our maximum time, don't want to just run forever.
	if (array_sum(explode(' ', microtime())) - array_sum(explode(' ', $time_start)) > 30)
	{
		$context['smushit_results'][9999999] = '|' . $txt['smushit_attachments_timeout'] . ' ' . array_sum(explode(' ', microtime())) - array_sum(explode(' ', $time_start));
		return;
	}

	// Set the context vars for display via the admin template 'not_done'
	$context['continue_get_data'] = '?action=admin;area=manageattachments;sa=smushit;step=' . $_GET['step'] . ';' . $context['session_var'] . '=' . $context['session_id'];
	$context['page_title'] = $txt['not_done_title'];
	$context['continue_post_data'] = '';
	$context['continue_countdown'] = 3;
	$context['sub_template'] = 'not_done';
	$context[$context['admin_menu_name']]['current_subsection'] = 'maintenance';
	$context['continue_percent'] = round(((int) $_GET['step'] / $max_steps) * 100);
	$context['continue_percent'] = min($context['continue_percent'], 100);

	// Save for the next loop of love
	$_SESSION['smushit_results'] = $context['smushit_results'];

	obExit();
}

/**
 * Show a list of attachment files available for smush.it
 *
 * - called by ?action=admin;area=manageattachments;sa=smushit
 * - uses the 'browse' sub template
 * - allows sorting by name, date, size and smush.it.
 * - paginates results.
 */
function SmushitBrowse()
{
	global $context, $txt, $scripturl, $modSettings;

	loadLanguage('smushit');

	//$context['sub_template'] = 'browse';
	$context['browse_type'] = 'smushit';

	// Set the options for the list.
	$listOptions = array(
		'id' => 'file_list',
		'title' => $txt['smushit_attachment_check'],
		'items_per_page' => $modSettings['defaultMaxMessages'],
		'base_href' => $scripturl . '?action=admin;area=manageattachments;sa=smushitbrowse',
		'default_sort_col' => 'filesize',
		'no_items_label' => $txt['smushit_attachment_empty'],
		'get_items' => array(
			'function' => 'smushit_getFiles',
		),
		'get_count' => array(
			'function' => 'smushit_getNumFiles',
		),
		'columns' => array(
			'name' => array(
				'header' => array(
					'value' => $txt['attachment_name'],
				),
				'data' => array(
					'function' => create_function('$rowData', '
						global $modSettings, $context, $scripturl;

						$link = \'<a href="\';
						$link .= sprintf(\'%1$s?action=dlattach;topic=%2$d.0&id=%3$d\', $scripturl, $rowData[\'id_topic\'], $rowData[\'id_attach\']);
						$link .= \'"\';

						// Show a popup on click if it\'s a picture and we know its dimensions.
						if (!empty($rowData[\'width\']) && !empty($rowData[\'height\']))
							$link .= sprintf(\' onclick="return reqWin(this.href\' .  \' + \\\';image\\\'\' . \', %1$d, %2$d, true);"\', $rowData[\'width\'] + 20, $rowData[\'height\'] + 20);

						$link .= sprintf(\'>%1$s</a>\', preg_replace(\'~&amp;#(\\\\d{1,7}|x[0-9a-fA-F]{1,6});~\', \'&#\\\\1;\', htmlspecialchars($rowData[\'filename\'])));

						// Show the dimensions.
						if (!empty($rowData[\'width\']) && !empty($rowData[\'height\']))
							$link .= sprintf(\' <span class="smalltext">%1$dx%2$d</span>\', $rowData[\'width\'], $rowData[\'height\']);

						return $link;
					'),
				),
				'sort' => array(
					'default' => 'a.filename',
					'reverse' => 'a.filename DESC',
				),
			),
			'filesize' => array(
				'header' => array(
					'value' => $txt['attachment_file_size'],
				),
				'data' => array(
					'function' => create_function('$rowData', '
						global $txt;
						return sprintf(\'%1$s%2$s\', round($rowData[\'size\'] / 1024, 2), $txt[\'kilobyte\']);
					'),
					'class' => 'windowbg',
				),
				'sort' => array(
					'default' => 'a.size DESC',
					'reverse' => 'a.size',
				),
			),
			'smushed' => array(
				'header' => array(
					'value' => $txt['smushited'],
				),
				'data' => array(
					'function' => create_function('$rowData', '
						global $txt;
						return (($rowData[\'smushit\'] == 0) ? $txt[\'no\'] : $txt[\'yes\']);
					'),
					'class' => 'windowbg',
				),
				'sort' => array(
					'default' => 'a.smushit DESC',
					'reverse' => 'a.smushit',
				),
			),
			'post' => array(
				'header' => array(
					'value' => $txt['subject'],
				),
				'data' => array(
					'function' => create_function('$rowData', '
						global $txt, $scripturl;
						return sprintf(\'%1$s <a href="%2$s?topic=%3$d.0.msg%4$d#msg%4$d">%5$s</a>\', $txt[\'in\'], $scripturl, $rowData[\'id_topic\'], $rowData[\'id_msg\'], $rowData[\'subject\']);
					'),
				),
				'sort' => array(
					'default' => 'm.subject',
					'reverse' => 'm.subject DESC',
				),
			),
			'date' => array(
				'header' => array(
					'value' => $txt['date'],
				),
				'data' => array(
					'function' => create_function('$rowData', '
						global $txt, $context, $scripturl;

						// The date the message containing the attachment was posted
						$date = empty($rowData[\'poster_time\']) ? $txt[\'never\'] : standardTime($rowData[\'poster_time\']);
						return $date;
						'),
					'class' => 'windowbg',
				),
				'sort' => array(
					'default' => 'm.poster_time',
					'reverse' => 'm.poster_time DESC',
				),
			),
			'check' => array(
				'header' => array(
					'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" class="input_check" />',
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<input type="checkbox" name="smushit[%1$d]" class="input_check" />',
						'params' => array(
							'id_attach' => false,
						),
					),
				),
			),
		),
		'form' => array(
			'href' => $scripturl . '?action=admin;area=manageattachments;sa=smushitselect',
			'include_sort' => true,
			'include_start' => true,
		),
		'additional_rows' => array(
			array(
				'position' => 'below_table_data',
				'value' => '<input type="submit" name="smushit_submit" class="button_submit" value="' . $txt['smushit_attachment_now'] . '" />',
				'style' => 'text-align: right;',
			),
			array(
				'position' => 'after_title',
				'value' => isset($_SESSION['truth_or_consequence']) ? $_SESSION['truth_or_consequence'] : $txt['smushit_attachment_check_desc'],
				'class' => 'windowbg2',
			),
		),
		'list_menu' => array(
			'show_on' => 'top',
			'links' => array(
				array(
					'href' => $scripturl . '?action=admin;area=manageattachments;sa=browse',
					'is_selected' => $context['browse_type'] === 'attachments',
					'label' => $txt['attachment_manager_attachments']
				),
				array(
					'href' => $scripturl . '?action=admin;area=manageattachments;sa=browse;avatars',
					'is_selected' => $context['browse_type'] === 'avatars',
					'label' => $txt['attachment_manager_avatars']
				),
				array(
					'href' => $scripturl . '?action=admin;area=manageattachments;sa=browse;thumbs',
					'is_selected' => $context['browse_type'] === 'thumbs',
					'label' => $txt['attachment_manager_thumbs']
				),
				array(
					'href' => $scripturl . '?action=admin;area=manageattachments;sa=smushitbrowse',
					'is_selected' => $context['browse_type'] === 'smushit',
					'label' => $txt['smushit']
				),
			),
		),
	);

	// Clear errors
	if (isset($_SESSION['truth_or_consequence']))
		unset($_SESSION['truth_or_consequence']);

	// Create the list.
	require_once(SUBSDIR . '/GenericList.class.php');
	createList($listOptions);
}

/**
 * Retrieves the attachment information for the selected range
 *
 * - Called from browse or batch
 *
 * @param int $start
 * @param int $chunk_size
 * @param string $sort
 * @param string $type
 * @param int $size
 * @param string $age
 */
function smushit_getFiles($start, $chunk_size, $sort = '', $type = '', $size = 0, $age = '')
{
	global $modSettings;

	$db = database();

	// Init
	if ($sort == '')
		$sort = 'a.id_attach DESC';

	if ($size === 0 && !empty($modSettings['smushit_attachment_size']))
		$size = 1024 * $modSettings['smushit_attachment_size'];

	// Make the query, smushit cant be larger than 1M :(
	$request = $db->query('', '
		SELECT
			a.id_folder, a.filename, a.file_hash, a.attachment_type, a.id_attach,
			a.id_member, a.width, a.height, a.smushit, a.fileext, a.size, a.downloads,
			m.id_msg, m.id_topic, m.subject, m.id_msg, m.poster_time
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
		WHERE a.attachment_type = {int:attach}
			AND a.size BETWEEN {int:attach_size} AND 1024000
			AND (a.fileext = \'jpg\' OR a.fileext = \'png\' OR a.fileext = \'gif\')' .
			(($age != '') ? 'AND m.poster_time > {int:poster_time} ' : '') .
			(($type != '') ? 'AND a.smushit = {int:smushit}' : '') . '
		ORDER BY {raw:sort}
		' . ((!empty($chunk_size)) ? 'LIMIT {int:offset}, {int:limit} ' : ''),
		array(
			'offset' => $start,
			'limit' => $chunk_size,
			'attach' => 0,
			'attach_size' => $size,
			'poster_time' => $age,
			'sort' => $sort,
			'smushit' => 0,
		)
	);
	// Put the results in an array
	$files = array();
	while ($row = $db->fetch_assoc($request))
		$files[] = $row;
	$db->free_result($request);

	return $files;
}

/**
 * Determines how many files meet our smush.it criteria
 *
 * - Uses age, size, type as parameters in determining the list
 *
 * @param boolean $not_smushed
 */
function smushit_getNumFiles($not_smushed = false)
{
	global $modSettings;

	$db = database();

	// Get the image attachment count that meets the criteria
	$request = $db->query('', '
		SELECT COUNT(a.id_attach)
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
		WHERE a.attachment_type = {int:attach}
			AND a.size BETWEEN {int:attach_size} AND 1024000
			AND m.poster_time > {int:poster_time}
			AND (a.fileext = \'jpg\' OR a.fileext = \'png\' OR a.fileext = \'gif\')' .
			(($not_smushed) ? 'AND a.smushit = {int:smushit}' : ''),
		array(
			'attach' => 0,
			'smushit' => 0,
			'attach_size' => !empty($modSettings['smushit_attachment_size']) ? 1024 * $modSettings['smushit_attachment_size'] : 0,
			'poster_time' => isset($_POST['smushitage']) ? (time() - 24 * 60 * 60 * (int) $_POST['smushitage']) : 0,
		)
	);
	// Survey says we have this much work to do
	list ($num_files) = $db->fetch_row($request);
	$db->free_result($request);

	return $num_files;
}

/**
 * Main smushit controller
 *
 * - Runs smush.it on the supplied attachments
 * - Uses values set in the admin panel, size/age/format
 * - Copy's attachment to the forum base for processing
 * - Calls smush.it on the file, if successful then copy's file back to attachments
 * - updates database with any changes
 *
 * @param mixed[] $file
 */
function smushitMain($file)
{
	global $boardurl, $context, $txt, $modSettings;

	$db = database();

	// Some needed functions
	require_once (SUBSDIR . '/Package.subs.php');
	require_once (SUBSDIR . '/Graphics.subs.php');

	// Get the actual attachment file location
	$filename_withpath = getAttachmentFilename($file['filename'], $file['id_attach'], $file['id_folder'], false, $file['file_hash']);

	// We need to copy to the smushit dir so:
	// 	  a. smush.it can get at it i.e. its in a public location
	// 	  b. smush.it must have full file names with an appropriate extension or it will not run
	$filename = basename($filename_withpath) . '.smushit.' . $file['fileext'];
	$filename_to = BOARDDIR . '/smushit/' . $filename;

	// Lets try to CHMOD the smush.it dir if needed.
	if (!is_writable(BOARDDIR . '/smushit'))
		@chmod(BOARDDIR . '/smushit', 0755);

	// Make a copy of the attachment to process
	if (@copy($filename_withpath, $filename_to))
	{
		// Build a URL to our "new" public file ... and send it to smush.it
		$fileurl = $boardurl . '/smushit/' . $filename;
		$address = 'http://www.smushit.com/ysmush.it/ws.php?img=' . urlencode($fileurl);
		$data = fetch_web_data($address);

		// Success on the web fetch and the data returned is a JSON response?
		if ($data !== false && (strpos(trim($data), '{') === 0))
		{
			// Parse the JSON response
			$data = json_decode($data);

			// We have both a size savings and a nice new image to grab?  if so then we continue on like lemmings.
			if (!isset($data->error) && intval($data->dest_size) != -1 && isset($data->dest))
			{
				// Need to make sure the returned URL is fully qualified so fetch web can get it
				$smushit_url = urldecode(stripcslashes($data->dest));
				if (strpos($smushit_url, 'http://') != 0)
					$smushit_url = 'http://www.smushit.com/' . $smushit_url;

				// See what kind of image file we got back and if we are allowed to change it if needed.
				$smushit_ext = strtolower(substr($smushit_url, strrpos($smushit_url, '.') + 1));
				if ((strtolower($file['fileext']) == 'gif' && $smushit_ext == 'png' && isset($modSettings['smushit_attachments_png'])) || (strtolower($file['fileext']) == $smushit_ext))
				{
					// Things are cool ... download the smushed image file, overwriting the public one we created
					$smushit_file = fopen($filename_to, 'wb');
					if ($smushit_file)
					{
						$fileContents = fetch_web_data($smushit_url);
						fwrite($smushit_file, $fileContents);
						fclose($smushit_file);
					}

					// Trust but verify ... ok really don't trust at all ... just verify that the returned file is good
					//  a) an image
					//  b) the same WxH dimensional size
					//  c) free of any hitchhikers
					$sizes = @getimagesize($filename_to);
					if ($sizes !== false && $sizes[0] == $file['width'] && $sizes[1] == $file['height'] && checkImageContents($filename_to))
					{
						// Can we can copy over the original file
						if (!is_writable($filename_withpath))
						{
							$orig_perm = @fileperms($filename_withpath);
							@chmod($filename_withpath, 0664);
							clearstatcache();
						}

						// No turning back now .. onward men !!
						if (@copy($filename_to, $filename_withpath))
						{
							// In the slim chance he perm changed worked, try to set it back to what it was
							if (isset($orig_perm))
							{
								@chmod($filename_withpath, $orig_perm);
								unset($orig_perm);
							}

							$savings = intval($data->src_size) - intval($data->dest_size);
							$context['smushit_results']['+' . $file['id_attach']] = $file['filename'] . '|' . sprintf($txt['smushit_attachments_reduction'] . " %01.1f%% (%s) bytes", $data->percent, $savings);

							// Update the attachment database with the new file size and potentially new type / mime (gif-png)
							$db->query('', '
								UPDATE {db_prefix}attachments
								SET size = {int:size},
									fileext = {string:ext},
									mime_type = {string:mime},
									smushit = {int:smushit}
								WHERE id_attach = {int:id_attach}
								LIMIT 1',
								array(
									'size' => $data->dest_size,
									'ext' => $smushit_ext,
									'mime' => (isset($sizes['mime']) ? $sizes['mime'] : 'image/' . $smushit_ext),
									'id_attach' => $file['id_attach'],
									'smushit' => 1,
								)
							);
						}
						// Image failed to copy back to the attach directory
						else
							$context['smushit_results'][$file['id_attach']] = $file['filename'] . '|' . $txt['smushit_attachments_copyfail'];
					}
					// Image failed validation, skipping
					else
						$context['smushit_results'][$file['id_attach']] = $file['filename'] . $file['width'] . $file['height'] . '|' . $txt['smushit_attachments_verify'];
				}
				// Not allowed to change the file format so skip it
				else
					$context['smushit_results'][$file['id_attach']] = $file['filename'] . '|' . $txt['smushit_attachments_noformatchange'] . $smushit_url;
			}
			else
			{
				// No savings in size possible, mark it as smushed then
				if (isset($data->dest_size) && $data->dest_size == -1)
				{
					$db->query('', '
						UPDATE {db_prefix}attachments
						SET smushit = {int:smushit}
						WHERE id_attach = {int:id_attach}
						LIMIT 1',
						array(
							'id_attach' => $file['id_attach'],
							'smushit' => 1
						)
					);
				}

				// Just a general smush.it error or no savings message
				$context['smushit_results'][$file['id_attach']] = $file['filename'] . '|' . ((isset($data->dest_size) && $data->dest_size == -1) ? $txt['smushit_attachments_nosavings'] : $txt['smushit_attachments_error'] . ' ' . $data->error);
			}
		}
		// Error on the web_fetch_data or a non JSON result ...
		else
			$context['smushit_results'][$file['id_attach']] = $file['filename'] . '|' . $txt['smushit_attachments_network'];

		// Done with this one, make sure we clean up after ourselves
		@unlink($filename_to);
	}
	// Could not copy the file to boarddir ... permissions, missing file, other?
	else
		$context['smushit_results'][$file['id_attach']] = $file['filename'] . '|' . $txt['smushit_attachments_copyerror'];
}

/**
 * Called from the browse selection list
 *
 * - Runs smush.it on the selected files
 */
function SmushitSelect()
{
	global $context, $settings, $txt;

	$db = database();

	// Check the session
	checkSession('post');

	if (!empty($_POST['smushit']))
	{
		$attachments = array();
		loadLanguage('smushit');

		// All the attachments that have been selected to smush.it
		foreach ($_POST['smushit'] as $smushID => $dummy)
			$attachments[] = (int) $smushID;

		// While we have attachments to work on
		if (!empty($attachments))
		{
			// Make the query
			$request = $db->query('', '
				SELECT
					a.id_folder, a.filename, a.file_hash, a.attachment_type, a.id_attach, a.id_member, a.width, a.height,
					m.id_msg, m.id_topic, a.fileext, a.size, a.downloads, m.subject, m.id_msg, m.poster_time
				FROM {db_prefix}attachments AS a
					INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
				WHERE a.id_attach IN ({array_int:attachments})',
				array(
					'attachments' => $attachments,
				)
			);
			// Put the results in an array
			$files = array();
			while ($row = $db->fetch_assoc($request))
				$files[] = $row;
			$db->free_result($request);

			// Do the smush.it oh baby.
			foreach ($files as $row)
			{
				smushitMain($row);

				// Try get more time...
				@set_time_limit(60);
				if (function_exists('apache_reset_timeout'))
					@apache_reset_timeout();
			}

			// Errors or savings?
			if (isset($context['smushit_results']))
			{
				$truth_or_consequence = '';
				$savings = 0;

				// Build the string of painful errors or blissful savings
				foreach ($context['smushit_results'] as $attach_id => $result)
				{
					$attach_id = str_replace('+', '', $attach_id, $count);
					list($filename, $result) = explode('|', $result, 2);

					// Build the string, we only have a textbox to show our results
					if ($count != 0)
					{
						// Keep track of the size savings
						if (preg_match('~.*\((\d*)\).*~', $result, $thissavings))
							$savings += $thissavings[1];
						$truth_or_consequence .= '<img src="' . $settings['images_url'] . '/icons/field_valid.png"/> ' . $filename . ': ' . $result;
					}
					else
						$truth_or_consequence .= '<img src="' . $settings['images_url'] . '/icons/field_invalid.png"/> ' . $filename . ': ' . $result;

					$truth_or_consequence .= '<br />';
				}

				// Show the total savings in a usable format
				if ($savings != 0)
				{
					$units = array('B', 'KB', 'MB', 'GB', 'TB');
					$savings = max($savings, 0);
					$pow = floor(($savings ? log($savings) : 0) / log(1024));
					$pow = min($pow, count($units) - 1);
					$savings /= pow(1024, $pow);
					$truth_or_consequence .= '<strong>' . $txt['smushit_attachments_savings'] . ' ' . round($savings, 2) . ' ' . $units[$pow] . '</strong>';
				}

				// Save it in session
				$_SESSION['truth_or_consequence'] = $truth_or_consequence;
			}
		}
	}

	// Done, back to the browse list we go
	$_REQUEST['sort'] = isset($_REQUEST['sort']) ? (string) $_REQUEST['sort'] : 'filesize';
	if (isset($_REQUEST["desc"]))
		$_REQUEST['sort'] .= ';desc';

	redirectexit('action=admin;area=manageattachments;sa=smushitbrowse;sort=' . $_REQUEST['sort'] . ';start=' . $_REQUEST['start']);
}

/**
 * Attachment Hook, integrate_sa_manage_attachments, called from AddonSettings.controller.php
 *
 * - Used to add subactions to the addon area
 *
 * @param mixed[] $sub_actions
 */
function iama_smushit(&$sub_actions)
{
	$sub_actions += array(
		'smushit' => array(
			'dir' => SUBSDIR,
			'file' => 'smushit.subs.php',
			'function' => 'smushitAttachments',
			'permission' => 'admin_forum',
		),
		'smushitbrowse' => array(
			'dir' => SUBSDIR,
			'file' => 'smushit.subs.php',
			'function' => 'SmushitBrowse',
			'permission' => 'admin_forum',
		),
		'smushitselect' => array(
			'dir' => SUBSDIR,
			'file' => 'smushit.subs.php',
			'function' => 'SmushitSelect',
			'permission' => 'admin_forum',
		)
	);

	// If we are on the attachment maintenance page, add in our helper
	if (isset($_REQUEST['sa']) && $_REQUEST['sa'] === 'maintenance')
	{
		loadLanguage('smushit');
		loadTemplate('smushit');
		Template_Layers::getInstance()->addBegin('smushit_maintain');
	}
}

/**
 * Integration hook, integrate_sa_manage_maintenance, called from Maintenance.controller
 *
 * - Here we use it to add in a template layer for display
 */
function ismm_smushit()
{
	// If we are on the attachment maintenance page, add in our helper
	if (isset($_REQUEST['sa']) && $_REQUEST['sa'] === 'attachments')
	{
		loadLanguage('smushit');
		loadTemplate('smushit');
		Template_Layers::getInstance()->addBegin('smushit_maintain');
	}
}

/**
 * Integration hook, integrate_general_mod_settings
 *
 * - Not a lot of settings for old smushit so we add them under the predefined
 * Miscellaneous
 *
 * @param mixed[] $config_vars
 */
function igm_smushit(&$config_vars)
{
	loadLanguage('smushit');

	$config_vars += array(
		array('check', 'smushit_attachments_png'),
		array('int', 'smushit_attachment_size'),
		'',
	);
}

/**
 * Called from the scheduled task area, runs smushit on a reoccurring basis
 */
function scheduled_smushit()
{
	global $modSettings;

	require_once(SOURCEDIR . '/smushit.subs.php');

	// Need to do this so we have some basic $txt available.
	loadEssentialThemeData();
	loadLanguage('Admin');
	loadLanguage('smushit');

	// Get the large files
	$size = (!empty($modSettings['smushit_attachments_size']) ? 1024 * $modSettings['smushit_attachments_size'] : 0);

	// Use a bit a buffer to look back a couple of days, smush.it can be down from time to time
	$age = time() - (72 * 60 * 60);

	// Load the attachment files that  match
	$files = smushit_getFiles(0, 0, '', 'unsmushed', $size, $age);

	// While we have attachments .... smush.em
	foreach ($files as $row)
	{
		smushitMain($row);

		// Try get more time...
		@set_time_limit(60);
		if (function_exists('apache_reset_timeout'))
			@apache_reset_timeout();
	}

	return true;
}

/**
 * Integration hook, Called from attachment controller, integrate_list_attach_browse,
 *
 * - Adds one more browser type to the mix (actually called form createlist)
 *
 * @param mixed[] $listOptions
 */
function ilab_smushit(&$listOptions)
{
	global $txt, $scripturl, $context;

	loadLanguage('smushit');

	$listOptions['list_menu']['links'][] = array(
		'href' => $scripturl . '?action=admin;area=manageattachments;sa=smushitbrowse',
		'is_selected' => $context['browse_type'] === 'smushit',
		'label' => $txt['smushit']
	);
}

/**
 * Integration hook, integrate_list_scheduled_tasks, called from ManageScheduledTasks.controller,
 * (actually called form createlist)
 *
 * @param mixed[] $listOptions
 */
function ilst_smushit(&$listOptions)
{
	// Just need our language strings for the listing
	loadLanguage('smushit');
}