const root = document.documentElement;
const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
const prefersReducedMotion = () => reducedMotionQuery.matches;
root.classList.add('js-ready');

const form = document.querySelector('#receipt-form');

if (form) {
    const fileInput = form.querySelector('input[type="file"]');
    const uploadBox = form.querySelector('.upload-box');
    const preview = form.querySelector('[data-receipt-preview]');
    const previewImage = form.querySelector('[data-receipt-preview-image]');
    const previewFile = form.querySelector('[data-receipt-preview-file]');
    const previewMeta = form.querySelector('[data-receipt-preview-meta]');
    const qualityWarning = form.querySelector('[data-receipt-quality-warning]');
    const uploadMessage = form.querySelector('[data-upload-message]');
    const formMessage = form.querySelector('[data-form-message]');
    const uploadTitle = form.querySelector('[data-upload-title]');
    const uploadState = form.querySelector('[data-upload-state]');
    const submitStatus = form.querySelector('[data-submit-status]');
    const submitButton = form.querySelector('button[type="submit"]');
    const cameraPanel = form.querySelector('[data-camera-panel]');
    const cameraVideo = form.querySelector('[data-camera-video]');
    const cameraMessage = form.querySelector('[data-camera-message]');
    let cameraStream = null;
    let rotation = 0;
    let selectedHash = '';
    let selectedFile = null;

    const setMessage = (element, message = '') => { if (element) { element.textContent = message; element.hidden = message === ''; } };
    const isImage = (file) => file && file.type.startsWith('image/');
    const allowed = (file) => file && (/^image\/(jpeg|png|webp)$/.test(file.type) || file.name.toLowerCase().endsWith('.txt'));
    const formatBytes = (bytes) => bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

    async function hashFile(file) {
        if (!window.crypto?.subtle) return '';
        const buffer = await file.arrayBuffer();
        const digest = await crypto.subtle.digest('SHA-256', buffer);
        return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function showPreview(file) {
        if (!file) return;
        selectedFile = file;
        rotation = 0;
        uploadBox?.classList.add('has-file');
        if (uploadTitle) uploadTitle.textContent = 'Receipt selected';
        if (uploadState) uploadState.textContent = 'Ready to analyze';
        if (preview) preview.hidden = false;
        if (previewMeta) previewMeta.textContent = `${file.name} · ${formatBytes(file.size)}`;
        if (isImage(file)) {
            previewImage.src = URL.createObjectURL(file);
            previewImage.hidden = false;
            if (previewFile) previewFile.hidden = true;
            const image = new Image();
            image.onload = () => {
                qualityWarning.hidden = image.naturalWidth >= 800 && image.naturalHeight >= 600;
                if (!qualityWarning.hidden) qualityWarning.textContent = 'The receipt image may be too small for accurate OCR. You can still continue or choose a clearer photo.';
            };
            image.src = previewImage.src;
        } else {
            previewImage.hidden = true;
            if (previewFile) { previewFile.hidden = false; previewFile.textContent = 'Text receipt selected. The file will be read as text.'; }
            if (qualityWarning) qualityWarning.hidden = true;
        }
        hashFile(file).then((hash) => {
            selectedHash = hash;
            const recent = JSON.parse(localStorage.getItem('r2h-recent-receipts') || '[]');
            if (hash && recent.includes(hash)) setMessage(uploadMessage, 'This exact file was recently analyzed on this browser. You can still continue if you want to re-analyze it.');
        }).catch(() => {});
    }

    function clearFile() {
        if (fileInput) fileInput.value = '';
        selectedFile = null; selectedHash = ''; rotation = 0;
        uploadBox?.classList.remove('has-file');
        if (uploadTitle) uploadTitle.textContent = 'Drop receipt here';
        if (uploadState) uploadState.textContent = 'Ready';
        if (preview) preview.hidden = true;
        setMessage(uploadMessage);
    }

    function stopCamera() {
        cameraStream?.getTracks().forEach((track) => track.stop());
        cameraStream = null;
        if (cameraVideo) cameraVideo.srcObject = null;
        if (cameraPanel) cameraPanel.hidden = true;
    }

    form.querySelector('[data-open-camera]')?.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setMessage(uploadMessage, 'Laptop camera access is not available in this browser. Choose an image file instead.');
            return;
        }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            cameraVideo.srcObject = cameraStream;
            cameraPanel.hidden = false;
            cameraMessage.textContent = 'Position the receipt clearly, then choose Take photo.';
        } catch (error) {
            setMessage(uploadMessage, 'Camera access was not granted. You can still choose or drag in a receipt image.');
        }
    });
    form.querySelector('[data-close-camera]')?.addEventListener('click', stopCamera);
    form.querySelector('[data-capture-camera]')?.addEventListener('click', () => {
        if (!cameraVideo?.videoWidth || !cameraVideo.videoHeight) return;
        const canvas = document.createElement('canvas');
        canvas.width = cameraVideo.videoWidth; canvas.height = cameraVideo.videoHeight;
        canvas.getContext('2d').drawImage(cameraVideo, 0, 0);
        canvas.toBlob((blob) => {
            if (!blob) return;
            const file = new File([blob], `receipt-camera-${Date.now()}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
            const transfer = new DataTransfer(); transfer.items.add(file); fileInput.files = transfer.files;
            fileInput.dispatchEvent(new Event('change')); stopCamera();
        }, 'image/jpeg', 0.92);
    });

    fileInput?.addEventListener('change', () => { setMessage(uploadMessage); showPreview(fileInput.files?.[0]); });
    ['dragenter', 'dragover'].forEach((name) => uploadBox?.addEventListener(name, (event) => { event.preventDefault(); uploadBox.classList.add('is-dragging'); }));
    uploadBox?.addEventListener('dragleave', () => uploadBox.classList.remove('is-dragging'));
    uploadBox?.addEventListener('drop', (event) => { event.preventDefault(); uploadBox.classList.remove('is-dragging'); const file = event.dataTransfer?.files?.[0]; if (file) { const transfer = new DataTransfer(); transfer.items.add(file); fileInput.files = transfer.files; fileInput.dispatchEvent(new Event('change')); } });
    uploadBox?.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput?.click(); } });
    form.querySelector('[data-remove-receipt]')?.addEventListener('click', clearFile);
    form.querySelectorAll('[data-rotate]').forEach((button) => button.addEventListener('click', async () => {
        if (!isImage(selectedFile)) return;
        const image = new Image(); image.src = previewImage.src; await new Promise((resolve) => { image.onload = resolve; });
        rotation = (rotation + Number(button.dataset.rotate)) % 360;
        const canvas = document.createElement('canvas'); const swap = Math.abs(rotation) % 180 === 90;
        canvas.width = swap ? image.naturalHeight : image.naturalWidth; canvas.height = swap ? image.naturalWidth : image.naturalHeight;
        const context = canvas.getContext('2d'); context.translate(canvas.width / 2, canvas.height / 2); context.rotate(rotation * Math.PI / 180); context.drawImage(image, -image.naturalWidth / 2, -image.naturalHeight / 2);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, selectedFile.type || 'image/jpeg', 0.92));
        if (!blob) return; const rotated = new File([blob], selectedFile.name, { type: selectedFile.type, lastModified: Date.now() });
        const transfer = new DataTransfer(); transfer.items.add(rotated); fileInput.files = transfer.files; fileInput.dispatchEvent(new Event('change'));
    }));

    form.querySelectorAll('[data-analysis-mode]').forEach((button) => button.addEventListener('click', () => {
        const mode = button.dataset.analysisMode;
        form.action = mode === 'text' ? 'api/analyze_text.php' : 'api/process_receipt.php';
        form.querySelectorAll('[data-analysis-mode]').forEach((item) => { const active = item === button; item.classList.toggle('is-active', active); item.setAttribute('aria-pressed', String(active)); });
        form.querySelectorAll('[data-analysis-panel]').forEach((panel) => { const active = panel.dataset.analysisPanel === mode; panel.hidden = !active; panel.querySelectorAll('input, textarea').forEach((field) => { field.disabled = !active; field.required = active && field.name === 'receipt_text'; }); });
        if (mode === 'text') clearFile();
    }));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const mode = form.action.includes('analyze_text') ? 'text' : 'image';
        if (mode === 'image' && (!fileInput?.files?.length || !allowed(fileInput.files[0]))) { setMessage(uploadMessage, 'Choose a JPG, PNG, WEBP, or TXT receipt file.'); uploadState.textContent = 'Invalid file'; return; }
        if (mode === 'image' && fileInput.files[0].size > 10 * 1024 * 1024) { setMessage(uploadMessage, 'That receipt is larger than the 10 MB upload limit. Choose a smaller file.'); uploadState.textContent = 'Invalid file'; return; }
        submitButton.disabled = true; submitButton.textContent = 'Processing...'; submitStatus.textContent = 'Uploading receipt';
        showLoadingOverlay('Processing receipt', 'Uploading receipt · Reading receipt text · Matching food items · Preparing health analysis');
        try {
            if (selectedHash) { const recent = JSON.parse(localStorage.getItem('r2h-recent-receipts') || '[]'); localStorage.setItem('r2h-recent-receipts', JSON.stringify([selectedHash, ...recent.filter((item) => item !== selectedHash)].slice(0, 5))); }
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
            if (response.redirected) { window.location.assign(response.url); return; }
            const data = await response.json(); throw new Error(data.error || 'Receipt processing failed. Please try again.');
        } catch (error) {
            document.querySelector('.loading-overlay')?.remove(); document.body.classList.remove('is-loading');
            setMessage(formMessage, error.message || 'Receipt processing failed. Please try again.'); submitButton.disabled = false; submitButton.textContent = 'Analyze receipt'; submitStatus.textContent = 'Ready to analyze';
        }
    });
}

document.querySelectorAll('[data-item-correction-form]').forEach((correctionForm) => {
    correctionForm.addEventListener('submit', () => {
        showLoadingOverlay('Analyzing corrected items', 'The corrected basket is being scored with current health notes and food rules.');
    });
});

const itemEditorBody = document.querySelector('[data-item-editor-body]');
const addItemRowButton = document.querySelector('[data-add-item-row]');

function removeItemRow(row) {
    if (!itemEditorBody || itemEditorBody.rows.length <= 1) {
        row.querySelectorAll('input').forEach((input) => {
            input.value = input.name.includes('quantity') ? '1' : '';
        });
        return;
    }

    row.remove();
}

function attachItemRowEvents(row) {
    row.querySelectorAll('[data-remove-item-row]').forEach((button) => {
        button.addEventListener('click', () => removeItemRow(row));
    });
}

if (itemEditorBody) {
    itemEditorBody.querySelectorAll('tr').forEach(attachItemRowEvents);
}

if (addItemRowButton && itemEditorBody) {
    addItemRowButton.addEventListener('click', () => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input type="text" name="item_name[]" placeholder="food item" required><input type="hidden" name="raw_line[]" value=""></td>
            <td><input type="number" name="quantity[]" min="0" step="0.1" value="1" required></td>
            <td class="muted">manual</td>
            <td><span class="risk-badge risk-low">new</span></td>
            <td><span class="risk-badge risk-low">manual</span><small>review</small></td>
            <td class="proof-cell">Added during review</td>
            <td><button class="mini-icon-button" type="button" data-remove-item-row title="Remove row">x</button></td>
        `;
        itemEditorBody.appendChild(row);
        attachItemRowEvents(row);
        row.querySelector('input')?.focus();
    });
}

const familySizeInput = document.querySelector('input[name="family_size"]');
const familyMemberEditor = document.querySelector('[data-family-member-editor]');

function resetFamilyMemberCard(card, index) {
    card.dataset.familyMemberIndex = String(index);

    card.querySelectorAll('input, select, textarea').forEach((field) => {
        if (field.name.includes('member_conditions[')) {
            field.name = field.name.replace(/member_conditions\[\d+\]/, `member_conditions[${index}]`);
            field.checked = false;
        } else if (field.name === 'member_name[]') {
            field.value = '';
        } else if (field.name === 'member_relationship[]') {
            field.value = '';
        } else if (field.name === 'member_notes[]') {
            field.value = '';
        } else if (field.name === 'member_age_group[]') {
            field.value = 'adult';
        }
    });
}

function setFamilyRelationshipMessage(message = '') {
    const messageElement = document.querySelector('[data-family-relationship-error]');
    if (!messageElement) return;
    messageElement.textContent = message;
    messageElement.hidden = message === '';
}

function enforceSingleSelfRelationship(changedField = null) {
    if (!familyMemberEditor) return;

    const activeSelfFields = [...familyMemberEditor.querySelectorAll('[data-family-member-card]:not([hidden]) select[name="member_relationship[]"]')]
        .filter((field) => field.value === 'self');

    if (activeSelfFields.length <= 1) {
        setFamilyRelationshipMessage('');
        return;
    }

    if (changedField?.value === 'self') {
        changedField.value = '';
    } else {
        activeSelfFields.slice(1).forEach((field) => { field.value = ''; });
    }

    setFamilyRelationshipMessage('Only one active household member can be marked as Self.');
}

function syncFamilyMemberCards() {
    if (!familySizeInput || !familyMemberEditor) return;

    const requestedCount = Math.max(1, Math.min(20, Number.parseInt(familySizeInput.value, 10) || 1));
    familySizeInput.value = String(requestedCount);
    let cards = [...familyMemberEditor.querySelectorAll('[data-family-member-card]')];

    while (cards.length < requestedCount) {
        const card = cards[0]?.cloneNode(true);
        if (!card) return;
        resetFamilyMemberCard(card, cards.length);
        familyMemberEditor.appendChild(card);
        cards = [...familyMemberEditor.querySelectorAll('[data-family-member-card]')];
    }

    cards.forEach((card, index) => {
        const active = index < requestedCount;
        card.hidden = !active;
        card.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !active;
        });
    });

    enforceSingleSelfRelationship();
}

