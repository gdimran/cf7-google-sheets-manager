jQuery(function ($) {
  $('.mark-done, .delete-row').on('click', function (e) {
    e.preventDefault();
    if ($(this).hasClass('delete-row') && !confirm('Are you sure?')) return;
    const row = $(this).data('row');
    const task = $(this).hasClass('mark-done') ? 'mark_done' : 'delete_row';
    $.post(CF7SheetsAjax.ajaxurl, {
      action: 'cf7_sheets_update',
      security: CF7SheetsAjax.nonce,
      row: row,
      task: task
    }, function (res) {
      if (res.success) location.reload();
      else alert('Error: ' + (res.data?.message || 'Failed'));
    });
  });
});