<?php
class wpTwitter {
	/**
	 * @var string Twitter App Consumer Key
	 */
	private $_consumer_key;

	/**
	 * @var string Twitter App Secret Key
	 */
	private $_consumer_secret;

	/**
	 * @var string Twitter App Bearer Token
	 */
	private $_bearer_token;

	/**
	 * @var array Twitter Request or Access Token
	 */
	private $_token;

	private static $_api_url;

	public function __construct( $args ) {
		if ( ! class_exists( 'wpOAuthUtil' ) ) {
			require_once( 'oauth-util.php' );
		}

		$defaults = array(
			'api-url' => 'https://api.twitter.com/',
		);
		$args = wp_parse_args( $args, $defaults );
		$this->_consumer_key = $args['consumer-key'];
		$this->_consumer_secret = $args['consumer-secret'];
		$this->_bearer_token = $args['bearer-token'];
		self::$_api_url = $args['api-url'];
		if ( !empty( $args['token'] ) )
			$this->_token = $args['token'];
	}

	public function get_api_endpoint( $endpoint, $version = '2' ) {
		$endpoint = trim( $endpoint, '/' );
		$version = trim( $endpoint, '/' );

		if ( ! empty( $version ) )
			$version .= '/';
		
		// Allow the :id placeholder for Twitter User ID
		$endpoint = str_replace( ':id', $this->_token['user_id'], $endpoint );

		return self::$_api_url . $version . $endpoint;
	}

	/**
	 * Get a request_token from Twitter
	 *
	 * @param string Oauth Callback
	 *
	 * @returns array key/value array containing oauth_token and oauth_token_secret
	 */
	public function get_request_token( $oauth_callback = null ) {
		$parameters = array(
			'oauth_nonce' => md5( microtime() . mt_rand() ),
		);
		if ( ! empty( $oauth_callback ) )
			$parameters['oauth_callback'] = add_query_arg( array('nonce'=>$parameters['oauth_nonce']), $oauth_callback );

		$request_url = $this->get_api_endpoint( 'oauth/request_token', '' );
		$this->_token = $this->send_authed_request( $request_url, 'GET', $parameters );
		if ( ! is_wp_error( $this->_token ) )
			$this->_token['nonce'] = $parameters['oauth_nonce'];
		return $this->_token;
	}

	private function _get_request_defaults() {
		$params = array(
			'sslverify' => apply_filters( 'twp_sslverify', false ),
			'body'      => array(),
		);

		return $params;
	}

	/**
	 * Get the authorize URL
	 *
	 * @param string $screen_name Twitter user name
	 *
	 * @returns bool|string false on failure or URL as string
	 */
	public function get_authorize_url( $screen_name = '' ) {
		if ( empty( $this->_token['oauth_token'] ) )
			return false;

		$query_args = array(
			'oauth_token' => $this->_token['oauth_token']
		);
		if ( !empty( $screen_name ) ) {
			$query_args['screen_name'] = $screen_name;
			$query_args['force_login'] = 'true';
		}
		return add_query_arg( $query_args, $this->get_api_endpoint( 'oauth/authorize', '' ) );
	}

	/**
	 * Format and sign an OAuth / API request
	 *
	 * @param string $endpoint Twitter Endpoint to request
	 * @param string $method Usually GET or POST
	 * @param array $body_parameters Data to send with request
	 * @param string $auth_type 'signed' or 'bearer'
	 *
	 * @return object Twitter response or WP_Error
	 */
	public function send_authed_request( $endpoint, $method, $body_parameters = array(), $auth_type = 'signed' ) {
		$parameters = $this->_get_request_defaults();
		$parameters['body'] = wp_parse_args( $body_parameters, $parameters['body'] );
		if ( ! filter_var( $endpoint , FILTER_VALIDATE_URL ) ) {
			$request_url = $this->get_api_endpoint( $endpoint );
		} else {
			$request_url = $endpoint;
		}

		if ( 'bearer' == $auth_type ) {
			$this->bearer_auth( $parameters, $request_url, $method );
		} else {
			$this->sign_request( $parameters, $request_url, $method );
		}
		switch ($method) {
			case 'GET':
				$request_url = $this->get_normalized_http_url( $request_url ) . '?' . wpOAuthUtil::build_http_query( $parameters['body'] );
				unset( $parameters['body'] );
				$resp = wp_remote_get( $request_url, $parameters );
				break;
			default:
				$parameters['method'] = $method;
				$resp = wp_remote_request( $request_url, $parameters );
		}

		if ( !is_wp_error( $resp ) && $resp['response']['code'] >= 200 && $resp['response']['code'] < 300 ) {
			$decoded_response = json_decode( $resp['body'] );
			/**
			 * There is a problem with some versions of PHP that will cause
			 * json_decode to return the string passed to it in certain cases
			 * when the string isn't valid JSON.  This is causing me all sorts
			 * of pain.  The solution so far is to check if the return isset()
			 * which is the correct response if the string isn't JSON.  Then
			 * also check if a string is returned that has an = in it and if
			 * that's the case assume it's a string that needs to fall back to
			 * using wp_parse_args()
			 * @see https://bugs.php.net/bug.php?id=45989
			 * @see https://github.com/OpenRange/twitter-widget-pro/pull/8
			 */
			if ( ( ! isset( $decoded_response ) && ! empty( $resp['body'] ) ) || ( is_string( $decoded_response ) && false !== strpos( $resp['body'], '=' ) ) )
				$decoded_response = wp_parse_args( $resp['body'] );
			return $decoded_response;
		} else {
			if ( is_wp_error( $resp ) )
				return $resp;

			$error_text = 'Could not recognize the response from Twitter';
			$error = json_decode( $resp['body'] );
			if ( !is_null( $error ) ) {
				$error_text = $error->detail;
			} elseif ( class_exists( 'SimpleXMLElement' ) ) {
				$xml = simplexml_load_string( $resp['body'] );
				if ( false !== $xml && !empty( $xml->error ) ) {
					$error_text = $xml->error;
				}
			}
			return new WP_Error( $resp['response']['code'], $error_text );
		}
	}

