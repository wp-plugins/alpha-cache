=== Alpha Cache ===
Contributors: Shra
Donate link: http://shra.ru/2012/09/alpha-cache-modul-keshirovaniya-dlya-wordpress
Tags: cache, advanced cache, benchmark, benchmarking, cache, cacheing, caching, cash, execution, highly extensible, includes extensive documentation, loading, options panel included, performance, quick cache, quickcache, speed, super cache, wp-cache
Requires at least: 3.0
Tested up to: 3.4
Stable tag: 1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin cache web requests and store static html code to your database.

== Description ==

This plugin cache web requests and store static html code to your database. You 
can configure what urls do not to cache, witch users don't to cache and ect. 
After first web request plugin creates a cache of this requested webpage, and 
on second request your webserver will serve html code stored in database cache 
table instead of processing the comparatively heavier and more expensive WordPress 
PHP scripts.

It has many options to drive caching process.

**You can:**

* ... define filter by regular expressions pattern to exclude 
necessary urls from cache.
* ... fill user's list to define who will not cached.
* ... set flag to process POST request without caching.
* ... enable cache only for anonymous users
* ... set force cleaning necessary cached pages on page/comment updating.

and etc.

== Installation ==

1. Upload and extract arhive files to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Check plugin's configuration through wp-admin option's section. Default 
configuration is situatable for most cases.
4. And enjoy, of course!

== Screenshots ==

1. Alpha Cache option's page

== Changelog ==

= 1.1.001 =
* Add usage table_prefix

= 1.1 =
* Add compatibility for some WP configurations.
* Fix some bugs
* Add default rule for wp-login.php to avoid cache this script. You can add this rule manually or use "Load defaults".
* Expand statistics. Now you can see table with actual number of cached pages by each user.
* Remove some debug code

= 1.0 =
First version, here is everything we have.

== Upgrade Notice ==

= 1.1 =
Delete old version before install this new.