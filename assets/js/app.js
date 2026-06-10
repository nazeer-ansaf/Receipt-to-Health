const root = document.documentElement;
const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
const prefersReducedMotion = () => reducedMotionQuery.matches;
root.classList.add('js-ready');

document.querySelectorAll('.upload-box input[type="file"]').forEach((fileInput) => {
    const uploadBox = fileInput.closest('.upload-box');

    if (!uploadBox) return;

    const emptyLabel = uploadBox.dataset.emptyLabel || 'No file selected';
    const fileName = document.createElement('small');
    fileName.className = 'file-name-pill';
    fileName.textContent = emptyLabel;
    fileName.setAttribute('aria-live', 'polite');
    fileInput.insertAdjacentElement('afterend', fileName);

    const syncFileState = () => {
        fileName.textContent = fileInput.files?.[0]?.name || emptyLabel;
        uploadBox.classList.toggle('has-file', Boolean(fileInput.files?.length));
    };

    fileInput.addEventListener('change', syncFileState);

    ['dragenter', 'dragover'].forEach((eventName) => {
        uploadBox.addEventListener(eventName, (event) => {
            event.preventDefault();
            uploadBox.classList.add('is-dragging');
        });
    });

    uploadBox.addEventListener('dragleave', () => uploadBox.classList.remove('is-dragging'));

    uploadBox.addEventListener('drop', (event) => {
        event.preventDefault();
        uploadBox.classList.remove('is-dragging');
        if (event.dataTransfer?.files?.length) {
            fileInput.files = event.dataTransfer.files;
            syncFileState();
        }
    });
});

const form = document.querySelector('#receipt-form');

if (form) {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Analyzing...';
        }
        showLoadingOverlay('Analyzing receipt', 'OCR, NLP, scoring, anomaly detection, and recommendations are running.');
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
            <td><input type="text" name="item_name[]" placeholder="food item" required></td>
            <td><input type="number" name="quantity[]" min="0" step="0.1" value="1" required></td>
            <td class="muted">manual</td>
            <td><span class="risk-badge risk-low">new</span></td>
            <td class="proof-cell">Added during review</td>
            <td><button class="mini-icon-button" type="button" data-remove-item-row title="Remove row">x</button></td>
        `;
        itemEditorBody.appendChild(row);
        attachItemRowEvents(row);
        row.querySelector('input')?.focus();
    });
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
