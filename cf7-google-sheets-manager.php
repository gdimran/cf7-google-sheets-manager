<?php
/*
Plugin Name: CF7 Google Sheets Manager
Description: Save CF7 submissions to Google Sheets, AJAX admin actions, CSV export, filters, REST API.
Version: 1.2
Author: Imran Hossain
*/

require_once __DIR__ . '/vendor/autoload.php';

// Add menu and sub-menu pages
add_action('admin_menu', function () {
    add_menu_page('CF7 Submissions', 'Form Submissions', 'manage_options', 'cf7_submissions', 'cf7_sheets_admin_page', 'dashicons-feedback', 29);
    add_submenu_page('cf7_submissions', 'Google Sheets Settings', 'Settings', 'manage_options', 'cf7_google_sheets_settings', 'cf7_sheets_settings_page');
});
// Enqueue JS
add_action('admin_enqueue_scripts', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'cf7_submissions') return;
    wp_enqueue_script('cf7-sheets-js', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], null, true);
    wp_localize_script('cf7-sheets-js', 'CF7SheetsAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cf7_sheets_nonce')
    ]);
});

// Settings page to update Google Sheets ID, JSON key, and API Key
function cf7_sheets_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['cf7_sheets_spreadsheet_id'])) {
            update_option('cf7_sheets_spreadsheet_id', sanitize_text_field($_POST['cf7_sheets_spreadsheet_id']));
        }

        if (isset($_FILES['cf7_sheets_json_file'])) {
            $uploaded_file = $_FILES['cf7_sheets_json_file'];
            if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
                $upload_dir = wp_upload_dir();
                $target_path = $upload_dir['basedir'] . '/cf7_google_sheets/';
                if (!file_exists($target_path)) {
                    mkdir($target_path, 0755, true);
                }
                $target_file = $target_path . $uploaded_file['name'];
                move_uploaded_file($uploaded_file['tmp_name'], $target_file);
                update_option('cf7_sheets_json_path', $target_file);
            }
        }

        if (isset($_POST['cf7_sheets_api_key'])) {
            update_option('cf7_sheets_api_key', sanitize_text_field($_POST['cf7_sheets_api_key']));
        }
    }

    $spreadsheet_id = get_option('cf7_sheets_spreadsheet_id');
    $json_path = get_option('cf7_sheets_json_path');
    $api_key = get_option('cf7_sheets_api_key');

    ?>
    <div class="wrap">
        <h1>Google Sheets Integration Settings</h1>
        <form method="POST" enctype="multipart/form-data">
            <table class="form-table">
                <tr>
                    <th><label for="cf7_sheets_spreadsheet_id">Google Sheets ID</label></th>
                    <td><input type="text" name="cf7_sheets_spreadsheet_id" value="<?php echo esc_attr($spreadsheet_id); ?>" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="cf7_sheets_json_file">Google API JSON Key File</label></th>
                    <td>
                        <input type="file" name="cf7_sheets_json_file" accept=".json" />
                        <?php if ($json_path) : ?>
                            <p>Current file: <?php echo basename($json_path); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="cf7_sheets_api_key">API Key</label></th>
                    <td><input type="text" name="cf7_sheets_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" required /></td>
                </tr>
                   
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
                     <div><strong><?php echo site_url(); ?>/wp-json/cf7api/v1/submissions</strong></div>
                    <div><strong>Get form data and modify use post method and for Authenticate use this "<?php $stored_api_key = get_option('cf7_sheets_api_key'); echo $stored_api_key; ?>" key as the value of x-api-key. </strong></div>
    </div>
    <?php
}

