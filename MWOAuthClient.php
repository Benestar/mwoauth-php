<?php

include_once './OAuth.php'; // reference php library from oauth.net

function wfDebugLog( $method, $msg) {
	// Uncomment this if you want debuggging info from the OAuth library
	//echo "[$method] $msg\n";
}


class MWOAuthClientConfig {

	// Url to the OAuth special page
	public $endpointURL;

	// Canonical server url, used to check /identify's iss
	public $canonicalServerUrl;

	// Url that the user is sent to. Can be different from
	// $endpointURL to play nice with MobileFrontend, etc.
	public $redirURL = null;

	// Use https when calling the server.
	// TODO: detect this from $endpointURL
	public $useSSL = true;

	// If you're testing against a server with self-signed certificates, you
	// can turn this off but don't do this in production.
	public $verifySSL = true;

	function __construct( $url, $useSSL, $verifySSL ) {
		$this->endpointURL = $url;
		$this->useSSL = $useSSL;
		$this->verifySSL = $verifySSL;
	}

}

class MWOAuthClient {

	// MWOAuthClientConfig
	private $config;

	// TODO: move this to $config
	private $consumerToken;

	// Any extra params in the call that need to be signed
	private $extraParams = array();

	// Track the last random nonce generated by the OAuth lib, used to
	// verify /identity response isn't a replay
	private $lastNonce;

	function __construct( MWOAuthClientConfig $config, OAuthToken $cmrToken ) {
		$this->consumerToken = $cmrToken;
		$this->config = $config;
	}


	public static function newFromKeyAndSecret( $url, $key, $secret ) {
		$cmrToken = new OAuthToken( $key, $secret );
		$config = new MWOAuthClientConfig( $url, true, true );
		return new self( $config, $cmrToken );
	}

	public function setExtraParam( $key, $value ) {
		$this->extraParams[$key] = $value;
	}

	public function setExtraParams( $params ) {
		$this->extraParams = $params;
	}

	/**
	 * First part of 3-legged OAuth, get the request Token.
	 * Redirect your authorizing users to the redirect url, and keep
	 * track of the request token since you need to pass it into complete()
	 *
	 * @return array (redirect, request/temp token)
	 */
	public function initiate() {
		$initUrl = $this->config->endpointURL . '/initiate&format=json&oauth_callback=oob';
		$data = $this->makeOAuthCall( null, $initUrl );
		$return = json_decode( $data );
		if ( $return->oauth_callback_confirmed !== 'true' ) {
			throw new Exception( "Callback wasn't confirmed" );
		}
		$requestToken = new OAuthToken( $return->key, $return->secret );
		$url = $this->config->redirURL ?: $this->config->endpointURL . "/authorize&";
		$url .= "oauth_token={$requestToken->key}&oauth_consumer_key={$this->consumerToken->key}";

		return array( $url, $requestToken );
	}

	/**
	 * The final leg of the OAuth handshake. Exchange the request Token from
	 * initiate() and the verification code that the user submitted back to you
	 * for an access token, which you'll use for all API calls.
	 *
	 * @param the authorization code sent to the callback url
	 * @param the temp/request token obtained from initiate, or null if this
	 *	object was used and the token is already set.
	 * @return OAuthToken The access token
	 */
	public function complete( OAuthToken $requestToken, $verifyCode ) {
		$tokenUrl = $this->config->endpointURL . '/token&format=json';
		$this->setExtraParam( 'oauth_verifier', $verifyCode );
		$data = $this->makeOAuthCall( $requestToken , $tokenUrl );
		$return = json_decode( $data );
		$accessToken = new OAuthToken( $return->key, $return->secret );
		$this->setExtraParams = array(); // cleanup after ourselves
		return $accessToken;
	}


	/**
	 * Optional step. This call the MediaWiki specific /identify method, which
	 * returns a signed statement of the authorizing user's identity. Use this
	 * if you are authenticating users in your application, and you need to
	 * know their username, groups, rights, etc in MediaWiki.
	 *
	 * @param OAuthToken access token from complete()
	 * @return object containing attributes of the user
	 */
	public function identify( OAuthToken $accessToken ) {
		$identifyUrl = $this->config->endpointURL . '/identify';
		$data = $this->makeOAuthCall( $accessToken, $identifyUrl );
		$identity = $this->decodeJWT( $data, $this->consumerToken->secret );

		if ( !$this->validateJWT(
			$identity,
			$this->consumerToken->key,
			$this->config->canonicalServerUrl,
			$this->lastNonce
		) ) {
			throw new Exception( "JWT didn't validate" );
		}

		return $identity;
	}

