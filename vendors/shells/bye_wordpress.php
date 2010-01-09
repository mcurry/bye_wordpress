<?php
App::import('Core', 'Security');

class ByeWordpressShell extends Shell {
	var $uses = array('Node');
	var $db = null;

	var $_userMap = array();
	var $_postMap = array();
	var $_termMap = array();
	var $_commentMap = array();

	var $options = array();
	var $_defaults = array('driver' => 'mysql',
												 'host' => 'localhost',
												 'database' => 'wordpress',
												 'login' => 'root',
												 'password' => '',
												 'prefix' => 'wp_');
	function main() {
		$this->options = array_merge($this->_defaults, array_intersect_key($this->params, $this->_defaults));

		$confirm = strtoupper($this->in(sprintf('Convert Wordpress database (%s:%s@%s/%s) to Croogo?',
																						$this->options['login'], $this->options['password'], $this->options['host'], $this->options['database']), array('Y', 'N')));
		switch (strtolower($confirm)) {
			case 'y':
				App::import('Core', 'ConnectionManager');
				$this->db = ConnectionManager::create('wordpressDb', $this->options);
				if (!$this->db->connected) {
					$this->out(__('Couldn\'t connect to DB.  Please check settings'), true);
					$this->_stop();
				}

				$this->__convert();
				break;
			case 'n':
				$this->_stop();
				break;
		}
	}

	function __convert() {
		$this->__convertUsers();
		$this->__convertTerms();
		$this->__convertAttachments();
		$this->__convertPostsPages();
		$this->__convertTermPosts();
		$this->__convertComments();
	}

	function __convertUsers() {
		$defaults = array('password' => Security::hash(time() + rand(0, 4949494) + uniqid(), null, true),
											'role_id' => 2,
											'status' => 1);

		$sql = sprintf('SELECT `ID`, `user_login`, `display_name`, `user_email`, `user_url`, `user_registered` FROM %susers AS WpUser', $this->options['prefix']);
		$wpUsers = $this->db->query($sql);
		$users = $this->Node->User->find('all', array('recursive' => -1));
		$users = $this->__createHash($users, 'User', 'username');

		$count = 0;
		foreach($wpUsers as $wpUser) {
			$key = md5($wpUser['WpUser']['user_login']);
			if (!empty($users[$key])) {
				$defaultUser = array('User' => array_merge($defaults, array_shift($users[$key])));
			} else {
				$defaultUser = array('User' => $defaults);
			}

			$user = Set::merge($defaultUser, array('User' => $this->__remap(array(null, 'username', 'name', 'email', 'website', 'created'), $wpUser['WpUser'])));
			$this->Node->User->create();
			if ($this->Node->User->save($user)) {
				$this->_userMap[$wpUser['WpUser']['ID']] = $this->Node->User->id;
				$count ++;
			}
		}

		$this->out(sprintf('Moved %d users', $count));
	}

	function __convertTerms() {
		$defaults = array('vocabulary_id' => 1,
											'status' => 1);

		$sql = sprintf('SELECT `term_id`, `name`, `slug` FROM %sterms AS WpTerm', $this->options['prefix']);
		$wpTerms = $this->db->query($sql);
		$terms = $this->Node->Term->find('all', array('recursive' => -1));
		$terms = $this->__createHash($terms, 'Term', 'slug');

		$count = 0;
		foreach($wpTerms as $wpTerm) {
			$key = md5($wpTerm['WpTerm']['slug']);
			if (!empty($terms[$key])) {
				$defaultTerm = array_merge($defaults, array_shift($terms[$key]));
			} else {
				$defaultTerm = $defaults;
			}

			$term = array('Term' => array_merge($defaultTerm, $this->__remap(array(null, 'title', 'slug'), $wpTerm['WpTerm'])));

			$this->Node->Term->create();
			if ($this->Node->Term->save($term)) {
				$this->_termMap[$wpTerm['WpTerm']['term_id']] = $this->Node->Term->id;
				$count ++;
			}
		}

		$this->out(sprintf('Moved %d terms', $count));
	}

