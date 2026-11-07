<?php

declare(strict_types=1);

return [
    'column_not_found' => 'Column not found',
    'column_not_editable' => 'Column is not editable',
    'record_not_found' => 'Record not found',
    'no_permission' => 'Permission denied',
    'no_permission_view' => 'No permission to view',
    'no_permission_edit' => 'No permission to edit',
    'record_conflict' => 'Record was modified by another user. Current value has been loaded.',
    'validation_failed' => 'Validation failed',
    'save_error' => 'Error saving: :error',
    'column_not_fillable' => 'Column cannot be filled',
    'fill_not_enabled' => 'Fill is not enabled for this table',
    'fill_error' => 'Fill failed: :error',
    'fill_partial' => ':filled of :total rows saved',
    'fill_handle' => 'Drag to fill the rows below',
    'select_placeholder' => 'Select...',
    'from' => 'From',
    'to' => 'To',
    'confirm_heading' => 'Confirm action',
    'confirm_description' => 'Are you sure you want to perform this action?',
    'confirm_submit' => 'Confirm',
    'confirm_cancel' => 'Cancel',
    'confirm_close' => 'Close',

    // Empty state
    'empty_heading' => 'No records',
    'empty_description' => 'No records found matching your search.',
    'empty_no_columns' => 'No columns to display',
    'empty_no_columns_hint' => 'Select at least one column to display using the button above.',
    'empty_filter_heading' => 'Nothing found',
    'empty_no_records_match' => 'No records match your search. Try adjusting the filters.',

    // Filters
    'filters' => 'Filters',
    'filter_all' => 'All',
    'filter_without_trashed' => 'Without deleted',
    'filter_with_trashed' => 'With deleted',
    'filter_only_trashed' => 'Only deleted',
    'filter_search' => 'Search...',
    'filter_no_results' => 'No results found',
    'filter_selected_count' => '{1}:count selected|[2,*]:count selected',
    'filter_yes' => 'Yes',
    'filter_no' => 'No',
    'filter_min' => 'Min',
    'filter_max' => 'Max',
    'filter_label' => 'Filter:',
    'filter_placeholder' => 'Filter...',
    'filter_reset' => 'Reset filters',
    'filter_reset_column' => 'Reset column filters',
    'filter_remove' => 'Remove filter',

    // Summary
    'summary_sum' => 'Sum',
    'summary_avg' => 'Average',
    'summary_count' => 'Count',
    'summary_min' => 'Min',
    'summary_max' => 'Max',
    'summary_range' => 'Range',
    'summary_total' => 'Total',
    'summary_distinct' => 'Distinct',
    'summary_median' => 'Median',
    'summary_variance' => 'Variance',
    'summary_stddev' => 'Std dev',
    'summary_first' => 'First',
    'summary_last' => 'Last',
    'summary_scope_label' => 'Showing:',
    'summary_scope_query' => 'All',
    'summary_scope_page' => 'This page',
    'summary_scope_selection' => 'Selection',
    'summary_subtotal' => 'Subtotal',

    // Column
    'copied' => 'Copied!',
    'copy' => 'Copy',
    'actions_label' => 'Actions',
    'toggle_columns' => 'Toggle columns',
    'reset_columns' => 'Reset columns',
    'view_options' => 'View options',
    'columns_section' => 'Columns',
    'details_section' => 'Details',
    'views_section' => 'Saved views',
    'save_current_view' => 'Save current view…',
    'save_view_prompt' => 'Name this view',
    'delete_view' => 'Delete view',
    'expand_all_rows' => 'Expand on every row',
    'export_label' => 'Export',
    'import_queued' => 'The import is running in the background.',
    'import_label' => 'Import',
    'import_result' => 'Imported :imported row(s), :failed failed.',

    'bulk_too_many' => 'Selection of :count records is over the :max limit for one action. Narrow the filter or raise bulkMaxRecords().',

    // Selection
    'select_all' => 'Select all',
    'select_row' => 'Select row',
    'deselect' => 'Deselect',
    'selection_on_page' => ':count selected.',
    'selection_all_matching' => 'All :count records matching the filter are selected.',
    'selection_select_all_matching' => 'Select all :count',
    'selection_only_this_page' => 'Only this page',
    'select_all_on_page' => 'Select all on this page',
    'selection_page_of_total' => ':page on this page · :total total',
    // Screen-reader announcements for the selection live region.
    'selection_announce_some' => ':count of :total selected',
    'selection_announce_all' => 'All :total selected',
    'selection_announce_none' => 'Selection cleared',
    'selection_selected_of_total' => ':count of :total selected',

    // Pagination
    'show' => 'Show',
    'showing' => 'Showing',
    'of' => 'of',
    'records' => 'records',
    'pagination_navigation' => 'Pagination Navigation',
    'pagination_previous' => 'Previous page',
    'pagination_next' => 'Next page',
    'pagination_goto' => 'Go to page :page',

    'sort_by' => 'Sort by',
    'sort_asc' => 'ascending',
    'sort_desc' => 'descending',

    // Search
    'search' => 'Search',

    // Loading
    'loading_table' => 'Loading table...',

    // Polling
    'paused' => 'Paused',
    'start' => 'Start',
    'stop' => 'Stop',

    // Sub-rows
    'expand' => 'Expand',
    'collapse' => 'Collapse',
    'expand_all' => 'Expand all',
    'collapse_all' => 'Collapse all',
    'reset' => 'Reset',
    'no_sub_rows' => 'No sub-rows found',
    'details' => 'Details',
    'actions' => 'Actions',
    'row_touch_menu' => 'More actions',
    'sub_rows_count' => '{1}:count item|[2,4]:count items|[5,*]:count items',
    'show_more_count' => 'Show :count more',

    // Inline editable
    'save_failed' => 'Save failed',
    'invalid' => 'Invalid',
    'rating_of_max' => ':rating out of :max',
    'error' => 'Error',

    // Keyboard shortcut legend
    'shortcuts_heading' => 'Keyboard shortcuts',
    'shortcuts_description' => 'These work while a row has focus.',
    'shortcuts_navigation' => 'Navigation',
    'shortcuts_selection' => 'Selection',
    'shortcuts_actions' => 'Actions',
    'shortcuts_help' => 'Help',
    'shortcut_move_active' => 'Move the active row',
    'shortcut_jump_edges' => 'Jump to the first / last row',
    'shortcut_page_step' => 'Move one page up / down',
    'shortcut_toggle_selection' => 'Select or deselect the active row',
    'shortcut_extend_selection' => 'Extend the selection',
    'shortcut_extend_selection_edges' => 'Extend the selection to the first / last row',
    'shortcut_select_page' => 'Select the whole page',
    'shortcut_run_action' => 'Run “:action”',
    'shortcut_context_menu' => 'Open the row menu',
    'shortcut_show_help' => 'Show keyboard shortcuts',

    // Queued exports ({@see Export\Jobs\RunExportJob}).
    'export_queued' => 'The export is being prepared in the background.',
    'export_ready' => 'Your export is ready: :file',
    'unsaved_edits' => 'Some changes have not been saved yet. Leave anyway?',
];
