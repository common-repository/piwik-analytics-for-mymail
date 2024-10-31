<?php
/*
Plugin Name: Piwik Analytics for MyMail
Plugin URI: https://evp.to/mymail?utm_campaign=wporg&utm_source=Piwik+Analytics+for+MyMail
Description: Integrates Piwik Analytics with MyMail Newsletter Plugin to track your clicks with the open source Analytics service
This requires at least version 2.0 of the plugin
Version: 0.5
Author: EverPress
Author URI: https://everpress.co
License: GPLv2 or later
 */


class MyMailPiwikAnalytics {

	private $plugin_path;
	private $plugin_url;

	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );

		load_plugin_textdomain( 'mymail_piwik', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/**
	 * init function.
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		if ( ! function_exists( 'mymail' ) ) {

			add_action( 'admin_notices', array( &$this, 'notice' ) );

		} else {

			if ( is_admin() ) {

				add_action( 'add_meta_boxes', array( &$this, 'add_meta_boxes' ) );
				add_filter( 'mymail_setting_sections', array( &$this, 'settings_tab' ), 1 );
				add_action( 'mymail_section_tab_piwik', array( &$this, 'settings' ) );
				add_action( 'save_post', array( &$this, 'save_post' ), 10, 2 );

			}

			add_action( 'mymail_wpfooter', array( &$this, 'wpfooter' ) );
			add_filter( 'mymail_redirect_to', array( &$this, 'redirect_to' ), 1, 2 );

		}

		if ( function_exists( 'mailster' ) ) {

			add_action(
				'admin_notices',
				function() {

					$name = 'Piwik Analytics for MyMail';
					$slug = 'mailster-piwik/mailster-piwik.php';

					$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . dirname( $slug ) ), 'install-plugin_' . dirname( $slug ) );

					$search_url = add_query_arg(
						array(
							's'    => $slug,
							'tab'  => 'search',
							'type' => 'term',
						),
						admin_url( 'plugin-install.php' )
					);

					?>
			<div class="error">
				<p>
				<strong><?php echo esc_html( $name ); ?></strong> is deprecated in Mailster and no longer maintained! Please switch to the <a href="<?php echo esc_url( $search_url ); ?>">new version</a> as soon as possible or <a href="<?php echo esc_url( $install_url ); ?>">install it now!</a>
				</p>
			</div>
					<?php

				}
			);
		}

	}



	/**
	 * click_target function.
	 *
	 * @access public
	 * @param mixed $target
	 * @return void
	 */
	public function redirect_to( $target, $campaign_id ) {

		$target_domain = parse_url( $target, PHP_URL_HOST );
		$site_domain   = parse_url( site_url(), PHP_URL_HOST );

		if ( $target_domain !== $site_domain ) {
			return $target;
		}

		global $wp;

		$hash  = isset( $wp->query_vars['_mymail_hash'] )
			? $wp->query_vars['_mymail_hash']
			: ( isset( $_REQUEST['k'] ) ? preg_replace( '/\s+/', '', $_REQUEST['k'] ) : null );
		$count = isset( $wp->query_vars['_mymail_extra'] )
			? $wp->query_vars['_mymail_extra']
			: ( isset( $_REQUEST['c'] ) ? intval( $_REQUEST['c'] ) : null );

		$subscriber = mymail( 'subscribers' )->get_by_hash( $hash );
		$campaign   = mymail( 'campaigns' )->get( $campaign_id );

		if ( ! $campaign || $campaign->post_type != 'newsletter' ) {
			return $target;
		}

		$search  = array( '%%CAMP_ID%%', '%%CAMP_TITLE%%', '%%CAMP_TYPE%%', '%%CAMP_LINK%%', '%%SUBSCRIBER_ID%%', '%%SUBSCRIBER_EMAIL%%', '%%SUBSCRIBER_HASH%%', '%%LINK%%' );
		$replace = array(
			$campaign->ID,
			$campaign->post_title,
			$campaign->post_status == 'autoresponder' ? 'autoresponder' : 'regular',
			get_permalink( $campaign->ID ),
			$subscriber->ID,
			$subscriber->email,
			$subscriber->hash,
			$target,
		);

		$values = wp_parse_args(
			get_post_meta( $campaign->ID, 'mymail-piwik', true ),
			mymail_option(
				'piwik',
				array(
					'pk_campaign' => '%%CAMP_TITLE%%',
					'pk_kwd'      => '%%LINK%%',
				)
			)
		);

		return add_query_arg(
			array(
				'pk_campaign' => urlencode( str_replace( $search, $replace, $values['pk_campaign'] ) ),
				'pk_kwd'      => urlencode( str_replace( $search, $replace, $values['pk_kwd'] ) ),
			),
			$target
		);
	}




	/**
	 * save_post function.
	 *
	 * @access public
	 * @param mixed $post_id
	 * @param mixed $post
	 * @return void
	 */
	public function save_post( $post_id, $post ) {

		if ( isset( $_POST['mymail_piwik'] ) && $post->post_type == 'newsletter' ) {

			$save = get_post_meta( $post_id, 'mymail-piwik', true );

			$piwik_values = mymail_option(
				'piwik',
				array(
					'pk_campaign' => '%%CAMP_TITLE%%',
					'pk_kwd'      => '%%LINK%%',
				)
			);

			$save = wp_parse_args( $_POST['mymail_piwik'], $save );
			update_post_meta( $post_id, 'mymail-piwik', $save );

		}

	}


	/**
	 * settings_tab function.
	 *
	 * @access public
	 * @param mixed $settings
	 * @return void
	 */
	public function settings_tab( $settings ) {

		$position = 11;
		$settings = array_slice( $settings, 0, $position, true ) +
					array( 'piwik' => 'Piwik' ) +
					array_slice( $settings, $position, null, true );

		return $settings;
	}


	/**
	 * add_meta_boxes function.
	 *
	 * @access public
	 * @return void
	 */
	public function add_meta_boxes() {

		if ( mymail_option( 'piwik_campaign_based' ) ) {
			add_meta_box( 'mymail_piwik', 'Piwik Analytics', array( &$this, 'metabox' ), 'newsletter', 'side', 'low' );
		}
	}


	/**
	 * metabox function.
	 *
	 * @access public
	 * @return void
	 */
	public function metabox() {

		global $post;

		$readonly = ( in_array( $post->post_status, array( 'finished', 'active' ) ) || $post->post_status == 'autoresponder' && ! empty( $_GET['showstats'] ) ) ? 'readonly disabled' : '';

		$values = wp_parse_args(
			get_post_meta( $post->ID, 'mymail-piwik', true ),
			mymail_option(
				'piwik',
				array(
					'pk_campaign' => '%%CAMP_TITLE%%',
					'pk_kwd'      => '%%LINK%%',
				)
			)
		);

		?>
		<style>#mymail_piwik {display: inherit;}</style>
		<p><label><?php _e( 'Campaign Name', 'mymail_piwik' ); ?>*: <input type="text" name="mymail_piwik[pk_campaign]" value="<?php echo esc_attr( $values['pk_campaign'] ); ?>" class="widefat" <?php echo $readonly; ?>></label></p>
		<p><label><?php _e( 'Campaign Keyword', 'mymail_piwik' ); ?>:<input type="text" name="mymail_piwik[pk_kwd]" value="<?php echo esc_attr( $values['pk_kwd'] ); ?>" class="widefat" <?php echo $readonly; ?>></label></p>
		<?php
	}

	public function settings() {

		?>
		<script type="text/javascript">
			jQuery(document).ready(function ($) {

				var inputs = $('.mymail-piwik-value');

				inputs.on('keyup change', function(){
					var pairs = [];
					$.each(inputs, function(){
						var el = $(this),
							key = el.attr('name').replace('mymail_options[piwik][','').replace(']', '');
						if(el.val())pairs.push(key+'='+encodeURIComponent(el.val().replace(/%%([A-Z_]+)%%/g, '$1')));
					});
					$('#mymail-piwik-preview').html('?'+pairs.join('&'));

				}).trigger('keyup');


			});
		</script>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Site ID:', 'mymail_piwik' ); ?></th>
			<td>
			<p><input type="text" name="mymail_options[piwik_siteid]" value="<?php echo esc_attr( mymail_option( 'piwik_siteid' ) ); ?>" class="small-text">
			</p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Domain:', 'mymail_piwik' ); ?></th>
			<td>
			<p>http(s)://<input type="text" name="mymail_options[piwik_domain]" value="<?php echo esc_attr( mymail_option( 'piwik_domain' ) ); ?>" class="regular-text" placeholder="analytics.example.com">
			</p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'SetDomains:', 'mymail_piwik' ); ?></th>
			<td>
			<p><input type="text" name="mymail_options[piwik_setdomains]" value="<?php echo esc_attr( mymail_option( 'piwik_setdomains' ) ); ?>" class="regular-text" placeholder="*.example.com"> <span class="description"><?php echo sprintf( __( '(Optional) Sets the %s variable.', 'mymail_piwik' ), '<code>setDomains</code>' ); ?></span></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Defaults', 'mymail_piwik' ); ?><p class="description"><?php _e( 'Define the defaults for click tracking. Keep the default values until you know better.', 'mymail_piwik' ); ?></p></th>
			<td>
			<?php
			$piwik_values = mymail_option(
				'piwik',
				array(
					'pk_campaign' => '%%CAMP_TITLE%%',
					'pk_kwd'      => '%%LINK%%',
				)
			);
			?>
			<div class="mymail_text"><label><?php _e( 'Campaign Name', 'mymail_piwik' ); ?> *:</label> <input type="text" name="mymail_options[piwik][pk_campaign]" value="<?php echo esc_attr( $piwik_values['pk_campaign'] ); ?>" class="mymail-piwik-value regular-text"></div>
			<div class="mymail_text"><label><?php _e( 'Campaign Keyword', 'mymail_piwik' ); ?>:</label> <input type="text" name="mymail_options[piwik][pk_kwd]" value="<?php echo esc_attr( $piwik_values['pk_kwd'] ); ?>" class="mymail-piwik-value regular-text"></div>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Example URL', 'mymail_piwik' ); ?></th>
			<td><code style="max-width:800px;white-space:normal;word-wrap:break-word;display:block;"><?php echo site_url( '/' ); ?><span id="mymail-piwik-preview"></span></code></td>
		</tr>
		<tr valign="top">
			<th scope="row"></th>
			<td><p class="description"><?php _e( 'Available variables:', 'mymail_piwik' ); ?><br>%%CAMP_ID%%, %%CAMP_TITLE%%, %%CAMP_TYPE%%, %%CAMP_LINK%%,<br>%%SUBSCRIBER_ID%%, %%SUBSCRIBER_EMAIL%%, %%SUBSCRIBER_HASH%%,<br>%%LINK%%</p></td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Campaign based value', 'mymail_piwik' ); ?></th>
			<td><label><input type="hidden" name="mymail_options[piwik_campaign_based]" value=""><input type="checkbox" name="mymail_options[piwik_campaign_based]" value="1" <?php checked( mymail_option( 'piwik_campaign_based' ) ); ?>> <?php _e( 'allow campaign based variations of these values', 'mymail_piwik' ); ?></label><p class="description"><?php _e( 'adds a metabox on the campaign edit screen to alter the values for each campaign', 'mymail_piwik' ); ?></p></td>
		</tr>

	</table>
		<?php
	}



	/**
	 * notice function.
	 *
	 * @access public
	 * @return void
	 */
	public function notice() {
		$msg = sprintf( __( 'You have to enable the %s to use the Piwik Extension!', 'mymail_piwik' ), '<a href="https://evp.to/mymail?utm_campaign=wporg&utm_source=Piwik+Analytics+for+MyMail">MyMail Newsletter Plugin</a>' );
		?>
		<div class="error"><p><strong><?php	echo $msg; ?></strong></p></div>
		<?php

	}


	/**
	 * wpfooter function.
	 *
	 * @access public
	 * @return void
	 */
	public function wpfooter() {

		$site_id    = mymail_option( 'piwik_siteid' );
		$domain     = mymail_option( 'piwik_domain' );
		$setDomains = mymail_option( 'piwik_setdomains' );
		if ( $setDomains ) {
			$setDomains = explode( ',', $setDomains );
		}

		if ( ! $site_id || ! $domain ) {
			return;
		}
		?>
	<script type="text/javascript">
		var _paq = _paq || [];
		<?php
		if ( $setDomains ) {
			echo "_paq.push(['setDomains', ['" . implode( "','", $setDomains ) . "']);";}
		?>

		_paq.push(["trackPageView"]);
		_paq.push(["enableLinkTracking"]);

		(function() {
			var u=(("https:" == document.location.protocol) ? "https" : "http") + "://<?php echo $domain; ?>/";
			_paq.push(["setTrackerUrl", u+"piwik.php"]);
			_paq.push(["setSiteId", "<?php echo $site_id; ?>"]);
			var d=document, g=d.createElement("script"), s=d.getElementsByTagName("script")[0]; g.type="text/javascript";
			g.defer=true; g.async=true; g.src=u+"piwik.js"; s.parentNode.insertBefore(g,s);
		})();
	</script>
		<?php

	}

	/**
	 * activate function
	 *
	 * @access public
	 * @return void
	 */
	public function activate() {

		if ( function_exists( 'mymail' ) ) {

			if ( ! mymail_option( 'piwik_siteid' ) ) {
				mymail_notice( sprintf( __( 'Please enter your site ID and domain on the %s!', 'mymail_piwik' ), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=piwik_analytics#piwik">Settings Page</a>' ), '', false, 'piwik_analytics' );
			}
		}

	}

}

new MyMailPiwikAnalytics();
