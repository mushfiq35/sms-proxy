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
$raw = file_get_contents( 'php://input' );
$raw = $raw === false ? '' : $raw;
$body = array();
if ( $raw !== '' ) {
	$json = json_decode( $raw, true );
	if ( is_array( $json ) ) {
		$body = $json;
	} else {
		parse_str( $raw, $form );
		if ( is_array( $form ) ) {
			$body = $form;
		}
	}
}
$in = array_merge( $_GET, $_POST, $body ); // body wins over query string
unset( $in['debug'], $in['last'] );

function nkey( $k ) { return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $k ) ); }
function is_list( $a ) { return is_array( $a ) && $a !== array() && array_keys( $a ) === range( 0, count( $a ) - 1 ); }

const K_APIKEY = array( 'apikey', 'key', 'token', 'apitoken' );
const K_SECRET = array( 'secretkey', 'secret', 'apisecret' );
const K_CALLER = array( 'callerid', 'caller', 'senderid', 'sender', 'from', 'masking', 'mask' );
const K_TO     = array( 'touser', 'to', 'number', 'numbers', 'phone', 'phones', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'msisdn', 'contact', 'contacts', 'recipient', 'recipients', 'customerphone', 'customermobile', 'cell', 'receiver' );
const K_MSG    = array( 'messagecontent', 'message', 'msg', 'text', 'sms', 'smstext', 'content', 'body', 'messagebody', 'msgbody' );
const K_NAME   = array( 'name', 'customername', 'customer', 'fullname' );

/** Find first scalar value whose key matches, searching top level first, then nested (breadth-first). */
function find_val( $arr, $names ) {
	$queue = array( $arr );
	while ( $queue ) {
		$cur = array_shift( $queue );
		foreach ( $names as $want ) {
			foreach ( $cur as $k => $v ) {
				if ( ! is_array( $v ) && nkey( $k ) === $want && trim( (string) $v ) !== '' ) {
					return trim( (string) $v );
				}
			}
		}
		foreach ( $cur as $k => $v ) {
			if ( is_array( $v ) && ! is_list( $v ) ) {
				$queue[] = $v;
			}
		}
	}
	return '';
}

/** List of plain numbers under a phone-ish key, e.g. "to": ["017..","018.."] */
function find_number_list( $arr ) {
	foreach ( $arr as $k => $v ) {
		if ( is_list( $v ) && in_array( nkey( $k ), K_TO, true ) && ! is_array( $v[0] ) ) {
			return implode( ',', $v );
		}
		if ( is_array( $v ) && ! is_list( $v ) ) {
			$r = find_number_list( $v );
			if ( $r !== '' ) {
				return $r;
			}
		}
	}
	return '';
}

/** List of recipient objects, e.g. "recipients":[{"name":"Rahim","phone":"017.."}] or [{"to":..,"message":..}] */
function find_object_list( $arr ) {
	foreach ( $arr as $k => $v ) {
		if ( is_list( $v ) && is_array( $v[0] ) && find_val( $v[0], K_TO ) !== '' ) {
			return $v;
		}
		if ( is_array( $v ) && ! is_list( $v ) ) {
			$r = find_object_list( $v );
			if ( $r ) {
				return $r;
			}
		}
	}
	return is_list( $arr ) && is_array( $arr[0] ) && find_val( $arr[0], K_TO ) !== '' ? $arr : array();
}

function norm_phone( $n ) {
	$d = preg_replace( '/\D+/', '', (string) $n );
	if ( strpos( $d, '00880' ) === 0 ) {
		$d = substr( $d, 4 );
	} elseif ( strpos( $d, '880' ) === 0 ) {
		$d = substr( $d, 3 );
	}
	if ( strlen( $d ) === 10 && $d !== '' && $d[0] === '1' ) {
		$d = '0' . $d;
	}
	return preg_match( '/^01[3-9]\d{8}$/', $d ) ? '88' . $d : false;
}

$base    = AGI_BASE_URL;
$apikey  = find_val( $in, K_APIKEY ) ?: DEFAULT_APIKEY;
$secret  = find_val( $in, K_SECRET ) ?: DEFAULT_SECRETKEY;
$caller  = find_val( $in, K_CALLER ) ?: DEFAULT_CALLERID;
$message = find_val( $in, K_MSG );

