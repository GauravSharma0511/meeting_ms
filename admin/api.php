function sendApi($data, $apiUrl) {
    $encryptedData = encrypt_common_fun(json_encode($data));

    $output = '';

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['data' => $encryptedData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $output = json_encode(['error' => curl_error($ch)]);
    }
    else {
        $output = decrypt_common_fun($response);
    }

    curl_close($ch);
    return $output;
}
    
function encrypt_common_fun($data)
{
    $iv = 'O2qr8hZ+6H7t5cFk';
    $encrypt = openssl_encrypt($data, 'AES-128-CBC', 'O2qr8hZ+6H7t5cFk', OPENSSL_RAW_DATA, $iv);
    $EncryptTxt = base64_encode($encrypt);

    return $EncryptTxt;
}
    
function decrypt_common_fun($data)
{
    $iv = 'O2qr8hZ+6H7t5cFk';
    $data = base64_decode($data);
    $decrypt = openssl_decrypt($data, 'AES-128-CBC', 'O2qr8hZ+6H7t5cFk', OPENSSL_RAW_DATA, $iv);

    return $decrypt;
}

$apiUrl = 'http://10.130.8.95/joassessment_api/current_posting_api.php';

$data['token'] = '08649D03EB3FE5E253F33A159D2653195FED73B4AD7C7EA2B0';
$data['request_type'] = "1";
$data['jocode'] = "rj00844";

echo sendApi($data, $apiUrl);
exit;