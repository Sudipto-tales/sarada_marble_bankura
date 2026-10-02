<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Sudipta Ghosh">
    <link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png'); ?>">
    <title>Documentation | <?= env('APP_NAME', 'Vayu'); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    
    <style>
        :root {
            --docs-blue: #007bff;
            --text-main: #1a1a1a;
            --text-secondary: #4a5568;
            --border-color: #e2e8f0;
            --code-bg: #f8f9fa;
        }

        body {
            background-color: #ffffff;
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
        }

        /* Documentation Layout */
        .navbar-docs {
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
            background: #fff;
        }

        .brand-text {
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text-main);
            text-decoration: none;
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-top: 4rem;
            margin-bottom: 1.5rem;
        }

        .section-heading {
            font-weight: 700;
            margin-top: 3rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .doc-section {
            margin-bottom: 2.5rem;
        }

        code {
            color: #d63384;
            background-color: var(--code-bg);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.9em;
        }

        .file-tree {
            background: var(--code-bg);
            padding: 1.5rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.875rem;
        }

        .alert-setup {
            border-left: 4px solid var(--docs-blue);
            background: #f0f7ff;
            color: #0353a4;
            padding: 1.25rem;
            border-radius: 0 6px 6px 0;
        }

        footer {
            margin-top: 5rem;
            padding: 2rem 0;
            border-top: 1px solid var(--border-color);
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
    </style>
</head>

<body>

    <nav class="navbar-docs">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="#" class="brand-text"><?= env('APP_NAME', 'Vayu'); ?></a>
            <div class="d-none d-md-flex gap-4">
                <small class="text-muted">v1.0.4 stable</small>
                <a href="#" class="text-decoration-none text-dark small fw-medium">Documentation</a>
                <a href="#" class="text-decoration-none text-dark small fw-medium">API Reference</a>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width: 800px;">
        <h1 class="hero-title">Release Notes & System Architecture</h1>
        <p class="lead text-secondary mb-5">
            You have successfully initialized the <strong><?= env('APP_NAME', 'Vayu'); ?> Framework</strong>. This page serves as a technical brief for the default directory structure and request lifecycle.
        </p>

        <div class="doc-section">
            <h2 class="section-heading">1. Directory Structure</h2>
            <p>The framework follows a strict separation of concerns to maintain model-agnostic capabilities and logic modularity.</p>
            <div class="file-tree">
                . root/<br>
                ├── api/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-muted">// API-specific logic (gate.php)</span><br>
                ├── app/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-muted">// Application views and routing (view.php)</span><br>
                ├── core/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-muted">// Framework kernel and helper functions</span><br>
                ├── public/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-muted">// Assets (CSS, JS, Images)</span><br>
                └── index.php&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="text-muted">// Main entry point</span>
            </div>
        </div>

        <div class="doc-section">
            <h2 class="section-heading">2. Request Dispatching</h2>
            <p>Requests are processed through a dual-router system. Depending on the URI prefix, the system delegates control to the appropriate handler:</p>
            <ul>
                <li><strong>Frontend:</strong> Managed via <code>app/view.php</code>.</li>
                <li><strong>Backend:</strong> Managed via <code>api/gate.php</code> with a default <code>/api/v1/</code> prefix.</li>
            </ul>
        </div>

        <div class="doc-section">
            <h2 class="section-heading">3. Quick Configuration</h2>
            <p>To begin development, ensure your environment variables are correctly set in your configuration file:</p>
            <div class="alert-setup">
                <strong>Notice:</strong> The current environment is set to <code>development</code>. 
                Debug headers and raw logic errors are currently visible.
            </div>
        </div>

        <div class="doc-section">
            <h2 class="section-heading">4. Modification</h2>
            <p>To modify this view, navigate to:</p>
            <code>/app/page/welcome.php</code>
            <p class="mt-3">To register a new route, update the dispatch logic in the root directory or within the <code>app/</code> context.</p>
        </div>
    </div>

    <footer class="text-center">
        <div class="container">
            <p>Developed by Sudipta Ghosh &bull; The Kolkata Coder</p>
            <p class="mb-0">Built with efficiency at its core.</p>
        </div>
    </footer>


</body>
</html>