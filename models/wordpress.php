<?php 
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

?>