if (familySizeInput && familyMemberEditor) {
    familySizeInput.addEventListener('input', syncFamilyMemberCards);
    familySizeInput.addEventListener('change', syncFamilyMemberCards);
    familyMemberEditor.addEventListener('change', (event) => {
        if (event.target.matches('select[name="member_relationship[]"]')) {
            enforceSingleSelfRelationship(event.target);
        }
    });
    syncFamilyMemberCards();
}

function showLoadingOverlay(title, message) {
    if (document.querySelector('.loading-overlay')) return;

    document.body.classList.add('is-loading');
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.innerHTML = `
        <div class="loading-card">
            <strong>${title}</strong>
            <span>${message}</span>
            <div class="loading-track"><i></i></div>
        </div>
    `;
    document.body.appendChild(overlay);
}

const assistantForm = document.querySelector('[data-assistant-form]');
const assistantLog = document.querySelector('[data-assistant-log]');
const assistantDataElement = document.querySelector('#report-assistant-data');
let reportAssistantData = null;

if (assistantDataElement) {
    try {
        reportAssistantData = JSON.parse(assistantDataElement.textContent || '{}');
    } catch (error) {
        reportAssistantData = null;
    }
}

function assistantList(values, fallback = 'none') {
    const filtered = (values || []).filter(Boolean);
    if (!filtered.length) return fallback;
    return filtered.slice(0, 4).join(', ');
}

