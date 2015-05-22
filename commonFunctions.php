<?php
/* COmmon functions used */
function rcmail_send_unread_count($mbox_name, $force=false, $count=null, $mark='')
{
    global $RCMAIL;

    $old_unseen = rcmail_get_unseen_count($mbox_name);

    if ($count === null)
        $unseen = $RCMAIL->storage->count($mbox_name, 'UNSEEN', $force);
    else
        $unseen = $count;

    if ($unseen != $old_unseen || ($mbox_name == 'INBOX'))
        $RCMAIL->output->command('set_unread_count', $mbox_name, $unseen,
            ($mbox_name == 'INBOX'), $unseen && $mark ? $mark : '');

    rcmail_set_unseen_count($mbox_name, $unseen);

    return $unseen;
}

function rcmail_set_unseen_count($mbox_name, $count)
{
    // @TODO: this data is doubled (session and cache tables) if caching is enabled

    // Make sure we have an array here (#1487066)
    if (!is_array($_SESSION['unseen_count'])) {
        $_SESSION['unseen_count'] = array();
    }

    $_SESSION['unseen_count'][$mbox_name] = $count;
}

/**
 * Returns 'to' if current folder is configured Sent or Drafts
 * or their subfolders, otherwise returns 'from'.
 *
 * @return string Column name
 */
function rcmail_message_list_smart_column_name()
{
    global $RCMAIL;

    $delim       = $RCMAIL->storage->get_hierarchy_delimiter();
    $mbox        = $RCMAIL->output->get_env('mailbox') ?: $RCMAIL->storage->get_folder();
    $sent_mbox   = $RCMAIL->config->get('sent_mbox');
    $drafts_mbox = $RCMAIL->config->get('drafts_mbox');

    if ((strpos($mbox.$delim, $sent_mbox.$delim) === 0 || strpos($mbox.$delim, $drafts_mbox.$delim) === 0)
        && strtoupper($mbox) != 'INBOX'
    ) {
        return 'to';
    }

    return 'from';
}

function rcmail_get_unseen_count($mbox_name)
{
    if (is_array($_SESSION['unseen_count']) && array_key_exists($mbox_name, $_SESSION['unseen_count'])) {
        return $_SESSION['unseen_count'][$mbox_name];
    }
}
/**
 * return javascript commands to add rows to the message list
 */
