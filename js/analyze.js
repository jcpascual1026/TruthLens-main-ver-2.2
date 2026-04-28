// Analyze Page: Tabs, Upload, Results, and History
const API_ENDPOINT = 'process.php';
const HISTORY_ENDPOINT = 'history.php';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initUpload();
    initAnalyzeButtons();
    loadHistory();
    initClearHistory();
});

/* ---------- Tabs ---------- */
function initTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;

            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === `tab-${target}`) {
                    content.classList.add('active');
                }
            });
        });
    });
}

/* ---------- Upload ---------- */
function initUpload() {
    const uploadZone = document.getElementById('upload-zone');
    const fileInput = uploadZone?.querySelector('input[type="file"]');
    const placeholder = uploadZone?.querySelector('.upload-placeholder');
    const preview = uploadZone?.querySelector('.upload-preview');
    const previewImg = preview?.querySelector('img');
    const previewName = preview?.querySelector('.filename');
    const removeBtn = preview?.querySelector('.remove-file');

    if (!uploadZone || !fileInput || !placeholder || !preview) return;

    uploadZone.addEventListener('click', (e) => {
        if (e.target.tagName !== 'INPUT' && !e.target.closest('.remove-file')) {
            fileInput.click();
        }
    });

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
        }, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => {
            uploadZone.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        uploadZone.addEventListener(eventName, () => {
            uploadZone.classList.remove('dragover');
        }, false);
    });

    uploadZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files.length > 0) handleFile(files[0]);
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) handleFile(fileInput.files[0]);
    });

    removeBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        resetUpload();
    });

    function handleFile(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please upload an image file.');
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = e.target.result;
            previewName.textContent = file.name;
            placeholder.style.display = 'none';
            preview.style.display = 'flex';
        };
        reader.readAsDataURL(file);
    }

    function resetUpload() {
        fileInput.value = '';
        previewImg.src = '';
        previewName.textContent = '';
        preview.style.display = 'none';
        placeholder.style.display = 'block';
    }
}

/* ---------- Analyze Buttons ---------- */
function initAnalyzeButtons() {
    const urlBtn = document.querySelector('#tab-url .btn-primary');
    const textBtn = document.querySelector('#tab-text .btn-primary');
    const imageBtn = document.querySelector('#tab-image .btn-primary');

    urlBtn?.addEventListener('click', () => {
        const input = document.getElementById('url-input');
        const url = input?.value.trim();
        if (!url) return alert('Please enter a URL.');
        sendAnalysis('url', url).then(() => input.value = '');
    });

    textBtn?.addEventListener('click', () => {
        const input = document.getElementById('text-input');
        const text = input?.value.trim();
        if (!text) return alert('Please paste some text.');
        sendAnalysis('text', text).then(() => input.value = '');
    });

    imageBtn?.addEventListener('click', () => {
        const previewName = document.querySelector('#tab-image .filename');
        const previewImg = document.querySelector('#tab-image .upload-preview img');
        const name = previewName?.textContent.trim();
        if (!name) return alert('Please upload an image first.');
        const imageData = previewImg?.src || '';
        sendAnalysis('image', name, imageData).then(() => document.querySelector('.remove-file')?.click());
    });
}

function sendAnalysis(type, data, imageData = '') {
    const params = {
        input_type: type,
        input_data: data
    };
    if (imageData) {
        params.image_data = imageData;
    }
    const formData = new URLSearchParams(params);

    return fetch(API_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData,
    })
    .then(response => response.text().then(text => {
        if (!text) throw new Error('Empty response from server');
        const cleanedText = text.replace(/^\uFEFF/, '');
        let parsed;
        try {
            parsed = JSON.parse(cleanedText);
        } catch (e) {
            console.error('Invalid JSON response:', cleanedText.substring(0, 200));
            throw new Error('Invalid JSON response: ' + e.message);
        }
        if (!response.ok) {
            throw new Error(parsed.error || `HTTP ${response.status}: ${response.statusText}`);
        }
        return parsed;
    }))
    .then(result => {
        if (result.error) {
            alert('Error: ' + result.error);
            throw new Error(result.error);
        }
        updateResultsPanel(result);
        return loadHistory();
    })
    .catch(error => {
        console.error('Analysis error:', error);
        alert('Failed to analyze: ' + error.message);
    });
}