function weakestComponents(data) {
    return Object.entries(data?.breakdown || {})
        .map(([name, value]) => ({ name, value: Number(value) }))
        .sort((a, b) => a.value - b.value)
        .slice(0, 3);
}

function buildReportAssistantAnswer(question, data) {
    if (!data) {
        return 'I cannot read the current report data on this page yet.';
    }

    const query = question.toLowerCase();
    const weak = weakestComponents(data);
    const riskyRows = (data.risk_rows || []).filter((row) => ['High', 'Moderate'].includes(row.level));
    const riskyItems = (data.items || [])
        .filter((item) => /high|processed|sodium|sugar/i.test(item.risk || ''))
        .map((item) => `${item.name} (${item.risk}, qty ${item.quantity})`);
    const recommendations = (data.recommendations || []).filter(Boolean);

    if (query.includes('why') || query.includes('low') || query.includes('score')) {
        if (data.score_explanation?.summary) {
            const reasons = assistantList(data.score_explanation.reasons || [], 'no extra reasons stored');
            const priorities = assistantList((data.priority_alerts || []).map((alert) => `${alert.priority}: ${alert.title}`), 'no priority alerts');
            return `${data.score_explanation.summary} Main reasons: ${reasons}. Priority actions: ${priorities}.`;
        }

        const weakText = weak.map((item) => `${item.name} ${item.value}`).join(', ') || 'no weak components';
        const riskText = riskyRows.map((row) => `${row.label}: ${row.value} ${row.unit} (${row.level})`).join('; ') || 'no elevated nutrient rows';
        return `Your score is ${data.score} (${data.label}). The weakest score components are ${weakText}. Current nutrient flags are ${riskText}. Main risky items are ${assistantList(riskyItems)}. First recommendation: ${recommendations[0] || 'keep variety high and watch packaged snacks.'}`;
    }

    if (query.includes('alternative') || query.includes('replace') || query.includes('swap') || query.includes('coconut') || query.includes('soda')) {
        const swaps = (data.shopping_alternatives || []).map((item) => {
            const alternatives = assistantList(item.alternatives, 'no listed swap');
            return `${item.item}: ${alternatives}`;
        });
        return swaps.length
            ? `Suggested swaps from this report: ${swaps.slice(0, 4).join(' | ')}. Coconut water is best as unsweetened and in small portions because it still contains natural sugar.`
            : 'This report did not find risky items with stored swap suggestions.';
    }

    if (query.includes('pregnant') || query.includes('diabetic') || query.includes('diabetes') || query.includes('child') || query.includes('salt') || query.includes('note')) {
        const flags = (data.health_note_flags || []).map((flag) => `${flag.label}: ${flag.proof}`);
        return flags.length
            ? `Detected health-note proof: ${flags.join(' | ')}`
            : 'No smart health-note flags were detected for this report.';
    }

    if (query.includes('trend') || query.includes('week')) {
        const trend = (data.weekly_trend || []).map((point) => `${point.label}: ${point.score}`);
        return trend.length
            ? `Weekly score trend: ${trend.join(' | ')}`
            : 'There is not enough weekly history yet for a trend answer.';
    }

    return `This report score is ${data.score} (${data.label}). Ask about score, risky items, swaps, health notes, or weekly trend for a focused answer.`;
}

