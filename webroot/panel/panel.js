/**
 * GameScore Panel — Dashboard JS
 *
 * Responsibilities:
 *  • Fetch GET /leaderboard on load and on interval
 *  • Render leaderboard table with rank badges, avatars, score deltas
 *  • Track previous scores to show per-row change indicators
 *  • Auto-refresh with countdown + pause/resume button
 *  • Report Match modal: form, validation, POST /matches/report, response display
 *  • Health check widget (GET /health)
 *  • Toast notification system
 *  • Pagination (client-side offset, server limit)
 */

'use strict';

(function () {

    // ── Configuration ───────────────────────────────────────────
    const cfg = window.PANEL_CONFIG || {};
    const API = 'http://localhost:8000';
    const REFRESH_MS = cfg.refreshInterval || 10_000;

    // ── State ────────────────────────────────────────────────────
    const state = {
        autoRefresh:   true,
        refreshTimer:  null,
        countdownTimer: null,
        countdownSec:  REFRESH_MS / 1000,
        limit:         10,
        offset:        0,
        totalCount:    null,      // null = unknown
        prevScores:    new Map(), // user_id → score (from last render)
        loading:       false,
        lastData:      [],
    };

    // ── DOM refs ─────────────────────────────────────────────────
    const $ = id => document.getElementById(id);
    const dom = {
        body:           $('leaderboardBody'),
        limitSelect:    $('limitSelect'),
        prevPageBtn:    $('prevPageBtn'),
        nextPageBtn:    $('nextPageBtn'),
        pageIndicator:  $('pageIndicator'),
        paginationInfo: $('paginationInfo'),
        sourceBadge:    $('sourceBadge'),
        sourceDot:      $('sourceDot'),
        sourceLabel:    $('sourceLabel'),
        lastUpdated:    $('lastUpdated'),
        autoRefreshBtn: $('autoRefreshBtn'),
        autoRefreshIcon:$('autoRefreshIcon'),
        autoRefreshLabel:$('autoRefreshLabel'),
        manualRefreshBtn:$('manualRefreshBtn'),
        openModalBtn:   $('openModalBtn'),
        statTotal:      $('statTotal'),
        statTop:        $('statTop'),
        statAvg:        $('statAvg'),
        statRefreshIn:  $('statRefreshIn'),
        // Health
        healthDot:      $('healthDot'),
        healthLabel:    $('healthLabel'),
        // Toast
        toastContainer: $('toastContainer'),
        // Modal
        backdrop:       $('modalBackdrop'),
        closeModalBtn:  $('closeModalBtn'),
        cancelModalBtn: $('cancelModalBtn'),
        submitReportBtn:$('submitReportBtn'),
        submitLabel:    $('submitLabel'),
        reportForm:     $('reportForm'),
        modalResponse:  $('modalResponse'),
        // Form fields
        fieldUserId:    $('fieldUserId'),
        fieldMatchId:   $('fieldMatchId'),
        fieldResult:    $('fieldResult'),
        fieldScoreDelta:$('fieldScoreDelta'),
        fieldRequestId: $('fieldRequestId'),
        regenRequestId: $('regenRequestId'),
        resultPicker:   $('resultPicker'),
        // Field errors
        errorUserId:    $('errorUserId'),
        errorMatchId:   $('errorMatchId'),
        errorResult:    $('errorResult'),
        errorScoreDelta:$('errorScoreDelta'),
    };

    // ── Avatar colour palette ─────────────────────────────────────
    const AVATAR_COLOURS = [
        '#6366f1','#8b5cf6','#ec4899','#f43f5e',
        '#f59e0b','#10b981','#06b6d4','#3b82f6',
    ];

    function avatarColour(name) {
        let h = 0;
        for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        return AVATAR_COLOURS[h % AVATAR_COLOURS.length];
    }

    function avatarInitial(name) {
        return (name || '?').charAt(0).toUpperCase();
    }

    // ── UUID v4 generator ─────────────────────────────────────────
    function uuidv4() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // ── Leaderboard fetch ─────────────────────────────────────────

    /**
     * Fetch the leaderboard from GET /leaderboard and render it.
     *
     * @param {boolean} showSpinner  Show loading spinner in the table body.
     */
    async function fetchLeaderboard(showSpinner = false) {
        if (state.loading) return;
        state.loading = true;

        if (showSpinner) renderLoading();

        const url = `${API}/leaderboard?limit=${state.limit}&offset=${state.offset}`;

        try {
            const res  = await fetch(url, { headers: { Accept: 'application/json' } });
            const json = await res.json();

            if (!json.success) {
                throw new Error(json.error || 'API returned success=false');
            }

            state.lastData  = json.data || [];
            renderTable(state.lastData);
            updateMeta(json);

        } catch (err) {
            renderError(err.message);
            toast('Leaderboard error', err.message, 'error');
        } finally {
            state.loading = false;
        }
    }

    // ── Rendering ─────────────────────────────────────────────────

    function renderLoading() {
        dom.body.innerHTML = `
            <tr class="skeleton-row">
                <td colspan="4">
                    <div class="loading-state">
                        <div class="spinner"></div>
                        <span>Loading leaderboard…</span>
                    </div>
                </td>
            </tr>`;
    }

    function renderError(msg) {
        dom.body.innerHTML = `
            <tr>
                <td colspan="4">
                    <div class="empty-state">
                        <div class="empty-state-icon">⚠️</div>
                        <div>${escHtml(msg)}</div>
                    </div>
                </td>
            </tr>`;
    }

    function renderTable(entries) {
        if (!entries.length) {
            dom.body.innerHTML = `
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <div class="empty-state-icon">🏆</div>
                            <div>No players yet. Report a match to get started.</div>
                        </div>
                    </td>
                </tr>`;
            return;
        }

        // Build new score map for delta calculation
        const newScores = new Map(entries.map(e => [e.user_id, e.score]));

        const rows = entries.map((entry, i) => {
            const prevScore = state.prevScores.get(entry.user_id);
            const delta     = prevScore !== undefined ? entry.score - prevScore : null;
            const isNew     = prevScore === undefined;

            const rankClass = i < 3 ? `rank-${i + 1}` : '';
            const colour    = avatarColour(entry.name);
            const initial   = avatarInitial(entry.name);

            const changeHtml = buildChangeBadge(delta, isNew);
            const delay      = (i * 35).toFixed(0);

            return `
                <tr data-user="${entry.user_id}" style="animation-delay: ${delay}ms">
                    <td class="rank-cell">
                        <span class="rank-badge ${rankClass}">${entry.rank}</span>
                    </td>
                    <td>
                        <div class="player-cell">
                            <div class="player-avatar" style="background: ${colour}">
                                ${initial}
                            </div>
                            <div>
                                <div class="player-name">${escHtml(entry.name)}</div>
                                <div class="player-id">uid ${entry.user_id}</div>
                            </div>
                        </div>
                    </td>
                    <td class="score-cell" data-score="${entry.score}">
                        ${entry.score.toLocaleString()}
                    </td>
                    <td class="change-cell">${changeHtml}</td>
                </tr>`;
        }).join('');

        dom.body.innerHTML = rows;

        // Flash scores that changed
        dom.body.querySelectorAll('tr[data-user]').forEach(row => {
            const uid   = parseInt(row.dataset.user, 10);
            const prev  = state.prevScores.get(uid);
            const score = parseInt(row.querySelector('.score-cell').dataset.score, 10);
            if (prev !== undefined && prev !== score) {
                row.querySelector('.score-cell').classList.add('score-changed');
            }
        });

        // Save scores for next diff
        state.prevScores = newScores;
    }

    function buildChangeBadge(delta, isNew) {
        if (isNew)           return `<span class="change-new">● New</span>`;
        if (delta === null)  return `<span class="change-same">—</span>`;
        if (delta > 0)       return `<span class="change-up">▲ +${delta.toLocaleString()}</span>`;
        if (delta < 0)       return `<span class="change-down">▼ ${delta.toLocaleString()}</span>`;
        return                      `<span class="change-same">— 0</span>`;
    }

    // ── Meta / stats update ───────────────────────────────────────

    function updateMeta(json) {
        const entries = json.data || [];
        const source  = json.source || '—';
        const meta    = json.meta   || {};

        // Source badge
        dom.sourceDot.className   = 'source-dot ' + source;
        dom.sourceLabel.textContent = source.toUpperCase();

        // Last updated
        const now = new Date();
        dom.lastUpdated.textContent = 'Updated ' + now.toLocaleTimeString();

        // Stats
        if (entries.length) {
            const scores = entries.map(e => e.score);
            const avg    = Math.round(scores.reduce((a, b) => a + b, 0) / scores.length);
            dom.statTop.textContent   = scores[0].toLocaleString();
            dom.statAvg.textContent   = avg.toLocaleString();
        }

        // Total count from meta (if provided) or estimate
        const total = meta.count !== undefined ? meta.count : entries.length;
        dom.statTotal.textContent = total > 0 ? total : '—';

        // Pagination
        const page        = Math.floor(state.offset / state.limit) + 1;
        const start       = state.offset + 1;
        const end         = state.offset + entries.length;
        dom.pageIndicator.textContent  = `Page ${page}`;
        dom.paginationInfo.textContent = entries.length
            ? `Showing ${start}–${end}`
            : 'No results';

        dom.prevPageBtn.disabled = state.offset === 0;
        dom.nextPageBtn.disabled = entries.length < state.limit;
    }

    // ── Auto-refresh ──────────────────────────────────────────────

    function startAutoRefresh() {
        stopAutoRefresh();
        state.countdownSec = REFRESH_MS / 1000;

        state.refreshTimer = setInterval(() => {
            fetchLeaderboard();
            state.countdownSec = REFRESH_MS / 1000;
        }, REFRESH_MS);

        state.countdownTimer = setInterval(() => {
            state.countdownSec = Math.max(0, state.countdownSec - 1);
            dom.statRefreshIn.textContent = state.countdownSec;
        }, 1000);
    }

    function stopAutoRefresh() {
        clearInterval(state.refreshTimer);
        clearInterval(state.countdownTimer);
        state.refreshTimer  = null;
        state.countdownTimer = null;
    }

    function toggleAutoRefresh() {
        state.autoRefresh = !state.autoRefresh;

        if (state.autoRefresh) {
            startAutoRefresh();
            dom.autoRefreshLabel.textContent = 'Pause';
            dom.autoRefreshIcon.style.opacity = '1';
            dom.statRefreshIn.textContent = REFRESH_MS / 1000;
            toast('Auto-refresh resumed', 'Leaderboard will update every 10 s.', 'info');
        } else {
            stopAutoRefresh();
            dom.autoRefreshLabel.textContent = 'Resume';
            dom.autoRefreshIcon.style.opacity = '.4';
            dom.statRefreshIn.textContent = '—';
            toast('Auto-refresh paused', 'Click Resume to restart automatic updates.', 'warn');
        }
    }

    // ── Health check ──────────────────────────────────────────────

    async function checkHealth() {
        try {
            const res  = await fetch(`${API}/health`, { headers: { Accept: 'application/json' } });
            const json = await res.json();

            const ok = json.status === 'ok';
            dom.healthDot.className   = 'health-dot ' + (ok ? 'ok' : 'degraded');
            dom.healthLabel.textContent = ok ? 'All systems OK'
                : `DB: ${json.database} · Redis: ${json.redis}`;
        } catch {
            dom.healthDot.className     = 'health-dot down';
            dom.healthLabel.textContent = 'API unreachable';
        }
    }

    // ── Modal ─────────────────────────────────────────────────────

    function openModal() {
        resetModalForm();
        dom.fieldRequestId.value = uuidv4();
        dom.backdrop.classList.add('open');
        setTimeout(() => dom.fieldUserId.focus(), 100);
    }

    function closeModal() {
        dom.backdrop.classList.remove('open');
    }

    function resetModalForm() {
        dom.reportForm.reset();
        dom.fieldResult.value = '';
        dom.modalResponse.hidden = true;
        dom.modalResponse.className = 'modal-response';
        dom.modalResponse.textContent = '';

        // Clear result picker selection
        dom.resultPicker.querySelectorAll('.result-option').forEach(btn => {
            btn.classList.remove('selected');
        });

        // Clear errors
        clearFieldError(dom.fieldUserId,     dom.errorUserId);
        clearFieldError(dom.fieldMatchId,    dom.errorMatchId);
        clearFieldError(null,                dom.errorResult);
        clearFieldError(dom.fieldScoreDelta, dom.errorScoreDelta);

        dom.submitLabel.textContent = 'Submit Report';
        dom.submitReportBtn.disabled = false;
    }

    function setFieldError(inputEl, errorEl, msg) {
        if (inputEl) inputEl.classList.add('has-error');
        errorEl.textContent = msg;
    }

    function clearFieldError(inputEl, errorEl) {
        if (inputEl) inputEl.classList.remove('has-error');
        errorEl.textContent = '';
    }

    /** Client-side form validation before hitting the API. */
    function validateForm() {
        let valid = true;

        clearFieldError(dom.fieldUserId,     dom.errorUserId);
        clearFieldError(dom.fieldMatchId,    dom.errorMatchId);
        clearFieldError(null,                dom.errorResult);
        clearFieldError(dom.fieldScoreDelta, dom.errorScoreDelta);

        const uid = parseInt(dom.fieldUserId.value, 10);
        if (!dom.fieldUserId.value || uid <= 0 || isNaN(uid)) {
            setFieldError(dom.fieldUserId, dom.errorUserId, 'Enter a valid user ID (positive integer).');
            valid = false;
        }

        const mid = dom.fieldMatchId.value.trim();
        if (!mid) {
            setFieldError(dom.fieldMatchId, dom.errorMatchId, 'Match ID is required.');
            valid = false;
        }

        if (!dom.fieldResult.value) {
            setFieldError(null, dom.errorResult, 'Select a match result.');
            valid = false;
        }

        const delta = parseInt(dom.fieldScoreDelta.value, 10);
        if (dom.fieldScoreDelta.value === '' || isNaN(delta)) {
            setFieldError(dom.fieldScoreDelta, dom.errorScoreDelta, 'Enter an integer score delta.');
            valid = false;
        }

        return valid;
    }

    /**
     * Submit the match report to POST /matches/report.
     * On success: refresh leaderboard, show inline response.
     */
    async function submitReport() {
        if (!validateForm()) return;

        dom.submitLabel.textContent  = 'Submitting…';
        dom.submitReportBtn.disabled = true;
        dom.modalResponse.hidden     = true;

        const payload = {
            request_id:  dom.fieldRequestId.value.trim(),
            user_id:     parseInt(dom.fieldUserId.value, 10),
            match_id:    parseInt(dom.fieldMatchId.value, 10),
            result:      dom.fieldResult.value,
            score_delta: parseInt(dom.fieldScoreDelta.value, 10),
            reported_at: Math.floor(Date.now() / 1000),
        };

        try {
            const res  = await fetch(`${API}/matches/report`, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                },
                body: JSON.stringify(payload),
            });

            const json = await res.json();
            showModalResponse(res.status, json);

            if (json.success) {
                // Refresh leaderboard immediately to show new score
                await fetchLeaderboard();

                toast(
                    json.duplicate ? 'Duplicate Request' : 'Match Reported',
                    json.duplicate
                        ? `Already recorded. Player ${json.user_id} score: ${json.new_score}`
                        : `Player ${json.user_id} new score: ${json.new_score}`,
                    json.duplicate ? 'info' : 'success',
                );

                // Regenerate request_id for next submission
                dom.fieldRequestId.value = uuidv4();
                dom.submitLabel.textContent  = 'Submit Report';
                dom.submitReportBtn.disabled = false;
            } else {
                dom.submitLabel.textContent  = 'Submit Report';
                dom.submitReportBtn.disabled = false;

                // Show specific field errors from API
                if (json.errors) {
                    if (json.errors.user_id)     setFieldError(dom.fieldUserId,     dom.errorUserId,     json.errors.user_id);
                    if (json.errors.match_id)    setFieldError(dom.fieldMatchId,    dom.errorMatchId,    json.errors.match_id);
                    if (json.errors.result)      setFieldError(null,                dom.errorResult,     json.errors.result);
                    if (json.errors.score_delta) setFieldError(dom.fieldScoreDelta, dom.errorScoreDelta, json.errors.score_delta);
                }
            }

        } catch (err) {
            dom.submitLabel.textContent  = 'Submit Report';
            dom.submitReportBtn.disabled = false;
            showModalResponse(0, { success: false, error: 'NETWORK_ERROR', message: err.message });
            toast('Network error', err.message, 'error');
        }
    }

    /**
     * Render the inline API response inside the modal.
     *
     * @param {number} status   HTTP status code.
     * @param {object} json     Parsed response body.
     */
    function showModalResponse(status, json) {
        const el = dom.modalResponse;

        let cls, lines;

        if (json.success && !json.duplicate) {
            cls   = 'success';
            lines = [
                `✓ Match recorded successfully`,
                `  user_id   : ${json.user_id}`,
                `  match_id  : ${json.match_id}`,
                `  new_score : ${json.new_score}`,
                `  duplicate : false`,
            ];
        } else if (json.success && json.duplicate) {
            cls   = 'duplicate';
            lines = [
                `↩ Duplicate request (same payload)`,
                `  user_id   : ${json.user_id}`,
                `  new_score : ${json.new_score}`,
                `  duplicate : true`,
            ];
        } else if (status === 429) {
            cls   = 'error';
            lines = [
                `✗ Rate limit exceeded`,
                `  error      : ${json.error}`,
                `  retry_after: ${json.retry_after}s`,
            ];
        } else if (status === 409) {
            cls   = 'error';
            lines = [
                `✗ Request ID conflict`,
                `  error : REQUEST_ID_CONFLICT`,
                `  The same request_id was used with a different payload.`,
            ];
        } else {
            cls   = 'error';
            const errLines = [`✗ ${json.error || 'Error'}`];
            if (json.message) errLines.push(`  ${json.message}`);
            if (json.errors) {
                Object.entries(json.errors).forEach(([k, v]) => errLines.push(`  ${k}: ${v}`));
            }
            lines = errLines;
        }

        el.className = `modal-response ${cls}`;
        el.textContent = lines.join('\n');
        el.hidden = false;
    }

    // ── Toast system ──────────────────────────────────────────────

    const TOAST_ICONS = {
        success: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
        error:   `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`,
        info:    `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`,
        warn:    `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    };

    /**
     * Show a toast notification.
     *
     * @param {string} title
     * @param {string} message
     * @param {'success'|'error'|'info'|'warn'} type
     * @param {number} duration  Auto-dismiss after N ms (0 = persist).
     */
    function toast(title, message, type = 'info', duration = 4000) {
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;

        el.innerHTML = `
            <div class="toast-icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</div>
            <div class="toast-body">
                <div class="toast-title">${escHtml(title)}</div>
                <div class="toast-msg">${escHtml(message)}</div>
            </div>
            <button class="toast-close" aria-label="Dismiss">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>`;

        dom.toastContainer.appendChild(el);

        // Trigger slide-in
        requestAnimationFrame(() => {
            requestAnimationFrame(() => el.classList.add('show'));
        });

        const dismiss = () => {
            el.classList.add('hide');
            el.classList.remove('show');
            setTimeout(() => el.remove(), 400);
        };

        el.querySelector('.toast-close').addEventListener('click', dismiss);

        if (duration > 0) {
            setTimeout(dismiss, duration);
        }
    }

    // ── Pagination ────────────────────────────────────────────────

    function goToPrevPage() {
        if (state.offset === 0) return;
        state.offset = Math.max(0, state.offset - state.limit);
        fetchLeaderboard(true);
    }

    function goToNextPage() {
        state.offset += state.limit;
        fetchLeaderboard(true);
    }

    // ── Helpers ───────────────────────────────────────────────────

    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Event wiring ──────────────────────────────────────────────

    function bindEvents() {
        // Auto-refresh toggle
        dom.autoRefreshBtn.addEventListener('click', toggleAutoRefresh);

        // Manual refresh
        dom.manualRefreshBtn.addEventListener('click', () => fetchLeaderboard(false));

        // Limit select
        dom.limitSelect.addEventListener('change', () => {
            state.limit  = parseInt(dom.limitSelect.value, 10);
            state.offset = 0;
            fetchLeaderboard(true);
        });

        // Pagination
        dom.prevPageBtn.addEventListener('click', goToPrevPage);
        dom.nextPageBtn.addEventListener('click', goToNextPage);

        // Open / close modal
        dom.openModalBtn.addEventListener('click', openModal);
        dom.closeModalBtn.addEventListener('click', closeModal);
        dom.cancelModalBtn.addEventListener('click', closeModal);

        // Close modal on backdrop click (outside the modal card)
        dom.backdrop.addEventListener('click', e => {
            if (e.target === dom.backdrop) closeModal();
        });

        // Close modal on Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && dom.backdrop.classList.contains('open')) closeModal();
        });

        // Submit report
        dom.submitReportBtn.addEventListener('click', submitReport);

        // Regenerate request_id
        dom.regenRequestId.addEventListener('click', () => {
            dom.fieldRequestId.value = uuidv4();
        });

        // Result picker buttons
        dom.resultPicker.querySelectorAll('.result-option').forEach(btn => {
            btn.addEventListener('click', () => {
                dom.resultPicker.querySelectorAll('.result-option').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                dom.fieldResult.value = btn.dataset.value;
                clearFieldError(null, dom.errorResult);
            });
        });

        // Auto-suggest score delta when result changes
        dom.resultPicker.querySelectorAll('.result-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const suggestions = { win: 25, draw: 5, lose: -15 };
                const suggested   = suggestions[btn.dataset.value];
                if (suggested !== undefined && dom.fieldScoreDelta.value === '') {
                    dom.fieldScoreDelta.value = suggested;
                }
            });
        });

        // Clear field errors on input
        dom.fieldUserId.addEventListener('input',     () => clearFieldError(dom.fieldUserId,     dom.errorUserId));
        dom.fieldMatchId.addEventListener('input',    () => clearFieldError(dom.fieldMatchId,    dom.errorMatchId));
        dom.fieldScoreDelta.addEventListener('input', () => clearFieldError(dom.fieldScoreDelta, dom.errorScoreDelta));
    }

    // ── Init ──────────────────────────────────────────────────────

    function init() {
        bindEvents();

        // Initial load
        fetchLeaderboard(true);

        // Start auto-refresh
        startAutoRefresh();

        // Health check — once on load, then every 30 s
        checkHealth();
        setInterval(checkHealth, 30_000);
    }

    // Run after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
