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
				if(!$this->db->connected) {
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
		$this->__convertPosts();
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
			if(!empty($users[$key])) {
				$defaultUser = array('User' => array_merge($defaults, array_shift($users[$key]))); 
			} else {
				$defaultUser = array('User' => $defaults); 
			}
			
			$user = Set::merge($defaultUser, array('User' => $this->__remap(array(null, 'username', 'name', 'email', 'website', 'created'), $wpUser['WpUser'])));
			$this->Node->User->create();
			if($this->Node->User->save($user)) {
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
			if(!empty($terms[$key])) {
				$defaultTerm = array_merge($defaults, array_shift($terms[$key])); 
			} else {
				$defaultTerm = $defaults; 
			}
			
			$term = array('Term' => array_merge($defaultTerm, $this->__remap(array(null, 'title', 'slug'), $wpTerm['WpTerm'])));

			$this->Node->Term->create();
			if($this->Node->Term->save($term)) {
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
		$attachments = $this->__createHash($attachments, 'Node', 'path');

		$count = 0;
		foreach($wpMetas as $wpMeta) {
			$wpMeta['WpMeta'] = unserialize($wpMeta['WpMeta']['meta_value']);
			
			$key = md5($wpMeta['WpMeta']['file']);
			if(!empty($attachments[$key])) {
				$defaultAttachment = array_merge($defaults, array_shift($attachments[$key])); 
			} else {
				$defaultAttachment = $defaults; 
			}
			
			$fileinfo = pathinfo($wpMeta['WpMeta']['file']);

			
			$attachment = array('Node' => array_merge($defaultAttachment, array('title' => $fileinfo['filename'],
																																					'slug' => $wpMeta['WpMeta']['file'],
																																					'mime_type' => $mediaView->mimeType[$fileinfo['extension']],
																																					'path' => '/uploads/' . $wpMeta['WpMeta']['file'])));
			$this->Node->create();
			if($this->Node->save($attachment)) {
				$count ++;
			}
		}
		
		$this->out(sprintf('Moved %d attachments', $count));
	}
	
	function __convertPosts() {
		$defaults = array('type' => 'blog');
		
		$sql = sprintf('SELECT `ID`, `post_author`, `post_date`, `post_content`, `post_title`, `post_excerpt`, `post_status`,
									 `comment_status`, `comment_count`, `post_modified`, `post_name` FROM %sposts AS WpPost
									 WHERE post_type = "post"', $this->options['prefix']);
		$wpPosts = $this->db->query($sql);
		$nodes = $this->Node->find('all', array('recursive' => -1));
		$nodes = $this->__createHash($nodes, 'Node', 'slug');
		
		$count = 0;
		foreach($wpPosts as $wpPost) {
			$key = md5($wpPost['WpPost']['post_name']);
			if(!empty($nodes[$key])) {
				$defaultPost = array_merge($defaults, array_shift($nodes[$key])); 
			} else {
				$defaultPost = $defaults; 
			}
			
			$post = array('Node' => array_merge($defaultPost, $this->__remap(array(null, 'user_id', 'created', 'body', 'title', 'excerpt', 'status', 'comment_status',
																																						'comment_count', 'updated', 'slug'), $wpPost['WpPost'])));
			
			$post['Node']['user_id'] = $this->__remapValue($this->_userMap, $wpPost['WpPost']['post_author']);
			$post['Node']['comment_status'] = $this->__remapValue(array('closed' => 0, '_default' => 2), $wpPost['WpPost']['comment_status']);
			$post['Node']['status'] = $this->__remapValue(array('private' => 0, '_default' => 1), $wpPost['WpPost']['post_status']);
			$post['Node']['promote'] = $this->__remapValue(array('private' => 0, '_default' => 1), $wpPost['WpPost']['post_status']);
			$post['Node']['body'] = str_replace('wp-content/', '', $post['Node']['body']);
				
			$this->Node->create();
			if($this->Node->save($post)) {
				$this->_postMap[$wpPost['WpPost']['ID']] = $this->Node->id;
				$count ++;
			}
		}
		
		$this->out(sprintf('Moved %d posts', $count));
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
			
			if($this->Node->save($data)) {
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
			if(!empty($comments[$key])) {
				$defaultComment = array_merge($defaults, array_shift($comments[$key])); 
			} else {
				$defaultComment = $defaults; 
			}
			
			$comment =  array('Comment' => array_merge($defaultComment, $this->__remap(array(null, 'user_id', 'parent_id', 'node_id', 'name', 'email', 'website', 'ip', 'created',
																																									'body', 'rating', 'status', 'comment_type'), $wpComment['WpComment'])));
			$comment['Comment']['node_id'] = $this->__remapValue($this->_postMap, $wpComment['WpComment']['comment_post_ID']);
					
			$comment['Comment']['user_id'] = $this->__remapValue(array('_default' => 0) + $this->_userMap, $wpComment['WpComment']['user_id']);
			
			$comment['Comment']['parent_id'] = $this->__remapValue($this->_commentMap, $wpComment['WpComment']['comment_parent']);
			if(empty($comment['Comment']['comment_type'])) {
				$comment['Comment']['comment_type'] = 'comment';
			}

			if(empty($comment['Comment']['node_id'])) {
				continue;
			}
			
			$this->Node->Comment->create();
			if($this->Node->Comment->save($comment)) {
				$this->_commentMap[$wpComment['WpComment']['comment_ID']] = $this->Node->Comment->id;
				$count ++;
			}
		}

		$this->out(sprintf('Moved %d comments', $count));
	}
	
	function __remap($fields, $data) {
		$newData = array();
		
		foreach(array_values($data) as $i => $val) {
			if($fields[$i]) {
				$newData[$fields[$i]] = $val;
			}
		}
		
		return $newData;
	}
	
	function __remapValue($options, $value) {
		if(!empty($options[$value])) {
			return $options[$value];
		}
		
		if(isset($options['_default'])) {
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