function addAssistantMessage(role, text) {
    if (!assistantLog) return;

    const message = document.createElement('div');
    message.className = `assistant-message ${role}`;
    const label = document.createElement('strong');
    label.textContent = role === 'user' ? 'You' : 'Assistant';
    const paragraph = document.createElement('p');
    paragraph.textContent = text;
    message.append(label, paragraph);
    assistantLog.appendChild(message);
    assistantLog.scrollTop = assistantLog.scrollHeight;
}

if (assistantForm && assistantLog) {
    assistantForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const input = assistantForm.querySelector('input[name="question"]');
        const question = input?.value.trim() || '';

        if (!question) return;

        addAssistantMessage('user', question);
        addAssistantMessage('bot', buildReportAssistantAnswer(question, reportAssistantData));
        input.value = '';
        input.focus();
    });
}

const catalogRules = document.querySelector('[data-catalog-rules]');

if (catalogRules) {
    const catalogFilter = catalogRules.querySelector('[data-catalog-filter]');
    const catalogCards = Array.from(catalogRules.querySelectorAll('[data-catalog-card]'));
    const catalogCount = catalogRules.querySelector('[data-catalog-count]');
    const catalogEmpty = catalogRules.querySelector('[data-catalog-empty]');

    const updateCatalogFilter = () => {
        const query = catalogFilter?.value.trim().toLowerCase() || '';
        let visibleCount = 0;

        catalogCards.forEach((card) => {
            const searchableText = card.dataset.catalogSearch || card.textContent.toLowerCase();
            const isVisible = query === '' || searchableText.includes(query);
            card.hidden = !isVisible;
            if (isVisible) visibleCount++;
        });

        if (catalogCount) {
            catalogCount.textContent = query
                ? `${visibleCount} of ${catalogCards.length} rules`
                : `${catalogCards.length} rules`;
        }

        if (catalogEmpty) {
            catalogEmpty.hidden = visibleCount !== 0;
        }
    };

    catalogFilter?.addEventListener('input', updateCatalogFilter);
    updateCatalogFilter();
}

