<?php
/*
Plugin Name: Alpha cache
Plugin URI: http://wordpress.org/extend/plugins/alpha-cache/
Description: Cache wordpress plug-in. Its makes your WP fast and your blog life easy.
Author: shra <to@shra.ru>
Author URI: http://shra.ru
Version: 1.1.002
*/ 
 
class AlphaCacheClass 
{
	var $active;
	var $ac_set; //settings
	var $timer;  //store timer value here
	
    public function __construct()
    {
		$this->timer = microtime(true);
		$this->active = true;
		//Actions
		add_action('admin_menu', array($this, '_add_menu'));
		add_action('init', array($this, 'init_hook'), 0);
		
		$this->ac_set = get_option('alpha_cache_settings');

		//Activity hooks
		if (!empty($this->ac_set['chTRACK'])) {
			add_action('delete_post', array($this, 'post_hook'));
			add_action('post_updated', array($this, 'post_hook'));
		
			add_action('wp_set_comment_status', array($this, 'comment_status_hook'));
			add_action('wp_insert_comment', array($this, 'comment_status_hook'));
			add_action('trash_comment', array($this, 'comment_status_hook'));
			add_action('spam_comment', array($this, 'comment_status_hook'));
			add_action('edit_comment', array($this, 'comment_status_hook'));
		}

	}

	/* comment status hook */
	public function comment_status_hook($comment_id) {
		global $wpdb;
		
		$comment_id += 0;
		$post_id = $wpdb->get_var("SELECT comment_post_ID FROM {$wpdb->prefix}comments WHERE comment_ID = {$comment_id}");
		$uri = $this->posturi($post_id);
		$this->delete_cache($uri);
	}

	/* site relative uri - get by post_id */
	static function posturi($post_id) {
		$uri = get_permalink($post_id);
		$a = parse_url($uri);
		unset($a['scheme'], $a['host'], $a['fragment']);
		if (!empty($a['query'])) $a['query'] = '?' . $a['query'];
		return implode('', $a);
	}
	
	/* post hook */
	public function post_hook($post_id) {
		$uri = $this->posturi($post_id);
		$this->delete_cache($uri);
	}
	
	/* admin_menu hook */
	public function _add_menu() {
		add_options_page('Alpha Cache', 'Alpha Cache', 8, __FILE__, array($this, '_options_page'));
	}

	/* output buffer hook */
	public function call_back_ob($data) {
		$this->set_cache($_SERVER['REQUEST_URI'], $data);
		return $data;
	}
	
	/* init hook */
	public function init_hook() {
		global $user_ID, $user_login;
		//can do cache?
		$uri = $_SERVER['REQUEST_URI'];
		
		//check URL list
		$u = explode("\n", $this->ac_set['avoid_urls']);
		foreach($u as $v) {
			$v = trim($v);
			if ($v && preg_match("#{$v}#is", $uri, $m)) {
				$this->active = false;
				break;
			}
		}

		//cache for anonymous users only
		if ($this->active && !empty($this->ac_set['chAnon']) && $user_ID > 0) {
			$this->active = false;
		}
		
		if ($this->active && !empty($user_login)) {
			//check users list
			$u = split("[\s]*,[\s]*", $this->ac_set['users_nocache']);
			if (in_array($user_login, $u)) {
				$this->active = false;
			}
		}
		
		if ($this->active) {		
			/* check post vars */
			if ($this->active && !empty($_POST) && !empty($this->ac_set['chPOST'])) {
				$this->active = false;
				//allow any kind of form to do what they do
			}
		}
		
		if ($this->active) {
			//look to cache
			if (($data = $this->get_cache($uri)) !== false) {
				$this->stat_hit();
				global $wpdb;
				echo $data . "\n<!-- Alpha cache content. Generated from cache in " . (microtime(true) - $this->timer) . ' s. '
					. ' DB queries count : ' . $wpdb->num_queries . ' -->';
				die();
			} 

			$this->stat_miss();
			//start buffering
			ob_start(array($this, 'call_back_ob'));
		}
	}
	
