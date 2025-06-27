<?php
// listarp.php
// メインのスクリプト - 画面表示に特化

require_once 'config.php';
require_once 'utils.php';

// ここからGETリクエスト（通常のページ表示）の場合の処理
ob_implicit_flush(true);
header('Content-type: text/html; charset=utf-8');

$device_map = load_device_mapping();
$mac_ip_cache = load_mac_ip_cache();
$last_detected_map = load_last_detected(); // 最終検出日時マップをロード
$device_icon_map = load_device_icons(); // デバイスアイコンマップをロード

// iconsディレクトリから利用可能なアイコンファイルを取得
$icon_files = [];
$icon_dir = __DIR__ . '/icons';
if (is_dir($icon_dir)) {
    $files = scandir($icon_dir);
    foreach ($files as $file) {
        if (preg_match('/\.(png|jpg|jpeg|gif|svg)$/i', $file)) {
            $icon_files[] = $file;
        }
    }
    sort($icon_files); // アイコン名をソート
}
$icon_files_json = json_encode($icon_files);

// ARPテーブル取得
$arp_result = shell_exec("arp -an | egrep -v '224.0.0.251|192.168.10.255|192.168.10.0|incomplete'");
$lines = explode("\n", trim($arp_result));

$arp_devices = []; // ARPで検出されたデバイスをMACアドレスをキーとして格納
foreach ($lines as $line) {
    if (!$line) continue;

    preg_match('/\(([\d\.]+)\).*?(([0-9a-fA-F]{1,2}[:\-]){5}[0-9a-fA-F]{1,2})/', $line, $matches);

    if (count($matches) < 3) continue;

    $ip = $matches[1];
    $raw_mac = str_replace('-', ':', $matches[2]); // MACアドレスのデリミタをコロンに統一
    $mac = normalize_mac($raw_mac);

    // ARPで取得したIPアドレスとキャッシュのIPアドレスが異なる場合、ARPの情報を優先してキャッシュを更新
    if (isset($mac_ip_cache[$mac]) && $mac_ip_cache[$mac] !== $ip) {
        $mac_ip_cache[$mac] = $ip; // ARPのIPアドレスで上書き
    } elseif (!isset($mac_ip_cache[$mac])) {
        $mac_ip_cache[$mac] = $ip; // 新規デバイスまたはキャッシュにない場合は追加
    }

    // 最終検出日時を更新
    $last_detected_map[$mac] = time(); // 現在のUnixタイムスタンプ

    $device_name_from_map = $device_map[$mac] ?? STATUS_UNKNOWN_DEVICE;

    $arp_devices[$mac] = [
        'ip' => $ip,
        'mac' => $mac,
        'name' => $device_name_from_map,
        'is_arp_found' => true
    ];
}

save_mac_ip_cache($mac_ip_cache); // 更新されたmac_ip_cacheをファイルに書き込む
save_last_detected($last_detected_map); // 更新された最終検出日時マップをファイルに書き込む

$final_display_devices = [];
// ARPで見つかったデバイスでDisplayDevicesを構築
foreach ($arp_devices as $mac => $info) {
    $final_display_devices[$mac] = $info; // ARP情報を優先
}

// mapping.csvの情報をマージ
foreach ($device_map as $mac_from_csv => $name_from_csv) {
    if (!isset($final_display_devices[$mac_from_csv])) {
        // ARPにはないがmapping.csvにある場合
        $ip_from_cache = $mac_ip_cache[$mac_from_csv] ?? '';
        $final_display_devices[$mac_from_csv] = [
            'ip' => $ip_from_cache,
            'mac' => $mac_from_csv,
            'name' => $name_from_csv,
            'is_arp_found' => false
        ];
    }
}

// mac_ip_cacheにのみ存在するデバイスを追加 (念のため)
foreach ($mac_ip_cache as $cached_mac => $cached_ip) {
    if (!isset($final_display_devices[$cached_mac])) {
        $final_display_devices[$cached_mac] = [
            'ip' => $cached_ip,
            'mac' => $cached_mac,
            'name' => $device_map[$cached_mac] ?? STATUS_UNKNOWN_DEVICE,
            'is_arp_found' => false
        ];
    }
}

// IPアドレスでソート (IPアドレスが空の場合は最後に)
usort($final_display_devices, function($a, $b) {
    $ip_a = empty($a['ip']) ? null : ip2long($a['ip']);
    $ip_b = empty($b['ip']) ? null : ip2long($b['ip']);

    if ($ip_a === null && $ip_b === null) {
        return strcmp($a['mac'], $b['mac']);
    }
    if ($ip_a === null) {
        return 1;
    }
    if ($ip_b === null) {
        return -1;
    }
    return $ip_a - $ip_b;
});

// CSSとJSファイルの最終更新日時を取得してキャッシュ対策
$css_version = filemtime('listarp.css');
$js_version = filemtime('listarp.js');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ARPテーブル監視</title>
    <link rel="stylesheet" href="listarp.css?v=<?= $css_version ?>">