/* ---------- 2. Build the send list: [ [number, message], ... ] ---------- */
$jobs    = array();
$bad     = array();
$to_raw  = '';
$objects = find_object_list( $in );
if ( $objects ) {
	foreach ( $objects as $o ) {
		$ph   = find_val( $o, K_TO );
		$to_raw .= ( $to_raw ? ',' : '' ) . $ph;
		$msg  = find_val( $o, K_MSG ) ?: $message;
		$name = find_val( $o, K_NAME );
		$msg  = str_replace( '{name}', $name !== '' ? $name : 'Customer', $msg );
		$n    = norm_phone( $ph );
		$n ? $jobs[] = array( $n, $msg ) : $bad[] = $ph;
	}
} else {
	$to_raw = find_val( $in, K_TO ) ?: find_number_list( $in );
	foreach ( preg_split( '/[\s,;]+/', $to_raw, -1, PREG_SPLIT_NO_EMPTY ) as $ph ) {
		$n = norm_phone( $ph );
		$n ? $jobs[] = array( $n, $message ) : $bad[] = $ph;
	}
}
// de-duplicate same number+message
$seen = array();
$jobs = array_values( array_filter( $jobs, function ( $j ) use ( &$seen ) {
	$k = $j[0] . '|' . md5( $j[1] );
	if ( isset( $seen[ $k ] ) ) { return false; }
	return $seen[ $k ] = true;
} ) );

/* ---------- 3. Request snapshot (for debug / ?last=1) ---------- */
function mask_str( $s ) {
	return preg_replace_callback( '/((?:api_?key|secret_?key|secret|token)=)([^&\s"]+)/i', function ( $m ) {
		return $m[1] . '***' . substr( $m[2], -3 );
	}, $s );
}
function mask_tree( $a ) {
	foreach ( $a as $k => $v ) {
		if ( is_array( $v ) ) {
			$a[ $k ] = mask_tree( $v );
		} elseif ( in_array( nkey( $k ), array_merge( K_APIKEY, K_SECRET ), true ) ) {
			$a[ $k ] = '***' . substr( (string) $v, -3 );
		} else {
			$a[ $k ] = mask_str( (string) $v );
		}
	}
	return $a;
}
$mask = function ( $s ) { return $s === '' ? '(EMPTY)' : '***' . substr( $s, -3 ); };
$snapshot = array(
	'time'           => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
	'method'         => $_SERVER['REQUEST_METHOD'] ?? '',
	'content_type'   => $_SERVER['CONTENT_TYPE'] ?? '',
	'origin'         => $_SERVER['HTTP_ORIGIN'] ?? '',
	'received'       => mask_tree( $in ),
	'raw_body_start' => mask_str( preg_replace( '/((?:api_?key|secret_?key|secret|token)["\':\s]+)[^&"\',\s}]+/i', '$1***', substr( $raw, 0, 400 ) ) ),
	'apikey'         => $mask( $apikey ),
	'secretkey'      => $mask( $secret ),
	'callerID'       => $caller === '' ? '(EMPTY)' : $caller,
	'toUser_raw'     => $to_raw === '' ? '(EMPTY)' : $to_raw,
	'valid_numbers'  => count( $jobs ),
	'messageContent' => $message === '' ? '(EMPTY)' : $message,
);
$last_file = sys_get_temp_dir() . '/sms_proxy_last.json';