	function __convertAttachments() {
		$defaults = array('type' => 'attachment');
		App::import('Core', array('Controller'));
		App::import('View', array('View', 'Media'));
		$fakeController = new Controller();
		$mediaView = new MediaView($fakeController);

		$sql = sprintf('SELECT `meta_value` FROM %spostmeta AS WpMeta WHERE meta_key = "_wp_attachment_metadata"', $this->options['prefix']);
		$wpMetas = $this->db->query($sql);

		$attachments = $this->Node->find('all', array('recursive' => -1, 'conditions' => array('type' => 'attachment')));
		$attachments = $this->__createHash($attachments, 'Node', 'slug');

		$count = 0;
		foreach($wpMetas as $wpMeta) {
			$wpMeta['WpMeta'] = unserialize($wpMeta['WpMeta']['meta_value']);
			if (empty($wpMeta['WpMeta'])) {
				continue;
			}

			$wpMeta['WpMeta']['file'] = str_replace('/wp-content/uploads/', '', $wpMeta['WpMeta']['file']);

			$key = md5($wpMeta['WpMeta']['file']);
			if (!empty($attachments[$key])) {
				$defaultAttachment = array_merge($defaults, array_shift($attachments[$key]));
			} else {
				$defaultAttachment = $defaults;
			}

			$fileinfo = pathinfo($wpMeta['WpMeta']['file']);

			$mimeType = '';
			if (isset($fileinfo['extension'])) {
				$mimeType = $mediaView->mimeType[$fileinfo['extension']];
			}

			$attachment = array('Node' => array_merge($defaultAttachment, array('title' => $fileinfo['filename'],
																	'slug' => $wpMeta['WpMeta']['file'],
																	'mime_type' => $mimeType,
																	'path' => '/uploads/' . $wpMeta['WpMeta']['file'])));
			$this->Node->create();
			if ($this->Node->save($attachment)) {
				$count ++;
			}
		}

		$this->out(sprintf('Moved %d attachments', $count));
	}

