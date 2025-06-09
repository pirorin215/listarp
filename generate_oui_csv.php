<?php

require_once 'config.php';
require_once 'functions.php';

/**
 * CSVファイルを読み込み、MACアドレスのOUI部分を抽出して配列として返す汎用関数
 * @param string $filePath 読み込むCSVファイルのパス
 * @return array 抽出されたOUIの配列 (例: ['1C-7C-98', 'D8-44-89', ...])
 */
function load_target_mac_ouis($filePath) {
    $target_ouis = [];
    if (file_exists($filePath)) {
        $file = fopen($filePath, "r");
        if ($file) {
            while (($line_data = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
                if (isset($line_data[0])) {
                    $mac_address = normalize_mac($line_data[0]); // MACアドレスを正規化
                    $mac_parts = explode(':', $mac_address);
                    if (count($mac_parts) >= 3) {
                        $oui = strtoupper($mac_parts[0] . '-' . $mac_parts[1] . '-' . $mac_parts[2]);
                        $target_ouis[$oui] = true; // 重複を避けるため連想配列のキーとして保持
                    }
                }
            }
            fclose($file);
        }
    }
    return array_keys($target_ouis); // キーの配列として返す
}

/**
 * OUIデータベースをCSV形式で生成する関数
 * @param string $source_type 動作モード ('web' または 'local')
 */
function generate_filtered_oui_csv($source_type) {
    // キャッシュディレクトリが存在しない場合は作成
    if (!is_dir(CACHE_DIR)) { // CACHE_DIR を使用
        mkdir(CACHE_DIR, 0777, true); // CACHE_DIR を使用
    }

    $oui_content = '';

    if ($source_type === 'web' || !file_exists(OUI_CACHE_FILE)) { // OUI_CACHE_FILE を使用
        // WEBから取得し、キャッシュに保存
        echo "WEBから最新のOUIデータを取得中...\n";
        $oui_content = @file_get_contents(OUI_WEB_SOURCE_URL); // OUI_WEB_SOURCE_URL を使用
        if ($oui_content === false) {
            die("エラー: URL " . OUI_WEB_SOURCE_URL . " から内容を取得できません。\n"); // OUI_WEB_SOURCE_URL を使用
        }
        if (file_put_contents(OUI_CACHE_FILE, $oui_content) === false) { // OUI_CACHE_FILE を使用
            echo "警告: OUIデータをキャッシュファイル " . OUI_CACHE_FILE . " に保存できませんでした。\n"; // OUI_CACHE_FILE を使用
        } else {
            echo "OUIデータをキャッシュファイル " . OUI_CACHE_FILE . " に保存しました。\n"; // OUI_CACHE_FILE を使用
        }
    } else {
        // ローカルキャッシュから取得
        echo "ローカルのOUIデータ " . OUI_CACHE_FILE . " を使用中...\n"; // OUI_CACHE_FILE を使用
        $oui_content = @file_get_contents(OUI_CACHE_FILE); // OUI_CACHE_FILE を使用
        if ($oui_content === false) {
            die("エラー: ローカルファイル " . OUI_CACHE_FILE . " を読み込めません。\n"); // OUI_CACHE_FILE を使用
        }
    }

    // 変換対象のOUIリストをロード
    $target_ouis = load_target_mac_ouis(MAC_IP_CACHE_FILE); // MAC_IP_CACHE_FILE を使用
    if (empty($target_ouis)) {
        echo "MAC_IP_CACHE_FILE に変換対象のMACアドレスが見つかりませんでした。処理をスキップします。\n"; // MAC_IP_CACHE_FILE を使用
        return;
    }
    $target_ouis_map = array_flip($target_ouis); // 検索を高速化するためフリップ

    $output_handle = @fopen(OUI_DB_FILE, 'w'); // OUI_DB_FILE を使用
    if (!$output_handle) {
        die("エラー: 出力ファイル " . OUI_DB_FILE . " を開けません。\n"); // OUI_DB_FILE を使用
    }

    $oui_map_for_output = [];
    $current_oui_hex = '';
    $current_organization = '';

    // 取得した内容を1行ずつ処理
    $lines = explode("\n", $oui_content);
    foreach ($lines as $line) {
        $line = trim($line);

        // OUI (hex) の行を検出 (例: 28-6F-B9 (hex) Nokia Shanghai Bell Co., Ltd.)
        if (preg_match('/^([0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2})\s+\(hex\)\s*(.*)$/', $line, $matches)) {
            // 新しいOUIが始まったら、前のOUIと組織名を、ターゲットリストに含まれていれば保存
            if ($current_oui_hex && $current_organization) {
                if (isset($target_ouis_map[$current_oui_hex])) {
                    $oui_map_for_output[$current_oui_hex] = $current_organization;
                }
            }
            $current_oui_hex = $matches[1];
            $current_organization = trim($matches[2]);
        }
        // OUI (base 16) の行を検出 (例: 286FB9 (base 16) Extreme Networks Headquarters)
        // この行は組織名を含まないが、直前の (hex) 行の組織名を使用する
        else if (preg_match('/^([0-9A-F]{6})\s+\(base 16\)\s*(.*)$/', $line, $matches)) {
            // (hex)行のOUIがまだ設定されていない場合、この行のOUIを使用
            if (empty($current_oui_hex)) {
                 // base 16形式からハイフン付きhex形式に変換
                $current_oui_hex = substr($matches[1], 0, 2) . '-' . substr($matches[1], 2, 2) . '-' . substr($matches[1], 4, 2);
            }
            // もし (base 16) の行に組織名がある場合は更新（ただしoui.txtの例では通常空）
            if (!empty(trim($matches[2]))) {
                 $current_organization = trim($matches[2]);
            }
        }
        // それ以外の行（住所など）はスキップ
    }

    // 最後のOUIと組織名を、ターゲットリストに含まれていれば保存
    if ($current_oui_hex && $current_organization) {
        if (isset($target_ouis_map[$current_oui_hex])) {
            $oui_map_for_output[$current_oui_hex] = $current_organization;
        }
    }

    // 収集したデータをCSVとして書き出す
    foreach ($oui_map_for_output as $oui => $organization) {
        fputcsv($output_handle, [$oui, $organization], ',', '"', '\\');
    }

    fclose($output_handle);

    echo OUI_DB_FILE . " が正常に生成されました。 (" . count($oui_map_for_output) . " 件)\n"; // OUI_DB_FILE を使用
}

// スクリプト実行
$source_mode = 'local'; // デフォルトはローカル

// コマンドライン引数をチェック
if (isset($argv[1])) {
    if ($argv[1] === '--web') {
        $source_mode = 'web';
    } elseif ($argv[1] === '--local') {
        $source_mode = 'local';
    }
}

// POSTパラメータをチェック (Ajaxからの呼び出しを想定)
if (isset($_POST['source'])) {
    if ($_POST['source'] === 'web') {
        $source_mode = 'web';
    } elseif ($_POST['source'] === 'local') {
        $source_mode = 'local';
    }
}

generate_filtered_oui_csv($source_mode);

?>
