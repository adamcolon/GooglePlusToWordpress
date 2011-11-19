<?php
/* Google+ To Wordpress
 * Usage: This can be called as a web page or in a cron
* For each Google+ entry in the user's public stream, it uses the Wordpress postmeta data to store G+ ID data preventing duplicate posts.
* It compares wordpress tags with #hashtags in the post annotation to filter out only the posts a particular blog wants.
* If a post title is too long or missing, it will insert the post with a status of 'pending'.
*/
require('config/gp_to_wp_config.php');
require('models/google_plus.php');
require('models/wordpress.php');
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

?>