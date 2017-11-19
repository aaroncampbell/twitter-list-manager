<?php
/**
 * Plugin Name: Twitter List Manager
 * Plugin URI: https://aarondcampbell.com/wordpress-plugin/twitter-list-manager/
 * Description: For managing twitter lists and building them from URLs
 * Version: 1.0.0-alpha-20171119
 * Author: Aaron D. Campbell
 * Author URI: https://aarondcampbell.com/
 * License: GPLv2 or later
 * Text Domain: twitter-list-manager
 */

/*
	Copyright 2006-current  Aaron D. Campbell  ( email : wp_plugins@campbells.online )

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	( at your option ) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

//require_once( 'tlc-transients.php' );
//require_once( 'class.wp_widget_twitter_pro.php' );

/**
 * twitterListManager is the class that handles everything outside the widget. This
 * includes filters that modify tweet content for things like linked usernames.
 * It also helps us avoid name collisions.
 */
class twitterListManager {
	/**
	 * @var wpTwitter
	 */
	private $_wp_twitter_oauth;

	/**
	 * @var twitterListManager - Static property to hold our singleton instance
	 */
	static $instance = false;

	/**
	 * @var array Plugin settings
	 */
	protected $_settings;

	/**
	 * @var WP_Error - Possible errors
	 */
	protected $_error;

	/**
	 * @var string - The option group to register
	 */
	protected $_optionGroup = 'tlm-options';

	/**
	 * @var array - An array of options to register to the option group
	 */
	protected $_optionNames = array( 'tlm' );

	/**
	 * @var array - An associated array of callbacks for the options, option name should be index, callback should be value
	 */
	protected $_optionCallbacks = array();

	/**
	 * @var array - Array of lists cached locally
	 */
	protected $_lists = '';

	/**
	 * @var array - Array of Authed Users
	 */
	protected $_authed_users = '';

	/**
	 * This is our constructor, which is private to force the use of getInstance()
	 */
	protected function __construct() {
		require_once( 'lib/wp-twitter.php' );

		/**
		 * Add filters and actions
		 */
		add_action( 'admin_init', array( $this, 'handle_settings_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_list_actions' ) );
		add_action( 'admin_notices', array( $this, 'show_messages' ) );

		add_filter( 'twitter-list-manager-opt-tlm', array( $this, 'filterSettings' ) );
		add_filter( 'twitter-list-manager-opt-tlm-authed-users', array( $this, 'authed_users_option' ) );

		$this->_get_settings();
		if ( is_callable( array($this, '_post_settings_init') ) ) {
			$this->_post_settings_init();
		}

		add_filter( 'init', array( $this, 'init_locale' ) );
		add_action( 'admin_init', array( $this, 'register_options' ) );

		add_action( 'admin_menu', array( $this, 'register_options_page' ) );
		if ( is_callable( array( $this, 'add_options_meta_boxes' ) ) ) {
			add_action( 'admin_init', array( $this, 'add_options_meta_boxes' ) );
		}
	}

	protected function _post_settings_init() {
		$oauth_settings = array(
			'consumer-key'    => $this->_settings['tlm']['consumer-key'],
			'consumer-secret' => $this->_settings['tlm']['consumer-secret'],
		);
		$this->_wp_twitter_oauth = new wpTwitter( $oauth_settings );

		// We want to fill 'tlm-authed-users' but not overwrite them when saving
		$this->_settings['tlm-authed-users'] = apply_filters( 'twitter-list-manager-opt-tlm-authed-users', get_option( 'tlm-authed-users' ) );
	}

	/**
	 * Function to instantiate our class and make it a singleton
	 */
	public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;