</head>
<body
    data-status-alive-registered="<?= htmlspecialchars(STATUS_ALIVE_REGISTERED) ?>"
    data-status-alive-unregistered="<?= htmlspecialchars(STATUS_ALIVE_UNREGISTERED) ?>"
    data-status-stopped-registered="<?= htmlspecialchars(STATUS_STOPPED_REGISTERED) ?>"
    data-status-stopped-unregistered="<?= htmlspecialchars(STATUS_STOPPED_UNREGISTERED) ?>"
    data-status-all-display="<?= htmlspecialchars(STATUS_ALL_DISPLAY) ?>"
    data-icon-files='<?= $icon_files_json ?>'
>

<div id="controlsContainer">
    <button id="refreshButton" class="control-button">表示更新</button>

    <div class="search-group">
        <label for="deviceNameSearch">検索:</label>
        <input type="text" id="deviceNameSearch" placeholder="デバイス名 or MACアドレス">
        <button id="clearSearchButton" class="control-button">クリア</button>
    </div>

    <button id="saveChangesButton" class="control-button" disabled>変更確定</button>
    <button id="updateOuiButton" class="control-button">OUIデータ更新</button>
    <div id="messageArea"></div>
</div>

<table border='1' id="deviceTable">
    <thead>
        <tr>
            <th class="col-icon">アイコン</th>
            <th class="col-ip-address">IPアドレス</th>
            <th class="col-device-name">デバイス名</th>
            <th class="col-status" id="statusHeader">状態</th>
            <th class="col-mac-address">MACアドレス</th>
            <th class="col-vendor">ベンダー</th>
            <th class="col-last-detected">最終検出日時</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($final_display_devices as $device): ?>
    <?php
        $ip = $device['ip'];
        $mac = $device['mac'];
        $name = $device['name'];
        $vendor_name = get_vendor_name($mac); // ベンダー名を取得
        $last_detected_timestamp = $last_detected_map[$mac] ?? null; // 最終検出日時 (Unixタイムスタンプ)
        $last_detected_display = $last_detected_timestamp ? date('Y-m-d H:i:s', $last_detected_timestamp) : '未検出';
        $device_icon = $device_icon_map[$mac] ?? 'unknown.png'; // デバイスアイコンを取得、なければunknown.png

        if (empty($ip)) {
            $is_alive = false;
            $status_key = ($name == STATUS_UNKNOWN_DEVICE) ? STATUS_STOPPED_UNREGISTERED : STATUS_STOPPED_REGISTERED;
        } else {
            $is_alive = check_ping($ip);
            $status_key = ($name == STATUS_UNKNOWN_DEVICE)
                ? ($is_alive ? STATUS_ALIVE_UNREGISTERED : STATUS_STOPPED_UNREGISTERED)
                : ($is_alive ? STATUS_ALIVE_REGISTERED : STATUS_STOPPED_REGISTERED);
        }

        $status_cell_bg_class = ""; // セル背景色用のクラスを定義
        switch ($status_key) {
            case STATUS_ALIVE_REGISTERED: $status_cell_bg_class = "status-bg-green"; break;
            case STATUS_ALIVE_UNREGISTERED: $status_cell_bg_class = "status-bg-orange"; break;
            case STATUS_STOPPED_REGISTERED: $status_cell_bg_class = "status-bg-gray"; break;
            case STATUS_STOPPED_UNREGISTERED: $status_cell_bg_class = "status-bg-red"; break;
            default: $status_cell_bg_class = ""; break;
        }

        $ip_td_class = empty($ip) ? "ip-empty" : "";
    ?>
    <tr data-status-key="<?= htmlspecialchars($status_key) ?>" data-mac-address="<?= htmlspecialchars($mac) ?>">
        <td class="col-icon icon-cell">
            <img src="icons/<?= htmlspecialchars($device_icon) ?>" alt="Device Icon" class="device-icon" data-original-icon="<?= htmlspecialchars($device_icon) ?>">
        </td>
        <td class="col-ip-address <?= $ip_td_class ?>"><?= htmlspecialchars($ip) ?></td>
        <td class="col-device-name device-name-cell">
            <input type="text" value="<?= htmlspecialchars($name) ?>" class="device-name-input" data-original-name="<?= htmlspecialchars($name) ?>">
        </td>
        <td class="col-status status-cell <?= $status_cell_bg_class ?>"><?= htmlspecialchars($status_key) ?></td>
        <td class="col-mac-address mac-address"><?= htmlspecialchars($mac) ?></td>
        <td class="col-vendor"><?= htmlspecialchars($vendor_name) ?></td>
        <td class="col-last-detected"><?= htmlspecialchars($last_detected_display) ?></td>
    </tr>
    <?php
        ob_flush();
        flush();
    ?>
<?php endforeach; ?>
    </tbody>
</table>

<!-- アイコン選択モーダル -->
<div id="iconModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2>アイコンを選択</h2>
        <div id="iconGrid"></div>
    </div>
</div>

<script src="listarp.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>
