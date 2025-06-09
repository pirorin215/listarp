<?php
// data_handler.php
// データファイルの読み書き、ファイルロック処理

require_once 'config.php';
require_once 'functions.php';

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

?>