		return self::$instance;
	}

	public function handle_list_actions() {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['tlm_list_name'] ) ) {
			wp_verify_nonce( $_POST['tlm-nonce'], 'create-list' );

			$_POST['tlm_list_name'] = substr( preg_replace( array( '/^[^a-zA-Z]*/', '/[^a-zA-Z0-9 _-]/' ), array( '', '' ), $_POST['tlm_list_name'] ), 0, 25 );

			if ( empty( $this->_settings['tlm-authed-users'] ) || ! isset( $this->_settings['tlm-authed-users'][ $_POST['tlm_list_owner'] ] ) ) {
				$this->add_error( 'bad-user', __( 'Must specify valid authed Twitter user', 'twitter-list-manager' ) );
			} else {
				$this->_wp_twitter_oauth->set_token( $this->_settings['tlm-authed-users'][ $_POST['tlm_list_owner'] ] );
				$twitter_return = $this->_wp_twitter_oauth->send_authed_request( 'lists/create', 'POST', array( 'name' => $_POST['tlm_list_name'], 'description' => $_POST['tlm_list_description'] ) );
				if ( is_wp_error( $twitter_return ) ) {
					$this->add_error( $twitter_return );
				} else {
					$this->_lists[ $twitter_return->id ] = $twitter_return;
					$local_list_info = new stdClass();
					$local_list_info->url = $_POST['tlm_list_url'];
					update_option( 'tlm-local-list-info-' . $twitter_return->id_str, $local_list_info, false );
					$this->add_users_to_list( $twitter_return->id, $this->get_twitter_users_from_url( $local_list_info->url ) );
					$this->add_error( 'success', dump( $twitter_return, 'Twitter Response', 'html', true ) );
				}
			}
		}

		if ( ! empty( $_GET['tlm-delete-list'] ) && ! empty( $_GET['page'] ) && 'twitter-list-manager' === $_GET['page'] ) {
			$list_id = $_GET['tlm-delete-list'];
			check_admin_referer( 'tlm-delete-' . $list_id, 'tlm-delete-nonce' );

			if ( empty( $this->_settings['tlm-authed-users'] ) ) {
				return;
			}
			$lists = $this->get_lists();
			if ( isset( $lists[ $list_id ] ) && isset( $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name] ) ) {
				$user_to_auth = $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name];
			} else {
				$user_to_auth = current( $this->_settings['tlm-authed-users'] );
			}

			$this->_wp_twitter_oauth->set_token( $user_to_auth );

			$twitter_return = $this->_wp_twitter_oauth->send_authed_request( 'lists/destroy', 'POST', array( 'list_id' => $list_id ) );
			if ( is_wp_error( $twitter_return ) ) {
				$this->add_error( $twitter_return );
			} else {
				delete_option( 'tlm-local-list-info-' . $list_id );
				$redirect_args = array(
					'message' => 'tlm-deleted-list',
				);
				wp_safe_redirect( add_query_arg( $redirect_args, $this->get_lists_url() ) );
				exit;
			}
		}

	}

	protected function get_local_list_info( $list_id ) {
		$default = (object) [ 'url' => '' ];
		return get_option( 'tlm-local-list-info-' . $list_id, $default );
	}

	protected function add_users_to_list( $list_id, $users ) {
		if ( empty( $this->_settings['tlm-authed-users'] ) ) {
			return;
		}
		$lists = $this->get_lists();
		if ( isset( $lists[ $list_id ] ) && isset( $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name] ) ) {
			$user_to_auth = $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name];
		} else {
			$user_to_auth = current( $this->_settings['tlm-authed-users'] );
		}

		$existing_users = $this->get_list_members( $list_id );
		dump( $existing_users, 'Existing Users' );

		$users = array_map( 'strtolower', $users );
		$users = array_unique( $users );
		$users = array_diff( $users, $existing_users );

		if ( empty( $users ) ) {
			return;
		}

		// Only 100 users can be added at a time.
		$users = array_chunk( $users, 100 );

		$this->_wp_twitter_oauth->set_token( $user_to_auth );
		foreach ( $users as $key => $users_to_add ) {
			$twitter_return = $this->_wp_twitter_oauth->send_authed_request( 'lists/members/create_all', 'POST', array( 'list_id' => $list_id, 'screen_name' => implode( ',', $users_to_add ) ) );
			if ( is_wp_error( $twitter_return ) ) {
				$this->add_error( $twitter_return );
			}
		}
	}

	protected function get_twitter_users_from_url( $url ) {
		$content = wp_remote_retrieve_body( wp_remote_get( $url ) );
		preg_match_all( '|https?://twitter.com/([^"\'/]+)|', $content, $matches );
		$usernames = array_map( 'strtolower', $matches[1] );
		return $usernames;
	}

	protected function add_error( $code, $message = '', $data = '' ) {
		// If a WP_Error object was passed in, use it or combine it with the existing one
		if ( is_wp_error( $code ) ) {
			// We already have a WP_Error and were given another, so loop through messages in the passed in object and add them.
			if ( is_wp_error( $this->_error ) ) {
				foreach ( $code->errors as $c => $messages ) {
					foreach ( $messages as $message ) {
						$data = '';
						if ( ! empty( $code->error_data[ $c ] ) ) {
							$data = $code->error_data[ $c ];
						}
						$this->_error->add( $c, $message, $data );
					}
				}
			} else {
				// There was no existing error and $code was a WP_Error object, so just use it
				$this->_error = $code;
			}
		} else {
			// We were passed regular data not a WP_Error object
			if ( is_wp_error( $this->_error ) ) {
				$this->_error->add( $code, $message, $data );
			} else {
				$this->_error = new WP_Error( $code, $message, $data );
			}
		}
	}

	public function handle_settings_actions() {
		if ( empty( $_GET['action'] ) || empty( $_GET['page'] ) || $_GET['page'] != 'twitter-list-manager-settings' ) {
			return;
		}

		if ( 'remove' == $_GET['action'] ) {
			check_admin_referer( 'remove-' . $_GET['screen_name'] );

			$redirect_args = array(
				'message'    => 'tlm-removed',
				'removed' => '',
			);
			unset( $this->_settings['tlm-authed-users'][strtolower($_GET['screen_name'])] );
			if ( update_option( 'tlm-authed-users', $this->_settings['tlm-authed-users'] ) );
				$redirect_args['removed'] = $_GET['screen_name'];

			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_options_url() ) );
			exit;
		}

		if ( 'authorize' == $_GET['action'] ) {
			check_admin_referer( 'authorize' );
			$auth_redirect = add_query_arg( array( 'action' => 'authorized' ), $this->get_options_url() );
			$token = $this->_wp_twitter_oauth->getRequestToken( $auth_redirect );
			if ( is_wp_error( $token ) ) {
				$this->add_error( $token );
				return;
			}
			update_option( '_tlm_request_token_'.$token['nonce'], $token );
			$screen_name = empty( $_GET['screen_name'] )? '':$_GET['screen_name'];
			wp_redirect( $this->_wp_twitter_oauth->get_authorize_url( $screen_name ) );
			exit;
		}

		if ( 'authorized' === $_GET['action'] ) {
			$redirect_args = array(
				'message'    => 'tlm-authorized',
				'authorized' => '',
			);
			if ( empty( $_GET['oauth_verifier'] ) || empty( $_GET['nonce'] ) )
				wp_safe_redirect( add_query_arg( $redirect_args, $this->get_options_url() ) );

			$this->_wp_twitter_oauth->set_token( get_option( '_tlm_request_token_'.$_GET['nonce'] ) );
			delete_option( '_tlm_request_token_'.$_GET['nonce'] );

			$token = $this->_wp_twitter_oauth->get_access_token( $_GET['oauth_verifier'] );
			if ( ! is_wp_error( $token ) ) {
				$this->_settings['tlm-authed-users'][strtolower($token['screen_name'])] = $token;
				update_option( 'tlm-authed-users', $this->_settings['tlm-authed-users'] );

				$redirect_args['authorized'] = $token['screen_name'];
			}
			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_lists_url() ) );
			exit;
		}
	}

	public function show_messages() {
		if ( ! empty( $_GET['message'] ) ) {
			$class = 'updated';
			if ( 'tlm-authorized' === $_GET['message'] ) {
				if ( ! empty( $_GET['authorized'] ) ) {
					$msg = sprintf( __( 'Successfully authorized @%s', 'twitter-list-manager' ), $_GET['authorized'] );
				} else {
					$msg = __( 'There was a problem authorizing your account.', 'twitter-list-manager' );
					$class = 'error';
				}
			} elseif ( 'tlm-removed' === $_GET['message'] ) {
				if ( ! empty( $_GET['removed'] ) ) {
					$msg = sprintf( __( 'Successfully removed @%s', 'twitter-list-manager' ), $_GET['removed'] );
				} else {
					$msg = __( 'There was a problem removing your account.', 'twitter-list-manager' );
					$class = 'error';
				}
			} elseif ( 'tlm-deleted-list' === $_GET['message'] ) {
				$msg = __( 'Successfully deleted list.', 'twitter-list-manager' );
			}
			if ( ! empty( $msg ) ) {
				printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $msg ) );
			}
		}

		if ( ! empty( $this->_error ) && is_wp_error( $this->_error ) ) {
			$msg = '<p>' . implode( '</p><p>', $this->_error->get_error_messages() ) . '</p>';
			echo '<div class="error">' . $msg . '</div>';
		}
	}

	public function add_options_meta_boxes() {
		add_meta_box( 'twitter-widget-pro-oauth', __( 'Authenticated Twitter Accounts', 'twitter-list-manager' ), array( $this, 'oauth_meta_box' ), 'aaron-twitter-list-manager', 'main' );
		add_meta_box( 'twitter-widget-pro-general-settings', __( 'General Settings', 'twitter-list-manager' ), array( $this, 'general_settings_meta_box' ), 'aaron-twitter-list-manager', 'main' );
	}

	public function oauth_meta_box() {
		$authorize_url = wp_nonce_url( add_query_arg( array( 'action' => 'authorize' ) ), 'authorize' );

		?>
		<table class="widefat">
			<thead>
				<tr valign="top">
					<th scope="row">
						<?php _e( 'Username', 'twitter-list-manager' );?>
					</th>
					<th scope="row">
						<?php _e( 'Lists Rate Usage', 'twitter-list-manager' );?>
					</th>
					<th scope="row">
						<?php _e( 'Statuses Rate Usage', 'twitter-list-manager' );?>
					</th>
				</tr>
			</thead>
		<?php
		foreach ( $this->_settings['tlm-authed-users'] as $u ) {
			$this->_wp_twitter_oauth->set_token( $u );
			$rates = $this->_wp_twitter_oauth->send_authed_request( 'application/rate_limit_status', 'GET', array( 'resources' => 'statuses,lists' ) );
			$style = $auth_link = '';
			if ( is_wp_error( $rates ) ) {
				$query_args = array(
					'action' => 'authorize',
					'screen_name' => $u['screen_name'],
				);
				$authorize_user_url = wp_nonce_url( add_query_arg( $query_args ), 'authorize' );
				$style = 'color:red;';
				$auth_link = ' - <a href="' . esc_url( $authorize_user_url ) . '">' . __( 'Reauthorize', 'twitter-list-manager' ) . '</a>';
			}
			$query_args = array(
				'action' => 'remove',
				'screen_name' => $u['screen_name'],
			);
			$remove_user_url = wp_nonce_url( add_query_arg( $query_args ), 'remove-' . $u['screen_name'] );
			?>
				<tr valign="top">
					<th scope="row" style="<?php echo esc_attr( $style ); ?>">
						<strong>@<?php echo esc_html( $u['screen_name'] ) . $auth_link;?></strong>
						<br /><a href="<?php echo esc_url( $remove_user_url ) ?>"><?php _e( 'Remove', 'twitter-list-manager' ) ?></a>
					</th>
					<?php
					if ( ! is_wp_error( $rates ) ) {
						$display_rates = array(
							__( 'Lists', 'twitter-list-manager' ) => $rates->resources->lists->{'/lists/statuses'},
							__( 'Statuses', 'twitter-list-manager' ) => $rates->resources->statuses->{'/statuses/user_timeline'},
						);
						foreach ( $display_rates as $title => $rate ) {
						?>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong>
							<p>
								<?php printf( __( 'Used: %d', 'twitter-list-manager' ), $rate->limit - $rate->remaining ); ?><br />
								<?php printf( __( 'Remaining: %d', 'twitter-list-manager' ), $rate->remaining ); ?><br />
								<?php
								$minutes = ceil( ( $rate->reset - gmdate( 'U' ) ) / 60 );
								printf( _n( 'Limits reset in: %d minutes', 'Limits reset in: %d minutes', $minutes, 'twitter-list-manager' ), $minutes );
								?><br />
								<small><?php _e( 'This is overall usage, not just usage from Twitter List Manager', 'twitter-list-manager' ); ?></small>
							</p>
						</td>
						<?php
						}
					} else {
						?>
						<td>
							<p><?php _e( 'There was an error checking your rate limit.', 'twitter-list-manager' ); ?></p>
						</td>
						<?php
					}
					?>
				</tr>
				<?php
			}
		?>
		</table>
		<?php
		if ( empty( $this->_settings['tlm']['consumer-key'] ) || empty( $this->_settings['tlm']['consumer-secret'] ) ) {
		?>
		<p>
			<strong><?php _e( 'You need to fill in the Consumer key and Consumer secret before you can authorize accounts.', 'twitter-list-manager' ) ?></strong>
		</p>
		<?php
		} else {
		?>
		<p>
			<a href="<?php echo esc_url( $authorize_url );?>" class="button button-large button-primary"><?php _e( 'Authorize New Account', 'twitter-list-manager' ); ?></a>
		</p>
		<?php
		}
	}
	public function general_settings_meta_box() {
		?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="tlm_consumer_key"><?php _e( 'Consumer key', 'twitter-list-manager' );?></label>
						</th>
						<td>
							<input id="tlm_consumer_key" name="tlm[consumer-key]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['tlm']['consumer-key'] ); ?>" size="40" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="tlm_consumer_secret"><?php _e( 'Consumer secret', 'twitter-list-manager' );?></label>
						</th>
						<td>
							<input id="tlm_consumer_secret" name="tlm[consumer-secret]" type="text" class="regular-text code" value="<?php esc_attr_e( $this->_settings['tlm']['consumer-secret'] ); ?>" size="40" />
						</td>
					</tr>
					<?php
					if ( empty( $this->_settings['tlm']['consumer-key'] ) || empty( $this->_settings['tlm']['consumer-secret'] ) ) {
					?>
					<tr valign="top">
						<th scope="row">&nbsp;</th>
						<td>
							<strong><?php _e( 'Directions to get the Consumer Key and Consumer Secret', 'twitter-list-manager' ) ?></strong>
							<ol>
								<li><a href="https://dev.twitter.com/apps/new"><?php _e( 'Add a new Twitter application', 'twitter-list-manager' ) ?></a></li>
								<li><?php _e( "Fill in Name, Description, Website, and Callback URL (don't leave any blank) with anything you want" ) ?></a></li>
								<li><?php _e( "Agree to rules, fill out captcha, and submit your application" ) ?></a></li>
								<li><?php _e( "Copy the Consumer key and Consumer secret into the fields above" ) ?></a></li>
								<li><?php _e( "Click the Update Options button at the bottom of this page" ) ?></a></li>
							</ol>
						</td>
					</tr>
					<?php
					}
					?>
				</table>
				<p class="submit">
					<input type="submit" name="Submit" class="button button-primary" value="<?php esc_attr_e( 'Update Options &raquo;', 'twitter-list-manager' ); ?>" />
				</p>
		<?php
	}

	public function targetBlank( $attributes ) {
		$attributes['target'] = '_blank';
		return $attributes;
	}

	public function authed_users_option( $settings ) {
		if ( ! is_array( $settings ) )
			return array();
		return $settings;
	}

	public function filterSettings( $settings ) {
		$default_args = array(
			'consumer-key'    => '',
			'consumer-secret' => '',
		);

		return wp_parse_args( $settings, $default_args );
	}

	public function get_users_list( $authed = false ) {
		$users = $this->_settings['tlm-authed-users'];
		if ( $authed ) {
			if ( ! empty( $this->_authed_users ) )
				return $this->_authed_users;
			foreach ( $users as $key => $u ) {
				$this->_wp_twitter_oauth->set_token( $u );
				$rates = $this->_wp_twitter_oauth->send_authed_request( 'application/rate_limit_status', 'GET', array( 'resources' => 'statuses,lists' ) );
				if ( is_wp_error( $rates ) )
					unset( $users[$key] );
			}
			$this->_authed_users = $users;
		}
		return $users;
	}

	public function get_lists() {
		if ( ! empty( $this->_lists ) ) {
			return $this->_lists;
		}
		$this->_lists =  array();
		foreach ( $this->_settings['tlm-authed-users'] as $key => $u ) {
			$this->_wp_twitter_oauth->set_token( $u );
			$user_lists = $this->_wp_twitter_oauth->send_authed_request( 'lists/list', 'GET', array( 'resources' => 'statuses,lists' ) );

			if ( ! empty( $user_lists ) && ! is_wp_error( $user_lists ) ) {
				foreach ( $user_lists as $l ) {
					$this->_lists[$l->id] = $l;
				}
			}
		}
		return $this->_lists;
	}

	protected function get_list_members( $list_id, $cursor = -1 ) {
		if ( empty( $this->_settings['tlm-authed-users'] ) ) {
			return array();
		}
		$lists = $this->get_lists();
		if ( isset( $lists[ $list_id ] ) && isset( $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name] ) ) {
			$user_to_auth = $this->_settings['tlm-authed-users'][$lists[ $list_id ]->user->screen_name];
		} else {
			$user_to_auth = current( $this->_settings['tlm-authed-users'] );
		}

		$this->_wp_twitter_oauth->set_token( $user_to_auth );
		$list_info = $this->_wp_twitter_oauth->send_authed_request( 'lists/members', 'GET', array( 'list_id' => $list_id, 'count' => 5000, 'include_user_entities' => 'false', 'include_entities' => 'false', 'skip_status' => 'true', 'cursor' => $cursor ) );

		/*
		//Removed because Twitter says lists can't have more than 5000 users, so this is overkill
		// If there are more users, combine using recursion
		if ( ! is_wp_error( $list_info ) && $list_info->next_cursor ) {
			$more_info = $this->get_list_members( $list_id, $list_info->next_cursor );
			$list_info->users = array_merge( $list_info->users, $more_info->users );
		}
		*/

		$users = array();
		foreach ( $list_info->users as $u ) {
			$users[] = strtolower( $u->screen_name );
		}

		return $users;
	}

	public function init_locale() {
		load_plugin_textdomain( 'twitter-list-manager' );
	}

	protected function _get_settings() {
		foreach ( $this->_optionNames as $opt ) {
			$this->_settings[$opt] = apply_filters( 'twitter-list-manager-opt-' . $opt, get_option( $opt ) );
		}
	}

	public function register_options() {
		foreach ( $this->_optionNames as $opt ) {
			if ( !empty($this->_optionCallbacks[$opt]) && is_callable( $this->_optionCallbacks[$opt] ) ) {
				$callback = $this->_optionCallbacks[$opt];
			} else {
				$callback = '';
			}
			register_setting( $this->_optionGroup, $opt, $callback );
		}
	}
	
	public function register_options_page() {
		add_menu_page( __( 'Twitter List Manager', 'twitter-list-manager' ), __( 'Twitter Lists', 'twitter-list-manager' ), 'manage_options', 'twitter-list-manager', null /* Overridden by subpages */, 'dashicons-twitter' );
		add_submenu_page( 'twitter-list-manager', __( 'Twitter List Manager Lists', 'twitter-list-manager' ), __( 'Lists', 'twitter-list-manager' ), 'manage_options', 'twitter-list-manager', array( $this, 'lists_page' ) );
		add_submenu_page( 'twitter-list-manager', __( 'Twitter List Manager Settings', 'twitter-list-manager' ), __( 'Settings', 'twitter-list-manager' ), 'manage_options', 'twitter-list-manager-settings', array( $this, 'options_page' ) );
	}

	protected function _filter_boxes_main($boxName) {
		if ( 'main' == strtolower($boxName) )
			return false;

		return $this->_filter_boxes_helper($boxName, 'main');
	}

	protected function _filter_boxes_sidebar($boxName) {
		return $this->_filter_boxes_helper($boxName, 'sidebar');
	}

	protected function _filter_boxes_helper($boxName, $test) {
		return ( strpos( strtolower($boxName), strtolower($test) ) !== false );
	}

	public function lists_page() {
		if ( empty( $this->_settings['tlm']['consumer-key'] ) || empty( $this->_settings['tlm']['consumer-secret'] ) ) {
			$msg = sprintf( __( 'You need to <a href="%s">set up your Twitter app keys</a>.', 'twitter-list-manager' ), $this->get_options_url() );
			echo '<div class="error"><p>' . $msg . '</p></div>';
		}

		if ( empty( $this->_settings['tlm-authed-users'] ) ) {
			$msg = sprintf( __( 'You need to <a href="%s">authorize your Twitter accounts</a>.', 'twitter-list-manager' ), $this->get_options_url() );
			echo '<div class="error"><p>' . $msg . '</p></div>';
		}
		?>
		<div class="wrap">
			<h2><?php echo esc_html( __( 'Twitter Lists', 'twitter-list-manager' ) ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr valign="top">
						<th scope="row"><?php _e( 'Name', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( 'Desription', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( '# members', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( 'URL', 'twitter-list-manager' );?></th>
					</tr>
				</thead>
				<tfoot>
					<tr valign="top">
						<th scope="row"><?php _e( 'Name', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( 'Desription', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( '# members', 'twitter-list-manager' );?></th>
						<th scope="row"><?php _e( 'URL', 'twitter-list-manager' );?></th>
					</tr>
				</tfoot>
				<tbody>
				<?php
				foreach ( $this->get_lists() as $id => $list ) {
					?>
					<tr>
						<td>
							<a href="https://twitter.com/<?php esc_attr_e( $list->uri );?>"><?php echo $list->name; ?></a>
							<div class="row-actions">
								<span class="trash"><a href="<?php echo wp_nonce_url( add_query_arg( array( 'tlm-delete-list' => $list->id ), $this->get_lists_url() ), 'tlm-delete-' . $list->id, 'tlm-delete-nonce' ); ?>"><?php esc_html_e( 'Delete', 'twitter-list-manager' ); ?></a></span> |
								<span class="inline"><a href="#"><?php esc_html_e( 'Refresh', 'twitter-list-manager' ); ?></a></span>
							</div>
						</td>
						<td><?php echo $list->description; ?></td>
						<td><?php echo $list->member_count; ?></td>
						<td><?php esc_html_e( $this->get_local_list_info( $list->id )->url ); ?></td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<h2><?php echo esc_html( __( 'Add New List', 'twitter-list-manager' ) ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'create-list', 'tlm-nonce' ); ?>
				<table class="form-table">
					<tbody>
						<tr class="form-field form-required">
							<th scope="row"><label for="list_owner"><?php _e( 'Owner <span class="description">(required)</span>', 'twitter-list-manager'); ?></label></th>
							<td>
								<select name="tlm_list_owner" id="list_owner">
									<?php
									foreach ( $this->_settings['tlm-authed-users'] as $username => $user ) {
										?>
										<option value="<?php esc_attr_e( $username ); ?>"><?php esc_html_e( $username ); ?></option>
										<?php
									}
									?>
								</select>
							</td>
						</tr>
						<tr class="form-field form-required">
							<th scope="row"><label for="list_name"><?php _e( 'Name <span class="description">(required)</span>', 'twitter-list-manager'); ?></label></th>
							<td>
								<input name="tlm_list_name" type="text" id="list_name" value="" aria-required="true" autocapitalize="words" autocorrect="off" maxlength="25">
								<p class="description" id="list-name-description"><?php _e( 'Must start with a letter and can consist only of 25 or fewer letters, numbers, “-”, or “_” characters.', 'twitter-list-manager' ); ?></p>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="list_description"><?php _e( 'Description', 'twitter-list-manager' ) ?></label></th>
							<td><input name="tlm_list_description" type="text" id="list_description" value=""></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="list_url"><?php _e( 'URL', 'twitter-list-manager' ) ?></label></th>
							<td>
								<input name="tlm_list_url" type="url" id="list_url" value="">
								<p class="description" id="list-url-description"><?php _e( 'This URL will be parsed for links to Twitter profiles, and those users will be added to the list.', 'twitter-list-manager' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" name="createlist" id="createlist" class="button button-primary" value="<?php esc_attr_e( 'Create List', 'twitter-list-manager' ) ?>">
				</p>
			</form>
			<?php
			$users = $this->get_twitter_users_from_url( 'http://src.wordpress-develop.dev/test-twitter-names/' );
			$this->add_users_to_list( 932286013300502529, $users );
			$this->add_users_to_list( 932272594212085761, $users );
			?>
			<?php //dump( $this->get_list_members( 925380123339120640 ) ); ?>
			<?php dump( $this->get_lists() ); ?>
		</div>
		<?php
	}

	public function options_page() {
		global $wp_meta_boxes;
		$allBoxes = array_keys( $wp_meta_boxes['aaron-twitter-list-manager'] );
		$mainBoxes = array_filter( $allBoxes, array( $this, '_filter_boxes_main' ) );
		unset($mainBoxes['main']);
		sort($mainBoxes);
		$sidebarBoxes = array_filter( $allBoxes, array( $this, '_filter_boxes_sidebar' ) );
		unset($sidebarBoxes['sidebar']);
		sort($sidebarBoxes);

		$main_width = empty( $sidebarBoxes )? '100%' : '75%';
		?>
			<div class="wrap">
				<h2><?php echo esc_html( __( 'Twitter List Manager', 'twitter-list-manager' ) ); ?></h2>
				<div class="metabox-holder">
					<div class="postbox-container" style="width:<?php echo $main_width; ?>;">
					<?php
						do_action( 'rpf-pre-main-metabox', $main_width );
						if ( in_array( 'main', $allBoxes ) ) {
					?>
						<form action="options.php" method="post"<?php do_action( 'rpf-options-page-form-tag' ) ?>>
							<?php
							settings_fields( $this->_optionGroup );
							do_meta_boxes( 'aaron-twitter-list-manager', 'main', '' );
							?>
						</form>
					<?php
						}
						foreach( $mainBoxes as $context ) {
							do_meta_boxes( 'aaron-twitter-list-manager', $context, '' );
						}
					?>
					</div>
					<?php
					if ( !empty( $sidebarBoxes ) ) {
					?>
					<div class="alignright" style="width:24%;">
						<?php
						foreach( $sidebarBoxes as $context ) {
							do_meta_boxes( 'aaron-twitter-list-manager', $context, '' );
						}
						?>
					</div>
					<?php
					}
					?>
				</div>
			</div>
			<?php
	}

	public function get_options_url() {
		return admin_url( 'admin.php?page=twitter-list-manager-settings' );
	}

	public function get_lists_url() {
		return admin_url( 'admin.php?page=twitter-list-manager' );
	}
}
// Instantiate our class
$twitter_list_manager = twitterListManager::getInstance();
