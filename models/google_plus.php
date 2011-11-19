<?php 
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