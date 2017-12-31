<?php
// Exit if accessed directly.
defined('ABSPATH') || exit;

require_once __DIR__.'/twitteroauth/autoload.php';
use Abraham\TwitterOAuth\TwitterOAuth;

if (!class_exists('Post_To_Twitter_Cron')) :
class Post_To_Twitter_Cron {

	private $loaded = false;
	var $plugin;

	public function __construct($plugin = false) {
		if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
	    	return;
		}
		if (!class_exists('Post_To_Twitter')) {
	    	return;
		}
		if (empty($plugin) || !is_object($plugin)) {
			$plugin = new Post_To_Twitter();
		}
		if (!is_a($plugin, 'Post_To_Twitter')) {
			return;
		}
		$active = $plugin->get_option('active');
		if (empty($active)) {
			return;
		}
		$this->loaded = true;
		$this->plugin = $plugin;
	}

	public function do_cron($echo_output = false) {
	    if (!$this->loaded) {
	    	return false;
	    }

		// get posts
	    $past = '2 hours ago';
	    #$past = "-2 hours";
	    #$past = "-2 years";
		$posts = $this->get_posts($past);
		if (empty($posts)) {
	    	return false;
		}

		// connect to twitter
		$do_tweet = false;
		$connection = null;
		if (strpos($_SERVER['HTTP_HOST'], 'localhost') === false) {
			$consumer_key = $this->plugin->get_option('consumer_key');
			$consumer_secret = $this->plugin->get_option('consumer_secret');
			if (!empty($consumer_key) && !empty($consumer_secret)) {
				$oauth_token = $this->plugin->get_option('oauth_token');
				$oauth_token_secret = $this->plugin->get_option('oauth_token_secret');
				// null is better than false?
				if (empty($oauth_token)) {
					$oauth_token = null;
				}
				if (empty($oauth_token_secret)) {
					$oauth_token_secret = null;
				}
				$connection = new TwitterOAuth($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
				if (is_object($connection)) {
					$do_tweet = true;
					set_time_limit(0);
				}
			}
		}

		$urlshortener_key = $this->plugin->get_option('urlshortener_key');
		$this->current_time = current_time('timestamp');
		$maxchars = 140;

		foreach ($posts as $post) {
			$url = get_permalink($post->ID);
			$url = esc_url($url);
			$url = $this->google_shorten_url($url, $urlshortener_key);
			if (empty($url)) {
				continue;
			}
			// url too long
			if (strlen($url) > $maxchars) {
				continue;
			}
			// url just fits
			if (strlen($url) <= $maxchars && strlen($url) >= ($maxchars - 4)) {
				$this->post_to_twitter($url, $do_tweet, $connection, $post->ID, $echo_output);
				continue;
			}

			$maxchars_str = $maxchars - strlen($url) - 4;

			// add titles
			$title_arr = array();
			if ($post->post_parent > 0) {
				$title_arr[] = get_the_title($post->post_parent);
			}
			$title_arr[] = get_the_title($post);
			$str = implode(" - ", $title_arr);
			if (strlen($str) >= $maxchars_str) {
				$str = get_the_title($post);
			}

			// add excerpt
			if (strlen($str) < $maxchars_str && (!empty($post->post_excerpt) || !empty($post->post_content))) {
				if (!empty($post->post_excerpt)) {
					$excerpt = $post->post_excerpt;
					if (function_exists('get_the_excerpt_filtered')) {
						$excerpt = get_the_excerpt_filtered($post);
					}
				}
				else {
					$excerpt = $post->post_content;
					if (function_exists('get_the_content_filtered')) {
						$excerpt = get_the_content_filtered($excerpt);
					}
				}
				if (function_exists('get_excerpt')) {
					$excerpt = get_excerpt($excerpt, $maxchars_str - strlen($str), array('trim_title' => $title_arr, 'trim_urls' => true, 'plaintext' => true, 'single_line' => true));
				}
				else {
					$excerpt = wp_strip_all_tags($excerpt, true);
					$excerpt = preg_replace("/\[[^\]]+\]/is", "", $excerpt); // strip_all_shortcodes					
					$excerpt = wp_trim_words($excerpt, 10, '...');
				}
				$str .= ' - '.$excerpt;
			}

			// trim to maxchars
			$str = substr($str, 0, $maxchars - strlen($url) - 1);
			$str = $str.' '.$url;
			$this->post_to_twitter($str, $do_tweet, $connection, $post->ID, $echo_output);
		}
		return true;
	}

	private function get_posts($time = '2 hours ago') {
		$posts = array();

		$allowed_post_types = $this->plugin->get_option('allowed_post_types');
		if (empty($allowed_post_types)) {
			return false;
		}
		$allowed_post_types = $this->plugin->make_array($allowed_post_types);		
		$allowed_post_statuses = $this->plugin->get_option('allowed_post_statuses');
		if (empty($allowed_post_statuses)) {
			return false;
		}
		$allowed_post_statuses = $this->plugin->make_array($allowed_post_statuses);
		$excluded_posts = $this->plugin->get_option('excluded_posts');
		$excluded_posts = $this->plugin->make_array($excluded_posts);
		$excluded_posts = apply_filters('posttotwitter_excluded_posts', $excluded_posts);

		$args = array(
			'post_type' => $allowed_post_types,
			'post_status' => $allowed_post_statuses,
			'post__not_in' => $excluded_posts,
			'post_parent__not_in' => $excluded_posts,
			'posts_per_page' => -1,
			'no_found_rows' => true,
			'nopaging' => true,
			'ignore_sticky_posts' => true,
			'orderby' => 'modified',
			// recently modified
			'date_query' => array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => $time,
				),
			),
			// ignore already tweeted
			'meta_query' => array(
				array(
					'key' => $this->plugin->prefix,
					'compare' => 'NOT EXISTS',
				)
			)
		);
		$posts = get_posts($args);

		if (empty($excluded_posts)) {
			return $posts;
		}

		// check ancestors
		foreach ($posts as $key => $value) {
			$ancestors = get_ancestors($value->ID, $value->post_type);
			if (empty($ancestors)) {
				continue;
			}
			$diff = array_diff($ancestors, $excluded_posts);
			if ($diff !== $ancestors) {
				unset($posts[$key]);
			}
		}

		return $posts;
	}

	private function google_shorten_url($url, $api_key) {
		if (empty($api_key)) {
			return $url;
		}
		$data = array(
			'longUrl' => $url,
			'key' => $api_key,
		);
		$curlObj = curl_init();
		curl_setopt($curlObj, CURLOPT_URL, 'https://www.googleapis.com/urlshortener/v1/url?key='.$api_key);
		curl_setopt($curlObj, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curlObj, CURLOPT_HEADER, 0);
		curl_setopt($curlObj, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
		curl_setopt($curlObj, CURLOPT_POST, 1);
		curl_setopt($curlObj, CURLOPT_POSTFIELDS, json_encode($data));
		$response = curl_exec($curlObj);
		$response = json_decode($response);
		curl_close($curlObj);
		if (!is_object($response)) {
			return false;
		}
		if (!empty($response->id)) {
			return $response->id;
		}
		return false;
	}

	private function post_to_twitter($message, $do_tweet = true, $connection = null, $post_ID, $echo_output = false) {
		$res = false;
		if ($do_tweet && is_object($connection)) {
			$data = array('status' => $message);
			$result = $connection->post("statuses/update", $data);
			if (empty($result->id_str)) {
				//error_log($result->error.': '.$message);
			}
			else {
				$res = $result->id_str;
				update_post_meta($post_ID, $this->plugin->prefix, array('timestamp' => $this->current_time, 'twitterid' => $res));
			}

		}
		if ($echo_output !== false) {
			if ($res) {
				echo $res.' - ';
			}
			echo $message."<br />\n";
		}
		return $res;
	}
}
endif;
?>