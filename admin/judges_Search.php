<?php
// mms/api/judges_search.php

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '' || mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

// ========== CRYPTO CONFIG ==========
$METHOD = 'AES-128-CBC';
$KEY    = '084s@yb3z0j2l2#X';
$TOKEN  = '7BDB084C3A2671584255B0B0D6B900E4';

// IV – still assumption, needs confirmation from them:

// ========== 1) BUILD PLAIN JSON (NO ARRAY) ==========
// Plain JSON string exactly like what they described
$plainJson = '{"Estt":"JP","TokSe":"' . $TOKEN . '"}';

// ========== 2) ENCRYPT → BASE64 ENCODE ==========
$IV = '084s@yb3z0j2l2#X'; 

$cipherRaw = openssl_encrypt(
    $plainJson,
    $METHOD,
    $KEY,
    OPENSSL_RAW_DATA,
    $IV
);


if ($cipherRaw === false) {
    http_response_code(500);
    echo json_encode(["error" => "Encryption failed"]);
    exit;
}

$encryptedBase64 = base64_encode($cipherRaw);

// ========== 3) SEND AS FORM-DATA (NO ARRAY) ==========
// IMPORTANT: "data=<value>"  (NOT "data:<value>")
$postBody = 'data=' . urlencode($encryptedBase64);

$ch = curl_init('https://hcraj.nic.in/hcraj/mapp/api/judge_name_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$responseBody = curl_exec($ch);

if ($responseBody === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo json_encode(["error" => "cURL error: " . $err]);
    exit;
}

$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpStatus < 200 || $httpStatus >= 300) {
    http_response_code(502);
    echo json_encode(["error" => "Upstream HTTP " . $httpStatus]);
    exit;
}

// ========== 4) BASE64 DECODE → DECRYPT RESPONSE ==========

$responseCipher = base64_decode($responseBody, true);
if ($responseCipher === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "Response is not valid base64",
        "raw"   => $responseBody
    ]);
    exit;
}

$responsePlain = openssl_decrypt(
    $responseCipher,
    $METHOD,
    $KEY,
    OPENSSL_RAW_DATA,
    $IV
);

if ($responsePlain === false) {
    http_response_code(500);
    echo json_encode(["error" => "Response decryption failed"]);
    exit;
}

// There may be junk before JSON, so cut from first '{'
$startPos = strpos($responsePlain, '{');
if ($startPos === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "No JSON object found in decrypted response",
        "raw"   => $responsePlain
    ]);
    exit;
}

$jsonPart = substr($responsePlain, $startPos);
$decoded  = json_decode($jsonPart, true);

if (!is_array($decoded)) {
    http_response_code(500);
    echo json_encode([
        "error"       => "Decrypted response is not valid JSON",
        "raw"         => $responsePlain,
        "json_chunk"  => $jsonPart
    ]);
    exit;
}

// ========== 5) HANDLE POSSIBLE WRAPPER STRUCTURE ==========
//
// If API returns like:
// { "msg": "...", "status": 1, "result": [ {...}, {...} ] }
//
// handle that. Otherwise, if it's a plain list [ {...}, {...} ],
// also handle that.

if (array_key_exists('status', $decoded) || array_key_exists('result', $decoded)) {
    // Wrapped format
    if (!isset($decoded['status']) || $decoded['status'] != 1) {
        echo json_encode([
            "error"  => "Judge API error",
            "status" => isset($decoded['status']) ? $decoded['status'] : null,
            "msg"    => isset($decoded['msg']) ? $decoded['msg'] : 'Unknown error'
        ]);
        exit;
    }

    if (!isset($decoded['result']) || !is_array($decoded['result'])) {
        echo json_encode([]);
        exit;
    }

    $judges = $decoded['result'];
} else {
    // Assume it's already a list of judges
    $judges = $decoded;
}

// ========== 6) MAP TO FORMAT YOU WANT & FILTER BY q ==========

$needle = mb_strtolower($q, 'UTF-8');
$result = [];

foreach ($judges as $row) {
    $jocode     = isset($row['jocode']) ? trim($row['jocode']) : '';
    $judgeCode  = isset($row['judge_code']) ? (int)$row['judge_code'] : 0;
    $judgeName  = isset($row['judge_name']) ? $row['judge_name'] : '';
    $salute     = isset($row['salute']) ? $row['salute'] : '';

    if ($judgeName === '' && $jocode === '' && $judgeCode === 0) {
        continue;
    }

    $haystack = mb_strtolower($judgeName . ' ' . $jocode . ' ' . $judgeCode, 'UTF-8');
    if ($needle !== '' && mb_strpos($haystack, $needle) === false) {
        continue;
    }

    $result[] = [
        "jocode"     => $jocode,
        "judge_code" => $judgeCode,
        "judge_name" => $judgeName,
        "salute"     => $salute
    ];
}

echo json_encode($result);
