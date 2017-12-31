<?php
/*
Plugin Name: Post to Twitter
Plugin URI: https://github.com/halftheory/wp-halftheory-post-to-twitter
Description: Post to Twitter
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 1.0
Network: false
*/

/*
Available filters:
posttotwitter_deactivation(string $db_prefix)
posttotwitter_uninstall(string $db_prefix)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Post_To_Twitter_Plugin')) :
class Post_To_Twitter_Plugin {

	public function __construct() {
		@include_once(dirname(__FILE__).'/class-post-to-twitter.php');
		$this->subclass = new Post_To_Twitter();
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self;
		$plugin->subclass->schedule_event();
		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self;
		$plugin->subclass->schedule_event(false);

		// remove transients
		global $wpdb;
		$query_single = "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$plugin->subclass->prefix."%' OR option_name LIKE '_transient_timeout_".$plugin->subclass->prefix."%'";
		if (is_multisite()) {
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_".$plugin->subclass->prefix."%' OR meta_key LIKE '_site_transient_timeout_".$plugin->subclass->prefix."%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query($query_single);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query($query_single);
		}
		apply_filters('posttotwitter_deactivation', $plugin->subclass->prefix);
		return;
	}

	public static function uninstall() {
		$plugin = new self;
		$plugin->subclass->schedule_event(false);

		// remove options + postmeta
		global $wpdb;
		$query_options = "DELETE FROM $wpdb->options WHERE option_name LIKE '".$plugin->subclass->prefix."_%'";
		$query_postmeta = "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$plugin->subclass->prefix."_%'";
		if (is_multisite()) {
			delete_site_option($plugin->subclass->prefix);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '".$plugin->subclass->prefix."_%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				delete_option($plugin->subclass->prefix);
				$wpdb->query($query_options);
				$wpdb->query($query_postmeta);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			delete_option($plugin->subclass->prefix);
			$wpdb->query($query_options);
			$wpdb->query($query_postmeta);
		}
		apply_filters('posttotwitter_uninstall', $plugin->subclass->prefix);
		return;
	}

}
// Load the plugin.
add_action('init', array('Post_To_Twitter_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('Post_To_Twitter_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('Post_To_Twitter_Plugin', 'deactivation'));
function Post_To_Twitter_Plugin_uninstall() {
	Post_To_Twitter_Plugin::uninstall();
};
register_uninstall_hook(__FILE__, 'Post_To_Twitter_Plugin_uninstall');
?>