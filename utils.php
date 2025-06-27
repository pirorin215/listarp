<?php
// utils.php
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

// データファイルの読み書き、ファイルロック処理

/**
 * CSVファイルを読み込み、連想配列として返す汎用関数
 * @param string $filePath 読み込むCSVファイルのパス
 * @return array CSVの内容 (連想配列)
 */
function read_csv_to_map($filePath) {
    $map = [];
    if (file_exists($filePath)) {
        $file = fopen($filePath, "r");
        if ($file) {
            while (($line = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
                if (isset($line[0]) && isset($line[1])) {
                    // MACアドレスの場合は正規化
                    if (strpos($line[0], ':') !== false && strlen($line[0]) >= 11) { // MACアドレスらしき形式か判定
                        $key = normalize_mac($line[0]);
                    } else {
                        $key = $line[0];
                    }
                    $map[$key] = $line[1];
                }
            }
            fclose($file);
        }
    }
    return $map;
}

/**
 * 連想配列の内容をCSVファイルに書き込む汎用関数 (排他ロック付き)
 * バックアップ機能を追加
 * @param string $filePath 書き込むCSVファイルのパス
 * @param array $data 書き込むデータ (連想配列)
 * @param bool $enableBackup バックアップを有効にするか (デフォルト: false)
 * @return bool 成功した場合 true, 失敗した場合 false
 */
function write_map_to_csv($filePath, $data, $enableBackup = false) {
    // ディレクトリが存在しない場合は作成を試みる
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if ($enableBackup) {
        // バックアップディレクトリが存在しない場合は作成
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0777, true);
        }

        // 既存のファイルをバックアップ
        if (file_exists($filePath)) {
            $timestamp = date('YmdHis');
            $backupFileName = basename($filePath, '.csv') . '_' . $timestamp . '.csv';
            copy($filePath, BACKUP_DIR . '/' . $backupFileName);
        }

        // 古いバックアップファイルを削除
        $files = glob(BACKUP_DIR . '/' . basename($filePath, '.csv') . '_*.csv');
        if (count($files) > BACKUP_GENERATIONS) {
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b); // 更新日時でソート (昇順)
            });
            for ($i = 0; $i < count($files) - BACKUP_GENERATIONS; $i++) {
                unlink($files[$i]); // 古いファイルを削除
            }
        }
    }

    $fp = fopen($filePath, 'w');
    if ($fp) {
        if (flock($fp, LOCK_EX)) { // 排他ロック
            foreach ($data as $key => $value) {
                fputcsv($fp, [$key, $value], ',', '"', '\\');
            }
            fflush($fp); // バッファをフラッシュ
            flock($fp, LOCK_UN); // ロック解除
            fclose($fp);
            return true;
        } else {
            fclose($fp);
            return false; // ロック失敗
        }
    }
    return false; // ファイルオープン失敗
}

/**
 * MACアドレスとデバイス名のマッピングを読み込む
 * @return array MACアドレス => デバイス名の連想配列
 */
function load_device_mapping() {
    return read_csv_to_map(DEVICE_NAMES_FILE); // 新しいファイル名を使用
}

/**
 * MACアドレスとデバイス名のマッピングを保存する
 * @param array $map MACアドレス => デバイス名の連想配列
 * @return bool 成功した場合 true, 失敗した場合 false
 */
function save_device_mapping($map) {
    return write_map_to_csv(DEVICE_NAMES_FILE, $map, true); // バックアップを有効にする
}

/**
 * MACアドレスとIPアドレスのキャッシュを読み込む
 * @return array MACアドレス => IPアドレスの連想配列
 */
function load_mac_ip_cache() {
    return read_csv_to_map(MAC_IP_CACHE_FILE);
}

/**
 * MACアドレスとIPアドレスのキャッシュを保存する
 * @param array $cache MACアドレス => IPアドレスの連想配列
 * @return bool 成功した場合 true, 失敗した場合 false
 */
function save_mac_ip_cache($cache) {
    return write_map_to_csv(MAC_IP_CACHE_FILE, $cache);
}

/**
 * 最終検出日時を読み込む
 * @return array MACアドレス => Unixタイムスタンプの連想配列
 */
function load_last_detected() {
    return read_csv_to_map(LAST_DETECTED_FILE);
}

/**
 * 最終検出日時を保存する
 * @param array $detected_map MACアドレス => Unixタイムスタンプの連想配列
 * @return bool 成功した場合 true, 失敗した場合 false
 */
function save_last_detected($detected_map) {
    return write_map_to_csv(LAST_DETECTED_FILE, $detected_map);
}

/**
 * MACアドレスとデバイスアイコンのマッピングを読み込む
 * @return array MACアドレス => アイコンファイル名の連想配列
 */
function load_device_icons() {
    return read_csv_to_map(DEVICE_ICONS_FILE);
}

/**
 * MACアドレスとデバイスアイコンのマッピングを保存する
 * @param array $map MACアドレス => アイコンファイル名の連想配列
 * @return bool 成功した場合 true, 失敗した場合 false
 */
function save_device_icons($map) {
    return write_map_to_csv(DEVICE_ICONS_FILE, $map, true); // バックアップを有効にする
}

