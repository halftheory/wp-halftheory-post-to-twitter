<?php
/*
Available filters:
halftheory_admin_menu_parent
posttotwitter_admin_menu_parent
posttotwitter_post_types
posttotwitter_post_statuses
posttotwitter_excluded_posts
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Post_To_Twitter')) :
class Post_To_Twitter {

	public function __construct() {
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		$this->prefix = sanitize_key($this->plugin_name);
		$this->prefix = preg_replace("/[^a-z0-9]/", "", $this->prefix);

		// cron
		add_action($this->prefix.'_schedule_hook', array($this,'schedule_hook'));

		// admin options
		if (!$this->is_front_end()) {
			add_action('admin_menu', array($this,'admin_menu'));
		}

		// stop if not active
		$active = $this->get_option('active');
		if (empty($active)) {
			return;
		}
	}

	/* functions-common */

	private function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$url_test = $_SERVER["HTTP_REFERER"];
			}
			else {
				$url_test = $this->get_current_uri();
			}
			if (strpos($url_test, admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	private function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		return $res;
	}

	/* cron */

	public function schedule_event($active = true) { // set to false to force event clearing
		if ($active !== false) {
			$active = $this->get_option('active');
			if (empty($active)) {
				$active = false;
			}
			else {
				$active = true;
			}
		}
		$timestamp_next = wp_next_scheduled($this->prefix.'_schedule_hook');
		// add event
		if ($active) {
			if ($timestamp_next === false) {
				$current_time = current_time('timestamp');
				$timestamp = $current_time + 120; // add 2 mins
				wp_clear_scheduled_hook($this->prefix.'_schedule_hook');
				wp_schedule_event($timestamp, 'hourly', $this->prefix.'_schedule_hook');
			}
		}
		// clear event
		else {
			if ($timestamp_next !== false) {
				wp_unschedule_event($timestamp_next, $this->prefix.'_schedule_hook');
				wp_clear_scheduled_hook($this->prefix.'_schedule_hook');
			}
		}
	}

	public function schedule_hook($echo_output = false) {
		// stop if not active
		$active = $this->get_option('active');
		if (empty($active)) {
			return;
		}
		include_once(dirname(__FILE__).'/class-post-to-twitter-cron.php');
		if (!class_exists('Post_To_Twitter_Cron')) {
			return;
		}
		$cron = new Post_To_Twitter_Cron($this);
		$cron->do_cron($echo_output);
	}

	/* admin */

	public function admin_menu() {
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = $this->prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters('posttotwitter_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				$this->prefix,
				__CLASS__ .'::menu_page'
			);
			return;
		}

		// find top level menu if it exists
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				__CLASS__ .'::menu_page'
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->prefix,
			__CLASS__ .'::menu_page'
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new Post_To_Twitter();

        if ($_POST['save']) {
        	$save = function() use ($plugin) {
				// verify this came from the our screen and with proper authorization
				if (!isset($_POST[$plugin->plugin_name.'::menu_page'])) {
					return;
				}
				if (!wp_verify_nonce($_POST[$plugin->plugin_name.'::menu_page'], plugin_basename(__FILE__))) {
					return;
				}
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin->prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if (empty($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($options)) {
		            	echo $updated;
		            }
		        	else {
		            	echo $error;
		        	}
				}
				else {
		            if ($plugin->delete_option()) {
		            	echo $updated;
		            }
		        	else {
		            	echo $updated;
		        	}
				}
				// cron
				$plugin->schedule_event();
			};
			$save();
        }

 		#$plugin->schedule_hook(true);

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option();
		$options = array_merge( array_fill_keys($options_arr, null), (array)$options );
		?>
	    <form id="<?php echo $plugin->prefix; ?>-admin-form" name="<?php echo $plugin->prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin->prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_active" name="<?php echo $plugin->prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> active?</label></p>

        <div class="postbox">
        	<div class="inside">
	            <h4>Twitter App Details</h4>
	            <p><span class="description"><?php _e('These details are available from twitter.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_consumer_key" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('Consumer Key'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_consumer_key" id="<?php echo $plugin->prefix; ?>_consumer_key" style="width: 50%;" value="<?php echo esc_attr($options['consumer_key']); ?>" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_consumer_secret" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('Consumer Secret'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_consumer_secret" id="<?php echo $plugin->prefix; ?>_consumer_secret" style="width: 50%;" value="<?php echo esc_attr($options['consumer_secret']); ?>" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_oauth_token" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('OAuth Token'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_oauth_token" id="<?php echo $plugin->prefix; ?>_oauth_token" style="width: 50%;" value="<?php echo esc_attr($options['oauth_token']); ?>" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_oauth_token_secret" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('OAuth Token Secret'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_oauth_token_secret" id="<?php echo $plugin->prefix; ?>_oauth_token_secret" style="width: 50%;" value="<?php echo esc_attr($options['oauth_token_secret']); ?>" /></p>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Google App Details</h4>
	            <p><span class="description"><?php _e('These details are available from google.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_urlshortener_key" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('URL Shortener Server API Key'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_urlshortener_key" id="<?php echo $plugin->prefix; ?>_urlshortener_key" style="width: 50%;" value="<?php echo esc_attr($options['urlshortener_key']); ?>" /></p>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Allowed Post Types</h4>
	            <p><span class="description"><?php _e('Automatic tweets will only be applied to the following post types.'); ?></span></p>
	            <?php
	            $post_types = array();
	            $arr = get_post_types(array('public' => true), 'objects');
	            foreach ($arr as $key => $value) {
	            	$post_types[$key] = $value->label;
	            }
	            $post_types = apply_filters('posttotwitter_post_types', $post_types);
	            $options['allowed_post_types'] = $plugin->make_array($options['allowed_post_types']);
	            foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_allowed_post_types[]" value="'.$key.'"';
					if (in_array($key, $options['allowed_post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Allowed Post Statuses</h4>
	            <p><span class="description"><?php _e('Automatic tweets will only be applied to the following post statuses.'); ?></span></p>
	            <?php
	            $post_statuses = array();
	            $arr = get_post_stati(array(), 'objects');
	            foreach ($arr as $key => $value) {
	            	$post_statuses[$key] = $value->label;
	            }
	            $post_statuses = apply_filters('posttotwitter_post_statuses', $post_statuses);
	            $options['allowed_post_statuses'] = $plugin->make_array($options['allowed_post_statuses']);
	            foreach ($post_statuses as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_allowed_post_statuses[]" value="'.$key.'"';
					if (in_array($key, $options['allowed_post_statuses'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Excluded Posts</h4>
	            <p><span class="description"><?php _e('Automatic tweets will be excluded from the following posts and any child posts.'); ?></span></p>
	            <?php
	            if (empty($options['allowed_post_types'])) {
	            	echo '<p>';
	            	_e('No Post Types selected.');
	            	echo '</p>';
	            }
	        	else {
					$options['excluded_posts'] = $plugin->make_array($options['excluded_posts']);
		            $options['excluded_posts'] = apply_filters('posttotwitter_excluded_posts', $options['excluded_posts']);

	        		foreach ($options['allowed_post_types'] as $value) {
		            	echo '<h4>'.$post_types[$value].'</h4>';
		            	// TODO: catch Post categories
						$posts = get_posts(array(
							'no_found_rows' => true,
							'nopaging' => true,
							'ignore_sticky_posts' => true,
							'post_status' => 'publish,inherit',
							'post_type' => $value,
							'orderby' => 'menu_order',
							'order' => 'ASC',
							'post_parent' => 0
				        ));
			            if (empty($posts)) {
			            	echo '<p>';
			            	_e('No top level posts found.');
			            	echo '</p>';
			            	continue;
			            }
						foreach ($posts as $post) {
							echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_excluded_posts[]" value="'.$post->ID.'"';
							if (in_array($post->ID, $options['excluded_posts'])) {
								checked($post->ID, $post->ID);
							}
							echo '> '.get_the_title($post).'</label>';
						}

	        		}
	        	}
	            ?>
        	</div>
        </div>

        <p class="submit">
            <input type="submit" value="Update" id="publish" class="button button-primary button-large" name="save">
        </p>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

    /* functions */

	public function make_array($str = '') {
		if (is_array($str)) {
			return $str;
		}
		if (empty($str)) {
			return array();
		}
		$arr = explode(",", $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr);
		return $arr;
	}

	public function get_option($key = '') {
		if (!isset($this->option)) {
			$option = get_option($this->prefix, array());
			$this->option = $option;
		}
		if (!empty($key)) {
			if (array_key_exists($key, $this->option)) {
				return $this->option[$key];
			}
			return false;
		}
		return $this->option;
	}
	public function update_option($option) {
		$bool = update_option($this->prefix, $option);
		if ($bool !== false) {
			$this->option = $option;
		}
		return $bool;
	}
	public function delete_option() {
		$bool = delete_option($this->prefix);
		if ($bool !== false && isset($this->option)) {
			unset($this->option);
		}
		return $bool;
	}

    private function get_options_array() {
		return array(
			'active',
			'consumer_key',
			'consumer_secret',
			'oauth_token',
			'oauth_token_secret',
			'urlshortener_key',
			'allowed_post_types',
			'allowed_post_statuses',
			'excluded_posts',
		);
    }
    private function get_postmeta_array() {
		return array(
			'timestamp',
			'twitterid',
		);
    }

}
endif;
?>