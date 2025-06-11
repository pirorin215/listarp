<?pp
// api_handler.php
// AJAXリクエストの処理を担当

require_once 'config.php';
require_once 'functions.php';
require_once 'data_handler.php';

header('Content-Type: application/json');

// POSTリクエストのJSONデータを取得
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$response = ['status' => 'error', 'message' => '不明なエラー'];

if (isset($data['action'])) {
    switch ($data['action']) {
        case 'save_device_names':
            if (isset($data['devices']) && is_array($data['devices'])) {
                $updated_devices = $data['devices'];
                $current_map = load_device_mapping();

                // 送信された変更を反映
                foreach ($updated_devices as $device) {
                    $raw_mac_from_post = $device['mac'];
                    $mac = normalize_mac($raw_mac_from_post); // POSTされたMACアドレスを正規化
                    $name = $device['name'];

                    if (strtolower(trim($name)) !== strtolower(STATUS_UNKNOWN_DEVICE) && trim($name) !== '') {
                        $current_map[$mac] = trim($name); // 余分な空白を除去
                    } else {
                        if (isset($current_map[$mac])) {
                            unset($current_map[$mac]);
                        }
                    }
                }

                if (save_device_mapping($current_map)) {
                    $response = ['status' => 'success', 'message' => 'CSVが更新されました。'];
                } else {
                    $response = ['status' => 'error', 'message' => 'CSVファイルへの書き込みに失敗しました。'];
                }
            } else {
                $response = ['status' => 'error', 'message' => '無効なデータ形式です。'];
            }
            break;

        default:
            $response = ['status' => 'error', 'message' => '未知のアクションです。'];
            break;
    }
} else {
    $response = ['status' => 'error', 'message' => 'アクションが指定されていません。'];
}

echo json_encode($response);
exit;
