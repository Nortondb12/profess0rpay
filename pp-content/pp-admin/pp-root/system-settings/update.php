<?php
if (!defined('Profess0rPay_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'manage_update', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    require_once __DIR__ . '/../../../pp-include/class-updater.php';

    $updater = new Profess0rPayUpdater();

    $currentVersion = '1.0.0';
    $pdo = connectDatabase();
    $stmt = $pdo->prepare("SELECT `value` FROM `{$db_prefix}env` WHERE `option_name` = 'pp_version'");
    $stmt->execute();
    if($val = $stmt->fetchColumn()) {
        $currentVersion = $val;
    }

    $preflightErrors = $updater->checkPreflight();
    $latestRelease = null;

    try {
        $latestRelease = $updater->getLatestRelease();
    } catch (Exception $e) {
        // API Failed
    }

    // Fetch Update Logs from Database
    $update_logs = [];
    try {
        $stmt_logs = $pdo->query("SELECT * FROM `{$db_prefix}update_logs` ORDER BY id DESC LIMIT 15");
        $update_logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist or empty
    }

    // Helper: Render Markdown to HTML
    if (!function_exists('pp_render_markdown')) {
        function pp_render_markdown($text) {
            if (empty($text)) return '';
            $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            
            // Headings
            $html = preg_replace('/^### (.+)$/m', '<h4 class="fw-bold mt-3 mb-2 text-primary d-flex align-items-center gap-1"><span class="badge bg-primary-lt p-1 rounded-pill me-1" style="font-size: 10px;">★</span> $1</h4>', $html);
            $html = preg_replace('/^## (.+)$/m', '<h3 class="fw-bold mt-3 mb-2 border-bottom pb-1">$1</h3>', $html);
            $html = preg_replace('/^# (.+)$/m', '<h2 class="fw-bold mt-3 mb-2">$1</h2>', $html);
            
            // Bold & Italic
            $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
            $html = preg_replace('/\*([^\*]+?)\*/s', '<em>$1</em>', $html);
            
            // Inline Code
            $html = preg_replace('/`([^`]+)`/', '<code class="px-1.5 py-0.5 rounded bg-light border text-dark font-monospace" style="font-size: 88%;">$1</code>', $html);
            
            // Bullet Lists
            $html = preg_replace('/^[-\*] (.+)$/m', '<li class="mb-1 text-secondary" style="line-height: 1.5;">$1</li>', $html);
            $html = preg_replace('/((?:<li class="mb-1 text-secondary"[^>]*>.*<\/li>\s*)+)/s', '<ul class="mb-2 ps-3" style="list-style-type: disc;">$1</ul>', $html);
            
            // Paragraph breaks
            $html = nl2br($html);
            $html = preg_replace('/<ul([^>]*)><br \/>/s', '<ul$1>', $html);
            $html = preg_replace('/<\/li><br \/>/s', '</li>', $html);
            $html = preg_replace('/<\/ul><br \/>/s', '</ul>', $html);
            
            return $html;
        }
    }

    // Helper: Parse CHANGELOG.md for Version History
    if (!function_exists('pp_parse_changelog_file')) {
        function pp_parse_changelog_file($filepath) {
            if (!file_exists($filepath)) return [];
            $content = file_get_contents($filepath);
            $releases = [];
            
            $parts = preg_split('/^## \[/m', $content);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part) || strpos($part, '# Changelog') !== false) continue;
                
                if (preg_match('/^([0-9\.]+)\]\s*-\s*([0-9\-]+)/', $part, $m)) {
                    $ver = $m[1];
                    $date = $m[2];
                    $body = substr($part, strpos($part, "\n") + 1);
                    
                    $sections = [];
                    $sec_parts = preg_split('/^### /m', $body);
                    foreach ($sec_parts as $sec) {
                        $sec = trim($sec);
                        if (empty($sec)) continue;
                        $lines = explode("\n", $sec);
                        $sec_title = trim(array_shift($lines));
                        $items = [];
                        foreach ($lines as $l) {
                            $l = trim($l);
                            if (strpos($l, '- ') === 0) {
                                $items[] = trim(substr($l, 2));
                            }
                        }
                        if (!empty($items)) {
                            $sections[$sec_title] = $items;
                        }
                    }
                    
                    $releases[] = [
                        'version' => $ver,
                        'date' => $date,
                        'sections' => $sections
                    ];
                }
            }
            return $releases;
        }
    }

    $changelog_path = realpath(__DIR__ . '/../../../../CHANGELOG.md');
    $changelog_releases = pp_parse_changelog_file($changelog_path);
