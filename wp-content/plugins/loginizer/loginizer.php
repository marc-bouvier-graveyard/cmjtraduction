<?php
/**
 * @package loginizer
 * @version 1.4.2
 */
/*
Plugin Name: Loginizer
Plugin URI: http://wordpress.org/extend/plugins/loginizer/
Description: Loginizer is a WordPress plugin which helps you fight against bruteforce attack by blocking login for the IP after it reaches maximum retries allowed. You can blacklist or whitelist IPs for login using Loginizer.
Version: 1.0
Author: Raj Kothari
Author URI: http://www.loginizer.com
License: GPLv3 or later
*/

/*
Copyright (C) 2013  Raj Kothari (email : support@loginizer.com)
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if(!function_exists('add_action')){
	echo 'You are not allowed to access this page directly.';
	exit;
}

define('lz_version', '1.0');

include_once('functions.php');

// Ok so we are now ready to go
register_activation_hook( __FILE__, 'loginizer_activation');

function loginizer_activation(){

global $wpdb;

$sql = array();
$sql[] = "
--
-- Table structure for table `".$wpdb->prefix."lz_failed_logs`
--

CREATE TABLE `".$wpdb->prefix."lz_failed_logs` (
  `username` varchar(255) NOT NULL DEFAULT '',
  `time` int(10) NOT NULL DEFAULT '0',
  `count` int(10) NOT NULL DEFAULT '0',
  `lockout` int(10) NOT NULL DEFAULT '0',
  `ip` varchar(255) NOT NULL DEFAULT '',
  UNIQUE KEY `ip` (`ip`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;";

$sql[] = "
--
-- Table structure for table `".$wpdb->prefix."lz_options`
--

CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lz_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `option_name` varchar(255) NOT NULL,
  `option_value` varchar(255) NOT NULL,
  `updated` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;";

$sql[] = "
--
-- Table structure for table `".$wpdb->prefix."lz_iprange`
--

CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lz_iprange` (
  `rid` int(10) NOT NULL AUTO_INCREMENT,
  `start` bigint(20) NOT NULL,
  `end` bigint(20) NOT NULL,
  `blacklist` tinyint(2) NOT NULL DEFAULT '0',
  `whitelist` tinyint(2) NOT NULL DEFAULT '0',
  `date` int(10) NOT NULL,
  PRIMARY KEY (`rid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;";

	foreach($sql as $sk => $sv){
		$wpdb->query($sv);
	}
	
	add_option('lz_version', lz_version);

}

add_action( 'plugins_loaded', 'loginizer_load_plugin' );

function loginizer_update_check(){

global $wpdb;

	$sql = array();
	$current_version = get_option('lz_version');

	if($current_version < lz_version){
		foreach($sql as $sk => $sv){
			$wpdb->query($sv);
		}

		update_option('lz_version', lz_version);
	}

}

function loginizer_load_plugin(){
	
	global $lz_globals;
	
	loginizer_update_check();
	
	$lz_globals = array();
	$lz_globals['lz_max_retries'] = lz_get_option('lz_max_retries', 3);
	$lz_globals['lz_lockout_time'] = lz_get_option('lz_lockout_time', 900); // 15 minutes
	$lz_globals['lz_max_lockouts'] = lz_get_option('lz_max_lockouts', 5);
	$lz_globals['lz_lockouts_extend'] = lz_get_option('lz_lockouts_extend', 86400); // 24 hours
	$lz_globals['lz_reset_retries'] = lz_get_option('lz_reset_retries', 86400); // 24 hours
	$lz_globals['lz_last_reset'] = lz_get_option('lz_last_reset', 0); // 24 hours
	$lz_globals['lz_notify_email'] = lz_get_option('lz_notify_email', 0);
	
	// Clear retries
	if((time() - $lz_globals['lz_last_reset']) >= $lz_globals['lz_reset_retries']){
		lz_reset_retries();
	}
	
	$lz_globals['current_ip'] = lz_getip();

	/* Filters and actions */	
	add_filter('wp_authenticate_user', 'lz_wp_authenticate_user', 99999, 2);// This is used for additional validation
	add_action('wp_login_failed', 'lz_login_failed');// Update our records login failed
	add_action('wp_authenticate', 'lz_wp_authenticate', 10, 2);// Use this to verify before WP tries to login
	add_action('login_errors', 'lz_update_error_msg');// Update Error message

}

