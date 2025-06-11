<?php
// functions.php
// 共通関数群

/**
 * MACアドレスを正規化する関数
 * 例: "78:c8:81:f8:a:96" -> "78:c8:81:f8:0a:96"
 * @param string $mac 正規化するMACアドレス
 * @return string 正規化されたMACアドレス
 */
function normalize_mac($mac) {
    $mac = strtolower($mac);
    $parts = explode(':', $mac);
    $normalized_parts = [];
    foreach ($parts as $part) {
        $normalized_parts[] = str_pad($part, 2, '0', STR_PAD_LEFT);
    }
    return implode(':', $normalized_parts);
}

/**
 * ping死活監視関数
 * @param string $ip 監視するIPアドレス
 * @return bool true:稼働中, false:停止中またはIPアドレスが無効
 */
function check_ping($ip) {
    if (empty($ip)) {
        return false;
    }
    // Windows環境の場合、pingコマンドのオプションが異なる可能性があるため注意
    // Linux/macOS: -c (カウント), -W (タイムアウト)
    // Windows: -n (カウント), -w (タイムアウト)
    $command = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "ping -n 1 -w 1000 $ip" : "ping -c 1 -W 1 $ip";
    $output = shell_exec($command . ' 2>&1');

    // Linux/macOSとWindowsの両方に対応できる可能性のある文字列を追加
    return strpos($output, "1 packets received") !== false || strpos(mb_convert_encoding($output, 'UTF-8', 'auto'), "bytes from") !== false || strpos($output, "Reply from") !== false;
}

// OUIデータベースをメモリにロードするグローバル変数
$GLOBALS['oui_map'] = [];

/**
 * OUIデータベースをロードする関数 (初回のみ実行)
 */
function load_oui_database() {
    if (!empty($GLOBALS['oui_map'])) {
        return; // 既にロード済み
    }

    if (file_exists(OUI_DB_FILE)) {
        $file = fopen(OUI_DB_FILE, "r");
        if ($file) {
            while (($line = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
                if (isset($line[0]) && isset($line[1])) {
                    $oui = str_replace('-', '', trim(strtoupper($line[0]))); // OUIをハイフンなしの大文字に正規化
                    $GLOBALS['oui_map'][$oui] = $line[1];
                }
            }
            fclose($file);
        }
    }
}

/**
 * MACアドレスがローカル管理アドレス (LAA) であるかを判定する関数
 * @param string $mac MACアドレス (正規化済みを推奨)
 * @return bool true: ローカル管理アドレス, false: ユニバーサル管理アドレス
 */
function is_local_administered_mac($mac) {
    $parts = explode(':', $mac);
    if (count($parts) < 1) {
        return false;
    }
    $first_octet_hex = $parts[0];
    $first_octet_bin = str_pad(base_convert($first_octet_hex, 16, 2), 8, '0', STR_PAD_LEFT);
    return $first_octet_bin[6] === '1';
}

/**
 * MACアドレスからベンダー名を取得する関数
 * @param string $mac MACアドレス (正規化済みを推奨)
 * @return string ベンダー名、見つからない場合は「不明」または「ランダムMAC」
 */
function get_vendor_name($mac) {
    load_oui_database(); // 初回呼び出し時にデータベースをロード

    // まずローカル管理アドレスであるかをチェック
    if (is_local_administered_mac($mac)) {
        return 'ランダムMAC'; // ローカル管理アドレスの場合は「ランダムMAC」と表示
    }

    $mac_parts = explode(':', $mac);
    if (count($mac_parts) < 3) {
        return '不明'; // MACアドレスの形式が不適切
    }
    $oui = strtoupper($mac_parts[0] . $mac_parts[1] . $mac_parts[2]);

    return $GLOBALS['oui_map'][$oui] ?? '不明';
}

?>
