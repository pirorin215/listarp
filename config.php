<?php
// config.php

// キャッシュディレクトリ
define('CACHE_DIR', __DIR__ . '/cache'); // スクリプトの実行ディレクトリからの相対パス
define('BACKUP_DIR', CACHE_DIR . '/backup'); // バックアップディレクトリ

// OUIデータ取得元URL
define('OUI_WEB_SOURCE_URL', 'https://standards-oui.ieee.org/oui/oui.txt');

// ファイルパス (キャッシュディレクトリ配下)
define('DEVICE_NAMES_FILE', CACHE_DIR . '/device_names.csv');
define('MAC_IP_CACHE_FILE', CACHE_DIR . '/mac_ipaddress.csv');
define('LAST_DETECTED_FILE', CACHE_DIR . '/last_detected.csv');
define('OUI_DB_FILE', CACHE_DIR . '/oui.csv'); // OUIデータベースファイル
define('OUI_CACHE_FILE', CACHE_DIR . '/oui.txt'); // WebからダウンロードしたOUIファイルのキャッシュパス

// バックアップ設定
define('BACKUP_GENERATIONS', 5);

// デバッグ設定 (本番環境では Off を推奨)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 色の定義 (HTMLタグに適用)
$GLOBALS['color_map'] = [
    "稼働 登録済" => "green",
    "稼働 未登録" => "orange",
    "停止 登録済" => "gray",
    "停止 未登録" => "red"
];

// 状態名の定義
define('STATUS_ALIVE_REGISTERED', '稼働 登録済');
define('STATUS_ALIVE_UNREGISTERED', '稼働 未登録');
define('STATUS_STOPPED_REGISTERED', '停止 登録済');
define('STATUS_STOPPED_UNREGISTERED', '停止 未登録');
define('STATUS_UNKNOWN_DEVICE', 'Unknown');
define('STATUS_ALL_DISPLAY', '全表示');

date_default_timezone_set('Asia/Tokyo');
?>