function rcmail_js_message_list($a_headers, $insert_top=false, $a_show_cols=null)
{
    global $RCMAIL, $OUTPUT;

    if (empty($a_show_cols)) {
        if (!empty($_SESSION['list_attrib']['columns']))
            $a_show_cols = $_SESSION['list_attrib']['columns'];
        else {
            $list_cols   = $RCMAIL->config->get('list_cols');
            $a_show_cols = !empty($list_cols) && is_array($list_cols) ? $list_cols : array('subject');
        }
    }
    else {
        if (!is_array($a_show_cols)) {
            $a_show_cols = preg_split('/[\s,;]+/', str_replace(array("'", '"'), '', $a_show_cols));
        }
        $head_replace = true;
    }

    $delimiter   = $RCMAIL->storage->get_hierarchy_delimiter();
    $search_set  = $RCMAIL->storage->get_search_set();
    $multifolder = $search_set && $search_set[1]->multi;

    // add/remove 'folder' column to the list on multi-folder searches
    if ($multifolder && !in_array('folder', $a_show_cols)) {
        $a_show_cols[] = 'folder';
        $head_replace = true;
    }
    else if (!$multifolder && ($found = array_search('folder', $a_show_cols)) !== false) {
        unset($a_show_cols[$found]);
        $head_replace = true;
    }

    $mbox = $RCMAIL->output->get_env('mailbox') ?: $RCMAIL->storage->get_folder();
    // make sure 'threads' and 'subject' columns are present
    if (!in_array('subject', $a_show_cols))
        array_unshift($a_show_cols, 'subject');
    if (!in_array('threads', $a_show_cols))
        array_unshift($a_show_cols, 'threads');

    // Make sure there are no duplicated columns (#1486999)
    $a_show_cols = array_unique($a_show_cols);
    $_SESSION['list_attrib']['columns'] = $a_show_cols;

    // Plugins may set header's list_cols/list_flags and other rcube_message_header variables
    // and list columns
    $plugin = $RCMAIL->plugins->exec_hook('messages_list',
        array('messages' => $a_headers, 'cols' => $a_show_cols));
	

    $a_show_cols = $plugin['cols'];
    $a_headers   = $plugin['messages'];

    $thead = $head_replace ? rcmail_message_list_head($_SESSION['list_attrib'], $a_show_cols) : NULL;

    // get name of smart From/To column in folder context
    if (array_search('fromto', $a_show_cols) !== false) {
        $smart_col = rcmail_message_list_smart_column_name();
    }
	
    $OUTPUT->command('set_message_coltypes', $a_show_cols, $thead, $smart_col);

    if ($multifolder && $_SESSION['search_scope'] == 'all') {
        $OUTPUT->command('select_folder', '');
    }

    $OUTPUT->set_env('multifolder_listing', $multifolder);

    if (empty($a_headers)) {
        return;
    }

    // remove 'threads', 'attachment', 'flag', 'status' columns, we don't need them here
    foreach (array('threads', 'attachment', 'flag', 'status', 'priority') as $col) {
        if (($key = array_search($col, $a_show_cols)) !== FALSE) {
            unset($a_show_cols[$key]);
        }
    }

    // loop through message headers
    foreach ($a_headers as $header) {
        if (empty($header))
            continue;

        // make message UIDs unique by appending the folder name
        if ($multifolder) {
            $header->uid .= '-'.$header->folder;
            $header->flags['skip_mbox_check'] = true;
            if ($header->parent_uid)
                $header->parent_uid .= '-'.$header->folder;
        }

        $a_msg_cols  = array();
        $a_msg_flags = array();

        // format each col; similar as in rcmail_message_list()
        foreach ($a_show_cols as $col) {
            $col_name = $col == 'fromto' ? $smart_col : $col;

            if (in_array($col_name, array('from', 'to', 'cc', 'replyto')))
				$cont = rcmail_address_string($header->$col_name, 3, false, null, $header->charset);
            else if ($col == 'subject') {
                $cont = trim(rcube_mime::decode_header($header->$col, $header->charset));
                if (!$cont) $cont = $RCMAIL->gettext('nosubject');
                $cont = rcube::Q($cont);
            }
            else if ($col == 'size')
                $cont = show_bytes($header->$col);
            else if ($col == 'date')
                $cont = $RCMAIL->format_date($header->date);
            else if ($col == 'folder') {
                if ($last_folder !== $header->folder) {
                    $last_folder      = $header->folder;
                    $last_folder_name = rcube_charset::convert($last_folder, 'UTF7-IMAP');
                    $last_folder_name = $RCMAIL->localize_foldername($last_folder_name, true);
                    $last_folder_name = str_replace($delimiter, " \xC2\xBB ", $last_folder_name);
                }

                $cont = rcube::Q($last_folder_name);
            }
            else
                $cont = rcube::Q($header->$col);

            $a_msg_cols[$col] = $cont;
        }

        $a_msg_flags = array_change_key_case(array_map('intval', (array) $header->flags));
        if ($header->depth)
            $a_msg_flags['depth'] = $header->depth;
        else if ($header->has_children)
            $roots[] = $header->uid;
        if ($header->parent_uid)
            $a_msg_flags['parent_uid'] = $header->parent_uid;
        if ($header->has_children)
            $a_msg_flags['has_children'] = $header->has_children;
        if ($header->unread_children)
            $a_msg_flags['unread_children'] = $header->unread_children;
        if ($header->others['list-post'])
            $a_msg_flags['ml'] = 1;
        if ($header->priority)
            $a_msg_flags['prio'] = (int) $header->priority;

        $a_msg_flags['ctype'] = rcube::Q($header->ctype);
        $a_msg_flags['mbox']  = $header->folder;
		
		//Custom Variable wrote to extract mail headers to return to page
		$mHeader[]=json_encode($header);
        
		// merge with plugin result (Deprecated, use $header->flags)
        if (!empty($header->list_flags) && is_array($header->list_flags))
            $a_msg_flags = array_merge($a_msg_flags, $header->list_flags);
        if (!empty($header->list_cols) && is_array($header->list_cols))
            $a_msg_cols = array_merge($a_msg_cols, $header->list_cols);
		
       $OUTPUT->command('add_message_row',
            $header->uid,
            $a_msg_cols,
            $a_msg_flags,
            $insert_top);
    }
	//var_dump($mHeader);
    if ($RCMAIL->storage->get_threading()) {
        $OUTPUT->command('init_threads', (array) $roots, $mbox);
    }
	
	return $mHeader;
}

/**
 * decode address string and re-format it as HTML links
 */
