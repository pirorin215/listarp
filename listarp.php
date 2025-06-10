<?php
// listarp.php
// メインのスクリプト - 画面表示に特化

require_once 'config.php';
require_once 'functions.php';
require_once 'data_handler.php';

// ここからGETリクエスト（通常のページ表示）の場合の処理
ob_implicit_flush(true);
header('Content-type: text/html; charset=utf-8');

$device_map = load_device_mapping();
$mac_ip_cache = load_mac_ip_cache();
$last_detected_map = load_last_detected(); // 最終検出日時マップをロード

// ARPテーブル取得
$arp_result = shell_exec("arp -an | egrep -v '224.0.0.251|192.168.10.255|192.168.10.0'");
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

        if (empty($ip)) {
            $is_alive = false;
            $status_key = ($name == STATUS_UNKNOWN_DEVICE) ? STATUS_STOPPED_UNREGISTERED : STATUS_STOPPED_REGISTERED;
        } else {
            // pingのチェックは時間がかかるため、リアルタイム性を重視する場合は、別途AJAXで実施するか、
            // 検出されたばかりのデバイスに限定するなど、考慮が必要です。
            // 今回は既存のロジックを踏襲します。
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
    <tr data-status-key="<?= htmlspecialchars($status_key) ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const controlsContainer = document.getElementById('controlsContainer');
    const body = document.body;
    
    // 初期状態ではコントロールコンテナを非表示にする
    controlsContainer.style.display = "none";

    function setDynamicPadding() {
        const controlsHeight = controlsContainer.offsetHeight;
        body.style.paddingTop = `${controlsHeight + 10}px`; // controlsContainerの高さ + 少し余白
        document.documentElement.style.setProperty('--controls-container-height', `${controlsHeight + 10}px`);
    }

    // 初期ロード時とウィンドウのリサイズ時にパディングを設定
    setDynamicPadding();
    window.addEventListener('resize', setDynamicPadding);

    const table = document.getElementById('deviceTable');
    const saveButton = document.getElementById('saveChangesButton');
    const refreshButton = document.getElementById('refreshButton');
    const messageArea = document.getElementById('messageArea');
    const deviceNameSearch = document.getElementById('deviceNameSearch');
    const clearSearchButton = document.getElementById('clearSearchButton');
    const statusHeader = document.getElementById('statusHeader');
    const updateOuiButton = document.getElementById('updateOuiButton');

    // PHPの定数をJavaScriptに渡す (これらはHTML内で直接定義されるように変更)
    // const STATUS_ALIVE_REGISTERED = "<?php echo STATUS_ALIVE_REGISTERED; ?>";
    // const STATUS_ALIVE_UNREGISTERED = "<?php echo STATUS_ALIVE_UNREGISTERED; ?>";
    // const STATUS_STOPPED_REGISTERED = "<?php echo STATUS_STOPPED_REGISTERED; ?>";
    // const STATUS_STOPPED_UNREGISTERED = "<?php echo STATUS_STOPPED_UNREGISTERED; ?>";
    // const STATUS_ALL_DISPLAY = "<?php echo STATUS_ALL_DISPLAY; ?>";

    // 代わりにHTMLからデータ属性として取得
    const STATUS_ALIVE_REGISTERED = document.body.dataset.statusAliveRegistered;
    const STATUS_ALIVE_UNREGISTERED = document.body.dataset.statusAliveUnregistered;
    const STATUS_STOPPED_REGISTERED = document.body.dataset.statusStoppedRegistered;
    const STATUS_STOPPED_UNREGISTERED = document.body.dataset.statusStoppedUnregistered;
    const STATUS_ALL_DISPLAY = document.body.dataset.statusAllDisplay;


    const filterStatuses = [
        STATUS_ALL_DISPLAY,
        STATUS_ALIVE_REGISTERED,
        STATUS_ALIVE_UNREGISTERED,
        STATUS_STOPPED_REGISTERED,
        STATUS_STOPPED_UNREGISTERED
    ];
    let currentFilterIndex = 0;

    function filterDevicesByStatus(statusToFilter) {
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const rowStatus = row.dataset.statusKey;
            const searchText = deviceNameSearch.value.toLowerCase();
            const deviceName = row.querySelector('.device-name-input').value.toLowerCase();
            const macAddress = row.querySelector('.mac-address').textContent.toLowerCase();

            const isMatch = (deviceName.includes(searchText) || macAddress.includes(searchText)) || searchText === '';

            if ((statusToFilter === STATUS_ALL_DISPLAY || rowStatus === statusToFilter) && isMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        statusHeader.textContent = `状態(${statusToFilter})`;
    }

    function checkChanges() {
        const deviceNameInputs = document.querySelectorAll('.device-name-input');
        let hasChanges = false;
        deviceNameInputs.forEach(input => {
            if (input.value.trim() !== input.dataset.originalName.trim()) {
                hasChanges = true;
                return;
            }
        });

        if (hasChanges) {
            saveButton.disabled = false;
            if (!saveButton.classList.contains('active')) {
                saveButton.classList.add('active');
                saveButton.classList.add('flicker-animation'); // フリッカー開始
                setTimeout(() => {
                    saveButton.classList.remove('flicker-animation'); // フリッカー停止
                }, 900); // 0.3s * 3回 = 0.9秒後に停止
            }
        } else {
            saveButton.disabled = true;
            saveButton.classList.remove('active');
            saveButton.classList.remove('flicker-animation'); // 念のためフリッカーも停止
        }
    }

    // 初期ロード時に変更がないことを確認し、ボタンをdisabledにする
    checkChanges();

    // デバイス名入力欄へのイベントリスナー設定
    document.querySelectorAll('.device-name-input').forEach(input => {
        input.addEventListener('input', checkChanges);
    });

    statusHeader.addEventListener('click', function() {
        currentFilterIndex = (currentFilterIndex + 1) % filterStatuses.length;
        const nextStatus = filterStatuses[currentFilterIndex];
        filterDevicesByStatus(nextStatus);
    });

    deviceNameSearch.addEventListener('keyup', function() {
        filterDevicesByStatus(filterStatuses[currentFilterIndex]);
    });

    clearSearchButton.addEventListener('click', function() {
        deviceNameSearch.value = '';
        filterDevicesByStatus(filterStatuses[currentFilterIndex]);
    });

    saveButton.addEventListener('click', function() {
        const rows = table.querySelectorAll('tbody tr');
        const updatedDevices = [];

        rows.forEach(row => {
            const macAddress = row.querySelector('.mac-address').textContent.trim();
            const deviceNameInput = row.querySelector('.device-name-input');
            const currentName = deviceNameInput.value.trim();
            const originalName = deviceNameInput.dataset.originalName || deviceNameInput.defaultValue;

            if (currentName !== originalName) {
                 updatedDevices.push({
                    mac: macAddress,
                    name: currentName
                 });
            }
        });

        if (updatedDevices.length === 0) {
            messageArea.textContent = '変更されたデバイス名はありません。';
            // 変更がない場合はボタンの状態をリセット
            checkChanges();
            return;
        }

        // 変更箇所: fetchのURLをapi_handler.phpに変更
        fetch('api_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'save_device_names', devices: updatedDevices }), // actionパラメータを追加
        })
        .then(response => {
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return response.json();
            } else {
                return response.text().then(text => {
                    throw new Error("サーバーからのレスポンスがJSONではありません: " + text);
                });
            }
        })
        .then(data => {
            if (data.status === 'success') {
                messageArea.textContent = '変更が保存されました！ ページを更新してください。';
                // 保存後、originalNameを更新し、ボタンをdisabledにする
                rows.forEach(row => {
                    const deviceNameInput = row.querySelector('.device-name-input');
                    deviceNameInput.dataset.originalName = deviceNameInput.value.trim();
                });
                checkChanges(); // ボタンの状態を更新
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                messageArea.textContent = '保存中にエラーが発生しました: ' + (data.message || '不明なエラー');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            messageArea.textContent = '通信エラーまたはサーバーエラーが発生しました: ' + error.message;
        });
    });

    refreshButton.addEventListener('click', function() {
        location.reload();
    });

    updateOuiButton.addEventListener('click', function() {
        messageArea.textContent = 'OUIデータを更新中... (しばらくお待ちください)';
        fetch('generate_oui_csv.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'source=local',
        })
        .then(response => {
            if (response.ok) {
                return response.text();
            }
            throw new Error('OUIデータ更新中にエラーが発生しました。');
        })
        .then(text => {
            messageArea.textContent = 'OUIデータの更新が完了しました。ページを更新してください。';
            console.log("OUI更新スクリプト出力:", text);
        })
        .catch(error => {
            console.error('Fetch error:', error);
            messageArea.textContent = 'OUIデータ更新中にエラーが発生しました: ' + error.message;
        });
    });

    filterDevicesByStatus(filterStatuses[currentFilterIndex]);
    
    // コントロールコンテナが表示された後に高さを取得し、bodyのpadding-topを設定する関数
    function adjustLayout() {
        // コントロールコンテナを表示
        controlsContainer.style.display = "flex";

        // レイアウトが確定した後に高さを取得するため、さらに短い遅延を入れるか、
        // requestAnimationFrame を使用すると、ブラウザの描画タイミングに合わせられる
        requestAnimationFrame(() => {
            const controlsHeight = controlsContainer.offsetHeight;

            // bodyのpadding-topを設定
            body.style.paddingTop = controlsHeight + "px";

            // CSSカスタムプロパティを設定
            // thのtopプロパティがこれに依存していることを確認
            document.documentElement.style.setProperty('--controls-container-height', controlsHeight + 'px');

            // 必要であれば、ここでテーブルのスクロール位置を調整する（例: 0に設定）
            // window.scrollTo(0, 0); // これはページの最上部にスクロールしてしまうので、必要な場合にのみ使用

            console.log("Controls Container Height:", controlsHeight);
            console.log("Body Padding Top:", body.style.paddingTop);
            console.log("Custom Property --controls-container-height:", document.documentElement.style.getPropertyValue('--controls-container-height'));
        });
    }
    setTimeout(adjustLayout, 300); // 300ms (0.3秒) 後に表示と調整を行う
});
</script>

</body>
</html>
