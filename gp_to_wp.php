<?php
	/* Google+ To Wordpress
	 * Usage: This can be called as a web page or in a cron
	 * For each Google+ entry in the user's public stream, it uses the Wordpress postmeta data to store G+ ID data preventing duplicate posts.
	 * It compares wordpress tags with #hashtags in the post annotation to filter out only the posts a particular blog wants.
	 * If a post title is too long or missing, it will insert the post with a status of 'pending'.
	*/
	require('gp_to_wp_config.php');
	main($google_settings, $wordpress_settings);
	
	function main($google_settings, $wordpress_settings){
		echo "<h1>Google+ to Wordpress</h1><div>".date('Y-m-d H:i:s')."</div>";

		// Instantiate main objects
		$google = new GooglePlus($google_settings['api_key'], $google_settings['user_id']);
		$wordpress = new Wordpress($wordpress_settings['db_host'], $wordpress_settings['db_username'], $wordpress_settings['db_password'], $wordpress_settings['db_name'], $wordpress_settings['db_table_prefix']);

		echo "<style>th {text-align:right;background-color:#ddd;}</style><table>";
		echo "	<tr><th>Google User Id:</th><td>{$google_settings['user_id']}</td></tr>";
		echo "	<tr><th>Google Stream Pages:</th><td>{$google_settings['stream_pages']}</td></tr>";
		echo "	<tr><th>Google Results Per Page:</th><td>{$google_settings['results_per_page']}</td></tr>";
		echo "	<tr><th>Wordpress Host:</th><td>{$wordpress_settings['db_host']}</td></tr>";
		echo "	<tr><th>Wordpress Default Status:</th><td>{$wordpress_settings['default_status']}</td></tr>";
		echo "	<tr><th>Wordpress Author Id:</th><td>{$wordpress_settings['author_id']}</td></tr>";
		echo "	<tr><th>Wordpress Category Id:</th><td>{$wordpress_settings['category_id']}</td></tr>";
		echo "	<tr><th>Wordpress Live Publish:</th><td>{$wordpress_settings['live']}</td></tr>";

		// Grab list of Wordpress tags to filter the G+ stream against
		$wp_tags = $wordpress->getPostTags();
		echo "	<tr><th>Wordpress Filter Tags:</th><td>".implode(',', $wp_tags)."</td></tr>";
		echo "</table><hr>";

		// Loop Over Stream Pages
		$stream_next = null;
		for ($i=1; $i <= $google_settings['stream_pages']; $i++){
			// Grab page of stream
			if($stream = $google->getStream($google_settings['results_per_page'], $stream_next)){
				// Loop over all stream items
				foreach ($stream['items'] as $item){
					$post = $google->getPostData($item);
					$stream_next = $stream['nextPageToken'];
					
					echo '<pre>';
					echo "Date: {$post['date_posted']}\n";
					echo "G+_id: {$post['gp_id']}\n";
					echo "Title: {$post['title']}\n";
					
					// Filter Stream by WP tags
					if($post_tags = array_intersect($wp_tags, $post['tags'])){
						echo "Found Tags: [".implode(',', $post_tags)."]\n";

						if (empty($post['title'])){
							echo "<pre>Item: ";print_r($item);echo "</pre>";
							echo "<pre>Post: ";print_r($post);echo "</pre>";			
						}
						
						// Insert Post into Wordpress
						$post_status = (empty($post['status']))?$wordpress_settings['default_status']:$post['status'];
						echo "Status: {$post_status}\n";
						
						if(!$wordpress->postExists($post['gp_id'], $post['url'])){
							if($wordpress_settings['live']){
								$wordpress->insertPost($wordpress_settings['category_id'], $wordpress_settings['author_id'], $post['gp_id'], $post['url'], $post['date_posted'], $post['title'], $post['content'], $post_tags, $post_status);
								
								$blogname = $wordpress->getOptions('blogname');
								$siteurl = $wordpress->getOptions('siteurl');
								$subject = "[{$blogname}::G+ToWp::{$post_status}] Blog Entry Added";
								$message = "{$subject}\n{$siteurl}\n\n----------\n".print_r($post, true);
								$wordpress->mailAuthor($wordpress_settings['author_id'], $subject, $message);
							}else{
								echo "* Wordpress Live is FALSE\n";
							}
						}else{
							echo "* Google+ Post Already Found, Skipping\n";
						}
					}else{
						echo "* No Tags Intersect\n";
					}
					echo '</pre><hr>';
				}
			}else{
				break;
			}
			echo "<h2>Next Page</h2>";
		}
	}
	
	class Wordpress{
		var $db_host = null;
		var $db_username = null;
		var $db_password = null;
		var $db_name = null;
		var $db_table_prefix = 'wp_';
		var $connection = null;
		var $options = array();
		
		function __construct($db_host, $db_username, $db_password, $db_name, $db_table_prefix){
			$this->db_host = $db_host;
			$this->db_username = $db_username;
			$this->db_password = $db_password;
			$this->db_name = $db_name;
			$this->db_table_prefix = $db_table_prefix;
			
			$this->connection = mysql_connect($this->db_host, $this->db_username, $this->db_password) or die('Could not connect: ' . mysql_error());
			mysql_select_db($this->db_name) or die('Could not select database');
		}
		
		function mailAuthor($author_id, $subject, $message){
			$sql = "SELECT user_email AS email FROM {$this->db_table_prefix}users as User WHERE ID={$author_id};";
			$result = mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
			
			if($rs = mysql_fetch_array($result, MYSQL_ASSOC)){
				$email_to = $rs['email'];
				mail($email_to, $subject, $message);
				echo "* Email Sent to [{$email_to}]\n";
			}
		}
		
		function getOptions($name){
			if(empty($this->options)){
				$sql = "SELECT * FROM {$this->db_table_prefix}options as Options";
				$result = mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
				
				while($rs = mysql_fetch_array($result, MYSQL_ASSOC)){
					$this->options[$rs['option_name']] = $rs['option_value'];
				}
			}
			return (empty($this->options[$name]))?null:$this->options[$name];
		}

		function getPostTags(){
			$tags = array();
			
			$sql = "SELECT term.term_id as id, term.slug as name FROM {$this->db_table_prefix}terms AS term INNER JOIN wp_term_taxonomy AS tax ON term.term_id=tax.term_id WHERE tax.taxonomy='post_tag';";
			$result = mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
			while($rs = mysql_fetch_array($result, MYSQL_ASSOC)){
				$tags[$rs['id']] = $rs['name'];
			}
			
			return $tags;
		}
		
		function postExists($gp_id, $url){
			$exists = false;
			
			echo "* Checking Existence [gp_id:{$gp_id}, url:{$url}]\n";
			$where = "(meta_key='gp_id' && meta_value='{$gp_id}')";
			if($url) $where .= " OR (meta_key='gp_url' && meta_value='{$url}')";
			
			$sql = "SELECT * FROM {$this->db_table_prefix}postmeta WHERE {$where} LIMIT 1;";
			$result = mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
			
			if(mysql_fetch_array($result, MYSQL_ASSOC)){
				$exists = true;
			}
			
			return $exists;
		}
		
		function insertPost($category_id, $author_id, $gp_id, $url, $date, $title, $content, $tags, $status){
			if($gp_id){
				$title = mysql_real_escape_string($title);
				$slug = str_replace(' ', '-', strtolower($title));
				$content = mysql_real_escape_string($content);
				
				if(!$this->postExists($gp_id, $url)){
					$date = date('Y-m-d H:i:s', strtotime($date));
					// Insert Post
					echo "* Inserting Post\n";
					$sql = "INSERT INTO {$this->db_table_prefix}posts (`post_author`, `post_date`, `post_title`, `post_name`, `post_content`, `post_status`, `comment_status`, `ping_status`, `post_type`) VALUES({$author_id}, '{$date}', '{$title}', '{$slug}', '{$content}', '{$status}', 'open', 'open', 'post');";
					mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
					
					if($post_id = mysql_insert_id()){
						// Add to Uncategorized
						echo "* Inserting Category\n";
						$sql = "INSERT INTO {$this->db_table_prefix}term_relationships (`object_id`, `term_taxonomy_id`, `term_order`) VALUES({$post_id},{$category_id},0);";
						mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());	
					
						// Add Tags
						echo "* Inserting Tags\n";
						foreach($tags as $tag_id=>$tag_slug){
							$sql = "INSERT INTO {$this->db_table_prefix}term_relationships (`object_id`, `term_taxonomy_id`, `term_order`) VALUES({$post_id},{$tag_id},0);";
							mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());	
						}

						// Add gp_id to Meta Data
						echo "* Inserting Meta gp_id\n";
						$sql = "INSERT INTO {$this->db_table_prefix}postmeta (`post_id`, `meta_key`, `meta_value`) VALUES({$post_id},'gp_id','{$gp_id}');";
						mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());	

						// Add gp_url to Meta Data
						if($url){
							echo "* Inserting Meta url\n";
							$sql = "INSERT INTO {$this->db_table_prefix}postmeta (`post_id`, `meta_key`, `meta_value`) VALUES({$post_id},'gp_url','{$url}');";
							mysql_query($sql) or die('['.__LINE__.'] Query failed: ' . mysql_error());
						}
					}else{
						echo "* Failed To Insert Post\n";
					}
				}else{
					echo "* Google+ Post Already Found, Skipping\n";
				}
			}else{
				echo "* Google+ ID Not Found\n";
			}
		}
	}
	
	class GooglePlus {
		var $api_key = null;
		var $user_id = null;
		var $stream_url = null;
		
		function __construct($api_key, $user_id){
			$this->api_key = $api_key;
			$this->user_id = $user_id;
			$this->stream_url = "https://www.googleapis.com/plus/v1/people/{$this->user_id}/activities/public?alt=json&pp=1&key={$this->api_key}";
		}
		
		function getStream($results_per_page, $stream_next = null){
			$stream_url = $this->stream_url;
			if($results_per_page) $stream_url .= "&maxResults={$results_per_page}";
			if($stream_next) $stream_url .= "&pageToken={$stream_next}";
			echo __METHOD__." running [url:{$stream_url}]<br>";

			$ch = curl_init();  
			curl_setopt($ch, CURLOPT_URL, $this->stream_url);  
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  		
			$stream = curl_exec($ch);  
			  
			return json_decode($stream, true);
		}
		
		function getAnnotation($item){
			$tags = array();
			$annotation = null;
			
			$raw_annotation = $item['annotation'];
			if ($item['verb'] == 'post'){
				$raw_annotation = $item['object']['content'];
			}

			$tag_line = preg_match_all('/#(\S+)/', $raw_annotation, $matches);
			if(!empty($matches[1])){
				$tags = $matches[1];
				$annotation = trim(preg_replace('/#\S+/', '', $raw_annotation));
				#if (!empty($annotation)) $annotation = $raw_annotation;
			}
			return array($annotation, $tags);
		}
		
		function getPostData($item){
			$min_share_content_length = 30;
			$max_title_length = 75;
			
			$post = array(
				'gp_id' => null
				,'url' => null
				,'date_posted' => null
				,'title' => null
				,'content' => null
				,'tags' => array()
				,'status' => null
			);

			list($annotation, $gp_tags) = $this->getAnnotation($item);
			list($content_title, $content_body, $content_url) = $this->extractContentFromAttachments($item['object']['attachments']);			

			$post['gp_id'] = (empty($item['object']['id']))?$item['id']:$item['object']['id'];
			$post['url'] = $content_url;
			$post['date_posted'] = $item['published'];
			$post['tags'] = $gp_tags;
			
			$post['content'] = (empty($annotation))?'':"{$annotation}<br><br>";
			if(strlen($item['object']['content'])>=$min_share_content_length) $post['content'] .= $item['object']['content'];
			$post['content'] .= $content_body;
			
			$post['title'] = strip_tags($content_title);
			if(empty($post['title'])) $post['title'] = strip_tags($annotation);
			if(empty($post['title'])) $post['title'] = strip_tags($item['object']['content']);
			
			if(empty($post['title']) || strlen($post['title'])>$max_title_length || stripos($post['title'], 'http') !== false){
				$post['status'] = 'pending';
			}

			return $post;
		}
		
		function extractContentFromAttachments($attachments){
			$content_title = null;
			$content_body = null;
			$content_url = null;
			$content_body_list = array();

			if($attachments){
				foreach($attachments as $attachment){
					switch($attachment['objectType']){
						case 'video':
								$content_title = $attachment['displayName'];
								$content_url = str_replace('autoplay=1', 'autoplay=0', $attachment['url']);
								$content_body_list[] = "<a target=\"_new\" href=\"{$content_url}\">{$content_title}</a>";
								$content_body_list[] = "<iframe width=\"440\" height=\"315\" src=\"{$content_url}\" frameborder=\"0\" allowfullscreen></iframe>";
							break;
						case 'photo':
								$image_width = min(440, $attachment['fullImage']['width']);
								$content_body_list[] = "<div><img width=\"{$image_width}\" src=\"{$attachment['fullImage']['url']}\" /></div>";
								if(empty($content_url)) $content_url = $attachment['fullImage']['url'];
							break;
						case 'article':
								$content_title = $attachment['displayName'];
								$content_url = $attachment['url'];
								$content_body_list[] = "<a target=\"_new\" href=\"{$content_url}\">{$content_title}</a>";
								$content_body_list[] = "{$attachment['content']}";
							break;
					}
				}
			}
			
			$content_body = implode('<br>', $content_body_list);
			return array($content_title, $content_body, $content_url);
		}
	}
	
?>