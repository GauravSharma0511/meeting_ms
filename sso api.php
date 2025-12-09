    private static $METHOD = 'AES-128-CBC';
    private static $KEY = 'O2qr8hZ+6H7t5cFk';
    private static $TOKEN = "08649D03EB3FE5E253F33A159D2653195FED73B4AD7C7EA2B0";

    public static function validate_token($input)
    {
        return (self::$TOKEN == $input);
    }

    public static function encrypt_common_fun2($data)
    {
        $iv = self::$KEY;
        $encrypt = openssl_encrypt($data, self::$METHOD, self::$KEY, OPENSSL_RAW_DATA, $iv);
        $EncryptTxt = base64_encode($encrypt);

        return $EncryptTxt;
    }

    public static function decrypt_common_fun2($data)
    {
        $iv = self::$KEY;
        $data = base64_decode($data);
        $decrypt = openssl_decrypt($data, self::$METHOD, self::$KEY, OPENSSL_RAW_DATA, $iv);

        return $decrypt;
    }
    protected function fetchCurrentPosting($jocode)
    {
        if (empty($jocode) || !is_string($jocode) || !preg_match('/^[A-Z0-9]+$/', $jocode)) {
            return null;
        }

        $apiUrl = 'http://10.130.8.95/joassessment_api/current_posting_api.php';
        $token = '08649D03EB3FE5E253F33A159D2653195FED73B4AD7C7EA2B0';
        $data = [
            'token' => $token,
            'request_type' => '1',
            'jocode' => $jocode
        ];

        $encryptedData = $this->encrypt_common_fun2(json_encode($data));
        if ($encryptedData === false) {
            throw new \Exception('Encryption failed for current posting API.');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['data' => $encryptedData]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
        curl_close($ch);

        $json_text = $this->decrypt_common_fun2($response);
        if ($json_text === false) {
            throw new \Exception('Decryption failed for current posting API response.');
        }

        $response_data = json_decode($json_text, true);
        // echo '<pre>';print_r($response_data);die;
        if (is_array($response_data) && isset($response_data['posting_details'])) {
            return $response_data['posting_details'];
        }

        return null;
    }

    //login sso api 

    public function loginSso()
    {
        $this->set('pageTitle', 'Legal Researcher App');

        if ($this->request->is('post')) {
            $token = trim($this->request->getData('token'));
            if (empty($token) || !is_string($token)) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode(['success' => false, 'message' => 'Invalid token']));
            }

            $apiUrl = "http://localhost/api/ValidateToken.php";
            $data = [
                'TokSe' => $token,
                'type' => '1'
            ];

            $encryptedData = $this->encrypt_common_fun(json_encode($data));

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['data' => $encryptedData]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                curl_close($ch);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode(['success' => false, 'message' => 'cURL Error: ' . curl_error($ch)]));
            }
            curl_close($ch);

            $response = json_decode($this->decrypt_common_fun($response), true);

            if (!isset($response['result']) || !is_array($response['result'])) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode(['success' => false, 'message' => 'Invalid response from API']));
            }
            $dummyUser = [];
            $jocode = $response['result']['username'] ?? null;
            $dummyUser = [
                'id' => $response['result']['id'] ?? null,
                'mst_id' => $response['result']['mst_id'] ?? null,
                'username' => $response['result']['username'] ?? null,
                'role' => $response['result']['role'] ?? null,
                'jship' => $response['result']['jship'] ?? null,
                'displayName' => $response['result']['display_name'] ?? null,
            ];

            if (empty($dummyUser['username'])) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode(['success' => false, 'message' => 'Missing required user data']));
            }

            $_SESSION['trans_representation']['program_name'] = trim($response['result']['program_name'] ?? '');
            $_SESSION['trans_representation']['login_type'] = 'sso';

            $this->Auth->setUser($dummyUser);

            return $this->redirect('/dashboard');
        }

        if ($this->Auth->user()) {
            return $this->redirect('/dashboard');
        }
    }