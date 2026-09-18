<?php
/**
 * Shows the outbound IP this Render service uses when it calls out to
 * AGI's server. Visit: https://<your-render-url>/ip_check.php
 */
header('Content-Type: text/plain; charset=utf-8');

function fetchIp($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return $out !== false ? trim($out) : ('ERROR: ' . $err);
}

echo "Render service outbound IP (this is what AGI sees):\n\n";
echo "ipify.org      : " . fetchIp('https://api.ipify.org') . "\n";
echo "icanhazip.com  : " . fetchIp('https://icanhazip.com') . "\n";