	function __convertPostsPages() {
		$defaults = array();

		$sql = sprintf('SELECT `ID`, `post_author`, `post_date`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `post_type`,
									 `comment_status`, `comment_count`, `post_modified`, `post_name` FROM %sposts AS WpPost
									 WHERE post_type IN ("page", "post") AND post_status != "draft"', $this->options['prefix']);
		$wpPosts = $this->db->query($sql);
		$nodes = $this->Node->find('all', array('conditions' => array('type' => array('blog', 'page')), 'recursive' => -1));
		$nodes = $this->__createHash($nodes, 'Node', 'slug');

		$count = 0;
		foreach($wpPosts as $wpPost) {
			$key = md5($wpPost['WpPost']['post_name']);
			if (!empty($nodes[$key])) {
				$defaultPost = array_merge($defaults, array_shift($nodes[$key]));
			} else {
				$defaultPost = $defaults;
			}

			$post = array('Node' => array_merge($defaultPost, $this->__remap(array(null, 'user_id', 'created', 'body', 'title', 'excerpt', 'status', 'type', 'comment_status',
																					'comment_count', 'updated', 'slug'), $wpPost['WpPost'])));

			$post['Node']['user_id'] = $this->__remapValue($this->_userMap, $wpPost['WpPost']['post_author']);
			$post['Node']['type'] = $this->__remapValue(array('post' => 'blog', 'page' => 'page'), $wpPost['WpPost']['post_type']);
			$post['Node']['comment_status'] = $this->__remapValue(array('closed' => 0, '_default' => 2), $wpPost['WpPost']['comment_status']);
			$post['Node']['status'] = $this->__remapValue(array('private' => 0, '_default' => 1), $wpPost['WpPost']['post_status']);
			$post['Node']['promote'] = $this->__remapValue(array('private' => 0, '_default' => 1), $wpPost['WpPost']['post_status']);
			$post['Node']['body'] = $this->wpautop(str_replace('wp-content/', '', $post['Node']['body']));
			
			//$post['Node']['body'] = str_replace('<pre name="code" class="', '<pre class="brush: ', $post['Node']['body'], $stcount);

			$this->Node->create();
			if ($this->Node->save($post)) {
				$this->_postMap[$wpPost['WpPost']['ID']] = $this->Node->id;
				$count ++;
			}
		}

		$this->out(sprintf('Moved %d posts/pages', $count));
	}

	function __convertTermPosts() {
		$sql = sprintf('SELECT `object_id`, `term_order`, `term_id` FROM %sterm_taxonomy AS WpTermTaxonomy, %sterm_relationships AS WpTermRelationship
									 WHERE WpTermTaxonomy.term_taxonomy_id = WpTermRelationship.term_taxonomy_id
									 AND object_id IN (%s)', $this->options['prefix'], $this->options['prefix'], implode(',', array_keys($this->_postMap)));
		$wpTerms = $this->db->query($sql);

		$count = 0;
		foreach($wpTerms as $wpTerm) {
			$data = array('Node' => array('id' => $this->_postMap[$wpTerm['WpTermRelationship']['object_id']]),
										'Term' => array('Term' => array($this->_termMap[$wpTerm['WpTermTaxonomy']['term_id']]))
									 );

			if ($this->Node->save($data)) {
				$count ++;
			}
		}

		$this->out(sprintf('Added %d terms to posts', $count));
	}

	function __convertComments() {
		$defaults = array('type' => 'blog');

		$sql = sprintf('SELECT `comment_ID`, `user_id`, `comment_parent`, `comment_post_ID`, `comment_author`, `comment_author_email`, `comment_author_url`,
									 `comment_author_IP`, `comment_date`, `comment_content`, `comment_karma`, `comment_approved`, `comment_type`
									 FROM %scomments AS WpComment
									 WHERE comment_approved = 1
									 ORDER BY comment_ID ASC', $this->options['prefix']);
		$wpComments = $this->db->query($sql);

		$comments = $this->Node->Comment->find('all', array('recursive' => -1));
		$comments = $this->__createHash($comments, 'Comment', 'body');

		$count = 0;
		foreach($wpComments as $wpComment) {
			$key = md5($wpComment['WpComment']['comment_content']);
			if (!empty($comments[$key])) {
				$defaultComment = array_merge($defaults, array_shift($comments[$key]));
			} else {
				$defaultComment = $defaults;
			}

			$comment =  array('Comment' => array_merge($defaultComment, $this->__remap(array(null, 'user_id', 'parent_id', 'node_id', 'name', 'email', 'website', 'ip', 'created',
																	 'body', 'rating', 'status', 'comment_type'), $wpComment['WpComment'])));
			$comment['Comment']['node_id'] = $this->__remapValue($this->_postMap, $wpComment['WpComment']['comment_post_ID']);

			$comment['Comment']['user_id'] = $this->__remapValue(array('_default' => 0) + $this->_userMap, $wpComment['WpComment']['user_id']);

			$comment['Comment']['parent_id'] = $this->__remapValue($this->_commentMap, $wpComment['WpComment']['comment_parent']);
			if (empty($comment['Comment']['comment_type'])) {
				$comment['Comment']['comment_type'] = 'comment';
			}

			if (empty($comment['Comment']['node_id'])) {
				continue;
			}

			$this->Node->Comment->create();
			if ($this->Node->Comment->save($comment)) {
				$this->_commentMap[$wpComment['WpComment']['comment_ID']] = $this->Node->Comment->id;
				$count ++;
			}
		}
		
		//fix the comment counts - for nodes w/ comments this is done automatically
		//but for nodes w/ just pingbacks the counts will be wrong.
		$commentCounts = $this->Node->Comment->find('all', array('fields' => array('node_id', 'COUNT(*) AS cnt'),
																						'group' => array('node_id')));
		foreach($commentCounts as $commentCount) {
			$this->Node->save(array('id' => $commentCount['Comment']['node_id'],
															'comment_count' => $commentCount[0]['cnt']));
		}
		
		$this->out(sprintf('Moved %d comments', $count));
	}

	function __remap($fields, $data) {
		$newData = array();

		foreach(array_values($data) as $i => $val) {
			if ($fields[$i]) {
				$newData[$fields[$i]] = $val;
			}
		}

		return $newData;
	}

	function __remapValue($options, $value) {
		if (!empty($options[$value])) {
			return $options[$value];
		}

		if (isset($options['_default'])) {
			return $options['_default'];
		}

		return null;
	}

	function __createHash($values, $model, $field) {
		$hash = array();

		foreach($values as $value) {
			$key = md5($value[$model][$field]);
			$hash[$key] = $value;
		}

		return $hash;
	}

	/**
	* The wpautop and clean_pre functions were taken from WordPress.  Usually WordPress applies this whenver a post is displayed,
	* so the version stored in the DB doesn't have the <p> tags.  We need to add them for the migration to look right.
	*/
	function wpautop($pee, $br = 1) {

		if ( trim($pee) === '' )
			return '';
		$pee = $pee . "\n"; // just to make things a little easier, pad the end
		$pee = preg_replace('|<br />\s*<br />|', "\n\n", $pee);
		// Space things out a little
		$allblocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|form|map|area|blockquote|address|math|style|input|p|h[1-6]|hr|fieldset|legend)';
		$pee = preg_replace('!(<' . $allblocks . '[^>]*>)!', "\n$1", $pee);
		$pee = preg_replace('!(</' . $allblocks . '>)!', "$1\n\n", $pee);
		$pee = str_replace(array("\r\n", "\r"), "\n", $pee); // cross-platform newlines
		if ( strpos($pee, '<object') !== false ) {
			$pee = preg_replace('|\s*<param([^>]*)>\s*|', "<param$1>", $pee); // no pee inside object/embed
			$pee = preg_replace('|\s*</embed>\s*|', '</embed>', $pee);
		}
		$pee = preg_replace("/\n\n+/", "\n\n", $pee); // take care of duplicates
		// make paragraphs, including one at the end
		$pees = preg_split('/\n\s*\n/', $pee, -1, PREG_SPLIT_NO_EMPTY);
		$pee = '';
		foreach ( $pees as $tinkle )
		$pee .= '<p>' . trim($tinkle, "\n") . "</p>\n";
		$pee = preg_replace('|<p>\s*</p>|', '', $pee); // under certain strange conditions it could create a P of entirely whitespace
		$pee = preg_replace('!<p>([^<]+)</(div|address|form)>!', "<p>$1</p></$2>", $pee);
		$pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)\s*</p>!', "$1", $pee); // don't pee all over a tag
		$pee = preg_replace("|<p>(<li.+?)</p>|", "$1", $pee); // problem with nested lists
		$pee = preg_replace('|<p><blockquote([^>]*)>|i', "<blockquote$1><p>", $pee);
		$pee = str_replace('</blockquote></p>', '</p></blockquote>', $pee);
		$pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)!', "$1", $pee);
		$pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*</p>!', "$1", $pee);
		if ($br) {
			$pee = preg_replace_callback('/<(script|style).*?<\/\\1>/s', create_function('$matches', 'return str_replace("\n", "<WPPreserveNewline />", $matches[0]);'), $pee);
			$pee = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $pee); // optionally make line breaks
			$pee = str_replace('<WPPreserveNewline />', "\n", $pee);
		}
		$pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*<br />!', "$1", $pee);
		$pee = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $pee);
		if (strpos($pee, '<pre') !== false)
			$pee = preg_replace_callback('!(<pre[^>]*>)(.*?)</pre>!is', array($this, 'clean_pre'), $pee );
		$pee = preg_replace( "|\n</p>$|", '</p>', $pee );

		return $pee;
	}

	function clean_pre($matches) {
		if ( is_array($matches) )
			$text = $matches[1] . $matches[2] . "</pre>";
		else
			$text = $matches;

		$text = str_replace('<br />', '', $text);
		$text = str_replace('<p>', "\n", $text);
		$text = str_replace('</p>', '', $text);

		return $text;
	}

	function help() {
		$this->out('Croogo Bye Wordpress Shell');
		$this->hr();
		$this->out('Usage: ./cake bye_wordpress [-host <wordpress_host>] [-database <wordpress_database>] [-login <wordpress_login>] [-password <wordpress_password>] [-prefix <wordpress_prefix>]');
		$this->hr();
		$this->out('Parameters:');
		$this->out('');
		$this->out("\t-host");
		$this->out("\t\tWordpress DB host.");
		$this->out("\t\tdefaults to localhost.");
		$this->out('');
		$this->out("\t-database");
		$this->out("\t\tWordpress DB name.");
		$this->out("\t\tdefaults to wordpress.");
		$this->out('');
		$this->out("\t-login");
		$this->out("\t\tWordpress DB login.");
		$this->out("\t\tdefaults to localhost.");
		$this->out('');
		$this->out("\t-password");
		$this->out("\t\tWordpress DB password.");
		$this->out("\t\tdefaults to empty.");
		$this->out('');
		$this->out("\t-prefix");
		$this->out("\t\tWordpress table prefix.");
		$this->out("\t\tdefaults to wp_.");
		$this->out('');
		$this->hr();
		return null;
	}
}

?>