function lz_wp_authenticate_user($user, $username){
	
	global $lz_error, $lz_cannot_login;
	
	if(is_wp_error($user) || empty($lz_cannot_login)){
		return $user;
	}
	
	$error = new WP_Error();
	$error->add('ip_blocked', implode('', $lz_error));
	return $error;
}

function lz_wp_authenticate($username, $password){
		
	global $lz_error, $lz_cannot_login, $lz_user_pass;
	
	if(!empty($username) && !empty($password)){	
		$lz_user_pass = 1;
	}
	
	// Are you whitelisted ?
	if(lz_is_whitelisted()){
		return $username;
	}
	
	// Are you blacklisted ?
	if(lz_is_blacklisted()){	
		$lz_cannot_login = 1;		
		$error = new WP_Error();
		$error->add('ip_blacklisted', implode('', $lz_error));
		return $error;
	}
	
	if(lz_can_login()){
		return $username;
	}
	
	$lz_cannot_login = 1;
	
	$error = new WP_Error();
	$error->add('ip_blocked', implode('', $lz_error));
	return $error;
}

function lz_can_login(){
	
	global $wpdb, $lz_globals, $lz_error;
	
	// Get the logs
	$result = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_failed_logs` WHERE `ip` = '".$lz_globals['current_ip']."';");
		
	if(!empty($result['count']) && $result['count'] >= $lz_globals['lz_max_retries']){
		
		// Has he reached max lockouts ?
		if($result['lockout'] >= $lz_globals['lz_max_lockouts']){
			$lz_globals['lz_lockout_time'] = $lz_globals['lz_lockouts_extend'];
		}
		
		// Is he in the lockout time ?
		if($result['time'] >= time() - $lz_globals['lz_lockout_time']){
			$banlift = ceil((($result['time'] + $lz_globals['lz_lockout_time']) - time()) / 60);
			
			//echo 'Current Time '.date('m/d/Y H:i:s', time()).'<br />';
			//echo 'Last attempt '.date('m/d/Y H:i:s', $result['time']).'<br />';
			//echo 'Unlock Time '.date('m/d/Y H:i:s', $result['time'] + $lz_globals['lz_lockout_time']).'<br />';
			
			$_time = $banlift.' minute(s)';
			
			if($banlift > 60){
				$banlift = ceil($banlift / 60);
				$_time = $banlift.' hour(s)';
			}
			
			$lz_error['ip_blocked'] = 'You have exceeded maximum login retries<br /> Please try after '.$_time;
			
			return false;
		}
	}
	
	if(!empty($result['count']) && $result['count'] < $lz_globals['lz_max_retries']){
		$lz_globals['lz_retries_left'] = $lz_globals['lz_max_retries'] - $result['count'];
	}
	
	return true;
}

function lz_is_blacklisted(){
	
	global $wpdb, $lz_globals, $lz_error;
	
	// Get the logs
	$result = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_iprange` WHERE (".ip2long($lz_globals['current_ip'])." BETWEEN `start` AND `end`) AND `blacklist` = '1';");
		
	// You are blacklisted
	if(!empty($result)){
		$lz_error['ip_blacklisted'] = 'Your IP has been blacklisted';
		return true;
	}
	
	return false;
}