// Save CF7 submission to Google Sheets
add_action('wpcf7_mail_sent', function ($form) {
    $spreadsheet_id = get_option('cf7_sheets_spreadsheet_id');
    $json_path = get_option('cf7_sheets_json_path');
    $api_key = get_option('cf7_sheets_api_key');

    if (!$spreadsheet_id || !$json_path || !$api_key) {
        return; // Missing configuration
    }

    $submission = WPCF7_Submission::get_instance();
    if (!$submission) return;
    $data = $submission->get_posted_data();

    $project_type_raw = $data['project-type'] ?? '';
    $project_type = is_array($project_type_raw) ? implode(', ', $project_type_raw) : sanitize_text_field($project_type_raw);

    $values = [[
        sanitize_text_field($data['first-name'] ?? ''),
        sanitize_text_field($data['last-name'] ?? ''),
        sanitize_email($data['email'] ?? ''),
        sanitize_text_field($data['phone'] ?? ''),
        $project_type,
        sanitize_textarea_field($data['message'] ?? ''),
        'Pending',
        date('Y-m-d H:i:s')
    ]];

    try {
        $client = new Google_Client();
        $client->setApplicationName('WP CF7 Sheets');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($json_path);
        $service = new Google_Service_Sheets($client);
        $body = new Google_Service_Sheets_ValueRange(['values' => $values]);
        $service->spreadsheets_values->append($spreadsheet_id, 'Sheet1!A1', $body, ['valueInputOption' => 'RAW']);
    } catch (Exception $e) {
        error_log('[CF7 → Sheets] ' . $e->getMessage());
    }
});

// Admin page to display submissions
function cf7_sheets_admin_page() {
    $data = cf7_get_sheet_data();
    $filter_status = $_GET['status'] ?? '';
    $filter_date = $_GET['date'] ?? '';
    $filtered = array_filter($data, function ($r) use ($filter_status, $filter_date) {
        $match = true;
        if ($filter_status && strtolower($r['status']) !== strtolower($filter_status)) $match = false;
        if ($filter_date && strpos($r['datetime'], $filter_date) !== 0) $match = false;
        return $match;
    });
    echo '<div class="wrap"><h1>CF7 Form Submissions</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="cf7_submissions">';
    echo '<select name="status"><option value="">All Status</option><option ' . selected($filter_status, 'Pending', false) . '>Pending</option><option ' . selected($filter_status, 'Done', false) . '>Done</option></select>';
    echo ' <input type="date" name="date" value="' . esc_attr($filter_date) . '">';
    echo ' <input type="submit" class="button" value="Filter"></form>';
    echo '<form method="post" style="margin-top:10px;"><input type="submit" class="button button-primary" name="cf7_export_csv" value="Export to CSV"></form>';
    echo '<table class="widefat"><thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Project</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    foreach (array_values($filtered) as $i => $r) {
        $row_number = $i + 2;
        echo '<tr><td>' . ($i + 1) . '</td><td>' . esc_html($r['first-name'] . ' ' . $r['last-name']) . '</td><td>' . esc_html($r['email']) . '</td><td>' . esc_html($r['phone']) . '</td><td>' . esc_html($r['project-type']) . '</td><td>' . esc_html($r['message']) . '</td><td>' . esc_html($r['status']) . '</td><td>' . esc_html($r['datetime']) . '</td><td>';
        echo '<button type="button" class="mark-done" data-row="' . $row_number . '">Done</button> ';
        echo '<button type="button" class="delete-row" data-row="' . $row_number . '">Delete</button>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// Fetch data from Google Sheets
function cf7_get_sheet_data() {
    $spreadsheet_id = get_option('cf7_sheets_spreadsheet_id');
    $json_path = get_option('cf7_sheets_json_path');
    if (!$spreadsheet_id || !$json_path) return [];

    $client = new Google_Client();
    $client->setApplicationName('WP CF7 Sheets');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($json_path);
    $service = new Google_Service_Sheets($client);
    $range = 'Sheet1!A2:H';
    $response = $service->spreadsheets_values->get($spreadsheet_id, $range);
    $rows = $response->getValues();
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'first-name' => $r[0] ?? '',
            'last-name' => $r[1] ?? '',
            'email' => $r[2] ?? '',
            'phone' => $r[3] ?? '',
            'project-type' => $r[4] ?? '',
            'message' => $r[5] ?? '',
            'status' => $r[6] ?? '',
            'datetime' => $r[7] ?? ''
        ];
    }
    return $data;
}