function loadHistory() {
    return fetch(HISTORY_ENDPOINT)
        .then(response => response.text().then(text => {
            if (!text) throw new Error('Empty response from server');
            let parsed;
            try {
                parsed = JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text.substring(0, 200));
                throw new Error('Invalid JSON response: ' + e.message);
            }
            if (!response.ok) {
                throw new Error(parsed.error || `HTTP ${response.status}: ${response.statusText}`);
            }
            return parsed;
        }))
        .then(history => {
            if (Array.isArray(history)) {
                renderHistory(history);
            }
        })
        .catch(error => {
            console.error('History load failed:', error);
        });
}

function initClearHistory() {
    document.getElementById('clear-history')?.addEventListener('click', () => {
        if (!confirm('Clear all analysis history?')) return;

        fetch(HISTORY_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'clear' }),
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                renderHistory([]);
                updateResultsPanel({ result: 'real', score: 0, label: 'No Data' });
            } else {
                alert('Unable to clear history.');
            }
        })
        .catch(error => {
            console.error('Clear history failed:', error);
        });
    });
}

function updateResultsPanel(item) {
    const scoreNum = document.querySelector('.score-number');
    const scoreLabel = document.querySelector('.score-label');
    const progress = document.querySelector('.score-ring .progress');
    const badges = document.querySelector('.result-badges');

    if (scoreNum) scoreNum.textContent = item.confidence ?? item.score ?? 0;
    if (scoreLabel) scoreLabel.textContent = item.result === 'real' ? '% Real' : '% Fake';

    const scoreValue = Number(item.confidence ?? item.score ?? 0);
    const offset = 327 - (327 * scoreValue / 100);
    if (progress) progress.style.strokeDashoffset = offset;

    if (badges) {
        const icon = item.result === 'real' ? 'fa-circle-check' : 'fa-triangle-exclamation';
        const badgeClass = item.result === 'real' ? 'verified' : 'warning';
        const label = item.label || (item.result === 'real' ? 'Source Verified' : 'Fake Alert');
        badges.innerHTML = `
            <div class="result-badge ${badgeClass}">
                <i class="fa-solid ${icon}"></i>
                <span>${label}</span>
            </div>
        `;
    }

    const bars = document.querySelectorAll('.breakdown-bar > div');
    const spans = document.querySelectorAll('.breakdown-item > span:last-child');
    if (bars.length >= 3 && spans.length >= 3) {
        const base = scoreValue;
        [base, Math.max(20, base - 15), Math.min(100, base + 10)].forEach((v, i) => {
            bars[i].style.width = v + '%';
            spans[i].textContent = v + '%';
        });
    }

    // Add detailed reasons section
    const breakdown = document.querySelector('.breakdown');
    const existingReasons = document.querySelector('.detailed-reasons');
    if (existingReasons) existingReasons.remove();
    if (breakdown && item.detailed_reasons) {
        let reasonsHtml = '<ul class="detailed-reasons">';
        item.detailed_reasons.forEach(reason => {
            reasonsHtml += `<li>${reason}</li>`;
        });
        reasonsHtml += '</ul>';
        breakdown.insertAdjacentHTML('afterend', reasonsHtml);
    }
}

function renderHistory(list) {
    const container = document.getElementById('history-list');
    const empty = document.getElementById('history-empty');

    if (!container) return;

    if (!Array.isArray(list) || list.length === 0) {
        container.innerHTML = '';
        if (empty) empty.style.display = 'block';
        return;
    }

    if (empty) empty.style.display = 'none';

    const typeIcons = {
        url: 'fa-link',
        text: 'fa-keyboard',
        image: 'fa-image'
    };

    container.innerHTML = list.map(item => {
        const date = new Date(item.created_at);
        const timeStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const resultClass = item.result;
        const resultIcon = item.result === 'real' ? 'fa-check' : 'fa-xmark';

        return `
            <div class="history-card">
                <div class="history-icon">
                    <i class="fa-solid ${typeIcons[item.input_type] || 'fa-file'}"></i>
                </div>
                <div class="history-info">
                    <p class="history-title">${escapeHtml(item.input_data)}</p>
                    <p class="history-meta">${capitalize(item.input_type)} · ${timeStr}</p>
                </div>
                <div class="history-result ${resultClass}">
                    <i class="fa-solid ${resultIcon}"></i>
                    ${capitalize(item.result)}
                </div>
                <div class="history-score">${item.confidence}%</div>
            </div>
        `;
    }).join('');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}


