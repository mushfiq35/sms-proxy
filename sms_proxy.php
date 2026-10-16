<?php
/**
 * sms_proxy.php — Bridge between an HTTPS app (POS) and the AGI SMS HTTP server (http://IP:PORT)
 *
 * - Accepts GET, POST form-data, x-www-form-urlencoded, or JSON body
 * - Accepts many parameter name variants (apikey / api_key / apiKey ...)
 * - Tells you EXACTLY which field is missing instead of AGI's "Missing required parameter"
 * - Optional: put your keys here so the POS never needs to send them
 * - Debug: add  ?debug=1  to see what the proxy received (keys hidden), nothing is sent
 */

/* ======================= CONFIG (optional) ======================= */
// Values come from Render > Environment (recommended, keeps keys out of GitHub).
// The text after ?: is only a fallback if the environment variable is not set.
define( 'AGI_BASE_URL',      getenv( 'AGI_BASE_URL' )   ?: 'http://103.48.119.37:2300' );
define( 'AGI_SEND_PATH',     getenv( 'AGI_SEND_PATH' )  ?: '/sendtext' );
define( 'DEFAULT_APIKEY',    getenv( 'AGI_APIKEY' )     ?: '' );   // never write real keys here if the repo is public
define( 'DEFAULT_SECRETKEY', getenv( 'AGI_SECRETKEY' )  ?: '' );
define( 'DEFAULT_CALLERID',  getenv( 'AGI_CALLERID' )   ?: '' );   // e.g. 8809617640273 or masking name

// Which websites may call this proxy. '*' = any. Better: 'https://your-pos-domain.com'
define( 'ALLOWED_ORIGIN', getenv( 'ALLOWED_ORIGIN' ) ?: '*' );
/* ================================================================= */

header( 'Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN );
header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With' );
header( 'Content-Type: application/json; charset=utf-8' );
if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
	http_response_code( 204 );
	exit;
}

