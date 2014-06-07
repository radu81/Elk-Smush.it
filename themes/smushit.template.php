<?php

function template_attachment_smushit()
{
	global $context, $txt, $settings;

	if ($context['completed'])
	{
		echo '
	<div id="manage_attachments">
		<h3 class="category_header">', $txt['smushit_attachments_complete'], '</h3>
		<div class="content">
			<p>', $txt['smushit_attachments_complete_desc'], '</p>
			<table class="table_grid" cellspacing="0" width="100%">
				<thead>
					<tr class="table_header">
						<th class="first_th"></th>
						<th>#</th>
						<th>', $txt['attachment_name'], '</th>
						<th class="last_th">', $txt['smushit_attachments'], '</th>
					</tr>
				</thead>
				<tbody>';

		// Loop through each result reporting the status
		$i = 1;
		$savings = 0;
		$alternate = true;

		if (isset($context['smushit_results']))
		{
			foreach ($context['smushit_results'] as $attach_id => $result)
			{
				$attach_id = str_replace('+', '', $attach_id, $count);
				list($filename, $result) = explode('|', $result, 2);
				echo '
					<tr>
						<td class="', $alternate ? 'windowbg2' : 'windowbg', '"><img src="' . $settings['images_url'] . '/icons/' . (($count != 0) ? 'field_valid' : 'field_invalid') . '.gif' . '"/></td>
						<td class="', $alternate ? 'windowbg2' : 'windowbg', '">' . $i . '</td>
						<td class="', $alternate ? 'windowbg2' : 'windowbg', '">[' . $attach_id . '] ' . $filename . '</td>
						<td class="', $alternate ? 'windowbg2' : 'windowbg', '">' . $result . '</td>
					</tr>';
				$alternate = !$alternate;
				$i++;

				// keep track of how great we are
				if ($count != 0 && preg_match('~.*\((\d*)\).*~', $result, $thissavings))
					$savings += $thissavings[1];
			}
		}

		// Show the total savings in some form the user will understnad
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$savings = max($savings, 0);
		$pow = floor(($savings ? log($savings) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$savings /= pow(1024, $pow);

		echo '
				</tbody>
			</table>
			<br />
			<p><strong>' . $txt['smushit_attachments_savings'] . ' ' . round($savings, 2) . ' ' . $units[$pow] . '</strong></p>
		</div>
	</div>';
	}
}

function template_smushit_maintain_below()
{
	global $txt, $scripturl;

	echo '
	<h3 class="category_header">', $txt['smushit_attachment_check'], '</h3>
	<div class="windowbg">
		<div class="content">
			<form action="', $scripturl, '?action=admin;area=manageattachments;sa=smushit;', $context['session_var'], '=', $context['session_id'], '" method="post" accept-charset="UTF-8">
				<p>', $txt['smushit_attachment_check_desc'], '</p><br />
				', $txt['smushit_attachments_age'], ' <input type="text" name="smushitage" value="25" size="4" class="input_text" /> ', $txt['days_word'], '<br />
				<input type="submit" name="submit" value="', $txt['smushit_attachment_now'], '" class="right_submit" />
			</form>
		</div>
	</div>';
}