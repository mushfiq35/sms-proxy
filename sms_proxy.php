<?php
/**
 * sms_proxy.php v6 — one secure SMS gateway for all client apps.
 *
 *   App (browser) ──[Firebase login token]──▶ this proxy (Render) ──[AGI keys]──▶ AGI SMS (http://IP:PORT)
 *
 * - AGI API key / secret key live ONLY here (Render Environment). Browsers never see them.
 * - Every request must carry a valid Firebase login of a MEMBER of a known client app (tenants.php).
 * - For billed clients the SMS balance is kept on the server (Firestore of the billing project,
 *   written with a service account). Clients cannot raise their own balance.
 * - Recharge / set balance only with a login of the billing project (Piconet Client Manager) + OWNER_EMAILS.
 *
 * Render > Environment:
 *   AGI_APIKEY, AGI_SECRETKEY   AGI keys (required)
 *   FIREBASE_SA_JSON            service-account JSON of the billing project (or Secret File /etc/secrets/firebase-sa.json)
 *   BILLING_PROJECT             default: piconet-cms
 *   OWNER_EMAILS                who may recharge, comma list (default mushfiq35@gmail.com)
 *   ALLOWED_ORIGIN              e.g. https://piconetit.com  (or * )
 *   AGI_BASE_URL                default http://103.48.119.37:2300
 *   DEBUG_MODE=1                only while testing: ?debug=1 shows config (no secrets)
 *
 * Actions (POST JSON, header  Authorization: Bearer <Firebase ID token>):
 *   send     {toUser, messageContent, callerID}            (default action)
 *   balance  {}                                             → this app's balance
 *   accounts {}                                             → all clients + balances   (owner only)
 *   recharge {tenant, type:"masking|nonmasking", amount, mode:"add|set"}   (owner only)
 *   createUser      {email, password, name}   create a login in THIS app's Firebase  (app admins only)
 *   setUserDisabled {email, disabled:true|false}  block / unblock a login              (app admins only)
 *     → lets you turn OFF public sign-up in Firebase, so strangers can't create accounts,
 *       while your clients still add staff from inside the app. Needs the service account to have
 *       the "Firebase Authentication Admin" role in that client's Google Cloud project.
 */