function lz_is_whitelisted(){
	
	global $wpdb, $lz_globals, $lz_error;
	
	// Get the logs
	$result = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_iprange` WHERE (".ip2long($lz_globals['current_ip'])." BETWEEN `start` AND `end`) AND `whitelist` = '1';");
		
	// You are whitelisted
	if(!empty($result)){
		return true;
	}
	
	return false;
}

function lz_login_failed($username){
	
	global $wpdb, $lz_globals, $lz_cannot_login;
	
	if(empty($lz_cannot_login)){
		$result = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_failed_logs` WHERE `ip` = '".$lz_globals['current_ip']."';");
		
		if(!empty($result)){
			$lockout = floor(($result['count'] / $lz_globals['lz_max_retries']));
			$sresult = $wpdb->query("UPDATE `".$wpdb->prefix."lz_failed_logs` SET `username` = '".$username."', `time` = '".time()."', `count` = `count`+1, `lockout` = '".$lockout."' WHERE `ip` = '".$lz_globals['current_ip']."';");
			
			// Do we need to email admin ? 
			$lz_globals['lz_notify_email'] = 4;
			if(!empty($lz_globals['lz_notify_email']) && $lockout >= $lz_globals['lz_notify_email']){
				
				$sitename = lz_is_multisite() ? get_site_option('site_name') : get_option('blogname');
				$mail = array();
				$mail['to'] = lz_is_multisite() ? get_site_option('admin_email') : get_option('admin_email');	
				$mail['subject'] = 'Failed Login Attempts from IP '.$lz_globals['current_ip'].' ('.$sitename.')';
				$mail['message'] = 'Hi,

'.($result['count']+1).' failed login attempts and '.$lockout.' lockout(s) from IP '.$lz_globals['current_ip'].'

Last Login Attempt : '.date('d/m/Y H:i:s', time()).'
Last User Attempt : '.$username.'
IP has been blocked until : '.date('m/d/Y H:i:s', time() + $lz_globals['lz_lockout_time']).'

Regards,
Loginizer';

				@wp_mail($mail['to'], $mail['subject'], $mail['message']);
			}
		}else{
			$result = $wpdb->query("INSERT INTO `".$wpdb->prefix."lz_failed_logs` SET `username` = '".$username."', `time` = '".time()."', `count` = '1', `ip` = '".$lz_globals['current_ip']."', `lockout` = '0';");
		}
	}
}

function lz_update_error_msg($default_msg){
	
	global $wpdb, $lz_globals, $lz_user_pass, $lz_cannot_login;
	
	$msg = '';
	
	if(!empty($lz_user_pass) && empty($lz_cannot_login)){
		
		$msg = '<b>ERROR:</b> Incorrect Username or Password';	
		
		if(!empty($lz_globals['lz_retries_left'])){
			$msg .= '<br /><b>'.$lz_globals['lz_retries_left'].'</b> attempt(s) left';
		}
	}
	
	if(!empty($msg)){
		return $msg;		
	}else{
		return $default_msg;
	}
	
}

function lz_reset_retries(){
	
	global $wpdb, $lz_globals;
	
	$deltime = time() - $lz_globals['lz_reset_retries'];	
	$result = $wpdb->query("DELETE FROM `".$wpdb->prefix."lz_failed_logs` WHERE `time` <= '".$deltime."';");
	
	lz_update_option('lz_last_reset', time());
	
}

// Add settings link on plugin page
function lz_settings_link($links) { 
  $settings_link = '<a href="options-general.php?page=loginizer">Settings</a>'; 
  array_unshift($links, $settings_link); 
  return $links; 
}
 
$plugin = plugin_basename(__FILE__); 
add_filter("plugin_action_links_$plugin", 'lz_settings_link' );

add_action('admin_menu', 'loginizer_admin_menu');

function loginizer_admin_menu() {
	global $wp_version;

	// Modern WP?
	if (version_compare($wp_version, '3.0', '>=')) {
	    add_options_page('Loginizer', 'Loginizer', 'manage_options', 'loginizer', 'loginizer_option_page');
	    return;
	}

	// Older WPMU?
	if (function_exists("get_current_site")) {
	    add_submenu_page('wpmu-admin.php', 'Loginizer', 'Loginizer', 9, 'loginizer', 'loginizer_option_page');
	    return;
	}

	// Older WP
	add_options_page('Loginizer', 'Loginizer', 9, 'loginizer', 'loginizer_option_page');
}