const simulator = document.querySelector('#simulator');

function lowerIsBetter(value, target, highRisk) {
    if (value <= target) return 100;
    if (value >= highRisk) return 0;
    return ((highRisk - value) / (highRisk - target)) * 100;
}

function higherIsBetter(value, target) {
    return Math.min(100, (value / target) * 100);
}

function updateSimulator() {
    if (!simulator) return;

    const values = {};
    simulator.querySelectorAll('[data-sim]').forEach((input) => {
        values[input.dataset.sim] = Number(input.value);
        const output = simulator.querySelector(`[data-out="${input.dataset.sim}"]`);
        if (output) output.textContent = input.value;
    });

    const weights = { sugar: 35, fat: 20, sodium: 25, fiber: 10, diversity: 10 };
    if (simulator.querySelector('[data-condition="diabetes"]')?.checked) weights.sugar += 10;
    if (simulator.querySelector('[data-condition="hypertension"]')?.checked) weights.sodium += 10;
    if (simulator.querySelector('[data-condition="cholesterol"]')?.checked) weights.fat += 8;
    if (simulator.querySelector('[data-condition="children"]')?.checked) weights.sugar += 5;
    if (simulator.querySelector('[data-condition="elderly"]')?.checked) {
        weights.sodium += 5;
        weights.fat += 4;
    }

    const components = {
        sugar: lowerIsBetter(values.sugar, 25, 70),
        fat: lowerIsBetter(values.fat, 10, 25),
        sodium: lowerIsBetter(values.sodium, 700, 2000),
        fiber: higherIsBetter(values.fiber, 10),
        diversity: higherIsBetter(values.diversity, 6),
    };

    const totalWeight = Object.values(weights).reduce((sum, value) => sum + value, 0);
    const score = Object.keys(components).reduce((sum, key) => sum + components[key] * weights[key], 0) / totalWeight;
    const roundedScore = Math.max(0, Math.min(100, score)).toFixed(1);
    const label = roundedScore >= 80 ? 'Strong' : roundedScore >= 65 ? 'Moderate' : roundedScore >= 45 ? 'Needs attention' : 'High risk';

    const scoreElement = simulator.querySelector('[data-sim-score]');
    scoreElement.textContent = roundedScore;
    scoreElement.style.color = Number(roundedScore) >= 80
        ? 'var(--ring-good)'
        : Number(roundedScore) >= 65
            ? 'var(--ring-mid)'
            : Number(roundedScore) >= 45
                ? 'var(--ring-watch)'
                : 'var(--ring-risk)';
    simulator.querySelector('[data-sim-label]').textContent = label;

    if (!prefersReducedMotion()) {
        scoreElement.classList.remove('score-bump');
        requestAnimationFrame(() => scoreElement.classList.add('score-bump'));
    }

    Object.entries(components).forEach(([key, value]) => {
        const rounded = Math.max(0, Math.min(100, value)).toFixed(1);
        simulator.querySelector(`[data-component="${key}"]`).textContent = rounded;
        simulator.querySelector(`[data-bar="${key}"]`).style.width = `${rounded}%`;
    });
}

