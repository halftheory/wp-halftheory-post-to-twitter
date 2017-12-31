# wp-halftheory-post-to-twitter
Wordpress plugin for automatically tweeting any post type to Twitter.

This plugin automatically posts a Twitter status update when you modify a post.

Features:
- Limit automatic tweeting to selected post types.
- Limit automatic tweeting to selected post statuses.
- Exclude selected posts amd their children from being automatically tweeted.
- Batch posts to Twitter every hour to avoid reaching connection quotas.
- Supports Google's URL Shortener (a Server API Key must be obtained from Google's Webmaster Tools).

# Custom filters

The following filters are available for plugin/theme customization:
- posttotwitter_admin_menu_parent
- posttotwitter_post_types
- posttotwitter_post_statuses
- posttotwitter_excluded_posts
- posttotwitter_deactivation
- posttotwitter_uninstall
- halftheory_admin_menu_parent

# Credits

Folder "twitteroauth" copied from https://twitteroauth.com