if ( isset( $_GET['last'] ) ) { // open in browser AFTER pressing SMS in the POS
	$c = @file_get_contents( $last_file );
	out( $c ? json_decode( $c, true ) : array( 'note' => 'Ekhono kono request ashe ni (ba server restart hoyeche). POS theke SMS chapun, tarpor abar ?last=1 khulun.' ) );
}
if ( ! isset( $_GET['debug'] ) ) {
	@file_put_contents( $last_file, json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
}
if ( isset( $_GET['debug'] ) ) {
	out( array( 'debug' => true, 'note' => 'Debug only — no SMS sent.' ) + $snapshot );
}

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

/* ---------- 3b. POS "url" mode: ?url=http://AGI_IP:PORT/sendtext?apikey=..&toUser=.. ---------- */
$full_url = '';
foreach ( $in as $k => $v ) {
	if ( ! is_array( $v ) && in_array( nkey( $k ), array( 'url', 'target', 'targeturl', 'endpoint', 'u' ), true ) && preg_match( '#^https?://#i', trim( $v ) ) ) {
		$full_url = trim( $v );
		break;
	}
}
if ( $full_url !== '' ) {
	$want = parse_url( AGI_BASE_URL );
	$got  = parse_url( $full_url );
	$same = isset( $got['host'] ) && strcasecmp( $got['host'], $want['host'] ?? '' ) === 0
		&& (int) ( $got['port'] ?? 80 ) === (int) ( $want['port'] ?? 80 );
	if ( ! $same ) {
		out( array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Proxy: url host not allowed (only ' . ( $want['host'] ?? '' ) . ':' . ( $want['port'] ?? 80 ) . ')', 'Message_ID' => '-1' ), 403 );
	}
	$path = $got['path'] ?? '/';
	if ( ! preg_match( '#^/[A-Za-z0-9_\-/]*$#', $path ) ) {
		out( array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Proxy: bad path', 'Message_ID' => '-1' ), 400 );
	}
	parse_str( $got['query'] ?? '', $qp );
	// fill keys from Render env if the POS left them out
	if ( empty( $qp['apikey'] ) && DEFAULT_APIKEY !== '' )       { $qp['apikey'] = DEFAULT_APIKEY; }
	if ( empty( $qp['secretkey'] ) && DEFAULT_SECRETKEY !== '' ) { $qp['secretkey'] = DEFAULT_SECRETKEY; }
	if ( stripos( $path, 'sendtext' ) !== false ) {
		if ( empty( $qp['callerID'] ) && DEFAULT_CALLERID !== '' ) { $qp['callerID'] = DEFAULT_CALLERID; }
		if ( ! empty( $qp['toUser'] ) ) {
			$fixed = array();
			foreach ( preg_split( '/[\s,;]+/', $qp['toUser'], -1, PREG_SPLIT_NO_EMPTY ) as $ph ) {
				$fixed[] = norm_phone( $ph ) ?: $ph;
			}
			$qp['toUser'] = implode( ',', $fixed );
		}
	}
	$fwd = rtrim( AGI_BASE_URL, '/' ) . $path . ( $qp ? '?' . http_build_query( $qp, '', '&', PHP_QUERY_RFC3986 ) : '' );
	list( $code, $resp, $err ) = agi_get( $fwd );
	if ( $resp === '' && $err !== '' ) {
		out( array( 'Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Proxy could not reach AGI: ' . $err, 'Message_ID' => '-1' ), 502 );
	}
	http_response_code( $code ?: 200 );
	echo $resp; // pass AGI reply through as-is
	exit;
}

/* ---------- 4. Validate: say exactly what's missing ---------- */
$missing = array();
if ( $apikey === '' )  { $missing[] = 'apikey'; }
if ( $secret === '' )  { $missing[] = 'secretkey'; }
if ( $caller === '' )  { $missing[] = 'callerID'; }
if ( $to_raw === '' )  { $missing[] = 'toUser (phone number)'; }
elseif ( ! $jobs )     { $missing[] = 'toUser invalid "' . substr( $to_raw, 0, 40 ) . '"'; }
foreach ( $jobs as $j ) {
	if ( trim( $j[1] ) === '' ) { $missing[] = 'messageContent (text)'; break; }
}
if ( $to_raw === '' && $message === '' ) { $missing[] = 'messageContent (text)'; }
if ( $missing ) {
	$keys = implode( ',', array_slice( array_keys( $in ), 0, 12 ) );
	out( array(
		// short first so it survives truncation in the POS popup
		'Status'            => '-1',
		'Text'              => 'REJECTD',
		'StatusDescription' => 'Proxy got [' . ( $keys ?: 'nothing' ) . '] via ' . $snapshot['method'] . ' ' . ( $snapshot['content_type'] ?: 'no-body' ) . ' — missing: ' . implode( ', ', array_unique( $missing ) ) . '. Details: ?last=1',
		'Message_ID'        => '-1',
	), 400 );
}

/* ---------- 5. Forward to AGI (one request per number) ---------- */

$endpoint = rtrim( $base, '/' ) . '/' . ltrim( AGI_SEND_PATH, '/' );
$results  = array();
foreach ( $jobs as $job ) {
	list( $n, $msg ) = $job;
	$q = http_build_query( array(
		'apikey'         => $apikey,
		'secretkey'      => $secret,
		'callerID'       => $caller,
		'toUser'         => $n,
		'messageContent' => $msg,
	), '', '&', PHP_QUERY_RFC3986 );
	list( $code, $resp, $err ) = agi_get( $endpoint . '?' . $q );
	$j = json_decode( $resp, true );
	$results[] = is_array( $j ) ? $j + array( 'toUser' => $n ) : array(
		'Status' => '-1', 'Text' => 'REJECTD', 'toUser' => $n,
		'StatusDescription' => $err ? 'Proxy could not reach AGI: ' . $err : 'Non-JSON reply (HTTP ' . $code . '): ' . substr( $resp, 0, 200 ),
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