	/* successful hit to cache */
	function stat_hit() {
		if (!empty($this->ac_set['doStat'])) {
			$this->ac_set['hits'] += 1;
			update_option('alpha_cache_settings', $this->ac_set);
		}
	}

	/* miss to cache */
	function stat_miss() {
		if (!empty($this->ac_set['doStat'])) {
			$this->ac_set['miss'] += 1;
			update_option('alpha_cache_settings', $this->ac_set);
		}
	}
	
	static function getkey($uri) {
		return md5($uri);
	}

	private function delete_cache($uri) {
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->prefix}cache_alpha WHERE debug LIKE '" . mysql_escape_string($uri).  "%%'");
	}
	
	private function get_cache($uri) {
		global $wpdb, $user_ID;
		$key = $this->getkey($uri);
		
		$user_ID += 0;
		$r = $wpdb->get_row("SELECT pagedata FROM {$wpdb->prefix}cache_alpha 
			WHERE pagekey = '{$key}' AND uid = {$user_ID} AND expiretime > " . time());
		if ($r === null) {
			return false;
		} else {
			return $r->pagedata;
		}
	}
	
	private function set_cache($uri, $data) {
		if (empty($data)) return false;
		global $wpdb, $user_ID;
		$key = $this->getkey($uri);
		$wpdb->replace($wpdb->prefix . 'cache_alpha', array('pagekey' => $key, 'pagedata' => $data, 'uid' => $user_ID + 0, 'debug' => $uri,
				'expiretime' => time() + $this->ac_set['cache_lifetime']));
		return true;
	}
	
	//clean up cache table and optimize it
	public function maintain_db() {
		global $wpdb;
		$t = time();
		if ($this->ac_set['last-maintain'] + $this->ac_set['dbmaintain_period'] < $t) {
			$wpdb->query("DELETE FROM `{$wpdb->prefix}cache_alpha` WHERE expiretime < $t");
			$wpdb->query("OPTIMIZE TABLE `{$wpdb->prefix}cache_alpha`");
			$this->ac_set['last-maintain'] = $t;
			update_option('alpha_cache_settings', $this->ac_set);
		}
	}
	
    /* Options admin page */
    public function _options_page() {
		global $wpdb;
	
		switch ($_POST['action']) {
		case 'save_cache_settings':
			//check & store new values
			unset($_POST['action'], $_POST['sbm']);
			if ($_POST['cache_lifetime'] < 15) {
				echo '<div class="error>' . __('Lifetime period too short. I set minimum - 15 s.') . '</div>';
				$_POST['cache_lifetime'] = 15;
			}
			unset($_POST['action'], $_POST['sbm'], $_POST['users']);
			if ($_POST['dbmaintain_period'] < 3600) {
				echo '<div class="error>' . __('Maintain period too short. I set minimum - 1 hour.') . '</div>';
				$_POST['dbmaintain_period'] = 3600;
			}			
			update_option('alpha_cache_settings', $_POST);
			$this->ac_set = $_POST;
			echo '<div class="updated"><p>' . __("Setting are updated.") . '</p></div>';
			
			break;
		case 'do_some_actions':
			//do some actions
			switch($_POST['commandToDo']) {
			case 'clear statistics':
				$this->ac_set['hits'] = 0;
				$this->ac_set['miss'] = 0;
				break;
			case 'clear cache data':
				$wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}cache_alpha`");
				break;
			case 'load defaults':
				$new_set = $this->default_settings();
				$new_set['hits'] = $this->ac_set['hits'];
				$new_set['miss'] = $this->ac_set['miss'];
				$this->ac_set = $new_set;
				print_r($new_set);
				break;
			}
			
			update_option('alpha_cache_settings', $this->ac_set);
		}
		
		$acs = $this->ac_set;

?>
<div class="wrap">
	<h2><?=__('Alpha cache settings');?></h2>
	<form method="post">
	<input type="hidden" name="action" value="save_cache_settings" />
	<input type="hidden" name="last-maintain" value="<?=$acs['last-maintain']?>" />
	<fieldset class="options">
		<legend></legend>
		<table border=0 cellspacing=0 cellpadding=0 width=700>
		<tr>
			<td colspan=3>
			<label for="avoid_urls"><?=__('Set rules to avoid caching necessary urls')?></label><br />
			<small><?=__('One line - one rule, here you should use <a target="_blank" href="http://www.php.net/manual/en/pcre.pattern.php">PCRE Patterns</a>.')?></small><br />
			<textarea name="avoid_urls" rows="5" cols="60"><?=htmlspecialchars($acs['avoid_urls'])?></textarea><br />
			</td>
		</tr>
		<tr valign="top">
			<td>
			<label for="users_nocache"><?=__('Don`t cache these users')?></label><br />
			<small><?=__('Input logins separated by comma or use user`s list.');?></small><br />
			<textarea id="users_nocache" name="users_nocache" rows="5" cols="60"><?=htmlspecialchars($acs['users_nocache'])?></textarea>
			</td>
			<td>
			<br/>
			<small><?=__('You can use multi-select.')?></small><br />
			<select id="user_selector" name="users" multiple size="5" style="width: 350px;">
<?
	$rows = $wpdb->get_results("SELECT ID, user_login, user_email FROM {$wpdb->prefix}users ORDER BY user_login");
	foreach($rows as $v) {
		echo "<option value=\"{$v->user_login}\">{$v->user_login} ({$v->user_email})</option>";
	}
?>			
			</select><br />
			<input type="button" class="button" onclick="
	var slk = document.getElementById('user_selector');
	var st = Array();
	
	for(var i = 0; i<slk.options.length; i++) 
		if (slk.options[i].selected) {
			st[st.length] = slk.options[i].value;
		}
	var txa = document.getElementById('users_nocache');
	var tagList = txa.value.split(/\s*,\s*/);

	if (tagList.length == 1 && tagList[0] == '') tagList = Array();
	
	for(j = 0; j < st.length; j++) {
		var exst = false;

		for(i = 0; i < tagList.length; i++) {
			if (tagList[i] == st[j]) {
				exst = true;
			}
		}
		if (!exst) {
			tagList[tagList.length] = st[j];
		}
	}

	txa.value = tagList.join(', ');
				" value="<?=__('Add to list')?>" />
			</td>
		</tr>

		<tr valign="top">
			<td colspan=3>
			<input type="checkbox" name="doStat" value="1" <?=empty($acs['doStat']) ? '' : 'checked' ?> />
			<label for="doStat"><?=__('Count hits and misses to cache.')?></label><br /><i>
<?
	if ($acs['hits'] + $acs['miss']) {
		$total = $acs['hits'] + $acs['miss'];
		$ratio = sprintf("%01.2f", $acs['hits'] / $total * 100);
		
		echo __("We have $ratio % of cached queries of $total total requests.");
	} else {
		echo __("We have no statistics yet.");
	}
	
	echo "</i><br />";
	
	$rows = $wpdb->get_results("
		SELECT ALPHA.uid, COUNT(*) as NN, US.user_login, US.user_email
		FROM {$wpdb->prefix}cache_alpha AS ALPHA
		LEFT JOIN {$wpdb->prefix}users US ON US.ID = ALPHA.uid
		WHERE expiretime > " . time() . "
		GROUP BY ALPHA.uid, US.user_login, US.user_email
		ORDER BY user_login	");

	echo "<table border=1 cellpadding=5 cellspacing=0 style='border-collapse: collapse'><tr><th>" . __('User name') . "</th><th>" . __('Cached pages') . "</th></tr>";
	$total = 0;
	foreach($rows as $v) {
		echo "<tr><td>" . ($v->uid ? htmlspecialchars($v->user_login) : __('Anonymous')) . "</td><td align=right>" . $v->NN .  "</td></tr>";
		$total += $v->NN;
	}
	
	echo "<tr><th>" . __('Total') . ":</th><td align=right>$total</td></tr></table>";
?>
			</td>
		</tr>

		<tr valign="top">
			<td colspan=3>
			<input type="checkbox" name="chAnon" value="1" <?=empty($acs['chAnon']) ? '' : 'checked'?> />
			<label for="chAnon"><?=__('Do cache only for anonymous users.')?></label><br />
			</td>
		</tr>		
		
		<tr valign="top">
			<td colspan=3>
			<input type="checkbox" name="chPOST" value="1" <?=empty($acs['chPOST']) ? '' : 'checked'?> />
			<label for="chPOST"><?=__('Don`t use cache on POST request.')?></label><br />
			</td>
		</tr>

		<tr valign="top">
			<td colspan=3>
			<input type="checkbox" name="chTRACK" value="1" <?=empty($acs['chTRACK']) ? '' : 'checked' ?> />
			<label for="chTRACK"><?=__('Clean cache for updated posts/comments')?></label>
			<hr />
			</td>
		</tr>

		<tr valign="top">
			<td >
			<label for="cache_lifetime"><?=__('Cache lifetime')?></label><br />
			<small><?=__('Setup life time of single cached page in seconds.');?></small>
			</td><td>
			<input type="text" style="text-align: right;" name="cache_lifetime" size="10" value="<?=htmlspecialchars($acs['cache_lifetime'])?>" /> <?=__('s.')?>
			</td>
		</tr>
		<tr valign="top">
			<td >
			<label for="dbmaintain_period"><?=__('Maintain DB period')?></label><br />
			<small><?=__('Time between checks and clean-ups of cache database. All expired cache data will be removed, cache table will be optimized.');?></small>
			</td><td>
			<input type="text" style="text-align: right;" name="dbmaintain_period" size="10" value="<?=htmlspecialchars($acs['dbmaintain_period'])?>" /> <?=__('s.')?>
			</td>
		</tr>
		<tr>
			<td colspan="3">
			<input type="submit" class="button-primary" name="sbm" value="<?=__('Save changes')?>" />
			</td>
		</tr>
		</table>
	</fieldset>
	</form>
	
	<h2><?=__('Do some actions');?></h2>

	<form method="post">
	<input type="hidden" name="action" value="do_some_actions" />	
	<select name="commandToDo">
		<option value="">-= <?=__('Select one')?> =-</option>
		<option value="clear statistics"><?=__('Clear statistics')?></option>
		<option value="clear cache data"><?=__('Clear cache data')?></option>
		<option value="load defaults"><?=__('Load defaults')?></option>
	</select>
	<input type="submit" class="button-primary" name="sbm" value="<?=__('Do')?>" />
	</form>

</div>
<?php
    }

	/* install actions (when activate first time) */
    static function install() {
		global $wpdb;
		
		//create cache table
		$wpdb->query("
			DROP TABLE IF EXISTS `cache_alpha`");
		$wpdb->query("
			DROP TABLE IF EXISTS `{$wpdb->prefix}cache_alpha`");
		$wpdb->query("
CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cache_alpha` (
  `pagekey` varchar(32) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `uid` int(11) NOT NULL,
  `pagedata` mediumtext CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `expiretime` int(11) NOT NULL,
  `debug` varchar(250) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`pagekey`,`uid`),
  KEY `uid` (`uid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

		//set defaults
		add_option('alpha_cache_settings', AlphaCacheClass::default_settings() );
	}

	static function default_settings() {
		return array(
			'cache_lifetime' => 3600, 
			'dbmaintain_period' => 43200,
			//no cache on admin's pages
			'avoid_urls' => '^/wp-admin/
^/wp-login.php', 
			'users_nocache' => '',
			'chPOST' => 1,
			'doStat' => '',
			'chTRACK' => 1,
			'chAnon' => '',
			'last-maintain' => time() );
	}
	
	/* uninstall hook */
    static function uninstall() {
		global $wpdb;

		$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}cache_alpha`");
		delete_option('alpha_cache_settings');
	}

} 

register_uninstall_hook( __FILE__, array('AlphaCacheClass', 'uninstall'));
register_activation_hook( __FILE__, array('AlphaCacheClass', 'install') );

if (class_exists("AlphaCacheClass")) {
	$alpha_cache_obj = new AlphaCacheClass();
}

if (isset($alpha_cache_obj)) {
	//to do:
	;

} // if (isset($alpha_cache_obj))