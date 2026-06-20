<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Leaderboard Service — API Docs</title>
    <?= $this->Html->css('/swagger/swagger-ui.css') ?>
</head>
<body>
<div class="swagger-wrapper">
    <div id="swagger-ui">
        <div class="loading-state">
            <div class="spinner"></div>
            Loading API specification…
        </div>
    </div>
</div>
<?= $this->Html->script('/swagger/swagger-ui-bundle.js') ?>
<?= $this->Html->script('/swagger/swagger-ui-standalone-preset.js') ?>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        SwaggerUIBundle({
            url: '/doc/spec',
            dom_id: '#swagger-ui',
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset,
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl,
            ],
            layout: 'StandaloneLayout',
            deepLinking:          true,   // Shareable URLs per operation
            displayRequestDuration: true, // Show response time in ms
            tryItOutEnabled:      true,   // "Try it out" open by default
            filter:               true,   // Show search/filter bar
            syntaxHighlight: {
                activate: true,
                theme:    'monokai',
            },
            docExpansion: 'list',
            defaultModelsExpandDepth: 1,
        });
    });
</script>
</body>
</html>
