<?php

// include environment
require_once 'program/include/iniset.php';
require_once 'commonFunctions.php';

//function readMail($username,$password)//'testing@mindzen.com','@123')
//{
	$username='testing@mindzen.com';
	$password='@123';
// trigger startup plugin hook
//$startup = $RCMAIL->plugins->exec_hook('startup', array('task' => $RCMAIL->task, 'action' => $RCMAIL->action));


$RCMAIL = rcmail::get_instance($GLOBALS['env']);

// Make the whole PHP output non-cacheable (#1487797)
$RCMAIL->output->nocacheing_headers();
$RCMAIL->output->common_headers();

// turn on output buffering
ob_start();

$RCMAIL->set_task('mail');
$RCMAIL->action = 'unread';
	/*try login*/
//$request_valid = $_SESSION['temp'] && $RCMAIL->check_request();

$auth = $RCMAIL->plugins->exec_hook('authenticate', array(
        'host' => $RCMAIL->autoselect_host(),
        'user' => trim($username),//trim(rcube_utils::get_input_value('_user', rcube_utils::INPUT_POST)),
        'pass' => trim($password),//rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST, true,
            //$RCMAIL->config->get('password_charset', 'ISO-8859-1')),
        'cookiecheck' => true,
        'valid'       => true,
    ));

    if ($auth['valid'] && !$auth['abort']
        && $RCMAIL->login($auth['user'], $auth['pass'], $auth['host'], $auth['cookiecheck'])) 
		{
		
			
			$a_folders = $RCMAIL->storage->list_folders_subscribed('', '*', 'mail');
			
			
			if (!empty($a_folders)) {
			$current   = $RCMAIL->storage->get_folder();
			$inbox     = ($current == 'INBOX');
			$trash     = $RCMAIL->config->get('trash_mbox');
			$check_all = true; //(bool)$RCMAIL->config->get('check_all_folders');
			
				
				foreach ($a_folders as $mbox) 
				{
					
					
					$unseen_old = $_SESSION['unseen_count'][$mbox];//rcmail_get_unseen_count($mbox);
					
					if (!$check_all && $unseen_old !== null && $mbox != $current) {
						$unseen = $unseen_old;
					}
					else {
						
						$unseen = $RCMAIL->storage->count($mbox, 'UNSEEN', $unseen_old === null);
						
					}
					
					// call it always for current folder, so it can update counter
					// after possible message status change when opening a message
					// not in preview frame
					if ($unseen || $unseen_old === null || $mbox == $current) {
						$OUTPUT->command('set_unread_count', $mbox, $unseen, $inbox && $mbox_row == 'INBOX');
					}

					$_SESSION['unseen_count'][$mbox_name] = $unseen;//rcmail_set_unseen_count($mbox, $unseen);
					
					
					// set trash folder state
					if ($mbox === $trash) {
						$OUTPUT->command('set_trash_count', $RCMAIL->storage->count($mbox, 'EXISTS'));
						}
				}
				
			}
			
			$mbox_name = $RCMAIL->storage->get_folder();
			$threading = (bool) $RCMAIL->storage->get_threading();

			// Synchronize mailbox cache, handle flag changes
			$RCMAIL->storage->folder_sync($mbox_name);//NULL
			//var_dump($RCMAIL->storage->folder_sync($mbox_name));
			$_REQUEST['_refresh']=1;
			
			// fetch message headers
			if ($count = $RCMAIL->storage->count($mbox_name, $threading ? 'THREADS' : 'ALL', !empty($_REQUEST['_refresh']))) {
				$a_headers = $RCMAIL->storage->list_messages($mbox_name, NULL, "", $RCMAIL->config->get('message_sort_order'));
			}
			
			// update mailboxlist
			rcmail_send_unread_count($mbox_name, !empty($_REQUEST['_refresh']), empty($a_headers) ? 0 : null);
			
			// add message rows
			$mailHeader=rcmail_js_message_list($a_headers, false, $cols);
			
			if (isset($a_headers) && count($a_headers)) {
				if ($search_request) {
					$OUTPUT->show_message('searchsuccessful', 'confirmation', array('nr' => $count));
				}

				// remember last HIGHESTMODSEQ value (if supported)
				// we need it for flag updates in check-recent
				$data = $RCMAIL->storage->folder_data($mbox_name);
				if (!empty($data['HIGHESTMODSEQ'])) {
					$_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
				}
			}
			else {
				// handle IMAP errors (e.g. #1486905)
				if ($err_code = $RCMAIL->storage->get_error_code()) {
					$RCMAIL->display_server_error();
				}
				else if ($search_request) {
					$OUTPUT->show_message('searchnomatch', 'notice');
				}
				else {
					$OUTPUT->show_message('nomessagesfound', 'notice');
				}
			}

			// set trash folder state
			if ($mbox_name === $RCMAIL->config->get('trash_mbox')) {
				$OUTPUT->command('set_trash_count', $exists);
			}

			if ($page == 1) {
				$OUTPUT->command('set_quota', $RCMAIL->quota_content(null, $multifolder ? 'INBOX' : $mbox_name));
			}

	}
		var_dump($mailHeader);
//}

//echo readMail('testing@mindzen.com','@123');	
?>