	/**
	 * parses the url and rebuilds it to be
	 * scheme://host/path
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public function get_normalized_http_url( $url ) {
		$parts = parse_url( $url );

		$scheme = (isset($parts['scheme'])) ? $parts['scheme'] : 'http';
		$port = (isset($parts['port'])) ? $parts['port'] : (($scheme == 'https') ? '443' : '80');
		$host = (isset($parts['host'])) ? strtolower($parts['host']) : '';
		$path = (isset($parts['path'])) ? $parts['path'] : '';

		if (($scheme == 'https' && $port != '443') || ($scheme == 'http' && $port != '80'))
			$host = "$host:$port";

		return "$scheme://$host$path";
	}

	public function sign_request( &$parameters, $request_url, $method = 'GET' ) {
		$auth_params = array(
			'oauth_version'          => '1.0',
			'oauth_nonce'            => md5( microtime() . mt_rand() ),
			'oauth_timestamp'        => time(),
			'oauth_consumer_key'     => $this->_consumer_key,
			'oauth_signature_method' => 'HMAC-SHA1',
		);
		if ( ! empty( $this->_token['oauth_token'] ) ) {
			$auth_params['oauth_token'] = $this->_token['oauth_token'];
		}

		// For GET requests, oauth parameters are sent in the URL
		if ( 'GET' === $method ) {
			$parameters['body'] = array_merge( $parameters['body'], $auth_params );
			$parameters['body']['oauth_signature'] = $this->build_signature( $parameters['body'], $request_url, $method );
		} else {
			// for non-GET requests oauth parameters are sent via headers
			$auth_params['oauth_signature'] = $this->build_signature( array_merge( $parameters['body'], $auth_params ), $request_url, $method );
			foreach ( $auth_params as $key => $value ) {
				$auth_params[$key] = $key . '="' . rawurlencode( $value ) . '"';
			}
			$parameters['headers']['Authorization'] = 'OAuth ' . implode( ", ", $auth_params );
		}
	}

	public function bearer_auth( &$parameters, $request_url, $method = 'GET' ) {
		$parameters['headers']['Authorization'] = 'Bearer ' . $this->_bearer_token;
	}

	/**
	 * The request parameters, sorted and concatenated into a normalized string.
	 *
	 * @param array $parameters
	 *
	 * @return string
	 */
	public function get_signable_parameters( $parameters ) {
		// Remove oauth_signature if present
		// Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
		if ( isset( $parameters['oauth_signature'] ) )
			unset( $parameters['oauth_signature'] );

		return wpOAuthUtil::build_http_query( $parameters );
	}

	public function build_signature( $parameters, $request_url, $method = 'GET' ) {
		$parts = array(
			$method,
			$this->get_normalized_http_url( $request_url ),
			$this->get_signable_parameters( $parameters )
		);

		$parts = wpOAuthUtil::urlencode_rfc3986($parts);

		$base_string = implode('&', $parts);
		$token_secret = '';

		if ( ! empty( $this->_token['oauth_token_secret'] ) )
			$token_secret = $this->_token['oauth_token_secret'];

		$key_parts = array(
			$this->_consumer_secret,
			$token_secret,
		);

		$key_parts = wpOAuthUtil::urlencode_rfc3986( $key_parts );
		$key = implode( '&', $key_parts );

		return base64_encode( hash_hmac( 'sha1', $base_string, $key, true ) );
	}

	/**
	 * Exchange request token and secret for an access token and
	 * secret, to sign API calls.
	 *
	 * @param bool|string $oauth_verifier
	 *
	 * @returns array containing oauth_token,
	 *                           oauth_token_secret,
	 *                           user_id
	 *                           screen_name
	 */
	function get_access_token( $oauth_verifier = false ) {
		$parameters = array(
			'oauth_nonce' => md5( microtime() . mt_rand() ),
		);
		if ( ! empty( $oauth_verifier ) )
			$parameters['oauth_verifier'] = $oauth_verifier;

		$request_url = $this->get_api_endpoint( 'oauth/access_token', '' );
		$this->_token = $this->send_authed_request( $request_url, 'GET', $parameters );
		return $this->_token;
	}

	public function set_token( $token ) {
		$this->_token = $token;
	}
}
