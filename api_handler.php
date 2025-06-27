<?php
// api_handler.php
// AJAXリクエストの処理を担当

require_once 'config.php';
require_once 'utils.php';

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

                $current_icon_map = load_device_icons();

                foreach ($updated_devices as $device) {
                    $raw_mac_from_post = $device['mac'];
                    $mac = normalize_mac($raw_mac_from_post);

                    if (isset($device['name'])) {
                        $name = $device['name'];
                        if (strtolower(trim($name)) !== strtolower(STATUS_UNKNOWN_DEVICE) && trim($name) !== '') {
                            $current_map[$mac] = trim($name);
                        } else {
                            if (isset($current_map[$mac])) {
                                unset($current_map[$mac]);
                            }
                        }
                    }

                    if (isset($device['icon'])) {
                        $icon = $device['icon'];
                        if (trim($icon) !== '' && $icon !== 'unknown.png') { // unknown.png は保存しない
                            $current_icon_map[$mac] = trim($icon);
                        } else {
                            if (isset($current_icon_map[$mac])) {
                                unset($current_icon_map[$mac]);
                            }
                        }
                    }
                }

                $name_save_success = save_device_mapping($current_map);
                $icon_save_success = save_device_icons($current_icon_map);

                if ($name_save_success && $icon_save_success) {
                    $response = ['status' => 'success', 'message' => 'デバイス名とアイコンが更新されました。'];
                } else {
                    $response = ['status' => 'error', 'message' => 'ファイルへの書き込みに失敗しました。'];
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