function loginizer_option_page(){

	global $wpdb, $wp_roles, $lz_globals;
	 
	if(!current_user_can('manage_options')){
		wp_die('Sorry, but you do not have permissions to change settings.');
	}

	/* Make sure post was from this page */
	if(count($_POST) > 0){
		check_admin_referer('loginizer-options');
	}
	
	if(isset($_POST['save_lz'])){
		
		$lz_max_retries = (int) lz_optpost('lz_max_retries');
		$lz_lockout_time = (int) lz_optpost('lz_lockout_time');
		$lz_max_lockouts = (int) lz_optpost('lz_max_lockouts');
		$lz_lockouts_extend = (int) lz_optpost('lz_lockouts_extend');
		$lz_reset_retries = (int) lz_optpost('lz_reset_retries');
		$lz_notify_email = (int) lz_optpost('lz_notify_email');
		
		$lz_lockout_time = $lz_lockout_time * 60;
		$lz_lockouts_extend = $lz_lockouts_extend * 60 * 60;
		$lz_reset_retries = $lz_reset_retries * 60 * 60;
		
		if(empty($error)){
			
			lz_update_option('lz_max_retries', $lz_max_retries);
			lz_update_option('lz_lockout_time', $lz_lockout_time);
			lz_update_option('lz_max_lockouts', $lz_max_lockouts);
			lz_update_option('lz_lockouts_extend', $lz_lockouts_extend);
			lz_update_option('lz_reset_retries', $lz_reset_retries);
			lz_update_option('lz_notify_email', $lz_notify_email);
			
			$saved = true;
			
		}else{
			lz_report_error($error);
		}
	
		if(!empty($notice)){
			lz_report_notice($notice);	
		}
			
		if(!empty($saved)){
			echo '<div id="message" class="updated fade"><p>'
				. __('The settings were saved successfully', 'loginizer')
				. '</p></div>';
		}
	
	}
	
	if(isset($_GET['delid'])){
		
		$delid = (int) lz_optreq('delid');
		
		$wpdb->query("DELETE FROM ".$wpdb->prefix."lz_iprange WHERE `rid` = '".$delid."'");
		echo '<div id="message" class="updated fade"><p>'
			. __('IP range has been deleted successfully', 'loginizer')
			. '</p></div>';	
	}
	
	if(isset($_POST['blacklist_iprange'])){

		$start_ip = lz_optpost('start_ip');
		$end_ip = lz_optpost('end_ip');
		
		if(empty($start_ip)){
			$error[] = 'Please enter the Start IP';
		}
		
		// If no end IP we consider only 1 IP
		if(empty($end_ip)){
			$end_ip = $start_ip;
		}
				
		if(!lz_valid_ip($start_ip)){
			$error[] = 'Please provide a valid start IP';
		}
		
		if(!lz_valid_ip($end_ip)){
			$error[] = 'Please provide a valid end IP';			
		}
			
		if(ip2long($start_ip) > ip2long($end_ip)){
			$error[] = 'The End IP cannot be smaller than the Start IP';
		}
		
		if(empty($error)){
			
			// This is to check if there is any other range exists with the same Start or End IP
			$ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`blacklist` = '1' AND
			(`start` BETWEEN '".ip2long($start_ip)."' AND '".ip2long($end_ip)."'
			OR `end` BETWEEN '".ip2long($start_ip)."' AND '".ip2long($end_ip)."');";
			
			$ip_exists = $wpdb->get_results($ip_exists_query);
			//print_r($ip_exists);
			
			if(!empty($ip_exists)){
				$error[] = 'The Start IP or End IP submitted conflicts with an existing IP range!';
			}
			
			// This is to check if there is any other range exists with the same Start IP
			$start_ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`blacklist` = '1' AND
			('".ip2long($start_ip)."' BETWEEN `start` AND `end`);";
			
			$start_ip_exists = $wpdb->get_results($start_ip_exists_query);
			//print_r($start_ip_exists);
			
			if(!empty($start_ip_exists)){
				$error[] = 'The Start IP is present in an existing range!';
			}
			
			// This is to check if there is any other range exists with the same End IP
			$end_ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`blacklist` = '1' AND
			('".ip2long($end_ip)."' BETWEEN `start` AND `end`);";
			
			$end_ip_exists = $wpdb->get_results($end_ip_exists_query);
			//print_r($end_ip_exists);
			
			if(!empty($end_ip_exists)){
				$error[] = 'The End IP is present in an existing range!';
			}
		
			if(empty($error)){
				
				$options = array();
				$options['start'] = ip2long($start_ip);
				$options['end'] = ip2long($end_ip);
				$options['blacklist'] = 1;
				$options['whitelist'] = 0;
				$options['date'] = date('Ymd');
				
				$wpdb->insert($wpdb->prefix.'lz_iprange', $options);
				
				if(!empty($wpdb->insert_id)){
					echo '<div id="message" class="updated fade"><p>'
						. __('Blacklist IP range added successfully', 'loginizer')
						. '</p></div>';
				}else{
					echo '<div id="message" class="updated fade"><p>'
						. __('There were some errors while adding the blacklist IP range', 'loginizer')
						. '</p></div>';
				}
				
			}
			
		}
		
		if(!empty($error)){
			lz_report_error($error);			
		}
	}
	
	if(isset($_POST['whitelist_iprange'])){

		$start_ip = lz_optpost('start_ip_w');
		$end_ip = lz_optpost('end_ip_w');
		
		if(empty($start_ip)){
			$error[] = 'Please enter the Start IP';
		}
		
		// If no end IP we consider only 1 IP
		if(empty($end_ip)){
			$end_ip = $start_ip;
		}
				
		if(!lz_valid_ip($start_ip)){
			$error[] = 'Please provide a valid start IP';
		}
		
		if(!lz_valid_ip($end_ip)){
			$error[] = 'Please provide a valid end IP';			
		}
			
		if(ip2long($start_ip) > ip2long($end_ip)){
			$error[] = 'The End IP cannot be smaller than the Start IP';
		}
		
		if(empty($error)){
			
			// This is to check if there is any other range exists with the same Start or End IP
			$ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`whitelist` = '1' AND
			(`start` BETWEEN '".ip2long($start_ip)."' AND '".ip2long($end_ip)."'
			OR `end` BETWEEN '".ip2long($start_ip)."' AND '".ip2long($end_ip)."');";
			
			$ip_exists = $wpdb->get_results($ip_exists_query);
			//print_r($ip_exists);
			
			if(!empty($ip_exists)){
				$error[] = 'The Start IP or End IP submitted conflicts with an existing IP range!';
			}
			
			// This is to check if there is any other range exists with the same Start IP
			$start_ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`whitelist` = '1' AND
			('".ip2long($start_ip)."' BETWEEN `start` AND `end`);";
			
			$start_ip_exists = $wpdb->get_results($start_ip_exists_query);
			//print_r($start_ip_exists);
			
			if(!empty($start_ip_exists)){
				$error[] = 'The Start IP is present in an existing range!';
			}
			
			// This is to check if there is any other range exists with the same End IP
			$end_ip_exists_query = "SELECT * FROM ".$wpdb->prefix."lz_iprange WHERE 
			`whitelist` = '1' AND
			('".ip2long($end_ip)."' BETWEEN `start` AND `end`);";
			
			$end_ip_exists = $wpdb->get_results($end_ip_exists_query);
			//print_r($end_ip_exists);
			
			if(!empty($end_ip_exists)){
				$error[] = 'The End IP is present in an existing range!';
			}
		
			if(empty($error)){
				
				$options = array();
				$options['start'] = ip2long($start_ip);
				$options['end'] = ip2long($end_ip);
				$options['blacklist'] = 0;
				$options['whitelist'] = 1;
				$options['date'] = date('Ymd');
				
				$wpdb->insert($wpdb->prefix.'lz_iprange', $options);
				
				if(!empty($wpdb->insert_id)){
					echo '<div id="message" class="updated fade"><p>'
						. __('Whitelist IP range added successfully', 'loginizer')
						. '</p></div>';
				}else{
					echo '<div id="message" class="updated fade"><p>'
						. __('There were some errors while adding the whitelist IP range', 'loginizer')
						. '</p></div>';
				}
				
			}
			
		}
		
		if(!empty($error)){
			lz_report_error($error);			
		}
	}
	
	// Get the logs
	$result = array();
	$result = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_failed_logs` ORDER BY `count` DESC LIMIT 0, 10;", 1);
	//print_r($result);
	
	// Get the Blacklist IP ranges
	$blacklist_ips = array();
	$blacklist_ips = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_iprange` WHERE `blacklist` = 1;", 1);
	//print_r($blacklist_ips);
	
	// Get the Whitelist IP ranges
	$whitelist_ips = array();
	$whitelist_ips = lz_selectquery("SELECT * FROM `".$wpdb->prefix."lz_iprange` WHERE `whitelist` = 1;", 1);
	//print_r($whitelist_ips);
	
	?>
    
	<div class="wrap">
    	<!--This is intentional-->
	<h2></h2>
	
	<h1><center><?php echo __('Loginizer','loginizer'); ?></center></h1><hr /><br />
     
	<script src="http://api.loginizer.com/news.js""></script>
	
	<h2><?php echo __('Failed Login Attempts Logs &nbsp; (Past '.($lz_globals['lz_reset_retries']/60/60).' hours)','loginizer'); ?></h2><hr /><br />
	
	<table class="wp-list-table widefat fixed users" border="0">
		<tr>
			<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('IP','loginizer'); ?></th>
			<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Last Failed Attempt  (DD/MM/YYYY)','loginizer'); ?></th>
			<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Failed Attempts Count','loginizer'); ?></th>
			<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Lockouts Count','loginizer'); ?></th>
		</tr>
		<?php
			if(empty($result)){
				echo '
				<tr>
					<td colspan="4">
						No Logs. You will see logs about failed login attempts here.
					</td>
				</tr>';
			}else{
				foreach($result as $ik => $iv){
					$status_button = (!empty($iv['status']) ? 'disable' : 'enable');
					echo '
					<tr>
						<td>
							'.$iv['ip'].'
						</td>
						<td>
							'.date('d/m/Y H:i:s', $iv['time']).'
						</td>
						<td>
							'.$iv['count'].'
						</td>
						<td>
							'.$iv['lockout'].'
						</td>
					</tr>';
				}
			}
		?>
	</table>
      <br />
	  <h2><?php echo __('Loginizer Settings','loginizer'); ?></h2><hr /><br />
      
	  <form action="options-general.php?page=loginizer" method="post" enctype="multipart/form-data">
		<?php wp_nonce_field('loginizer-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><label for="lz_max_retries"><?php echo __('Max Retries','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="3" value="<?php echo lz_optpost('lz_max_retries', $lz_globals['lz_max_retries']); ?>" name="lz_max_retries" id="lz_max_retries" /> <?php echo __('Maximum failed attempts allowed before lockout','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="lz_lockout_time"><?php echo __('Lockout Time','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="3" value="<?php echo (!empty($lz_lockout_time) ? $lz_lockout_time : $lz_globals['lz_lockout_time']) / 60; ?>" name="lz_lockout_time" id="lz_lockout_time" /> <?php echo __('minutes','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="lz_max_lockouts"><?php echo __('Max Lockouts','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="3" value="<?php echo lz_optpost('lz_max_lockouts', $lz_globals['lz_max_lockouts']); ?>" name="lz_max_lockouts" id="lz_max_lockouts" /> <?php echo __('','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="lz_lockouts_extend"><?php echo __('Extend Lockout','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="3" value="<?php echo (!empty($lz_lockouts_extend) ? $lz_lockouts_extend : $lz_globals['lz_lockouts_extend']) / 60 / 60; ?>" name="lz_lockouts_extend" id="lz_lockouts_extend" /> <?php echo __('hours. Extend Lockout time after Max Lockouts','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="lz_reset_retries"><?php echo __('Reset Retries','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="3" value="<?php echo (!empty($lz_reset_retries) ? $lz_reset_retries : $lz_globals['lz_reset_retries']) / 60 / 60; ?>" name="lz_reset_retries" id="lz_reset_retries" /> <?php echo __('hours','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="lz_notify_email"><?php echo __('Email Notification','loginizer'); ?></label></th>
			<td>
			  <?php echo __('after ','loginizer'); ?>
			  <input type="text" size="3" value="<?php echo (!empty($lz_notify_email) ? $lz_notify_email : $lz_globals['lz_notify_email']); ?>" name="lz_notify_email" id="lz_notify_email" /> <?php echo __('lockouts <br />0 to disable email notifications','loginizer'); ?>
			</td>
		  </tr>
		</table><br />
		<input name="save_lz" class="button action" value="<?php echo __('Save Settings','loginizer'); ?>" type="submit" />		
	  </form>
            
      <br /><br />
      <hr />      
	  <h2><?php echo __('Blacklist IP','loginizer'); ?></h2>
	  <?php echo __('Enter the IP you want to blacklist from login','loginizer'); ?>
	  <form action="options-general.php?page=loginizer" method="post">
		<?php wp_nonce_field('loginizer-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><label for="start_ip"><?php echo __('Start IP','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="25" value="<?php echo(lz_optpost('start_ip')); ?>" name="start_ip" id="start_ip"/> <?php echo __('Start IP of the range','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="end_ip"><?php echo __('End IP (Optional)','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="25" value="<?php echo(lz_optpost('end_ip')); ?>" name="end_ip" id="end_ip"/> <?php echo __('End IP of the range. <br />If you want to blacklist single IP leave this field blank.','loginizer'); ?> <br />
			</td>
		  </tr>
		</table><br />
		<input name="blacklist_iprange" class="button action" value="<?php echo __('Blacklist IP range','loginizer'); ?>" type="submit" />		
	  </form>
		<br />
		<table class="wp-list-table widefat fixed users" border="0">
			<tr>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Start IP','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('End IP','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Date (DD/MM/YYYY)','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Options','loginizer'); ?></th>
			</tr>
			<?php
				if(empty($blacklist_ips)){
					echo '
					<tr>
						<td colspan="4">
							No Blacklist IPs. You will see blacklisted IP ranges here.
						</td>
					</tr>';
				}else{
					foreach($blacklist_ips as $ik => $iv){
						echo '
						<tr>
							<td>
								'.long2ip($iv['start']).'
							</td>
							<td>
								'.long2ip($iv['end']).'
							</td>
							<td>
								'.date('d/m/Y', strtotime($iv['date'])).'
							</td>
							<td>
								<a class="submitdelete" href="options-general.php?page=loginizer&delid='.$iv['rid'].'" onclick="return confirm(\'Are you sure you want to delete this IP range ?\')">Delete</a>
							</td>
						</tr>';
					}
				}
			?>
         </table>
      <br />
      <hr />      
	  <h2><?php echo __('Whitelist IP','loginizer'); ?></h2>
	  <?php echo __('Enter the IP you want to whitelist for login','loginizer'); ?>
	  <form action="options-general.php?page=loginizer" method="post">
		<?php wp_nonce_field('loginizer-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><label for="start_ip_w"><?php echo __('Start IP','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="25" value="<?php echo(lz_optpost('start_ip_w')); ?>" name="start_ip_w" id="start_ip_w"/> <?php echo __('Start IP of the range','loginizer'); ?> <br />
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><label for="end_ip_w"><?php echo __('End IP (Optional)','loginizer'); ?></label></th>
			<td>
			  <input type="text" size="25" value="<?php echo(lz_optpost('end_ip_w')); ?>" name="end_ip_w" id="end_ip_w"/> <?php echo __('End IP of the range. <br />If you want to whitelist single IP leave this field blank.','loginizer'); ?> <br />
			</td>
		  </tr>
		</table><br />
		<input name="whitelist_iprange" class="button action" value="<?php echo __('Whitelist IP range','loginizer'); ?>" type="submit" />
	  </form>
		<br />
		<table class="wp-list-table widefat fixed users" border="0">
			<tr>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Start IP','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('End IP','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Date (DD/MM/YYYY)','loginizer'); ?></th>
				<th scope="row" valign="top" style="background:#EFEFEF;"><?php echo __('Options','loginizer'); ?></th>
			</tr>
			<?php
				if(empty($whitelist_ips)){
					echo '
					<tr>
						<td colspan="4">
							No Whitelist IPs. You will see whitelisted IP ranges here.
						</td>
					</tr>';
				}else{
					foreach($whitelist_ips as $ik => $iv){
						echo '
						<tr>
							<td>
								'.long2ip($iv['start']).'
							</td>
							<td>
								'.long2ip($iv['end']).'
							</td>
							<td>
								'.date('d/m/Y', strtotime($iv['date'])).'
							</td>
							<td>
								<a class="submitdelete" href="options-general.php?page=loginizer&delid='.$iv['rid'].'" onclick="return confirm(\'Are you sure you want to delete this IP range ?\')">Delete</a>
							</td>
						</tr>';
					}
				}
			?>
         </table>
		<br />
	</div>
	<?php
	
	echo '<br /><br /><hr />
	<a href="http://www.loginizer.com" target="_blank">Loginizer</a> v'.lz_version.'. <br />
	You can report any bugs <a href="http://wordpress.org/support/plugin/loginizer" target="_blank">here</a>.';
}	

// Sorry to see you going
register_uninstall_hook( __FILE__, 'loginizer_deactivation');

function loginizer_deactivation(){

global $wpdb;

	$sql = array();
	$sql[] = "DROP TABLE ".$wpdb->prefix."lz_failed_logs;";
	$sql[] = "DROP TABLE ".$wpdb->prefix."lz_options;";
	$sql[] = "DROP TABLE ".$wpdb->prefix."lz_iprange;";

	foreach($sql as $sk => $sv){
		$wpdb->query($sv);
	}
	

delete_option('lz_version');

}

?>