<?php
if (!defined('Profess0rPay_INIT')) {
    exit('Direct access not allowed');
}

if ($global_user_response['response'][0]['role'] !== 'admin' && !empty($global_user_response['response'][0]['role'])) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    exit();
}
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">System Error Logs</h3>
                <button type="button" class="btn btn-danger btn-sm" onclick="clearSystemLogs()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-trash" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><line x1="4" y1="7" x2="20" y2="7"></line><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"></path><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"></path></svg>
                    Clear All Logs
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped" id="system-logs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Time</th>
                                <th>Level</th>
                                <th>Message</th>
                                <th>File / URL</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $logs = json_decode(getData($db_prefix.'system_logs', 'ORDER BY id DESC LIMIT 500'), true);
                            if ($logs['status'] == true) {
                                foreach ($logs['response'] as $log) {
                                    $level_badge = 'bg-secondary';
                                    if ($log['error_level'] == 'Fatal Error') $level_badge = 'bg-danger';
                                    elseif ($log['error_level'] == 'Exception') $level_badge = 'bg-warning';
                                    elseif ($log['error_level'] == 'Fatal Shutdown Error') $level_badge = 'bg-dark';

                                    echo '<tr>';
                                    echo '<td>#'.$log['id'].'</td>';
                                    echo '<td>'.date('Y-m-d H:i:s', strtotime($log['created_at'])).'</td>';
                                    echo '<td><span class="badge '.$level_badge.'">'.$log['error_level'].'</span></td>';
                                    echo '<td>'.htmlspecialchars(substr($log['message'], 0, 50)).'...</td>';
                                    echo '<td class="text-muted"><small>'.htmlspecialchars($log['file_name']).':'.$log['line_number'].'<br/>'.htmlspecialchars($log['url']).'</small></td>';
                                    echo '<td><button class="btn btn-sm btn-outline-primary" data-log=\''.htmlspecialchars(json_encode($log), ENT_QUOTES, 'UTF-8').'\' onclick="viewLogDetails(this)">View</button></td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="6" class="text-center">No logs found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal modal-blur fade" id="modal-log-details" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log Details #<span id="log-detail-id"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
            <strong>Time:</strong> <span id="log-detail-time"></span>
        </div>
        <div class="mb-3">
            <strong>Level:</strong> <span id="log-detail-level" class="badge"></span>
        </div>
        <div class="mb-3">
            <strong>Message:</strong>
            <pre id="log-detail-message" class="bg-dark text-light p-3 rounded" style="white-space: pre-wrap; font-size: 13px;"></pre>
        </div>
        <div class="mb-3">
            <strong>File:</strong> <span id="log-detail-file"></span> (Line: <span id="log-detail-line"></span>)
        </div>
        <div class="mb-3">
            <strong>URL:</strong> <span id="log-detail-url"></span>
        </div>
        <div class="mb-3">
            <strong>IP Address:</strong> <span id="log-detail-ip"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn me-auto" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
    $('#system-logs-table').DataTable({
        "order": [[0, "desc"]],
        "pageLength": 25,
    });

    function viewLogDetails(btn) {
        let logStr = btn.getAttribute('data-log');
        let log = JSON.parse(logStr);
        $('#log-detail-id').text(log.id);
        $('#log-detail-time').text(log.created_at);
        $('#log-detail-level').text(log.error_level);
        $('#log-detail-message').text(log.message);
        $('#log-detail-file').text(log.file_name);
        $('#log-detail-line').text(log.line_number);
        $('#log-detail-url').text(log.url);
        $('#log-detail-ip').text(log.ip_address);
        
        let modal = new bootstrap.Modal(document.getElementById('modal-log-details'));
        modal.show();
    }

    function clearSystemLogs() {
        if (confirm("Are you sure you want to clear all logs?")) {
            $.ajax({
                type: 'POST',
                url: '<?php echo $site_url.$path_admin ?>/dashboard',
                data: {
                    action: 'clear-system-logs',
                    csrf_token: '<?php echo $_SESSION['csrf_token'] ?>'
                },
                success: function(response) {
                    try {
                        let res = JSON.parse(response);
                        if (res.status == 'true') {
                            createToast({
                                title: 'Success',
                                description: res.message,
                                type: 'success',
                                icon: 'check-circle'
                            });
                            load_content('System Logs', '<?php echo $site_url.$path_admin ?>/system-logs', 'nav-item-system-logs');
                        } else {
                            createToast({
                                title: 'Error',
                                description: res.message,
                                type: 'danger',
                                icon: 'alert-circle'
                            });
                        }
                    } catch (e) {
                        console.log(response);
                    }
                }
            });
        }
    }
</script>