/* =============================== CONFIG =============================== */
function env( $k, $d = '' ) { $v = getenv( $k ); return ( $v === false || trim( $v ) === '' ) ? $d : trim( $v ); }
define( 'AGI_BASE_URL',     env( 'AGI_BASE_URL', 'http://103.48.119.37:2300' ) );
define( 'AGI_SEND_PATH',    env( 'AGI_SEND_PATH', '/sendtext' ) );
define( 'AGI_APIKEY',       env( 'AGI_APIKEY' ) );
define( 'AGI_SECRETKEY',    env( 'AGI_SECRETKEY' ) );
define( 'ALLOWED_ORIGIN',   env( 'ALLOWED_ORIGIN', '*' ) );
define( 'BILLING_PROJECT',  env( 'BILLING_PROJECT', 'piconet-cms' ) );
define( 'OWNER_EMAILS',     strtolower( env( 'OWNER_EMAILS', 'mushfiq35@gmail.com' ) ) );
define( 'DEBUG_MODE',       env( 'DEBUG_MODE' ) === '1' );
define( 'MEMBER_CACHE_MIN', max( 1, (int) env( 'MEMBER_CACHE_MIN', '15' ) ) ); // member check is re-read at most every N minutes per user
define( 'LEDGER',           env( 'LEDGER', '1' ) === '1' );                     // 1 write per SMS batch (no reads)
define( 'GOOGLE_CERTS_URL', env( 'GOOGLE_CERTS_URL', 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com' ) );
define( 'FIRESTORE_BASE',   env( 'FIRESTORE_BASE', 'https://firestore.googleapis.com/v1' ) );
define( 'OAUTH_TOKEN_URL',  env( 'OAUTH_TOKEN_URL', '' ) ); // test override only
define( 'IDTK_BASE',        env( 'IDTK_BASE', 'https://identitytoolkit.googleapis.com/v1' ) );
define( 'TMP',              sys_get_temp_dir() );
$TENANTS = require __DIR__ . '/tenants.php';
/* ===================================================================== */

header( 'Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN );
header( 'Vary: Origin' );
header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type, Accept, Authorization' );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
	http_response_code( 204 );
	exit;
}

function out( $arr, $code = 200 ) {
	http_response_code( $code );
	echo json_encode( $arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}
function reject( $why, $code = 400, $extra = array() ) {
	out( array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Proxy: ' . $why, 'Message_ID' => '-1' ) + $extra, $code );
}

/* ------------------------------ HTTP helper ------------------------------ */
function http( $method, $url, $headers = array(), $body = null, $timeout = 20 ) {
	$ch = curl_init( $url );
	$opt = array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true );
	if ( $body !== null ) {
		$opt[ CURLOPT_POSTFIELDS ] = $body;
	}
	curl_setopt_array( $ch, $opt );
	$resp = curl_exec( $ch );
	$err  = curl_error( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hs   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );
	if ( $resp === false ) {
		return array( 0, '', '', $err );
	}
	return array( $code, substr( $resp, $hs ), substr( $resp, 0, $hs ), '' );
}

/* ------------------------------ Input ------------------------------ */
$raw  = (string) file_get_contents( 'php://input' );
$body = array();
if ( $raw !== '' ) {
	$j = json_decode( $raw, true );
	if ( is_array( $j ) ) {
		$body = $j;
	} else {
		parse_str( $raw, $f );
		$body = is_array( $f ) ? $f : array();
	}
}
$in = array_merge( $_GET, $_POST, $body );
function pick( $in, $names ) {
	foreach ( $in as $k => $v ) {
		if ( ! is_array( $v ) && in_array( strtolower( preg_replace( '/[^a-z0-9]/i', '', $k ) ), $names, true ) && trim( (string) $v ) !== '' ) {
			return trim( (string) $v );
		}
	}
	return '';
}
// older apps send everything inside ?url=http://AGI/sendtext?... — unpack it (its keys are ignored)
$legacy = pick( $in, array( 'url' ) );
if ( $legacy !== '' && preg_match( '#^https?://#i', $legacy ) ) {
	parse_str( (string) parse_url( $legacy, PHP_URL_QUERY ), $qp );
	$in = array_merge( $qp, $in );
}

/* ------------------------------ Debug (no secrets) ------------------------------ */
if ( isset( $_GET['debug'] ) ) {
	if ( ! DEBUG_MODE ) {
		reject( 'debug is off', 403 );
	}
	out( array(
		'debug' => true, 'agi_base' => AGI_BASE_URL, 'agi_keys_set' => AGI_APIKEY !== '' && AGI_SECRETKEY !== '',
		'service_account_set' => sa_json() !== null, 'billing_project' => BILLING_PROJECT, 'owners' => OWNER_EMAILS,
		'sa_files' => array_map( function ( $f ) { return array( 'path' => $f, 'exists' => file_exists( $f ), 'readable' => is_readable( $f ) ); }, array( '/var/private/firebase-sa.json', '/etc/secrets/firebase-sa.json' ) ),
		'sa_client_email' => ( sa_json()['client_email'] ?? null ), 'sa_project' => ( sa_json()['project_id'] ?? null ),
		'sa_token_ok' => sa_token() !== null, 'sa_error' => $GLOBALS['SA_ERR'] ?? '',
		'php_user' => function_exists( 'posix_geteuid' ) ? posix_getpwuid( posix_geteuid() )['name'] : get_current_user(),
		'tenants' => array_keys( $TENANTS ), 'origin' => ALLOWED_ORIGIN,
	) );
}

/* ============================ Firebase login check ============================ */
function b64url_dec( $s ) { return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); }
function b64url_enc( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
function google_certs( $force = false ) {
	$cache = TMP . '/sms_proxy_gcerts.json';
	if ( ! $force && is_file( $cache ) ) {
		$c = json_decode( (string) @file_get_contents( $cache ), true );
		if ( is_array( $c ) && ( $c['exp'] ?? 0 ) > time() ) {
			return $c['keys'];
		}
	}
	list( $code, $b, $h ) = http( 'GET', GOOGLE_CERTS_URL, array(), null, 10 );
	$keys = json_decode( $b, true );
	if ( $code !== 200 || ! is_array( $keys ) ) {
		return array();
	}
	$ttl = preg_match( '/max-age=(\d+)/i', $h, $m ) ? (int) $m[1] : 3600;
	@file_put_contents( $cache, json_encode( array( 'exp' => time() + min( $ttl, 21600 ), 'keys' => $keys ) ) );
	return $keys;
}
/** @return array claims (+ 'project') or string error */
function verify_login( $jwt, $tenants ) {
	$p = explode( '.', (string) $jwt );
	if ( count( $p ) !== 3 ) {
		return 'login token missing — please log in again';
	}
	$h = json_decode( b64url_dec( $p[0] ), true );
	$c = json_decode( b64url_dec( $p[1] ), true );
	if ( ! is_array( $h ) || ! is_array( $c ) || ( $h['alg'] ?? '' ) !== 'RS256' || empty( $h['kid'] ) ) {
		return 'bad login token';
	}
	$certs = google_certs();
	if ( ! isset( $certs[ $h['kid'] ] ) ) {
		$certs = google_certs( true );
	}
	if ( ! isset( $certs[ $h['kid'] ] ) || openssl_verify( $p[0] . '.' . $p[1], b64url_dec( $p[2] ), $certs[ $h['kid'] ], OPENSSL_ALGO_SHA256 ) !== 1 ) {
		return 'invalid login token';
	}
	$proj = (string) ( $c['aud'] ?? '' );
	if ( ! isset( $tenants[ $proj ] ) && $proj !== BILLING_PROJECT ) {
		return 'this app (' . $proj . ') is not registered for SMS';
	}
	if ( ( $c['iss'] ?? '' ) !== 'https://securetoken.google.com/' . $proj ) {
		return 'bad token issuer';
	}
	$now = time();
	if ( ( $c['exp'] ?? 0 ) < $now - 30 ) {
		return 'login expired — reload the app';
	}
	if ( ( $c['iat'] ?? 0 ) > $now + 300 || empty( $c['sub'] ) ) {
		return 'bad token time';
	}
	$c['project'] = $proj;
	$c['email']   = strtolower( trim( (string) ( $c['email'] ?? '' ) ) );
	return $c;
}
/** Reads the member document AS THE USER (that app's Firestore rules apply).
 *  @return array member doc, or string error. Cached MEMBER_CACHE_MIN minutes → very few reads. */
function check_member( $claims, $idToken, $tenant ) {
	$m     = $tenant['members'] ?? array();
	$email = $claims['email'];
	if ( $email === '' ) {
		return 'account has no email';
	}
	if ( in_array( $email, array_map( 'strtolower', (array) ( $tenant['primary_emails'] ?? array() ) ), true ) ) {
		return array( 'role' => (string) ( $tenant['primary_role'] ?? 'super' ) ); // app owner, no member record needed
	}
	$cache = TMP . '/sms_proxy_mem_' . md5( $claims['project'] . '|' . $claims['sub'] . '|' . $email );
	$cacheMin = max( 1, (int) ( $tenant['member_cache_min'] ?? MEMBER_CACHE_MIN ) );
	if ( is_file( $cache ) && filemtime( $cache ) > time() - $cacheMin * 60 ) {
		$doc = json_decode( (string) @file_get_contents( $cache ), true );
		if ( is_array( $doc ) ) {
			return $doc;
		}
	}
	$url = FIRESTORE_BASE . '/projects/' . rawurlencode( $claims['project'] ) . '/databases/(default)/documents/'
		. implode( '/', array_map( 'rawurlencode', explode( '/', trim( $m['collection'] ?? 'members', '/' ) ) ) ) . '/' . rawurlencode( ( $m['key'] ?? 'email' ) === 'uid' ? $claims['sub'] : $email );
	list( $code, $b ) = http( 'GET', $url, array( 'Authorization: Bearer ' . $idToken ), null, 10 );
	if ( $code !== 200 ) {
		@unlink( $cache );
		return in_array( $code, array( 403, 404 ), true ) ? 'user ' . $email . ' is not an active member of this app' : 'could not check membership (HTTP ' . $code . ')';
	}
	$doc = fs_decode( json_decode( $b, true )['fields'] ?? array() );
	foreach ( (array) ( $m['where'] ?? array() ) as $f => $want ) {
		if ( ( $doc[ $f ] ?? null ) !== $want ) {
			return 'user ' . $email . ' is not active';
		}
	}
	if ( ! empty( $m['roles'] ) && ! in_array( $doc['role'] ?? '', $m['roles'], true ) ) {
		return 'user ' . $email . ' has no SMS permission';
	}
	@file_put_contents( $cache, json_encode( array( 'role' => (string) ( $doc['role'] ?? '' ) ) ) );
	return $doc;
}

/* ============================ Firestore (billing, service account) ============================ */
function sa_json() {
	static $sa = false;
	if ( $sa !== false ) {
		return $sa;
	}
	$raw = env( 'FIREBASE_SA_JSON' );
	// start.sh copies Render's Secret File here (outside the web root, readable by Apache)
	foreach ( array( '/var/private/firebase-sa.json', '/etc/secrets/firebase-sa.json' ) as $f ) {
		if ( $raw === '' && is_readable( $f ) ) {
			$raw = (string) file_get_contents( $f );
		}
	}
	if ( $raw !== '' && $raw[0] !== '{' ) {
		$raw = (string) base64_decode( $raw );
	}
	$j  = json_decode( $raw, true );
	$sa = ( is_array( $j ) && ! empty( $j['private_key'] ) && ! empty( $j['client_email'] ) ) ? $j : null;
	return $sa;
}
$GLOBALS['SA_ERR'] = '';
function sa_token() {
	$sa = sa_json();
	if ( ! $sa ) {
		$GLOBALS['SA_ERR'] = 'service-account file not found or not valid JSON';
		return null;
	}
	$cache = TMP . '/sms_proxy_sa2_' . md5( $sa['client_email'] ) . '.json';
	$c     = is_file( $cache ) ? json_decode( (string) @file_get_contents( $cache ), true ) : null;
	if ( is_array( $c ) && ( $c['exp'] ?? 0 ) > time() + 60 ) {
		return $c['token'];
	}
	$aud = OAUTH_TOKEN_URL ?: ( $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token' );
	$now = time();
	$h   = b64url_enc( json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$p   = b64url_enc( json_encode( array( 'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/cloud-platform', 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600 ) ) );
	if ( ! openssl_sign( "$h.$p", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
		$GLOBALS['SA_ERR'] = 'could not sign with private_key (' . openssl_error_string() . ')';
		return null;
	}
	list( $code, $b ) = http( 'POST', $aud, array( 'Content-Type: application/x-www-form-urlencoded' ),
		http_build_query( array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$h.$p." . b64url_enc( $sig ) ) ), 15 );
	$j = json_decode( $b, true );
	if ( $code !== 200 || empty( $j['access_token'] ) ) {
		$GLOBALS['SA_ERR'] = 'Google token request failed: HTTP ' . $code . ' ' . substr( (string) ( $j['error_description'] ?? $j['error'] ?? $b ), 0, 200 );
		return null;
	}
	@file_put_contents( $cache, json_encode( array( 'token' => $j['access_token'], 'exp' => $now + (int) ( $j['expires_in'] ?? 3600 ) ) ) );
	@chmod( $cache, 0600 );
	return $j['access_token'];
}
function fs_root() { return 'projects/' . BILLING_PROJECT . '/databases/(default)/documents'; }
function fs_call( $method, $path, $body = null ) {
	$tok = sa_token();
	if ( ! $tok ) {
		return array( 0, array( 'error' => 'service account not configured: ' . $GLOBALS['SA_ERR'] ) );
	}
	list( $code, $b, , $err ) = http( $method, FIRESTORE_BASE . '/' . $path, array( 'Authorization: Bearer ' . $tok, 'Content-Type: application/json' ), $body === null ? null : json_encode( $body ), 15 );
	return array( $code, $err ? array( 'error' => $err ) : (array) json_decode( $b, true ) );
}
function fs_val( $v ) {
	if ( is_bool( $v ) ) { return array( 'booleanValue' => $v ); }
	if ( is_int( $v ) ) { return array( 'integerValue' => (string) $v ); }
	if ( is_float( $v ) ) { return array( 'doubleValue' => $v ); }
	if ( $v === null ) { return array( 'nullValue' => null ); }
	return array( 'stringValue' => (string) $v );
}
function fs_decode( $fields ) {
	$o = array();
	foreach ( (array) $fields as $k => $v ) {
		if ( isset( $v['integerValue'] ) ) { $o[ $k ] = (int) $v['integerValue']; }
		elseif ( isset( $v['doubleValue'] ) ) { $o[ $k ] = (float) $v['doubleValue']; }
		elseif ( array_key_exists( 'booleanValue', $v ) ) { $o[ $k ] = (bool) $v['booleanValue']; }
		elseif ( isset( $v['stringValue'] ) ) { $o[ $k ] = $v['stringValue']; }
		else { $o[ $k ] = null; }
	}
	return $o;
}
function acct_path( $tenantId ) { return fs_root() . '/sms_accounts/' . rawurlencode( $tenantId ); }

/** @return array [masking, nonmasking] or string error */
function get_balance( $tenantId ) {
	list( $code, $j ) = fs_call( 'GET', acct_path( $tenantId ) );
	if ( $code === 404 ) {
		return array( 'masking' => 0, 'nonmasking' => 0 );
	}
	if ( $code !== 200 ) {
		return 'balance store unavailable (' . ( $j['error']['message'] ?? $j['error'] ?? $code ) . ')';
	}
	$d = fs_decode( $j['fields'] ?? array() );
	return array( 'masking' => (int) ( $d['masking'] ?? 0 ), 'nonmasking' => (int) ( $d['nonmasking'] ?? 0 ) );
}
/** Atomically add $delta (can be negative) to one balance — ONE write, ZERO reads.
 *  Firestore's increment() is atomic and returns the new value. If a deduction would go below 0,
 *  it is put back at once (one more write) and 'insufficient' is returned.
 *  With $setTo the balance is simply set. @return int new balance of $type, or string error */
function change_balance( $tenantId, $type, $delta, $mustCover, $setTo = null ) {
	$name = acct_path( $tenantId );
	if ( $setTo !== null ) {
		$w = array( 'update' => array( 'name' => $name, 'fields' => array( $type => fs_val( (int) $setTo ), 'updatedAt' => fs_val( gmdate( 'c' ) ) ) ),
			'updateMask' => array( 'fieldPaths' => array( $type, 'updatedAt' ) ) );
		list( $c, $j ) = fs_call( 'POST', fs_root() . ':commit', array( 'writes' => array( $w ) ) );
		return $c === 200 ? (int) $setTo : 'balance update failed (' . ( $j['error']['message'] ?? $j['error'] ?? $c ) . ')';
	}
	$inc = function ( $d ) use ( $name, $type ) {
		$w = array(
			'update'           => array( 'name' => $name, 'fields' => array( 'updatedAt' => fs_val( gmdate( 'c' ) ) ) ),
			'updateMask'       => array( 'fieldPaths' => array( 'updatedAt' ) ),
			'updateTransforms' => array( array( 'fieldPath' => $type, 'increment' => array( 'integerValue' => (string) (int) $d ) ) ),
		);
		list( $c, $j ) = fs_call( 'POST', fs_root() . ':commit', array( 'writes' => array( $w ) ) );
		if ( $c !== 200 ) {
			return 'balance update failed (' . ( $j['error']['message'] ?? $j['error'] ?? $c ) . ')';
		}
		$v = $j['writeResults'][0]['transformResults'][0] ?? array();
		return (int) ( $v['integerValue'] ?? $v['doubleValue'] ?? 0 );
	};
	$new = $inc( $delta );
	if ( is_string( $new ) ) {
		return $new;
	}
	if ( $mustCover && $new < 0 ) {
		$inc( -$delta ); // put it back
		return 'insufficient';
	}
	return $new;
}
function ledger( $row ) {
	if ( ! LEDGER ) {
		return;
	}
	$fields = array();
	foreach ( $row as $k => $v ) {
		$fields[ $k ] = fs_val( $v );
	}
	$fields['at'] = fs_val( gmdate( 'c' ) );
	fs_call( 'POST', fs_root() . '/sms_ledger', array( 'fields' => $fields ) );
}

/* ============================ SMS helpers ============================ */
function norm_phone( $n ) {
	$d = preg_replace( '/\D+/', '', (string) $n );
	if ( strpos( $d, '00880' ) === 0 ) { $d = substr( $d, 4 ); }
	elseif ( strpos( $d, '880' ) === 0 ) { $d = substr( $d, 3 ); }
	if ( strlen( $d ) === 10 && $d[0] === '1' ) { $d = '0' . $d; }
	return preg_match( '/^01[3-9]\d{8}$/', $d ) ? '88' . $d : false;
}
/** Same counting as the apps: GSM 160/153, Unicode 70/67 */
function sms_parts( $text ) {
	static $basic = null, $ext = null;
	if ( $basic === null ) {
		$basic = preg_split( '//u', "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", -1, PREG_SPLIT_NO_EMPTY );
		$basic = array_flip( $basic );
		$ext   = array_flip( preg_split( '//u', "^{}\\[~]|€\f", -1, PREG_SPLIT_NO_EMPTY ) );
	}
	$len = 0;
	$uni = false;
	foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
		if ( isset( $basic[ $ch ] ) ) { $len += 1; }
		elseif ( isset( $ext[ $ch ] ) ) { $len += 2; }
		else { $uni = true; break; }
	}
	if ( $uni ) {
		$len = (int) ( strlen( mb_convert_encoding( (string) $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
	}
	if ( $len === 0 ) {
		return 0;
	}
	$single = $uni ? 70 : 160;
	$multi  = $uni ? 67 : 153;
	return $len <= $single ? 1 : (int) ceil( $len / $multi );
}
function sender_type( $sender ) { return preg_match( '/^\+?\d+$/', trim( $sender ) ) ? 'nonmasking' : 'masking'; }
function agi_send( $to, $msg, $sender ) {
	$q = http_build_query( array( 'apikey' => AGI_APIKEY, 'secretkey' => AGI_SECRETKEY, 'callerID' => $sender, 'toUser' => $to, 'messageContent' => $msg ), '', '&', PHP_QUERY_RFC3986 );
	list( $code, $b, , $err ) = http( 'GET', rtrim( AGI_BASE_URL, '/' ) . '/' . ltrim( AGI_SEND_PATH, '/' ) . '?' . $q, array(), null, 30 );
	$j = json_decode( $b, true );
	if ( is_array( $j ) ) {
		return $j;
	}
	return array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => $err ? 'could not reach AGI: ' . $err : 'AGI reply HTTP ' . $code . ': ' . substr( $b, 0, 150 ) );
}

/* ============================ 1. Who is calling? ============================ */
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
if ( $auth === '' && function_exists( 'getallheaders' ) ) {
	foreach ( getallheaders() as $hk => $hv ) {
		if ( strcasecmp( $hk, 'Authorization' ) === 0 ) { $auth = $hv; }
	}
}
$idToken = preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ? $m[1] : pick( $in, array( 'idtoken' ) );
$user    = verify_login( $idToken, $TENANTS );
if ( ! is_array( $user ) ) {
	reject( $user, 401 );
}
$tenantId = $user['project'];
$tenant   = $TENANTS[ $tenantId ] ?? null;
$isOwner  = $tenantId === BILLING_PROJECT && in_array( $user['email'], array_map( 'trim', explode( ',', OWNER_EMAILS ) ), true );

if ( $tenant ) {
	$mem = check_member( $user, $idToken, $tenant );
	if ( ! is_array( $mem ) ) {
		reject( $mem, 403 );
	}
} elseif ( ! $isOwner ) {
	reject( 'not allowed', 403 );
}

$action = strtolower( pick( $in, array( 'action' ) ) ?: 'send' );

/* ============================ 2. Owner actions ============================ */
if ( $action === 'accounts' || $action === 'recharge' ) {
	if ( ! $isOwner ) {
		reject( 'only the owner can do this', 403 );
	}
	if ( $action === 'recharge' ) {
		$tid  = pick( $in, array( 'tenant' ) );
		$type = pick( $in, array( 'type' ) );
		$amt  = pick( $in, array( 'amount' ) );
		$mode = pick( $in, array( 'mode' ) ) ?: 'add';
		if ( ! isset( $TENANTS[ $tid ] ) || empty( $TENANTS[ $tid ]['billing'] ) ) {
			reject( 'unknown or non-billed client "' . $tid . '"' );
		}
		if ( ! in_array( $type, array( 'masking', 'nonmasking' ), true ) || ! preg_match( '/^\d{1,7}$/', $amt ) ) {
			reject( 'type must be masking/nonmasking and amount a whole number' );
		}
		$r = $mode === 'set' ? change_balance( $tid, $type, 0, false, (int) $amt ) : change_balance( $tid, $type, (int) $amt, false );
		if ( ! is_int( $r ) ) {
			reject( $r, 503 );
		}
		ledger( array( 'tenant' => $tid, 'kind' => 'recharge_' . $mode, 'type' => $type, 'credits' => (int) $amt, 'by' => $user['email'] ) );
		out( array( 'Status' => '0', 'Text' => 'OK', 'tenant' => $tid, 'Type' => $type, 'Balance' => array( $type => $r ) ) );
	}
	$list = array();
	foreach ( $TENANTS as $tid => $t ) {
		$list[] = array( 'tenant' => $tid, 'name' => $t['name'] ?? $tid, 'billing' => ! empty( $t['billing'] ),
			'Balance' => ! empty( $t['billing'] ) ? get_balance( $tid ) : null, 'senders' => $t['senders'] ?? array() );
	}
	out( array( 'Status' => '0', 'Text' => 'OK', 'accounts' => $list ) );
}

if ( ! $tenant ) {
	reject( 'this login cannot send SMS', 400 );
}
$billing = ! empty( $tenant['billing'] );

/* ============================ 2b. Create / block logins (sign-up can stay OFF in Firebase) ============================ */
if ( $action === 'createuser' || $action === 'setuserdisabled' ) {
	$adminRoles = (array) ( $tenant['user_admin_roles'] ?? array() );
	if ( $adminRoles && ! in_array( (string) ( $mem['role'] ?? '' ), $adminRoles, true ) ) {
		reject( 'only ' . implode( '/', $adminRoles ) . ' users can manage logins', 403 );
	}
	$target = strtolower( pick( $in, array( 'email' ) ) );
	if ( ! filter_var( $target, FILTER_VALIDATE_EMAIL ) ) {
		reject( 'valid email required' );
	}
	$protected = array_merge( array( $user['email'] ), array_map( 'trim', explode( ',', OWNER_EMAILS ) ), (array) ( $tenant['primary_emails'] ?? array() ) );
	$tok = sa_token();
	if ( ! $tok ) {
		reject( 'service account not configured on the proxy', 500 );
	}
	$api = IDTK_BASE . '/projects/' . rawurlencode( $tenantId ) . '/accounts';
	$hdr = array( 'Authorization: Bearer ' . $tok, 'Content-Type: application/json' );
	if ( $action === 'createuser' ) {
		$pass = (string) ( $in['password'] ?? '' );
		if ( strlen( $pass ) < 6 ) {
			reject( 'password must be at least 6 characters' );
		}
		$req = array( 'email' => $target, 'password' => $pass );
		if ( ( $nm = pick( $in, array( 'name', 'displayname' ) ) ) !== '' ) {
			$req['displayName'] = $nm;
		}
		list( $c, $b ) = http( 'POST', $api, $hdr, json_encode( $req ), 15 );
		$j = (array) json_decode( $b, true );
		if ( $c === 200 && ! empty( $j['localId'] ) ) {
			out( array( 'Status' => '0', 'Text' => 'OK', 'result' => 'created', 'uid' => $j['localId'] ) );
		}
		$msg = (string) ( $j['error']['message'] ?? $c );
		if ( strpos( $msg, 'EMAIL_EXISTS' ) !== false || strpos( $msg, 'DUPLICATE_EMAIL' ) !== false ) {
			list( , $lb ) = http( 'POST', $api . ':lookup', $hdr, json_encode( array( 'email' => array( $target ) ) ), 15 );
			out( array( 'Status' => '0', 'Text' => 'OK', 'result' => 'existing', 'uid' => ( (array) json_decode( $lb, true ) )['users'][0]['localId'] ?? '' ) );
		}
		reject( 'could not create login (' . $msg . ')', $c === 403 ? 403 : 502 );
	}
	// block / unblock
	if ( in_array( $target, $protected, true ) ) {
		reject( 'this account cannot be blocked here', 403 );
	}
	$disable = in_array( strtolower( (string) ( $in['disabled'] ?? 'true' ) ), array( '1', 'true', 'yes' ), true );
	list( $c, $b ) = http( 'POST', $api . ':lookup', $hdr, json_encode( array( 'email' => array( $target ) ) ), 15 );
	$lid = ( (array) json_decode( $b, true ) )['users'][0]['localId'] ?? '';
	if ( $c !== 200 ) {
		reject( 'could not find login (HTTP ' . $c . ')', 502 );
	}
	if ( $lid === '' ) {
		out( array( 'Status' => '0', 'Text' => 'OK', 'result' => 'no_login' ) );
	}
	list( $c, $b ) = http( 'POST', $api . ':update', $hdr, json_encode( array( 'localId' => $lid, 'disableUser' => $disable ) ), 15 );
	if ( $c !== 200 ) {
		reject( 'could not update login (HTTP ' . $c . ')', 502 );
	}
	out( array( 'Status' => '0', 'Text' => 'OK', 'result' => $disable ? 'disabled' : 'enabled' ) );
}

/* ============================ 3. Balance ============================ */
if ( $action === 'balance' ) {
	if ( ! $billing ) {
		out( array( 'Status' => '0', 'Text' => 'OK', 'billing' => false, 'Balance' => null ) );
	}
	$b = get_balance( $tenantId );
	if ( ! is_array( $b ) ) {
		reject( $b, 503 );
	}
	out( array( 'Status' => '0', 'Text' => 'OK', 'billing' => true, 'Balance' => $b ) );
}
if ( $action !== 'send' ) {
	reject( 'unknown action' );
}

/* ============================ 4. Send ============================ */
if ( AGI_APIKEY === '' || AGI_SECRETKEY === '' ) {
	reject( 'AGI_APIKEY / AGI_SECRETKEY not set in Render', 500 );
}
$sender  = pick( $in, array( 'callerid', 'senderid', 'sender' ) );
$message = pick( $in, array( 'messagecontent', 'message', 'msg', 'text' ) );
$toRaw   = pick( $in, array( 'touser', 'to', 'number', 'phone', 'mobile' ) );
if ( $sender === '' ) { reject( 'callerID (Sender ID) missing' ); }
if ( $message === '' ) { reject( 'message is empty' ); }
$allowed = array_map( 'strtolower', (array) ( $tenant['senders'] ?? array() ) );
if ( $allowed && ! in_array( strtolower( $sender ), $allowed, true ) ) {
	reject( 'sender "' . $sender . '" is not allowed for this app', 403 );
}
$numbers = array();
foreach ( preg_split( '/[\s,;]+/', $toRaw, -1, PREG_SPLIT_NO_EMPTY ) as $ph ) {
	if ( $n = norm_phone( $ph ) ) { $numbers[ $n ] = true; }
}
$numbers = array_keys( $numbers );
if ( ! $numbers ) { reject( 'valid phone number missing (01XXXXXXXXX)' ); }
if ( count( $numbers ) > 100 ) { reject( 'max 100 numbers per request' ); }

$type    = sender_type( $sender );
$parts   = sms_parts( $message );
$credits = $parts * count( $numbers );

if ( $billing ) {
	$r = change_balance( $tenantId, $type, -$credits, true );
	if ( $r === 'insufficient' ) {
		reject( 'Insufficient Balance', 402, array( 'Status' => '-4', 'Credits' => $credits, 'Type' => $type ) );
	}
	if ( ! is_int( $r ) ) {
		reject( $r, 503 );
	}
}

$results = array();
$failed  = 0;
foreach ( $numbers as $n ) {
	$res = agi_send( (string) $n, $message, $sender ) + array( 'toUser' => (string) $n );
	if ( (string) ( $res['Status'] ?? '' ) !== '0' ) {
		$failed++;
	}
	$results[] = $res;
}
$balance = null;
if ( $billing ) {
	$balance = $failed ? change_balance( $tenantId, $type, $failed * $parts, false ) : $r; // refund failed ones
	ledger( array( 'tenant' => $tenantId, 'kind' => 'send', 'type' => $type, 'credits' => ( count( $numbers ) - $failed ) * $parts,
		'refunded' => $failed * $parts, 'numbers' => count( $numbers ), 'by' => $user['email'], 'sender' => $sender ) );
}
$extra = array( 'Credits' => ( count( $numbers ) - $failed ) * $parts, 'Type' => $type, 'Balance' => is_int( $balance ) ? array( $type => $balance ) : null );
if ( count( $results ) === 1 ) {
	out( $results[0] + $extra );
}
out( array( 'Status' => $failed ? '-1' : '0', 'Text' => $failed ? 'PARTIAL' : 'ACCEPTD', 'Sent' => count( $results ) - $failed, 'Failed' => $failed, 'Results' => $results ) + $extra );
