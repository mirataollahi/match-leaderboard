<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($this->fetch('title', 'Panel')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/panel/panel.css">
    <?= $this->fetch('css') ?>
</head>
<body>

<div class="app-shell">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-mark">
                <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                    <rect width="28" height="28" rx="8" fill="#6366f1"/>
                    <path d="M7 20L14 8L21 20" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9.5 16H18.5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="logo-text">GameScore</span>
        </div>

        <nav class="sidebar-nav">
            <a href="/panel/leaderboard" class="nav-item active">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <span>Leaderboard</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="health-widget" id="healthWidget">
                <div class="health-dot" id="healthDot"></div>
                <span class="health-label" id="healthLabel">Checking…</span>
            </div>
        </div>
    </aside>

    <!-- Main content -->
    <main class="main-content">
        <?= $this->fetch('content') ?>
    </main>

</div>

<script src="/panel/panel.js"></script>
<?= $this->fetch('script') ?>
</body>
</html>
