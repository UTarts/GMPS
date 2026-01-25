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
        } else {
            $this->log("ERROR: service-account.json not found at " . $this->serviceAccountPath);
        }
    }

    private function log($msg) {
        $date = date('Y-m-d H:i:s');
        file_put_contents(__DIR__ . '/notification_debug.log', "[$date] $msg" . PHP_EOL, FILE_APPEND);
    }

    public function broadcastToAll($title, $body, $data = []) {
        $this->log("Broadcasting to ALL: $title");
        $tokens = $this->getTokensByQuery("SELECT token FROM fcm_tokens");
        return $this->sendBatch($tokens, $title, $body, $data);
    }

    public function sendToClass($class_id, $title, $body, $data = []) {
        $this->log("Broadcasting to CLASS $class_id: $title");
        $sql = "SELECT t.token FROM fcm_tokens t 
                JOIN students s ON t.user_id = s.id 
                WHERE s.class_id = " . (int)$class_id . " AND t.role = 'student'";
        $tokens = $this->getTokensByQuery($sql);
        return $this->sendBatch($tokens, $title, $body, $data);
    }

    // --- NEW METHOD: SEND TO SPECIFIC USERS (For Attendance) ---
    public function sendToUserIds($userIds, $title, $body, $data = []) {
        if (empty($userIds)) return false;
        
        // Sanitize IDs
        $ids = implode(',', array_map('intval', $userIds));
        
        $this->log("Broadcasting to Specific Users ($ids): $title");
        
        // Fetch tokens only for these students
        $sql = "SELECT token FROM fcm_tokens WHERE user_id IN ($ids) AND role = 'student'";
        $tokens = $this->getTokensByQuery($sql);
        
        return $this->sendBatch($tokens, $title, $body, $data);
    }

    private function getTokensByQuery($sql) {
        $result = $this->conn->query($sql);
        $tokens = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['token'])) $tokens[] = $row['token'];
            }
        }
        $this->log("Found " . count($tokens) . " tokens.");
        return array_unique($tokens);
    }

    private function sendBatch($tokens, $title, $body, $data) {
        if (empty($tokens)) {
            $this->log("No tokens found. Aborting.");
            return false;
        }
        if (empty($this->projectId)) {
            $this->log("Project ID missing. Check service-account.json.");
            return false;
        }

        $accessToken = $this->getGoogleAccessToken();
        if (!$accessToken) {
            $this->log("Failed to generate Access Token.");
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        $successCount = 0;
        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body
                    ],
                    'data' => (object)$data
                ]
            ];
            
            $result = $this->makeRequest($url, $headers, $payload);
            $resJson = json_decode($result, true);

            if (isset($resJson['error'])) {
                $this->log("FCM Error: " . json_encode($resJson['error']));
                
                // Cleanup Stale Tokens
                $errCode = $resJson['error']['details'][0]['errorCode'] ?? '';
                if ($errCode === 'UNREGISTERED' || $resJson['error']['status'] === 'NOT_FOUND') {
                    $this->conn->query("DELETE FROM fcm_tokens WHERE token = '$token'");
                    $this->log("Deleted stale token.");
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
        $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtClaim = base64_encode(json_encode([
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]));

        $dataToSign = $jwtHeader . '.' . $jwtClaim;
        $signature = '';
        if (!openssl_sign($dataToSign, $signature, $key['private_key'], 'SHA256')) return null;
        $jwt = $dataToSign . '.' . base64_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true)['access_token'] ?? null;
    }

    private function makeRequest($url, $headers, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
?>