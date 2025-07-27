// jQuery(function ($) {
//   $('.mark-done, .delete-row').on('click', function (e) {
//     e.preventDefault();
//     if ($(this).hasClass('delete-row') && !confirm('Are you sure?')) return;
//     const row = $(this).data('row');
//     const task = $(this).hasClass('mark-done') ? 'mark_done' : 'delete_row';
//     $.post(CF7SheetsAjax.ajaxurl, {
//       action: 'cf7_sheets_update',
//       security: CF7SheetsAjax.nonce,
//       row: row,
//       task: task
//     }, function (res) {
//       if (res.success) location.reload();
//       else alert('Error: ' + (res.data?.message || 'Failed'));
//     });
//   });
// });
jQuery(document).ready(function ($) {
    // Mark as Done
    $(document).on('click', '.mark-done', function () {
        var row = $(this).data('row');
        var nonce = CF7SheetsAjax.nonce;

        $.ajax({
            url: CF7SheetsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cf7_sheets_update',
                security: nonce,
                task: 'mark_done',
                row: row
            },
            success: function (response) {
                if (response.success) {
                    alert('Status updated to "Done"');
                    location.reload();
                } else {
                    alert('Failed to update status: ' + response.data.message);
                }
            }
        });
    });

    // Delete Row
    $(document).on('click', '.delete-row', function () {
        var row = $(this).data('row');
        var nonce = CF7SheetsAjax.nonce;

        $.ajax({
            url: CF7SheetsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cf7_sheets_update',
                security: nonce,
                task: 'delete_row',
                row: row
            },
            success: function (response) {
                if (response.success) {
                    alert('Row deleted');
                    location.reload();
                } else {
                    alert('Failed to delete row: ' + response.data.message);
                }
            }
        });
    });
});
