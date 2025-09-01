<?php
// validate.php — same-origin bridge to remote validator
declare(strict_types=1);

// ----- CONFIG -----
$REMOTE_VERIFY_URL = getenv('REMOTE_VERIFY_URL') ?: 'https://api.example.com/verify';
$allowedEnv = trim((string) getenv('ALLOWED_REMOTE_HOSTS')); // comma list
$REMOTE_HOST = strtolower(parse_url($REMOTE_VERIFY_URL, PHP_URL_HOST) ?: '');
$ALLOWED_REMOTE_HOSTS = array_filter(array_map('strtolower', array_map('trim',
  $allowedEnv !== '' ? explode(',', $allowedEnv) : ($REMOTE_HOST ? [$REMOTE_HOST] : [])
)));

// ----- helpers -----
function respond(int $status, array $data): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *'); // safe: only returns status+json
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

// CORS preflight (optional)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type, Accept-Language, X-Requested-With');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  http_response_code(204); exit;
}

// Require POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['valid'=>false,'message'=>'Method not allowed','code'=>'bad_method']);
}

// Accept JSON or form-urlencoded (we’ll turn both into JSON)
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$body = [];
if (strpos($ct, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) respond(400, ['valid'=>false,'message'=>'Malformed JSON','code'=>'bad_json']);
} else {
  // be lenient for older forms
  $body = $_POST;
}

// Extract expected fields
$email      = strtolower(trim((string)($body['email'] ?? '')));
$jsToken    = (string)($body['jsToken'] ?? '');
$cfToken    = (string)($body['cfToken'] ?? '');
$middleName = (string)($body['middleName'] ?? ''); // honeypot must be empty

// Minimal shape check before forwarding
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(422, ['valid'=>false,'message'=>'Invalid email address','code'=>'bad_email']);
}
if ($jsToken === '' || strpos($jsToken, 'ok-') !== 0) {
  respond(403, ['valid'=>false,'message'=>'Invalid token','code'=>'bad_js_token']);
}
if ($middleName !== '') {
  respond(403, ['valid'=>false,'message'=>'Bot detected','code'=>'honeypot']);
}

// Validate remote host allowlist (anti-SSRF)
if (!$REMOTE_HOST || (!empty($ALLOWED_REMOTE_HOSTS) && !in_array($REMOTE_HOST, $ALLOWED_REMOTE_HOSTS, true))) {
  respond(500, ['valid'=>false,'message'=>'Remote host not allowed','code'=>'bad_remote_host']);
}

// ----- Forward to remote validator -----
$payload = [
  'email'      => $email,
  'jsToken'    => $jsToken,
  'cfToken'    => $cfToken,
  'middleName' => $middleName,
];

$ch = curl_init($REMOTE_VERIFY_URL);
curl_setopt_array($ch, [
  CURLOPT_POST            => true,
  CURLOPT_HTTPHEADER      => [
    'Content-Type: application/json; charset=utf-8',
    'Accept: application/json',
    'X-Client-Lang: ' . ($\_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')
  ],
  CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_SLASHES),
  CURLOPT_RETURNTRANSFER  => true,
  CURLOPT_CONNECTTIMEOUT  => 5,
  CURLOPT_TIMEOUT         => 12,
  CURLOPT_FOLLOWLOCATION  => false,
  CURLOPT_SSL_VERIFYPEER  => true,
  CURLOPT_SSL_VERIFYHOST  => 2,
  CURLOPT_USERAGENT       => 'ValidateBridge/1.0'
]);

$respBody = curl_exec($ch);
$errno    = curl_errno($ch);
$errstr   = curl_error($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($errno !== 0 || $respBody === false) {
  // Network failure → neutral response (no enumeration)
  respond(200, ['valid'=>false,'message'=>'Temporary validation issue. Please try again.','code'=>'upstream_error']);
}

$data = json_decode($respBody, true);
// Pass through upstream JSON & status if it’s valid JSON
if (is_array($data)) {
  // Optional: ensure only expected keys are returned
  $out = [
    'valid'    => (bool)($data['valid'] ?? false),
    'message'  => (string)($data['message'] ?? ''),
    'redirect' => isset($data['redirect']) ? (string)$data['redirect'] : null,
    'code'     => isset($data['code']) ? (string)$data['code'] : null,
  ];
  // Use upstream HTTP code; your front-end already handles non-200 neutrally
  respond($status >= 100 ? $status : 200, $out);
}

// Upstream returned non-JSON → neutral message
respond(200, ['valid'=>false,'message'=>'Unexpected response from validator.','code'=>'bad_upstream']);