if (simulator) {
    simulator.querySelectorAll('input').forEach((input) => input.addEventListener('input', updateSimulator));
    updateSimulator();
}

const navToggle = document.querySelector('.nav-toggle');
const mainNav = document.querySelector('#main-nav');

if (navToggle && mainNav) {
    navToggle.addEventListener('click', () => {
        const isOpen = mainNav.classList.toggle('open');
        navToggle.setAttribute('aria-expanded', String(isOpen));
    });

    mainNav.addEventListener('click', (event) => {
        if (event.target.closest('a') && mainNav.classList.contains('open')) {
            mainNav.classList.remove('open');
            navToggle.setAttribute('aria-expanded', 'false');
        }
    });
}

const themeToggles = document.querySelectorAll('[data-theme-toggle]');
const savedTheme = localStorage.getItem('r2h-theme');

function applyTheme(theme) {
    root.dataset.theme = theme;

    const isDark = theme === 'dark';
    themeToggles.forEach((themeToggle) => {
        themeToggle.textContent = isDark ? 'Light' : 'Dark';
        themeToggle.setAttribute('aria-label', `Switch to ${isDark ? 'light' : 'dark'} mode`);
        themeToggle.setAttribute('aria-pressed', String(isDark));
        themeToggle.title = `Switch to ${isDark ? 'light' : 'dark'} mode`;
    });
}

