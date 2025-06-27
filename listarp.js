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

    // アイコン関連の要素
    const iconModal = document.getElementById('iconModal');
    const closeButton = iconModal.querySelector('.close-button');
    const iconGrid = document.getElementById('iconGrid');
    const iconFiles = JSON.parse(document.body.dataset.iconFiles);
    let currentEditingIcon = null; // 現在編集中のアイコン要素を保持

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
        const deviceIcons = document.querySelectorAll('.device-icon');
        let hasChanges = false;

        deviceNameInputs.forEach(input => {
            if (input.value.trim() !== input.dataset.originalName.trim()) {
                hasChanges = true;
            }
        });

        deviceIcons.forEach(icon => {
            const currentIconSrc = icon.getAttribute('src').split('/').pop();
            if (currentIconSrc !== icon.dataset.originalIcon) {
                hasChanges = true;
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

    // アイコンクリック時のイベントリスナー設定
    document.querySelectorAll('.device-icon').forEach(icon => {
        icon.addEventListener('click', function() {
            currentEditingIcon = this; // 現在編集中のアイコンを保存
            openIconModal(this.dataset.originalIcon); // 現在のアイコンをモーダルに渡す
        });
    });

    // モーダルを閉じるボタンのイベントリスナー
    closeButton.addEventListener('click', function() {
        iconModal.style.display = 'none';
    });

    // モーダルの外側をクリックで閉じる
    window.addEventListener('click', function(event) {
        if (event.target == iconModal) {
            iconModal.style.display = 'none';
        }
    });

    // アイコン選択モーダルを開く関数
    function openIconModal(currentIconName) {
        iconGrid.innerHTML = ''; // グリッドをクリア
        iconFiles.forEach(iconFile => {
            const img = document.createElement('img');
            img.src = `icons/${iconFile}`;
            img.alt = iconFile;
            img.dataset.iconName = iconFile;
            img.classList.add('icon-option');
            if (iconFile === currentIconName) {
                img.classList.add('selected');
            }
            img.addEventListener('click', function() {
                // 選択状態を更新
                iconGrid.querySelectorAll('.icon-option').forEach(i => i.classList.remove('selected'));
                this.classList.add('selected');

                // 選択されたアイコンを現在のデバイスアイコンに適用
                if (currentEditingIcon) {
                    currentEditingIcon.src = `icons/${this.dataset.iconName}`;
                    // originalIconは変更しない。checkChangesで比較するため
                    checkChanges(); // 変更をチェック
                }
                iconModal.style.display = 'none'; // モーダルを閉じる
            });
            iconGrid.appendChild(img);
        });
        iconModal.style.display = 'block';
    }

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
            const macAddress = row.dataset.macAddress.trim();
            const deviceNameInput = row.querySelector('.device-name-input');
            const currentName = deviceNameInput.value.trim();
            const originalName = deviceNameInput.dataset.originalName || deviceNameInput.defaultValue;

            const deviceIconImg = row.querySelector('.device-icon');
            const currentIcon = deviceIconImg.getAttribute('src').split('/').pop();
            const originalIcon = deviceIconImg.dataset.originalIcon;

            if (currentName !== originalName || currentIcon !== originalIcon) {
                 updatedDevices.push({
                    mac: macAddress,
                    name: currentName,
                    icon: currentIcon
                 });
            }
        });

        if (updatedDevices.length === 0) {
            messageArea.textContent = '変更されたデバイス名やアイコンはありません。';
            checkChanges();
            return;
        }

        fetch('api_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'save_device_names', devices: updatedDevices }),
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
                rows.forEach(row => {
                    const deviceNameInput = row.querySelector('.device-name-input');
                    deviceNameInput.dataset.originalName = deviceNameInput.value.trim();
                    const deviceIconImg = row.querySelector('.device-icon');
                    deviceIconImg.dataset.originalIcon = deviceIconImg.getAttribute('src').split('/').pop();
                });
                checkChanges();
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