	/**
	 * Make a signed request to MediaWiki
	 *
	 * @param OAuthToken $token additional token to use in signature, besides the consumer token.
	 *	In most cases, this will be the access token you got from complete(), but we set it
	 *	to the request token when finishing the handshake.
	 * @param $url string url to call
	 * @param $isPost bool true if this should be a POST request
	 * @param $postFields array of POST parameters, only if $isPost is also true
	 * @return body from the curl request
	 */
	public function makeOAuthCall( $token, $url, $isPost = false, $postFields = false  ) {

		$params = array();

		// Get any params from the url
		if ( strpos( $url, '?' ) ) {
			$parsed = parse_url( $url );
			parse_str($parsed['query'], $params);
		}
		$params += $this->extraParams;

		$method = $isPost ? 'POST' : 'GET';
		$req = OAuthRequest::from_consumer_and_token(
			$this->consumerToken,
			$token,
			$method,
			$url,
			$params
		);
		$req->sign_request(
			new OAuthSignatureMethod_HMAC_SHA1(),
			$this->consumerToken,
			$token
		);

		$this->lastNonce = $req->get_parameter( 'oauth_nonce' );

		return $this->makeCurlCall(
			$url,
			$req->to_header(),
			$isPost,
			$postFields,
			$this->config
		);

	}


	private function makeCurlCall( $url, $headers, $isPost, $postFields, MWOAuthClientConfig $config ) {

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, (string) $url );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $headers ) );

		if ( $isPost ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $postFields ) );
		}

		if ( $config->useSSL ) {
			curl_setopt( $ch, CURLOPT_PORT , 443 );
		}

		if ( $config->verifySSL ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		}

		$data = curl_exec( $ch );
		if( !$data ) {
			throw new Exception ( 'Curl error: ' . curl_error( $ch ) );
		}

		return $data;
	}


	private function decodeJWT( $JWT, $secret ) {
		list( $headb64, $bodyb64, $sigb64 ) = explode( '.', $JWT );

		$header = json_decode( $this->urlsafeB64Decode( $headb64 ) );
		$payload = json_decode( $this->urlsafeB64Decode( $bodyb64 ) );
		$sig = $this->urlsafeB64Decode( $sigb64 );

		// MediaWiki will only use sha256 hmac (HS256) for now. This check makes sure
		// an attacker doesn't return a JWT with 'none' signature type.
		$expectSig = hash_hmac( 'sha256', "$headb64.$bodyb64", $secret, true);
		if ( $header->alg !== 'HS256' || !$this->compareHash( $sig, $expectSig ) ) {
			throw new Exception( "Invalid JWT signature from /identify." );
		}
		return $payload;
	}

	protected function validateJWT( $identity, $consumerKey, $expectedConnonicalServer, $nonce ) {
		// Verify the issuer is who we expect (server sends $wgCanonicalServer)
		if ( $identity->iss !== $expectedConnonicalServer ) {
			print "Invalid Issuer";
			return false;
		}
		// Verify we are the intended audience
		if ( $identity->aud !== $consumerKey ) {
			print "Invalid Audience";
			return false;
		}
		// Verify we are within the time limits of the token. Issued at (iat) should be
		// in the past, Expiration (exp) should be in the future.
		$now = time();
		if ( $identity->iat > $now || $identity->exp < $now ) {
			print "Invalid Time";
			return false;
		}
		// Verify we haven't seen this nonce before, which would indicate a replay attack
		if ( $identity->nonce !== $nonce ) {
			print "Invalid Nonce";
			return false;
		}
		return true;
	}

	private function urlsafeB64Decode( $input ) {
		$remainder = strlen( $input ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$input .= str_repeat( '=', $padlen );
		}
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	// Constant time comparison
	private function compareHash( $hash1, $hash2 ) {
		$result = strlen( $hash1 ) ^ strlen( $hash2 );
		$len = min( strlen( $hash1 ), strlen( $hash2 ) ) - 1;
		for ( $i = 0; $i < $len; $i++ ) {
			$result |= ord( $hash1{$i} ) ^ ord( $hash2{$i} );
		}
		return $result == 0;
	}

}