function rcmail_address_string($input, $max=null, $linked=false, $addicon=null, $default_charset=null, $title=null)
{
    global $RCMAIL, $PRINT_MODE;

    $a_parts = rcube_mime::decode_address_list($input, null, true, $default_charset);

    if (!sizeof($a_parts)) {
        return $input;
    }

    $c   = count($a_parts);
    $j   = 0;
    $out = '';
    $allvalues  = array();
    $show_email = $RCMAIL->config->get('message_show_email');

    if ($addicon && !isset($_SESSION['writeable_abook'])) {
        $_SESSION['writeable_abook'] = $RCMAIL->get_address_sources(true) ? true : false;
    }

    foreach ($a_parts as $part) {
        $j++;

        $name   = $part['name'];
        $mailto = $part['mailto'];
        $string = $part['string'];
        $valid  = rcube_utils::check_email($mailto, false);

        // phishing email prevention (#1488981), e.g. "valid@email.addr <phishing@email.addr>"
        if (!$show_email && $valid && $name && $name != $mailto && strpos($name, '@')) {
            $name = '';
        }

        // IDNA ASCII to Unicode
        if ($name == $mailto)
            $name = rcube_utils::idn_to_utf8($name);
        if ($string == $mailto)
            $string = rcube_utils::idn_to_utf8($string);
        $mailto = rcube_utils::idn_to_utf8($mailto);

        if ($PRINT_MODE) {
            $address = sprintf('%s &lt;%s&gt;', rcube::Q($name), rcube::Q($mailto));
        }
        else if ($valid) {
            if ($linked) {
                $attrs = array(
                    'href'    => 'mailto:' . $mailto,
                    'class'   => 'rcmContactAddress',
                    'onclick' => sprintf("return %s.command('compose','%s',this)",
                        rcmail_output::JS_OBJECT_NAME, rcube::JQ(format_email_recipient($mailto, $name))),
                );

                if ($show_email && $name && $mailto) {
                    $content = rcube::Q($name ? sprintf('%s <%s>', $name, $mailto) : $mailto);
                }
                else {
                    $content = rcube::Q($name ? $name : $mailto);
                    $attrs['title'] = $mailto;
                }

                $address = html::a($attrs, $content);
            }
            else {
                $address = html::span(array('title' => $mailto, 'class' => "rcmContactAddress"),
                    rcube::Q($name ? $name : $mailto));
            }

            if ($addicon && $_SESSION['writeable_abook']) {
                $address .= html::a(array(
                        'href'    => "#add",
                        'title'   => $RCMAIL->gettext('addtoaddressbook'),
                        'class'   => 'rcmaddcontact',
                        'onclick' => sprintf("return %s.command('add-contact','%s',this)",
                            rcmail_output::JS_OBJECT_NAME, rcube::JQ($string)),
                    ),
                    html::img(array(
                        'src' => $RCMAIL->output->abs_url($addicon, true),
                        'alt' => "Add contact",
                )));
            }
        }
        else {
            $address = '';
            if ($name)
                $address .= rcube::Q($name);
            if ($mailto)
                $address = trim($address . ' ' . rcube::Q($name ? sprintf('<%s>', $mailto) : $mailto));
        }

        $address = html::span('adr', $address);
        $allvalues[] = $address;

        if (!$moreadrs)
            $out .= ($out ? ', ' : '') . $address;

        if ($max && $j == $max && $c > $j) {
            if ($linked) {
                $moreadrs = $c - $j;
            }
            else {
                $out .= '...';
                break;
            }
        }
    }

    if ($moreadrs) {
        if ($PRINT_MODE) {
            $out .= ' ' . html::a(array(
                    'href'    => '#more',
                    'class'   => 'morelink',
                    'onclick' => '$(this).hide().next().show()',
                ),
                rcube::Q($RCMAIL->gettext(array('name' => 'andnmore', 'vars' => array('nr' => $moreadrs)))))
                . html::span(array('style' => 'display:none'), join(', ', $allvalues));
        }
        else {
            $out .= ' ' . html::a(array(
                    'href'    => '#more',
                    'class'   => 'morelink',
                    'onclick' => sprintf("return %s.show_popup_dialog('%s','%s')",
                        rcmail_output::JS_OBJECT_NAME,
                        rcube::JQ(join(', ', $allvalues)),
                        rcube::JQ($title))
                ),
                rcube::Q($RCMAIL->gettext(array('name' => 'andnmore', 'vars' => array('nr' => $moreadrs)))));
        }
    }

    return $out;
}
?>
