<?php /*

**************************************************************************

Plugin Name:  ET Anticipate Maintenance Plugin
Plugin URI:   http://www.elegantthemes.com
Version:      1.7
Description:  Maintenance Plugin
Author:       Elegant Themes
Author URI:   http://www.elegantthemes.com

**************************************************************************/

class ET_Anticipate
{
	var $_settings;
	var $_options_pagename = 'et_anticipate_options';
	var $_exception_urls = array( 'wp-login.php', 'async-upload.php', '/plugins/', 'wp-admin/', 'upgrade.php', 'trackback/', 'feed/' );
	var $location_folder;
	var $menu_page;
	var $update_name = 'Anticipate/anticipate-maintenance-plugin.php';

	function __construct()
	{
		$this->_settings = get_option('et_anticipate_settings') ? get_option('et_anticipate_settings') : array();
		$this->location_folder = trailingslashit(WP_PLUGIN_URL) . dirname( plugin_basename(__FILE__) );

		$this->_set_standart_values();

		add_action( 'admin_menu', array(&$this, 'create_menu_link') );
		add_action( 'init', array( &$this, 'maintenance_active' ), 100 );
		wp_enqueue_script('jquery');

		add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'check_plugin_updates' ) );
		add_filter( 'site_transient_update_plugins', array( &$this, 'add_plugin_to_update_notification' ) );
		add_action( 'admin_init', array( &$this, 'remove_plugin_update_info' ), 11 );
	}

	function remove_plugin_update_info(){
		$et_update_anticipate = get_site_transient( 'et_update_anticipate_plugin' );

		if ( isset( $et_update_anticipate->response[$this->update_name] ) ){
			remove_action( "after_plugin_row_" . $this->update_name, 'wp_plugin_update_row', 10, 2 );
			add_action( "after_plugin_row_" . $this->update_name, array( &$this, 'update_plugin_information' ), 10, 2 );
		}
	}

	function update_plugin_information( $file, $plugin_data ){
		# based on wp-admin/includes/update.php

		$current = get_site_transient( 'update_plugins' );
		if ( !isset( $current->response[ $file ] ) )
			return false;

		$r = $current->response[ $file ];

		$plugins_allowedtags = array('a' => array('href' => array(),'title' => array()),'abbr' => array('title' => array()),'acronym' => array('title' => array()),'code' => array(),'em' => array(),'strong' => array());
		$plugin_name = wp_kses( $plugin_data['Name'], $plugins_allowedtags );

		$et_update_anticipate = get_site_transient( 'et_update_anticipate_plugin' );
		# open handheld changelog file in TB
		$details_url = add_query_arg( array('TB_iframe' => 'true', 'width' => 1024, 'height' => 800), $et_update_anticipate->response[$this->update_name]->url );

		$wp_list_table = _get_list_table('WP_Plugins_List_Table');

		if ( is_network_admin() || !is_multisite() ) {
			echo '<tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange"><div class="update-message">';

			if ( ! current_user_can('update_plugins') )
				printf( __('There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a>.'), $plugin_name, esc_url($details_url), esc_attr($plugin_name), $r->new_version );
			else if ( empty($r->package) )
				printf( __('There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a>. <em>Automatic update is unavailable for this plugin.</em>'), $plugin_name, esc_url($details_url), esc_attr($plugin_name), $r->new_version );
			else
				printf( __('There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a> or <a href="%5$s">update automatically</a>.'), $plugin_name, esc_url($details_url), esc_attr($plugin_name), $r->new_version, wp_nonce_url( self_admin_url('update.php?action=upgrade-plugin&plugin=') . $file, 'upgrade-plugin_' . $file) );

			do_action( "in_plugin_update_message-$file", $plugin_data, $r );

			echo '</div></td></tr>';
		}
	}

	function check_plugin_updates( $update_transient ){
		global $wp_version;

		if ( !isset($update_transient->checked) ) return $update_transient;
		else $plugins = $update_transient->checked;

		$all_plugins_info = apply_filters( 'all_plugins', get_plugins() );

		$plugin_version = $all_plugins_info[$this->update_name]['Version'];

		$send_to_api = array(
			'action' => 'check_plugin_updates',
			'single_plugin_version' => $plugin_version,
			'check_single_plugin_name' => $this->update_name
		);

		$options = array(
			'timeout' => ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 3),
			'body'			=> $send_to_api,
			'user-agent'	=> 'WordPress/' . $wp_version . '; ' . home_url()
		);

		$last_update = new stdClass();

		$plugin_request = wp_remote_post( 'http://www.elegantthemes.com/api/api.php', $options );
		if ( !is_wp_error($plugin_request) && wp_remote_retrieve_response_code($plugin_request) == 200 ){
			$plugin_response = unserialize( wp_remote_retrieve_body( $plugin_request ) );
			if ( !empty($plugin_response) ) {
				$update_transient->response = array_merge(!empty($update_transient->response) ? $update_transient->response : array(),$plugin_response);
				$last_update->checked = $plugins;
				$last_update->response = $plugin_response;
			}
		}

		$last_update->last_checked = time();
		set_site_transient( 'et_update_anticipate_plugin', $last_update );

		return $update_transient;
	}

	function add_plugin_to_update_notification( $update_transient ){
		$et_update_anticipate = get_site_transient( 'et_update_anticipate_plugin' );
		if ( !is_object($et_update_anticipate) || !isset($et_update_anticipate->response) ) return $update_transient;

		// Fix for warning messages on Dashboard / Updates page
		if ( ! is_object( $update_transient ) ) {
			$update_transient = new stdClass();
		}

		$update_transient->response = array_merge(!empty($update_transient->response) ? $update_transient->response : array(), $et_update_anticipate->response);

		return $update_transient;
	}

	function add_settings_link($links) {
		$settings = '<a href="'.admin_url('options-general.php?page=et_anticipate_options').'">' . __('Settings') . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	function output_activation_warning()
	{ ?>
		<div id="message" class="error"><p>ET Anticipate plugin isn't active. Activate it here.</p></div>
	<?php }


	function create_menu_link()
	{
		$this->menu_page = add_options_page('ET Anticipate Plugin Options', 'ET Anticipate Plugin', 'manage_options',$this->_options_pagename, array(&$this, 'build_settings_page'));
		add_action( "admin_print_scripts-{$this->menu_page}", array(&$this, 'plugin_page_js') );
		add_action("admin_head-{$this->menu_page}", array(&$this, 'plugin_page_css'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'), 10, 2);
	}

	function build_settings_page()
	{
		if (!current_user_can('manage_options')) {
			wp_die( __('You do not have sufficient permissions to access this page.') );
		}

		if (isset($_REQUEST['saved'])) {
			if ( $_REQUEST['saved'] ) echo '<div id="message" class="updated fade"><p><strong>'.'ET Anticipate'.' settings saved.</strong></p></div>';
	}

		if ( isset($_POST['et_anticipate_settings_saved']) )
			$this->_save_settings_todb($_POST);
?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2>ET Anticipate Plugin Options</h2>

			<form name="et_anticipate_form" method="post">
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_logo"><?php _e( 'Logo URL' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_logo" type="text" id="et_anticipate_logo" value="<?php echo($this->_settings['et_anticipate_logo']); ?>" class="regular-text" />
							<span class="description">Input the URL to your logo image. </span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_date"><?php _e( 'Date' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_date" type="text" id="et_anticipate_date" value="<?php echo($this->_settings['et_anticipate_date']); ?>" class="regular-text" />
							<span class="description">Choose a completion date. ex: 03/16/2011 00:00</span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_complete_percent"><?php _e( 'Completed on' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_complete_percent" type="text" id="et_anticipate_complete_percent" value="<?php echo($this->_settings['et_anticipate_complete_percent']); ?>" class="small-text" />
							<span class="description">ex. 70 (results in 70%)</span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_content_pages"><?php _e( 'Slider Pages' ); ?></label>
						</th>
						<td>
							<?php
								$pages_array = get_pages();
								foreach ($pages_array as $page) {
									$checked = '';

									if (!empty($this->_settings['et_anticipate_content-pages'])) {
										 if (in_array($page->ID, $this->_settings['et_anticipate_content-pages'])) $checked = "checked=\"checked\"";
									} ?>

									<label style="padding-bottom: 5px; display: block; width: 200px; float: left;" for="<?php echo 'et_anticipate_content-pages-',$page->ID; ?>">
										<input type="checkbox" name="et_anticipate_content-pages[]" id="<?php echo 'et_anticipate_content-pages-',$page->ID; ?>" value="<?php echo ($page->ID); ?>" <?php echo $checked; ?> />
										<?php echo $page->post_title; ?>
									</label>
							<?php
								}
							?>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_twitter_url"><?php _e( 'Twitter Page Url' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_twitter_url" type="text" id="et_anticipate_twitter_url" value="<?php echo($this->_settings['et_anticipate_twitter_url']); ?>" class="regular-text code" />
							<span class="description">ex. <code>http://twitter.com/elegantthemes</code></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_facebook_url"><?php _e( 'Facebook Page Url' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_facebook_url" type="text" id="et_anticipate_facebook_url" value="<?php echo($this->_settings['et_anticipate_facebook_url']); ?>" class="regular-text code" />
							<span class="description">ex. <code>http://www.facebook.com/elegantthemes</code></span>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_rss_url"><?php _e( 'RSS Url' ); ?></label>
						</th>
						<td>
							<input name="et_anticipate_rss_url" type="text" id="et_anticipate_rss_url" value="<?php echo($this->_settings['et_anticipate_rss_url']); ?>" class="regular-text code" />
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<label for="et_anticipate_cufon"><?php _e( 'Cufon' ); ?></label>
						</th>
						<td>
							<label><input type="radio" name="et_anticipate_cufon" value="1"<?php if ($this->_settings['et_anticipate_cufon'] == 1) echo ' checked="checked"'; ?>> Activate</label><br/>
							<label><input type="radio" name="et_anticipate_cufon" value="0"<?php if ($this->_settings['et_anticipate_cufon'] == 0) echo ' checked="checked"'; ?>> Deactivate</label>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row">
							<p><?php _e( 'Emails' ); ?></p>
						</th>
						<td>
							<p><?php echo($this->_settings['et_anticipate_emails']); ?></p>
							<span class="description">Here is a list of people who have subscribed to your mailing list</span>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="et_anticipate_settings_saved" class="button-primary" value="<?php esc_attr_e( 'Save Changes' ); ?>" />
				</p>
			</form>
		</div> <!-- end .wrap -->
<?php
	}

	function plugin_page_js()
	{
		wp_enqueue_script('anticipate-admin-date', $this->location_folder . '/js/jquery-ui-1.7.3.custom.min.js');
		wp_enqueue_script('anticipate-admin-date-addon', $this->location_folder . '/js/jquery-ui-timepicker-addon.js');
		wp_enqueue_script('anticipate-admin-main', $this->location_folder . '/js/admin.js');
	}

	function plugin_page_css()
	{
?>
		<link rel="stylesheet" href="<?php echo $this->location_folder; ?>/css/jquery-ui-1.7.3.custom.css" type="text/css" />
<?php
	}

	function add_email( $email ){
		$emails = explode(",", $email);
		$valid_emails = array();
		$unique_emails = array();

		foreach($emails as $mail){
			if ( is_email(trim($mail)) ) $valid_emails[] = trim($mail);
		}

		if ( empty($valid_emails) ) return false;

		$valid_emails_string = implode(",", $valid_emails);
		if ( $this->_settings['et_anticipate_emails'] <> '' ) $valid_emails_string = ',' . $valid_emails_string;

		$this->_settings['et_anticipate_emails'] .= $valid_emails_string;
		$unique_emails = explode(",", $this->_settings['et_anticipate_emails']);
		$unique_emails = array_unique($unique_emails);

		$this->_settings['et_anticipate_emails'] = implode(",", $unique_emails);
		$this->_save_settings_todb();

		return true;
	}

	function _save_settings_todb($form_settings = '')
	{
		if ( $form_settings <> '' ) {
			unset($form_settings['et_anticipate_settings_saved']);

			$emails = $this->_settings['et_anticipate_emails'];

			$this->_settings = $form_settings;
			$this->_settings['et_anticipate_emails'] = $emails;

			#set standart values in case we have empty fields
			$this->_set_standart_values();
		}

		update_option('et_anticipate_settings', $this->_settings);
	}

	function _set_standart_values()
	{
		global $shortname;
		$logo = ( $shortname <> '' && get_option( $shortname . '_logo' ) <> '' ) ? get_option( $shortname . '_logo' ) : $this->location_folder . '/images/logo.png';

		$standart_values = array(
			'et_anticipate_logo' => $logo,
			'et_anticipate_date' => '',
			'et_anticipate_complete_percent' => '10',
			'et_anticipate_content-pages' => '',
			'et_anticipate_twitter_url' => '',
			'et_anticipate_facebook_url' => '',
			'et_anticipate_rss_url' => get_bloginfo('rss2_url'),
			'et_anticipate_cufon' => 1,
			'et_anticipate_emails' => ''
		);

		foreach ($standart_values as $key => $value){
			if ( !array_key_exists( $key, $this->_settings ) )
				$this->_settings[$key] = '';
		}

		foreach ($this->_settings as $key => $value) {
			if ( $value == '' ) $this->_settings[$key] = $standart_values[$key];
		}
	}

	function maintenance_active(){
		if ( !$this->check_user_capability() && !$this->is_page_url_excluded() )
		{
			nocache_headers();
			header("HTTP/1.0 503 Service Unavailable");
			remove_action('wp_head','head_addons',7);

			// remove the main theme css file
			remove_action( 'wp_enqueue_scripts', 'et_nimble_load_scripts_styles' ); 	// fix for the Nimble theme
			remove_action( 'wp_enqueue_scripts', 'et_fusion_load_scripts_styles' ); 	// fix for the Fusion theme
			remove_action( 'wp_enqueue_scripts', 'et_harmony_load_scripts_styles' ); 	// fix for the Harmony theme
			remove_action( 'wp_enqueue_scripts', 'et_origin_load_scripts_styles' ); 	// fix for the Origin theme
			remove_action( 'wp_enqueue_scripts', 'et_load_scripts_styles' );

			add_action('et_anticipate_footer_icons', array(&$this,'show_social_icons'));
			add_action( 'et_wp_head', array( $this, 'add_external_jquery' ) );
			include('anticipate-maintenance-page.php');
			exit();
		}
	}

	function add_external_jquery() {
		$protocol = is_ssl() ? 'https' : 'http';
		echo "<script type='text/javascript' src='{$protocol}://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js'></script>";
	}

	function check_user_capability()
	{
		if ( is_super_admin() || current_user_can('manage_options') ) return true;

		return false;
	}

	function is_page_url_excluded()
	{
		$this->_exception_urls = apply_filters('et_anticipate_exceptions',$this->_exception_urls);
		foreach ( $this->_exception_urls as $url ){
			if ( strstr( $_SERVER['PHP_SELF'], $url) || strstr( $_SERVER["REQUEST_URI"], $url) ) return true;
		}
		if ( strstr($_SERVER['QUERY_STRING'], 'feed=') ) return true;
		return false;
	}

	function get_option($setting)
	{
		return $this->_settings[$setting];
	}

	function show_social_icons()
	{
		$social_icons = array();
?>
		<div id="anticipate-social-icons">
			<?php
				$social_icons['twitter'] = array('image' => $this->location_folder . '/images/twitter.png', 'url' => $this->_settings['et_anticipate_twitter_url'], 'alt' => 'Twitter' );
				$social_icons['rss'] = array('image' => $this->location_folder . '/images/rss.png', 'url' => $this->_settings['et_anticipate_rss_url'], 'alt' => 'Rss' );
				$social_icons['facebook'] = array('image' => $this->location_folder . '/images/facebook.png', 'url' => $this->_settings['et_anticipate_facebook_url'], 'alt' => 'Facebook' );
				$social_icons = apply_filters('et_anticipate_social', $social_icons);

				foreach ($social_icons as $icon) {
					echo "<a href='{$icon['url']}' target='_blank'><img alt='{$icon['alt']}' src='{$icon['image']}' /></a>";
				}
			?>
		</div> <!-- end #anticipate-social-icons -->
<?php
	}
} // end ET_Anticipate class

add_action( 'init', 'ET_Anticipate_Init', 5 );
function ET_Anticipate_Init()
{
	global $ET_Anticipate;
	$ET_Anticipate = new ET_Anticipate();
}