?>

<div class="page-header d-print-none" aria-label="Page header">
    <div class="container-xl">
        <div class="row g-3 align-items-center">
            <div class="col">
                <div class="page-pretitle">
                    <ol class="breadcrumb breadcrumb-arrow mb-0">
                        <li class="breadcrumb-item"><a href="javascript:void(0)" onclick="load_content('System Settings','<?php echo $site_url.$path_admin ?>/system-settings','nav-item-system-settings')">System Settings</a></li>
                        <li class="breadcrumb-item active"><a href="javascript:void(0)">System Update</a></li>
                    </ol>
                </div>
                <h2 class="page-title d-flex align-items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-primary"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                    System Update & History
                </h2>
            </div>

            <div class="col-auto ms-auto d-print-none">
                <a href="#section-history" class="btn btn-outline-primary btn-sm d-flex align-items-center justify-content-center gap-1" onclick="document.getElementById('section-history').scrollIntoView({behavior: 'smooth'})">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 8l0 4l2 2" /><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" /></svg>
                    View Update History
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <style>
        /* Mobile responsive cards for the Installation Logs table */
        @media (max-width: 767px) {
            #tab-install-logs .table-responsive {
                border: 0;
            }
            #tab-install-logs table {
                border: 0;
            }
            #tab-install-logs table thead {
                display: none;
            }
            #tab-install-logs table tr {
                display: block;
                border: 1px solid #e6e8eb;
                border-radius: 8px;
                margin-bottom: 15px;
                padding: 10px;
                background-color: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            #tab-install-logs table td {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                border-bottom: 1px solid #f1f3f5;
                padding: 10px 5px !important;
                text-align: left;
            }
            #tab-install-logs table td:last-child {
                border-bottom: 0;
            }
            #tab-install-logs table td::before {
                content: attr(data-label);
                font-size: 11px;
                text-transform: uppercase;
                font-weight: 600;
                color: #6c7a91;
                margin-bottom: 4px;
            }
            /* Timeline responsiveness */
            .timeline-event {
                margin-bottom: 1rem !important;
            }
            .timeline-event-content {
                padding: 1rem !important;
            }
        }
    </style>
    <div class="container-xl">
        
        <!-- Version Status Cards -->
        <div class="row row-cards mb-4">
            <div class="col-md-6">
                <div class="card p-3 h-100 shadow-sm border-0" style="background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, rgba(255, 255, 255, 1) 100%);">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                        <span class="text-muted fw-semibold">Current Installed Version</span>
                        <span class="badge bg-blue-lt">Active System</span>
                    </div>
                    <div class="d-flex align-items-baseline gap-2 mt-1">
                        <h1 class="mb-0 fw-bolder text-primary display-6">v<?= htmlspecialchars($currentVersion) ?></h1>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Running on PHP <?= phpversion() ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3 h-100 shadow-sm border-0" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(255, 255, 255, 1) 100%);">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                        <span class="text-muted fw-semibold">Latest Available Version</span>
                        <span class="badge bg-green-lt">GitHub Release</span>
                    </div>
                    <?php if ($latestRelease && isset($latestRelease['tag_name'])): ?>
                        <?php $latestVer = str_replace('v', '', $latestRelease['tag_name']); ?>
                        <div class="d-flex align-items-baseline gap-2">
                            <h1 class="mb-0 fw-bolder <?= version_compare($currentVersion, $latestVer, '<') ? 'text-success' : 'text-primary' ?> display-6" id="latest-ver-tag">v<?= htmlspecialchars($latestVer) ?></h1>
                        </div>
                        <?php if (version_compare($currentVersion, $latestVer, '<')): ?>
                            <span class="badge bg-warning text-white mt-2 d-inline-block shadow-sm" style="width: fit-content; font-size: 12px; padding: 4px 10px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler me-1"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 16v.01" /><path d="M12 3a9 9 0 1 0 9 9a9 9 0 0 0 -9 -9" /></svg>
                                New Update Available!
                            </span>
                        <?php elseif (version_compare($currentVersion, $latestVer, '==')): ?>
                            <span class="badge bg-primary text-white mt-2 d-inline-block" style="width: fit-content;">Up to Date (Re-install Available)</span>
                        <?php else: ?>
                            <span class="badge bg-success mt-2 d-inline-block" style="width: fit-content;">You are on the latest version!</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <h2 class="mb-0 fw-bold text-muted">Unknown</h2>
                        <span class="badge bg-danger mt-2 d-inline-block" style="width: fit-content;">Could not fetch release</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($preflightErrors)): ?>
            <div class="alert alert-danger mb-4 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-danger"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 16v.01" /><path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z" /></svg>
                    <strong class="h4 mb-0">Pre-flight Checks Failed!</strong>
                </div>
                Please fix the following issues on your server before proceeding with the update:
                <ul class="mb-0 mt-2 ps-3">
                    <?php foreach($preflightErrors as $err) echo "<li>$err</li>"; ?>
                </ul>
            </div>
        <?php elseif ($latestRelease && version_compare($currentVersion, str_replace('v', '', $latestRelease['tag_name']), '<=')): ?>
            
            <!-- Update Action Card -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h3 class="card-title fw-bold text-dark d-flex align-items-center gap-2 m-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-primary"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12h6" /><path d="M12 9v6" /><path d="M12 3a9 9 0 1 0 9 9a9 9 0 0 0 -9 -9" /></svg>
                        Release Notes (<?= htmlspecialchars($latestRelease['tag_name']) ?>)
                    </h3>
                    <span class="badge bg-indigo-lt">Published: <?= date('d M Y', strtotime($latestRelease['published_at'] ?? 'now')) ?></span>
                </div>
                <div class="card-body">
                    <div style="background: var(--tblr-bg-surface-secondary, #f8fafc); border: 1px solid var(--tblr-border-color, #e2e8f0); padding: 20px; border-radius: 8px; max-height: 280px; overflow-y: auto;" class="mb-4">
                        <?= pp_render_markdown($latestRelease['body'] ?? 'No release notes provided.') ?>
                    </div>
                    
                    <div id="update-action-container">
                        <button class="btn btn-primary btn-lg shadow-sm" id="btnRunUpdate" data-bs-toggle="modal" data-bs-target="#modal-confirm-update">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon me-1"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                            <?php echo version_compare($currentVersion, str_replace('v', '', $latestRelease['tag_name']), '==') ? 'Re-install Update (Force)' : 'Download & Install Update'; ?>
                        </button>
                    </div>

                    <!-- Update Confirmation Modal -->
                    <div class="modal modal-blur fade" id="modal-confirm-update" tabindex="-1" role="dialog" aria-hidden="true">
                      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                        <div class="modal-content">
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                          <div class="modal-status bg-warning"></div>
                          <div class="modal-body text-center py-4">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-warning icon-lg" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v2m0 4v.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                            <h3>Are you sure?</h3>
                            <div class="text-muted small text-start mt-2">
                                <p class="mb-2">Do you want to proceed with updating to <strong><?= htmlspecialchars($latestRelease['tag_name']) ?></strong>?</p>
                                <ul class="mb-0 ps-3">
                                    <li>Core system files will be replaced safely.</li>
                                    <li>Custom settings and database will be preserved.</li>
                                    <li>Takes less than 15 seconds to finish.</li>
                                </ul>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <div class="w-100">
                              <div class="row">
                                <div class="col"><button class="btn w-100" data-bs-dismiss="modal">Cancel</button></div>
                                <div class="col"><button class="btn btn-warning w-100" data-bs-dismiss="modal" onclick="startUpdateSequence()">Confirm</button></div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Progress Bar Sequence -->
                    <div id="update-progress-container" style="display: none;">
                        <div class="d-flex justify-content-between mb-2">
                            <h3 id="update-status-text" class="mb-0 text-primary fw-bold">Initializing...</h3>
                            <span id="update-percent" class="text-muted fw-bold">0%</span>
                        </div>
                        <div class="progress progress-lg mb-3" style="height: 12px; border-radius: 6px;">
                            <div id="update-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 0%"></div>
                        </div>
                        <p class="text-muted small" id="update-subtext">Please do not close this window.</p>
                    </div>
                </div>
            </div>

            <script>
                function startUpdateSequence() {
                    let targetVersion = document.getElementById('latest-ver-tag').innerText.replace('v', '').trim();
                    let csrf = document.querySelector('input[name="csrf_token_default"]') ? document.querySelector('input[name="csrf_token_default"]').value : '';
                    
                    document.getElementById('update-action-container').style.display = 'none';
                    document.getElementById('update-progress-container').style.display = 'block';

                    const steps = [
                        { id: 1, text: "Downloading Update...", sub: "Fetching the latest release from GitHub", pct: 25 },
                        { id: 2, text: "Extracting Files...", sub: "Unpacking update files on server", pct: 50 },
                        { id: 3, text: "Installing Update...", sub: "Copying new files and updating database", pct: 80 },
                        { id: 4, text: "Cleaning Up...", sub: "Finalizing and removing temporary files", pct: 100 }
                    ];

                    let currentStep = 0;

                    function executeNextStep() {
                        if (currentStep >= steps.length) {
                            // Done!
                            document.getElementById('update-status-text').innerText = "Update Successful!";
                            document.getElementById('update-status-text').className = "mb-0 text-success fw-bold";
                            document.getElementById('update-subtext').innerText = "System is up to date.";
                            document.getElementById('update-progress-bar').className = "progress-bar bg-success";
                            
                            createToast({
                                title: 'Update Successful!',
                                description: 'System has been successfully updated to v' + targetVersion,
                                svg: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5f38f9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-circle-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>`,
                                timeout: 6000,
                                top: 70
                            });

                            setTimeout(() => {
                                load_content('System Update','<?php echo $site_url.$path_admin ?>/system-settings/update','nav-item-system-settings');
                            }, 2000);
                            return;
                        }

                        let step = steps[currentStep];
                        document.getElementById('update-status-text').innerText = step.text;
                        document.getElementById('update-subtext').innerText = step.sub;
                        
                        $.ajax({
                            type: 'POST',
                            url: '<?php echo $site_url.$path_admin ?>/dashboard',
                            data: { 
                                action: 'process-system-update', 
                                step: step.id,
                                target_version: targetVersion,
                                csrf_token: csrf 
                            },
                            success: function(text) {
                                try {
                                    let res = typeof text === 'object' ? text : JSON.parse(text.match(/(\{"status":.+\})/s)[1]);
                                    if (res.status === 'success') {
                                        document.getElementById('update-progress-bar').style.width = step.pct + "%";
                                        document.getElementById('update-percent').innerText = step.pct + "%";
                                        currentStep++;
                                        executeNextStep();
                                    } else {
                                        showError(res.message);
                                    }
                                } catch(e) {
                                    console.error("Parse Error:", e, text);
                                    showError("Unexpected response from server on Step " + step.id);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error("Network Error:", error);
                                showError("Network connection lost during Step " + step.id);
                            }
                        });
                    }

                    function showError(msg) {
                        document.getElementById('update-status-text').innerText = "Update Failed!";
                        document.getElementById('update-status-text').className = "mb-0 text-danger fw-bold";
                        document.getElementById('update-subtext').innerText = msg;
                        document.getElementById('update-progress-bar').className = "progress-bar bg-danger";
                        
                        createToast({
                            title: 'Update Failed',
                            description: msg,
                            svg: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d63939" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" /></svg>`,
                            timeout: 6000,
                            top: 70
                        });

                        setTimeout(() => {
                            document.getElementById('update-progress-container').style.display = 'none';
                            document.getElementById('update-action-container').style.display = 'block';
                            // Reset bar for retry
                            document.getElementById('update-progress-bar').style.width = "0%";
                            document.getElementById('update-percent').innerText = "0%";
                            document.getElementById('update-status-text').className = "mb-0 text-primary fw-bold";
                            document.getElementById('update-progress-bar').className = "progress-bar progress-bar-striped progress-bar-animated bg-primary";
                        }, 5000);
                    }

                    // Start the chain
                    executeNextStep();
                }
            </script>
        <?php endif; ?>

        <!-- SECTION: Update History & Version Changelog -->
        <div id="section-history" class="mt-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent">
                    <ul class="nav nav-tabs card-header-tabs flex-nowrap" data-bs-toggle="tabs" role="tablist" style="overflow-x: auto; overflow-y: hidden; white-space: nowrap; padding-bottom: 1px; -webkit-overflow-scrolling: touch; scrollbar-width: none;">
                        <style>
                            .card-header-tabs.flex-nowrap::-webkit-scrollbar {
                                display: none;
                            }
                        </style>
                        <li class="nav-item" role="presentation">
                            <a href="#tab-changelog" class="nav-link active fw-bold d-flex align-items-center gap-2" data-bs-toggle="tab" aria-selected="true" role="tab">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-primary"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M9 4v16" /><path d="M14 10l2 2l4 -4" /></svg>
                                Version Changelog History
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a href="#tab-install-logs" class="nav-link fw-bold d-flex align-items-center gap-2" data-bs-toggle="tab" aria-selected="false" tabindex="-1" role="tab">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-success"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 14l2 2l4 -4" /></svg>
                                Installation Logs
                                <?php if (!empty($update_logs)): ?>
                                    <span class="badge bg-primary-lt ms-1"><?= count($update_logs) ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content">
                        
                        <!-- TAB 1: Version Changelog Timeline -->
                        <div class="tab-pane active show" id="tab-changelog" role="tabpanel">
                            <?php if (empty($changelog_releases)): ?>
                                <div class="text-center text-muted py-4">No changelog entries found.</div>
                            <?php else: ?>
                                <div class="timeline" style="border-left: 2px solid #e2e8f0; padding-left: 24px; position: relative;">
                                    <?php foreach ($changelog_releases as $rel): ?>
                                        <div class="mb-4 position-relative">
                                            <!-- Timeline Dot -->
                                            <div style="position: absolute; left: -31px; top: 2px; width: 14px; height: 14px; border-radius: 50%; background: <?= ($rel['version'] === $currentVersion) ? '#10b981' : '#4361ee' ?>; border: 3px solid #fff; box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);"></div>
                                            
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <h3 class="mb-0 fw-bold text-dark">v<?= htmlspecialchars($rel['version']) ?></h3>
                                                <a href="javascript:void(0)" class="changelog-info-btn text-primary d-inline-flex align-items-center justify-content-center" data-bs-toggle="collapse" data-bs-target="#changelog-<?= str_replace('.', '-', $rel['version']) ?>" aria-expanded="false" title="View Details" style="width: 24px; height: 24px; border-radius: 50%; background: #eef2ff; transition: all 0.2s; text-decoration: none; outline: none;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
                                                </a>
                                                <?php if ($rel['version'] === $currentVersion): ?>
                                                    <span class="badge bg-success-lt fw-bold">Current Version</span>
                                                <?php endif; ?>
                                                <span class="text-muted small ms-auto d-flex align-items-center gap-1">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /></svg>
                                                    <?= date('d M Y', strtotime($rel['date'])) ?>
                                                </span>
                                            </div>

                                            <div class="collapse" id="changelog-<?= str_replace('.', '-', $rel['version']) ?>">
                                                <div class="card p-3 bg-light border-0 shadow-none rounded-3 mt-1 mb-3">
                                                    <?php foreach ($rel['sections'] as $sec_title => $items): ?>
                                                        <div class="mb-2">
                                                            <div class="d-flex align-items-center gap-1 mb-1">
                                                                <?php if (strtolower($sec_title) === 'added'): ?>
                                                                    <span class="badge bg-green-lt fw-bold" style="font-size: 11px;">+ ADDED</span>
                                                                <?php elseif (strtolower($sec_title) === 'fixed'): ?>
                                                                    <span class="badge bg-blue-lt fw-bold" style="font-size: 11px;">✔ FIXED</span>
                                                                <?php elseif (strtolower($sec_title) === 'changed'): ?>
                                                                    <span class="badge bg-orange-lt fw-bold" style="font-size: 11px;">⚡ CHANGED</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary-lt fw-bold" style="font-size: 11px;"><?= strtoupper(htmlspecialchars($sec_title)) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <ul class="mb-0 ps-3 text-secondary" style="font-size: 13.5px; line-height: 1.6;">
                                                                <?php foreach ($items as $item): ?>
                                                                    <li><?= pp_render_markdown($item) ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 2: Database Installation Logs -->
                        <div class="tab-pane" id="tab-install-logs" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-vcenter card-table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Target Version</th>
                                            <th>Status</th>
                                            <th>Log Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($update_logs)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">No update installation logs found in the database.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($update_logs as $log): ?>
                                                <tr>
                                                    <td data-label="Timestamp" class="text-nowrap">
                                                        <?php
                                                            $created = $log['created_at'] ?? 'now';
                                                            try {
                                                                $dt = new DateTime($created, new DateTimeZone('UTC'));
                                                                $dt->setTimezone(new DateTimeZone($global_response_brand['response'][0]['timezone'] ?? 'Asia/Dhaka'));
                                                                echo $dt->format('d M Y, h:i A');
                                                            } catch (Exception $e) {
                                                                echo date('d M Y, h:i A', strtotime($created));
                                                            }
                                                        ?>
                                                    </td>
                                                    <td data-label="Target Version">
                                                        <span class="badge bg-primary-lt fw-bold">v<?= htmlspecialchars($log['version']) ?></span>
                                                    </td>
                                                    <td data-label="Status">
                                                        <?php if (strtolower($log['status']) === 'success'): ?>
                                                            <span class="badge bg-success-lt fw-bold d-inline-flex align-items-center gap-1">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                                                                Success
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger-lt fw-bold d-inline-flex align-items-center gap-1">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                                                                Failed
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="Log Details" class="text-muted small">
                                                        <?= htmlspecialchars($log['log'] ?? 'N/A') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>