applyTheme(savedTheme || 'light');

themeToggles.forEach((themeToggle) => {
    themeToggle.addEventListener('click', () => {
        const current = root.dataset.theme === 'dark' ? 'light' : 'dark';
        applyTheme(current);
        localStorage.setItem('r2h-theme', current);
    });
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('.quick-menu[open], .nav-more[open]').forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    document.querySelectorAll('.quick-menu[open], .nav-more[open]').forEach((menu) => {
        menu.removeAttribute('open');
    });

    if (mainNav?.classList.contains('open')) {
        mainNav.classList.remove('open');
        navToggle?.setAttribute('aria-expanded', 'false');
    }
});

const revealTargets = document.querySelectorAll(
    '.panel, .metric, .pipeline div, .module-list div, .weight-list div, .risk-cards div, .method-steps div, .graph-column'
);

revealTargets.forEach((element, index) => {
    const delay = Math.min(index * 38, 420);
    element.style.animationDelay = `${delay}ms`;
    element.style.setProperty('--reveal-delay', `${delay}ms`);
});

if (!prefersReducedMotion() && 'IntersectionObserver' in window) {
    const isInViewport = (element) => {
        const rect = element.getBoundingClientRect();
        return rect.top < window.innerHeight && rect.bottom > 0;
    };

    revealTargets.forEach((element) => {
        element.classList.add('reveal-pending');
        if (isInViewport(element)) {
            element.classList.add('is-visible');
        }
    });

    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            revealObserver.unobserve(entry.target);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealTargets.forEach((element) => {
        if (!element.classList.contains('is-visible')) {
            revealObserver.observe(element);
        }
    });

    window.setTimeout(() => {
        revealTargets.forEach((element) => element.classList.add('is-visible'));
    }, 900);
} else {
    revealTargets.forEach((element) => element.classList.add('is-visible'));
}

function updateScrollProgress() {
    const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
    const progress = maxScroll > 0 ? window.scrollY / maxScroll : 0;
    root.style.setProperty('--scroll-progress', String(Math.max(0, Math.min(1, progress))));
}

updateScrollProgress();
window.addEventListener('scroll', updateScrollProgress, { passive: true });
window.addEventListener('resize', updateScrollProgress);

if (window.matchMedia('(pointer: fine)').matches) {
    document.querySelectorAll(
        '.panel, .metric, .module-list div, .weight-list div, .risk-cards div, .method-steps div, .category-grid div, .pipeline div'
    ).forEach((surface) => {
        surface.addEventListener('pointermove', (event) => {
            const rect = surface.getBoundingClientRect();
            surface.style.setProperty('--spotlight-x', `${event.clientX - rect.left}px`);
            surface.style.setProperty('--spotlight-y', `${event.clientY - rect.top}px`);
        });
    });
}

function animateNumber(element) {
    const raw = element.textContent.trim();
    if (!/^-?\d+(\.\d+)?$/.test(raw) || prefersReducedMotion()) return;

    const target = Number(raw);
    const decimals = raw.includes('.') ? raw.split('.')[1].length : 0;
    const formatter = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
    const duration = 850;
    const startTime = performance.now();

    function tick(now) {
        const elapsed = Math.min(1, (now - startTime) / duration);
        const eased = 1 - Math.pow(1 - elapsed, 3);
        element.textContent = formatter.format(target * eased);

        if (elapsed < 1) {
            requestAnimationFrame(tick);
        } else {
            element.textContent = raw;
        }
    }

    requestAnimationFrame(tick);
}

const numericMetrics = document.querySelectorAll('.metric strong, .report-score strong');

if ('IntersectionObserver' in window && !prefersReducedMotion()) {
    const numberObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            animateNumber(entry.target);
            numberObserver.unobserve(entry.target);
        });
    }, { threshold: 0.35 });

    numericMetrics.forEach((element) => numberObserver.observe(element));
} else {
    numericMetrics.forEach(animateNumber);
}