add_action('wp_ajax_cf7_sheets_update', function () {
    check_ajax_referer('cf7_sheets_nonce', 'security');
    
    $row = intval($_POST['row'] ?? 0);
    $task = sanitize_text_field($_POST['task'] ?? '');

    if ($row === 0 || !in_array($task, ['mark_done', 'delete_row'])) {
        wp_send_json_error(['message' => 'Invalid row or task']);
        return;
    }

    $spreadsheet_id = get_option('cf7_sheets_spreadsheet_id');
    $json_path = get_option('cf7_sheets_json_path');
    
    if (!$spreadsheet_id || !$json_path) {
        wp_send_json_error(['message' => 'Google Sheets configuration missing']);
        return;
    }

    try {
        $client = new Google_Client();
        $client->setApplicationName('WP CF7 Sheets');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($json_path);
        $service = new Google_Service_Sheets($client);

        if ($task === 'mark_done') {
            // Update the status to 'Done' in the specific row and column
            $service->spreadsheets_values->update(
                $spreadsheet_id, 
                "Sheet1!G{$row}", 
                new Google_Service_Sheets_ValueRange(['values' => [['Done']]]), 
                ['valueInputOption' => 'RAW']
            );
        } elseif ($task === 'delete_row') {
            // Delete the row
            $service->spreadsheets_values->clear(
                $spreadsheet_id, 
                "Sheet1!A{$row}:H{$row}", 
                new Google_Service_Sheets_ClearValuesRequest()
            );
        }

        wp_send_json_success();
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

add_action('rest_api_init', function () {
    // Get the API key from settings
    $api_key = get_option('cf7_sheets_api_key');  // Retrieve the API key from the WordPress options
error_log('Stored API Key: ' . $api_key);
    // Route for GET (fetching submissions)
    register_rest_route('cf7api/v1', '/submissions', [
        'methods' => 'GET',
        'callback' => function ($req) use ($api_key) {
            // Get the API key from the request header
            $key = $req->get_header('X-API-Key');
            
            // If the API keys don't match, return Unauthorized
            if ($key !== $api_key) {
                return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            }

            // If authorized, return the submissions data
            return cf7_get_sheet_data();
        },
        'permission_callback' => '__return_true',
    ]);

    // Route for POST (submitting data)
    register_rest_route('cf7api/v1', '/submit', [
        'methods' => 'POST',
        'callback' => function ($req) use ($api_key) {
            // Get the API key from the request header
            $key = $req->get_header('X-API-Key');
            
            // If the API keys don't match, return Unauthorized
            if ($key !== $api_key) {
                return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            }

            // Get the form submission data from the request
            $data = $req->get_json_params();
            $data['datetime'] = date('Y-m-d H:i:s');
            $data['status'] = 'Pending';

            // Prepare data to append to Google Sheets
            $values = [[
                $data['first-name'] ?? '',
                $data['last-name'] ?? '',
                $data['email'] ?? '',
                $data['phone'] ?? '',
                $data['project-type'] ?? '',
                $data['message'] ?? '',
                $data['status'],
                $data['datetime']
            ]];

            // Get Google Sheets credentials
            $spreadsheet_id = get_option('cf7_sheets_spreadsheet_id');
            $json_path = get_option('cf7_sheets_json_path');

            if (!$spreadsheet_id || !$json_path) {
                return new WP_REST_Response(['error' => 'Google Sheets configuration missing'], 400);
            }

            // Google Client Configuration
            $client = new Google_Client();
            $client->setApplicationName('WP CF7 Sheets');
            $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
            $client->setAuthConfig($json_path);
            $service = new Google_Service_Sheets($client);
            $body = new Google_Service_Sheets_ValueRange(['values' => $values]);

            // Append data to Google Sheet
            try {
                $service->spreadsheets_values->append($spreadsheet_id, 'Sheet1!A1', $body, ['valueInputOption' => 'RAW']);
            } catch (Exception $e) {
                return new WP_REST_Response(['error' => 'Failed to submit data: ' . $e->getMessage()], 500);
            }

            // Return a success message
            return ['message' => 'Submitted'];
        },
        'permission_callback' => '__return_true',
    ]);
});