function out( $arr, $code = 200 ) {
	http_response_code( $code );
	echo json_encode( $arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

/* ---------- 1. Collect input from every possible place ---------- */
$in  = array();
$raw = file_get_contents( 'php://input' );
if ( $raw !== '' && $raw !== false ) {
	$json = json_decode( $raw, true );
	if ( is_array( $json ) ) {
		$in = $json;
	} else {
		parse_str( $raw, $form );
		if ( is_array( $form ) ) {
			$in = $form;
		}
	}
}
$in = array_merge( $_GET, $_POST, $in ); // body wins over query string
// flatten one level (e.g. {"sms": {...}} or {"data": {...}})
foreach ( array( 'data', 'sms', 'payload', 'params' ) as $wrap ) {
	if ( isset( $in[ $wrap ] ) && is_array( $in[ $wrap ] ) ) {
		$in = array_merge( $in, $in[ $wrap ] );
	}
}
$lower = array();
foreach ( $in as $k => $v ) {
	if ( ! is_array( $v ) ) {
		$lower[ strtolower( str_replace( array( '_', '-' ), '', $k ) ) ] = trim( (string) $v );
	} elseif ( in_array( strtolower( $k ), array( 'to', 'touser', 'numbers', 'number', 'phones' ), true ) ) {
		$lower[ strtolower( $k ) ] = implode( ',', $v ); // array of numbers
	}
}
function pick( $lower, $names, $default = '' ) {
	foreach ( $names as $n ) {
		$n = strtolower( str_replace( array( '_', '-' ), '', $n ) );
		if ( isset( $lower[ $n ] ) && $lower[ $n ] !== '' ) {
			return $lower[ $n ];
		}
	}
	return $default;
}

$base    = AGI_BASE_URL;
$apikey  = pick( $lower, array( 'apikey', 'key', 'token', 'apitoken' ), DEFAULT_APIKEY );
$secret  = pick( $lower, array( 'secretkey', 'secret', 'apisecret' ), DEFAULT_SECRETKEY );
$caller  = pick( $lower, array( 'callerid', 'caller', 'senderid', 'sender', 'from', 'masking', 'mask' ), DEFAULT_CALLERID );
$to      = pick( $lower, array( 'touser', 'to', 'number', 'numbers', 'phone', 'mobile', 'msisdn', 'contacts', 'recipient' ) );
$message = pick( $lower, array( 'messagecontent', 'message', 'msg', 'text', 'sms', 'content', 'body' ) );

/* ---------- 2. Normalize numbers -> 8801XXXXXXXXX ---------- */
$nums = array();
foreach ( preg_split( '/[\s,;]+/', $to ) as $n ) {
	$d = preg_replace( '/\D+/', '', $n );
	if ( $d === '' ) {
		continue;
	}
	if ( strpos( $d, '00880' ) === 0 ) {
		$d = substr( $d, 4 );
	} elseif ( strpos( $d, '880' ) === 0 ) {
		$d = substr( $d, 3 );
	}
	if ( strlen( $d ) === 10 && $d[0] === '1' ) {
		$d = '0' . $d;
	}
	if ( preg_match( '/^01[3-9]\d{8}$/', $d ) ) {
		$nums[] = '88' . $d;
	}
}
$nums = array_values( array_unique( $nums ) );

/* ---------- 3. Debug mode ---------- */
$mask = function ( $s ) { return $s === '' ? '(EMPTY)' : '***' . substr( $s, -3 ); };
if ( isset( $_GET['debug'] ) ) {
	out( array(
		'debug'          => true,
		'method'         => $_SERVER['REQUEST_METHOD'] ?? '',
		'content_type'   => $_SERVER['CONTENT_TYPE'] ?? '',
		'received_keys'  => array_keys( $in ),
		'base'           => $base,
		'apikey'         => $mask( $apikey ),
		'secretkey'      => $mask( $secret ),
		'callerID'       => $caller === '' ? '(EMPTY)' : $caller,
		'toUser_raw'     => $to === '' ? '(EMPTY)' : $to,
		'toUser_valid'   => $nums,
		'messageContent' => $message === '' ? '(EMPTY)' : $message,
		'note'           => 'Debug only — no SMS sent.',
	) );
}

/* ---------- 4. Validate: say exactly what's missing ---------- */
$missing = array();
if ( $apikey === '' )  { $missing[] = 'apikey (API Key)'; }
if ( $secret === '' )  { $missing[] = 'secretkey (Secret Key)'; }
if ( $caller === '' )  { $missing[] = 'callerID (Sender / Caller ID)'; }
if ( $to === '' )      { $missing[] = 'toUser (customer phone number)'; }
elseif ( ! $nums )     { $missing[] = 'toUser — number invalid: "' . $to . '" (01XXXXXXXXX hote hobe)'; }
if ( $message === '' ) { $missing[] = 'messageContent (message text)'; }
if ( $missing ) {
	out( array(
		'Status'            => '-1',
		'Text'              => 'REJECTD',
		'StatusDescription' => 'Proxy: missing ' . implode( ', ', $missing ),
		'Missing'           => $missing,
		'Received'          => array_keys( $in ),
		'Message_ID'        => '-1',
	), 400 );
}
if ( ! preg_match( '#^https?://#i', $base ) ) {
	out( array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Proxy: invalid base URL "' . $base . '"' ), 400 );
}

/* ---------- 5. Forward to AGI (one request per number) ---------- */
function agi_get( $url ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10 ) );
		$body = curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return array( $code, $body === false ? '' : $body, $err );
	}
	$ctx  = stream_context_create( array( 'http' => array( 'timeout' => 30, 'ignore_errors' => true ) ) );
	$body = @file_get_contents( $url, false, $ctx );
	return array( $body === false ? 0 : 200, (string) $body, $body === false ? 'connection failed' : '' );
}

$endpoint = rtrim( $base, '/' ) . '/' . ltrim( AGI_SEND_PATH, '/' );
$results  = array();
foreach ( $nums as $n ) {
	$q = http_build_query( array(
		'apikey'         => $apikey,
		'secretkey'      => $secret,
		'callerID'       => $caller,
		'toUser'         => $n,
		'messageContent' => $message,
	), '', '&', PHP_QUERY_RFC3986 );
	list( $code, $body, $err ) = agi_get( $endpoint . '?' . $q );
	$j = json_decode( $body, true );
	$results[] = is_array( $j ) ? $j + array( 'toUser' => $n ) : array(
		'Status' => '-1', 'Text' => 'REJECTD', 'toUser' => $n,
		'StatusDescription' => $err ? 'Proxy could not reach AGI: ' . $err : 'Non-JSON reply (HTTP ' . $code . '): ' . substr( $body, 0, 200 ),
	);
}

// Single number -> return AGI's reply as-is (POS expects this). Multiple -> summary + details.
if ( count( $results ) === 1 ) {
	out( $results[0] );
}
$ok = count( array_filter( $results, function ( $r ) { return isset( $r['Status'] ) && (string) $r['Status'] === '0'; } ) );
out( array(
	'Status'  => $ok === count( $results ) ? '0' : '-1',
	'Text'    => $ok === count( $results ) ? 'ACCEPTD' : 'PARTIAL',
	'Sent'    => $ok,
	'Failed'  => count( $results ) - $ok,
	'Results' => $results,
) );
