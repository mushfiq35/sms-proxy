<?php
/**
 * SMS Proxy for AGI SMS HTTP API
 * -------------------------------
 * Purpose: Your POS app is hosted over HTTPS (piconetit.com), but AGI's SMS
 * server only accepts HTTP. Browsers block HTTPS pages from calling HTTP
 * endpoints directly (Mixed Content) and AGI's server does not send CORS
 * headers either. This script sits on your own HTTPS server and forwards
 * the request to AGI server-side (PHP is not a browser, so neither
 * restriction applies here).
 *
 * Upload this file to your piconetit.com hosting (same folder as your app,
 * or anywhere reachable), e.g. as:  https://piconetit.com/sms_proxy.php
 *
 * The app calls THIS file (same-origin HTTPS), and this file calls AGI.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Accept params from GET or POST (JSON body or form fields)
function param($key, $default = '') {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    static $jsonBody = null;
    if ($jsonBody === null) {
        $raw = file_get_contents('php://input');
        $jsonBody = json_decode($raw, true);
        if (!is_array($jsonBody)) $jsonBody = [];
    }
    return isset($jsonBody[$key]) ? $jsonBody[$key] : $default;
}

$baseUrl        = rtrim(param('baseUrl'), '/');   // e.g. http://103.48.119.37:2300
$apikey         = param('apikey');
$secretkey      = param('secretkey');
$callerID       = param('callerID');
$toUser         = param('toUser');
$messageContent = param('messageContent');

if (!$baseUrl || !$apikey || !$secretkey || !$callerID || !$toUser || !$messageContent) {
    http_response_code(400);
    echo json_encode(['Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Missing required parameter', 'Message_ID' => '-1']);
    exit;
}

$url = $baseUrl . '/sendtext'
     . '?apikey=' . urlencode($apikey)
     . '&secretkey=' . urlencode($secretkey)
     . '&callerID=' . urlencode($callerID)
     . '&toUser=' . urlencode($toUser)
     . '&messageContent=' . urlencode($messageContent);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // baseUrl is plain http anyway
$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['Status' => '-1', 'Text' => 'REJECTD', 'StatusDescription' => 'Upstream error: ' . $err, 'Message_ID' => '-1']);
    exit;
}

// Pass through AGI's response as-is
http_response_code(200);
echo $response;
