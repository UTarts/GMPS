<?php
require_once 'config.php';

class NotificationService {
    private $conn;
    private $serviceAccountPath;
    private $projectId;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->serviceAccountPath = __DIR__ . '/service-account.json';
        
        if (file_exists($this->serviceAccountPath)) {
            $json = json_decode(file_get_contents($this->serviceAccountPath), true);
            $this->projectId = $json['project_id'] ?? '';
        }
    }

    private function log($msg) {
        $date = date('Y-m-d H:i:s');
        file_put_contents(__DIR__ . '/notification_debug.log', "[$date] $msg" . PHP_EOL, FILE_APPEND);
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // THE NEW DATABASE SAVER
    private function saveToDatabase($title, $body, $data, $targetType, $targetIds = '') {
        $url = $data['url'] ?? '/';
        $stmt = $this->conn->prepare("INSERT INTO app_notifications (title, body, url, target_type, target_ids) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $title, $body, $url, $targetType, $targetIds);
        $stmt->execute();
    }

    public function broadcastToAll($title, $body, $data = []) {
        $this->saveToDatabase($title, $body, $data, 'all');
        $this->log("Broadcasting to ALL: $title");
        $tokens = $this->getTokensByQuery("SELECT token FROM fcm_tokens");
        return $this->sendBatch($tokens, $title, $body, $data);
    }

    public function sendToClass($class_id, $title, $body, $data = []) {
        $this->saveToDatabase($title, $body, $data, 'class', $class_id);
        $this->log("Broadcasting to CLASS $class_id: $title");
        $sql = "SELECT t.token FROM fcm_tokens t JOIN students s ON t.user_id = s.id WHERE s.class_id = " . (int)$class_id . " AND t.role = 'student'";
        return $this->sendBatch($this->getTokensByQuery($sql), $title, $body, $data);
    }

    public function sendToUserIds($userIds, $title, $body, $data = []) {
        if (empty($userIds)) return false;
        $ids = implode(',', array_map('intval', $userIds));
        $this->saveToDatabase($title, $body, $data, 'users', $ids);
        $this->log("Broadcasting to Specific Users ($ids): $title");
        $sql = "SELECT token FROM fcm_tokens WHERE user_id IN ($ids) AND role = 'student'";
        return $this->sendBatch($this->getTokensByQuery($sql), $title, $body, $data);
    }

    private function getTokensByQuery($sql) {
        $result = $this->conn->query($sql);
        $tokens = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['token'])) $tokens[] = $row['token'];
            }
        }
        return array_unique($tokens);
    }

    private function sendBatch($tokens, $title, $body, $data) {
        if (empty($tokens) || empty($this->projectId)) return false;

        $accessToken = $this->getGoogleAccessToken();
        if (!$accessToken) return false;

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];

        $successCount = 0;
        foreach ($tokens as $token) {
            $stringData = [];
            if (!empty($data)) {
                foreach ($data as $k => $v) $stringData[(string)$k] = (string)$v;
            }

            // Standard payload to wake up Android
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => (string)$title,
                        'body'  => (string)$body
                    ]
                ]
            ];
            if (!empty($stringData)) $payload['message']['data'] = $stringData;
            
            $result = $this->makeRequest($url, $headers, $payload);
            $resJson = json_decode($result, true);

            if (isset($resJson['error'])) {
                $errCode = $resJson['error']['details'][0]['errorCode'] ?? '';
                if ($errCode === 'UNREGISTERED' || $resJson['error']['status'] === 'NOT_FOUND') {
                    $this->conn->query("DELETE FROM fcm_tokens WHERE token = '$token'");
                }
            } else {
                $successCount++;
            }
        }
        $this->log("Batch finished. Success: $successCount / " . count($tokens));
        return true;
    }

    private function getGoogleAccessToken() {
        if (!file_exists($this->serviceAccountPath)) return null;
        $key = json_decode(file_get_contents($this->serviceAccountPath), true);
        
        $now = time();
        $iat = $now - 300; 
        $exp = $iat + 3600; 
        
        $jwtHeader = $this->base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtClaim = $this->base64url_encode(json_encode([
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $exp,
            'iat' => $iat
        ]));

        $dataToSign = $jwtHeader . '.' . $jwtClaim;
        $signature = '';
        if (!openssl_sign($dataToSign, $signature, $key['private_key'], 'SHA256')) return null;
        $jwt = $dataToSign . '.' . $this->base64url_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        $res = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($res, true);
        return $json['access_token'] ?? null;
    }

    private function makeRequest($url, $headers, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
?>