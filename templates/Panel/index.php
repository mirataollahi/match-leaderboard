<?php
/**
 * Panel — Leaderboard Dashboard View
 *
 * Renders the full leaderboard UI shell.
 * All data is loaded client-side via XHR → /leaderboard and /matches/report.
 *
 * @var \App\View\AppView $this
 * @var string $apiBase   Base URL for API calls (e.g. "http://localhost:8000")
 * @var string $title
 */
$this->setLayout('panel');
$this->assign('title', $title ?? 'Game Leaderboard');
?>

<!-- ── Page header ──────────────────────────────────────────── -->
<header class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Leaderboard</h1>
        <p class="page-subtitle">Live player rankings • auto-refreshes every 10 s</p>
    </div>

    <div class="page-header-right">
        <!-- Source badge -->
        <div class="source-badge" id="sourceBadge" title="Data source">
            <span class="source-dot" id="sourceDot"></span>
            <span id="sourceLabel">—</span>
        </div>

        <!-- Last-updated timestamp -->
        <div class="last-updated" id="lastUpdated">—</div>

        <!-- Auto-refresh toggle -->
        <button class="btn btn-ghost" id="autoRefreshBtn" title="Toggle auto-refresh">
            <svg id="autoRefreshIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            <span id="autoRefreshLabel">Pause</span>
        </button>

        <!-- Report match button -->
        <button class="btn btn-primary" id="openModalBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Report Match
        </button>
    </div>
</header>

<!-- ── Stats bar ────────────────────────────────────────────── -->
<div class="stats-bar">
    <div class="stat-card">
        <span class="stat-value" id="statTotal">—</span>
        <span class="stat-label">Players</span>
    </div>
    <div class="stat-card">
        <span class="stat-value" id="statTop">—</span>
        <span class="stat-label">Top Score</span>
    </div>
    <div class="stat-card">
        <span class="stat-value" id="statAvg">—</span>
        <span class="stat-label">Top Score today</span>
    </div>
    <div class="stat-card">
        <span class="stat-value" id="statRefreshIn">10</span>
        <span class="stat-label">Next Refresh</span>
    </div>
</div>

<!-- ── Leaderboard table ─────────────────────────────────────── -->
<div class="table-card">
    <!-- Table toolbar -->
    <div class="table-toolbar">
        <div class="toolbar-left">
            <label class="toolbar-label" for="limitSelect">Show</label>
            <select class="select-input" id="limitSelect">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <span class="toolbar-label">players</span>
        </div>
        <div class="toolbar-right">
            <button class="btn btn-ghost btn-sm" id="manualRefreshBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                Refresh now
            </button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <table class="leaderboard-table" id="leaderboardTable">
            <thead>
            <tr>
                <th class="col-rank">Rank</th>
                <th class="col-player">Player</th>
                <th class="col-score">Score</th>
                <th class="col-change">Change</th>
            </tr>
            </thead>
            <tbody id="leaderboardBody">
            <!-- Populated by JS -->
            <tr class="skeleton-row">
                <td colspan="4">
                    <div class="loading-state">
                        <div class="spinner"></div>
                        <span>Loading leaderboard…</span>
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="table-footer">
        <span class="pagination-info" id="paginationInfo">—</span>
        <div class="pagination-controls">
            <button class="btn btn-ghost btn-sm" id="prevPageBtn" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Prev
            </button>
            <span class="page-indicator" id="pageIndicator">1</span>
            <button class="btn btn-ghost btn-sm" id="nextPageBtn">
                Next
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- ── Toast container ──────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ── Report Match Modal ────────────────────────────────────── -->
<div class="modal-backdrop" id="modalBackdrop">
    <div class="modal" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">

        <div class="modal-header">
            <div class="modal-title-group">
                <h2 class="modal-title" id="modalTitle">Report Match Result</h2>
                <p class="modal-subtitle">Submit a match outcome to update the leaderboard</p>
            </div>
            <button class="modal-close" id="closeModalBtn" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <form class="modal-form" id="reportForm" novalidate>

            <!-- Row 1: User ID + Match ID -->
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label" for="fieldUserId">
                        User ID
                        <span class="required-star">*</span>
                    </label>
                    <input
                        class="form-input"
                        type="number"
                        id="fieldUserId"
                        name="user_id"
                        placeholder="e.g. 12"
                        min="1"
                        required
                    >
                    <span class="field-error" id="errorUserId"></span>
                </div>

                <div class="form-field">
                    <label class="form-label" for="fieldMatchId">
                        Match ID
                        <span class="required-star">*</span>
                    </label>
                    <input
                        class="form-input"
                        type="number"
                        id="fieldMatchId"
                        name="match_id"
                        placeholder="e.g. 8801"
                        min="1"
                        required
                    >
                    <span class="field-error" id="errorMatchId"></span>
                </div>
            </div>

            <!-- Row 2: Result + Score Delta -->
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label" for="fieldResult">
                        Result
                        <span class="required-star">*</span>
                    </label>
                    <div class="result-picker" id="resultPicker">
                        <button type="button" class="result-option result-win"  data-value="win">
                            <span class="result-icon">🏆</span>
                            <span class="result-label">Win</span>
                        </button>
                        <button type="button" class="result-option result-draw" data-value="draw">
                            <span class="result-icon">🤝</span>
                            <span class="result-label">Draw</span>
                        </button>
                        <button type="button" class="result-option result-lose" data-value="lose">
                            <span class="result-icon">💀</span>
                            <span class="result-label">Lose</span>
                        </button>
                    </div>
                    <input type="hidden" id="fieldResult" name="result" required>
                    <span class="field-error" id="errorResult"></span>
                </div>

                <div class="form-field">
                    <label class="form-label" for="fieldScoreDelta">
                        Score Delta
                        <span class="required-star">*</span>
                    </label>
                    <input
                        class="form-input font-mono"
                        type="number"
                        id="fieldScoreDelta"
                        name="score_delta"
                        placeholder="e.g. 25 or -15"
                        required
                    >
                    <span class="field-hint">Positive to add, negative to deduct</span>
                    <span class="field-error" id="errorScoreDelta"></span>
                </div>
            </div>

            <!-- Request ID (auto-generated, shown for transparency) -->
            <div class="form-field">
                <label class="form-label" for="fieldRequestId">
                    Request ID
                    <span class="field-badge">Auto-generated</span>
                </label>
                <div class="input-with-action">
                    <input
                        class="form-input font-mono"
                        type="text"
                        id="fieldRequestId"
                        name="request_id"
                        readonly
                        required
                    >
                    <button type="button" class="input-action-btn" id="regenRequestId" title="Regenerate">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                    </button>
                </div>
            </div>

        </form>

        <div class="modal-footer">
            <button class="btn btn-ghost" id="cancelModalBtn">Cancel</button>
            <button class="btn btn-primary btn-lg" id="submitReportBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                <span id="submitLabel">Submit Report</span>
            </button>
        </div>

        <!-- Inline response area -->
        <div class="modal-response" id="modalResponse" hidden></div>

    </div>
</div>

<!-- Pass PHP variables to JS -->
<script>
    window.PANEL_CONFIG = {
        apiBase: <?= json_encode($apiBase ?? '') ?>,
        refreshInterval: 10000,
    };
</script>
