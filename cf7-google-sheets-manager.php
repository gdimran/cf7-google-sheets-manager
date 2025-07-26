<?php
/*
Plugin Name: CF7 Google Sheets Manager
Description: Save CF7 submissions to Google Sheets, AJAX admin actions, CSV export, filters, REST API.
Version: 1.1
Author: Imran Hossain
*/

require_once __DIR__ . '/vendor/autoload.php';
define('CF7_SHEETS_SPREADSHEET_ID', '1xHe7UYwcA3-VeT3gZt7m3HErwf07duvrOsu5wTNVExA');
define('CF7_SHEETS_JSON_PATH', __DIR__ . '/khadim-api-key.json');
define('CF7_SHEETS_API_KEY', 'your_api_key_here');

// Save CF7 submission
add_action('wpcf7_mail_sent', function ($form) {
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
        $client->setAuthConfig(CF7_SHEETS_JSON_PATH);
        $service = new Google_Service_Sheets($client);
        $body = new Google_Service_Sheets_ValueRange(['values' => $values]);
        $service->spreadsheets_values->append(CF7_SHEETS_SPREADSHEET_ID, 'Sheet1!A1', $body, ['valueInputOption' => 'RAW']);
    } catch (Exception $e) {
        error_log('[CF7 → Sheets] ' . $e->getMessage());
    }
});

// Admin page
add_action('admin_menu', function () {
    add_menu_page('CF7 Submissions', 'Form Submissions', 'manage_options', 'cf7_submissions', 'cf7_sheets_admin_page', 'dashicons-feedback', 29);
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

// AJAX update/delete
add_action('wp_ajax_cf7_sheets_update', function () {
    check_ajax_referer('cf7_sheets_nonce', 'security');
    $row = intval($_POST['row'] ?? 0);
    $task = sanitize_text_field($_POST['task'] ?? '');

    try {
        $client = new Google_Client();
        $client->setApplicationName('WP CF7 Sheets');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig(CF7_SHEETS_JSON_PATH);
        $service = new Google_Service_Sheets($client);

        if ($task === 'mark_done') {
            $service->spreadsheets_values->update(CF7_SHEETS_SPREADSHEET_ID, "Sheet1!G{$row}", new Google_Service_Sheets_ValueRange(['values' => [['Done']]]), ['valueInputOption' => 'RAW']);
        } elseif ($task === 'delete_row') {
            $service->spreadsheets_values->clear(CF7_SHEETS_SPREADSHEET_ID, "Sheet1!A{$row}:H{$row}", new Google_Service_Sheets_ClearValuesRequest());
        }

        wp_send_json_success();
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// CSV export
add_action('admin_init', function () {
    if (!isset($_POST['cf7_export_csv']) || !current_user_can('manage_options')) return;
    $rows = cf7_get_sheet_data();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="cf7-submissions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['First Name', 'Last Name', 'Email', 'Phone', 'Project Type', 'Message', 'Status', 'Date/Time']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
});

// REST API
add_action('rest_api_init', function () {
    register_rest_route('cf7api/v1', '/submissions', [
        'methods' => 'GET',
        'callback' => function ($req) {
            $key = $req->get_header('X-API-Key');
            if ($key !== CF7_SHEETS_API_KEY) return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            return cf7_get_sheet_data();
        },
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('cf7api/v1', '/submit', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $key = $req->get_header('X-API-Key');
            if ($key !== CF7_SHEETS_API_KEY) return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            $data = $req->get_json_params();
            $data['datetime'] = date('Y-m-d H:i:s');
            $data['status'] = 'Pending';
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
            $client = new Google_Client();
            $client->setApplicationName('WP CF7 Sheets');
            $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
            $client->setAuthConfig(CF7_SHEETS_JSON_PATH);
            $service = new Google_Service_Sheets($client);
            $body = new Google_Service_Sheets_ValueRange(['values' => $values]);
            $service->spreadsheets_values->append(CF7_SHEETS_SPREADSHEET_ID, 'Sheet1!A1', $body, ['valueInputOption' => 'RAW']);
            return ['message' => 'Submitted'];
        },
        'permission_callback' => '__return_true',
    ]);
});

// Helper
function cf7_get_sheet_data()
{
    $client = new Google_Client();
    $client->setApplicationName('WP CF7 Sheets');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig(CF7_SHEETS_JSON_PATH);
    $service = new Google_Service_Sheets($client);
    $range = 'Sheet1!A2:H';
    $response = $service->spreadsheets_values->get(CF7_SHEETS_SPREADSHEET_ID, $range);
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

// Admin Page
function cf7_sheets_admin_page()
{
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
