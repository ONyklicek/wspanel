<?php

declare(strict_types=1);

return [
    'column_not_found' => 'Sloupec nenalezen',
    'column_not_editable' => 'Sloupec není editovatelný',
    'record_not_found' => 'Záznam nenalezen',
    'no_permission' => 'Nemáte oprávnění',
    'no_permission_view' => 'Nemáte oprávnění pro zobrazení',
    'no_permission_edit' => 'Nemáte oprávnění pro editaci',
    'record_conflict' => 'Záznam byl mezitím změněn jiným uživatelem. Aktuální hodnota byla načtena.',
    'validation_failed' => 'Validace selhala',
    'save_error' => 'Chyba při ukládání: :error',
    'column_not_fillable' => 'Sloupec nelze vyplnit tažením',
    'fill_not_enabled' => 'Vyplňování tažením není pro tuto tabulku povoleno',
    'fill_error' => 'Vyplnění selhalo: :error',
    'fill_partial' => 'Uloženo :filled z :total řádků',
    'fill_handle' => 'Tažením vyplníte řádky níže',
    'select_placeholder' => 'Vyberte...',
    'from' => 'Od',
    'to' => 'Do',
    'confirm_heading' => 'Potvrdit akci',
    'confirm_description' => 'Opravdu chcete provést tuto akci?',
    'confirm_submit' => 'Potvrdit',
    'confirm_cancel' => 'Zrušit',
    'confirm_close' => 'Zavřít',

    // Empty state
    'empty_heading' => 'Žádné záznamy',
    'empty_description' => 'Nebyly nalezeny žádné záznamy odpovídající vašemu vyhledávání.',
    'empty_no_columns' => 'Žádné sloupce k zobrazení',
    'empty_no_columns_hint' => 'Vyberte alespoň jeden sloupec pomocí tlačítka výše.',
    'empty_filter_heading' => 'Nic nenalezeno',
    'empty_no_records_match' => 'Žádné záznamy neodpovídají vašemu hledání. Zkuste upravit filtry.',

    // Filters
    'filters' => 'Filtry',
    'filter_all' => 'Vše',
    'filter_without_trashed' => 'Bez smazaných',
    'filter_with_trashed' => 'Včetně smazaných',
    'filter_only_trashed' => 'Jen smazané',
    'filter_search' => 'Hledat...',
    'filter_no_results' => 'Nic nenalezeno',
    'filter_selected_count' => '{1}:count vybrán|[2,4]:count vybrány|[5,*]:count vybráno',
    'filter_yes' => 'Ano',
    'filter_no' => 'Ne',
    'filter_min' => 'Min',
    'filter_max' => 'Max',
    'filter_label' => 'Filtr:',
    'filter_placeholder' => 'Filtr...',
    'filter_reset' => 'Resetovat filtry',
    'filter_reset_column' => 'Resetovat filtry sloupců',
    'filter_remove' => 'Odebrat filtr',

    // Summary
    'summary_sum' => 'Součet',
    'summary_avg' => 'Průměr',
    'summary_count' => 'Počet',
    'summary_min' => 'Min',
    'summary_max' => 'Max',
    'summary_range' => 'Rozsah',
    'summary_total' => 'Celkem',
    'summary_distinct' => 'Různých',
    'summary_median' => 'Medián',
    'summary_variance' => 'Rozptyl',
    'summary_stddev' => 'Sm. odchylka',
    'summary_first' => 'První',
    'summary_last' => 'Poslední',
    'summary_scope_label' => 'Zobrazeno:',
    'summary_scope_query' => 'Vše',
    'summary_scope_page' => 'Tato stránka',
    'summary_scope_selection' => 'Výběr',
    'summary_subtotal' => 'Mezisoučet',

    // Column
    'copied' => 'Zkopírováno!',
    'copy' => 'Kopírovat',
    'actions_label' => 'Akce',
    'toggle_columns' => 'Přepnout sloupce',
    'reset_columns' => 'Obnovit sloupce',
    'view_options' => 'Zobrazení',
    'columns_section' => 'Sloupce',
    'details_section' => 'Detaily',
    'views_section' => 'Uložené pohledy',
    'save_current_view' => 'Uložit aktuální pohled…',
    'save_view_prompt' => 'Pojmenujte pohled',
    'delete_view' => 'Smazat pohled',
    'expand_all_rows' => 'Rozbalit u všech řádků',
    'export_label' => 'Export',
    'import_queued' => 'Import běží na pozadí.',
    'import_label' => 'Import',
    'import_result' => 'Naimportováno :imported řádků, :failed selhalo.',

    'bulk_too_many' => 'Výběr :count záznamů překračuje limit :max pro jednu akci. Zužte filtr nebo zvyšte bulkMaxRecords().',

    // Selection
    'select_all' => 'Vybrat vše',
    'select_row' => 'Vybrat řádek',
    'deselect' => 'Zrušit výběr',
    'selection_on_page' => 'Vybráno :count.',
    'selection_all_matching' => 'Vybráno všech :count záznamů odpovídajících filtru.',
    'selection_select_all_matching' => 'Vybrat všech :count',
    'selection_only_this_page' => 'Jen tuto stránku',
    'select_all_on_page' => 'Vybrat vše na této stránce',
    'selection_page_of_total' => ':page na této stránce · :total celkem',
    // Hlášky pro čtečky obrazovky (live region výběru).
    'selection_announce_some' => 'Vybráno :count z :total',
    'selection_announce_all' => 'Vybráno vše (:total)',
    'selection_announce_none' => 'Výběr zrušen',
    'selection_selected_of_total' => 'vybráno :count z :total',

    // Pagination
    'show' => 'Zobrazit',
    'showing' => 'Zobrazuje se',
    'of' => 'z',
    'records' => 'záznamů',
    'pagination_navigation' => 'Stránkování',
    'pagination_previous' => 'Předchozí stránka',
    'pagination_next' => 'Další stránka',
    'pagination_goto' => 'Přejít na stránku :page',

    'sort_by' => 'Řadit podle',
    'sort_asc' => 'vzestupně',
    'sort_desc' => 'sestupně',

    // Search
    'search' => 'Hledat',

    // Loading
    'loading_table' => 'Načítání tabulky...',

    // Polling
    'paused' => 'Pozastaveno',
    'start' => 'Spustit',
    'stop' => 'Zastavit',

    // Sub-rows
    'expand' => 'Rozbalit',
    'collapse' => 'Sbalit',
    'expand_all' => 'Rozbalit vše',
    'collapse_all' => 'Sbalit vše',
    'reset' => 'Reset',
    'no_sub_rows' => 'Žádné podzáznamy',
    'details' => 'Detail',
    'actions' => 'Akce',
    'row_touch_menu' => 'Další akce',
    'sub_rows_count' => '{1}:count položka|[2,4]:count položky|[5,*]:count položek',
    'show_more_count' => 'Zobrazit dalších :count',

    // Inline editable
    'save_failed' => 'Uložení selhalo',
    'invalid' => 'Neplatné',
    'rating_of_max' => ':rating z :max',
    'error' => 'Chyba',

    // Legenda klávesových zkratek
    'shortcuts_heading' => 'Klávesové zkratky',
    'shortcuts_description' => 'Fungují, když je vybraný řádek.',
    'shortcuts_navigation' => 'Navigace',
    'shortcuts_selection' => 'Výběr',
    'shortcuts_actions' => 'Akce',
    'shortcuts_help' => 'Nápověda',
    'shortcut_move_active' => 'Přesunout aktivní řádek',
    'shortcut_jump_edges' => 'Skočit na první / poslední řádek',
    'shortcut_page_step' => 'Posun o stránku nahoru / dolů',
    'shortcut_toggle_selection' => 'Označit nebo odznačit aktivní řádek',
    'shortcut_extend_selection' => 'Rozšířit výběr',
    'shortcut_extend_selection_edges' => 'Rozšířit výběr k prvnímu / poslednímu řádku',
    'shortcut_select_page' => 'Vybrat celou stránku',
    'shortcut_run_action' => 'Spustit „:action“',
    'shortcut_context_menu' => 'Otevřít nabídku řádku',
    'shortcut_show_help' => 'Zobrazit klávesové zkratky',

    // Frontované exporty ({@see Export\Jobs\RunExportJob}).
    'export_queued' => 'Export se připravuje na pozadí.',
    'export_ready' => 'Export je hotový: :file',
    'unsaved_edits' => 'Některé změny ještě nejsou uložené. Opravdu odejít?',
];
