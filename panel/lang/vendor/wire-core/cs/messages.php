<?php

declare(strict_types=1);

return [
    'breadcrumbs' => 'Drobečková navigace',

    // Dotykový seznam, kterým se select stane na telefonu (->touchOnMobile()).
    'select_done' => 'Hotovo',
    'select_clear_all' => 'Zrušit výběr',
    'select_selected' => 'Vybráno: :count',
    // Sdílené hlášky inline editace (editovatelné panel entry).
    'error' => 'Něco se pokazilo.',
    'save_failed' => 'Uložení se nezdařilo. Zkuste to prosím znovu.',
    'save_error' => 'Uložení se nezdařilo: :error',
    'validation_failed' => 'Hodnota není platná.',
    'record_conflict' => 'Záznam byl mezitím změněn jinde. Zobrazena je aktuální hodnota.',
    'record_not_found' => 'Záznam nebyl nalezen.',
    'entry_not_editable' => 'Toto pole nelze upravovat.',
    'no_permission' => 'Nemáte oprávnění upravovat toto pole.',
    'no_permission_edit' => 'Nemáte oprávnění upravovat tento záznam.',

    // The copy-to-clipboard affordance ({@see Foundation\View\CopyButton}).
    'copy' => 'Kopírovat',
    'copied' => 'Zkopírováno!',

    // Zvoneček notifikací ({@see Notifications\NotificationBell}).
    'notifications' => 'Oznámení',
    'no_notifications' => 'Zatím tu nic není.',
    'mark_read' => 'Označit jako přečtené',
    'mark_all_read' => 'Označit vše',
    'notifications_all' => 'Vše',
    'notifications_unread' => 'Nepřečtené',
    'no_unread_notifications' => 'Nic nepřečteného.',
    'view_all_notifications' => 'Zobrazit vše',
    'mark_unread' => 'Označit jako nepřečtené',
    'delete_notification' => 'Smazat',
    'clear_read' => 'Uklidit přečtené',
    'notifications_today' => 'Dnes',
    'notifications_yesterday' => 'Včera',
    'notifications_earlier' => 'Dříve',
    'notifications_all_read' => 'Vše přečteno',

    // Frontované akce ({@see Actions\Concerns\Queueable}).
    'action_queued' => ':action běží na pozadí.',
    'action_queued_done' => ':action doběhla.',

    // Tři stavy Foundation\Enums\Theme. `System` je taky volba — je to to, kam
    // se musí dát vrátit, aby notebook, který si večer sám ztmaví, dál platil.
    'theme_light' => 'Světlý',
    'theme_system' => 'Systém',
    'theme_dark' => 'Tmavý',

    'shape_rounded' => 'Zaoblený',
    'shape_sharp' => 'Hranatý',
    'density' => 'Hustota',
    'density_normal' => 'Normální',
    'density_compact' => 'Kompaktní',

    // Čím soubor je — rodina, ne formát ({@see Foundation\Enums\FileKind}).
    // Ukáže se tam, kde soubor nemá náhled a jeho název nemá příponu, kterou by
    // šlo vypsat místo toho.
    'file_kinds' => [
        'image' => 'Obrázek',
        'video' => 'Video',
        'audio' => 'Zvuk',
        'document' => 'Dokument',
        'spreadsheet' => 'Tabulka',
        'presentation' => 'Prezentace',
        'archive' => 'Archiv',
        'code' => 'Kód',
        'other' => 'Soubor',
    ],

    // Mřížka dashboardu ({@see Widgets\Widget}). `widget_loading` se oznamuje
    // odečítači obrazovky, dokud se odložený widget načítá; prázdný stav je to,
    // co ukáže seznamový widget, když se dotaz vrátil bez záznamů.
    'widget_loading' => 'Načítání…',
    'widget_filter' => 'Filtr',
    'widget_empty' => 'Není co zobrazit.',
    'widget_reorder' => 'Přetažením změníte pořadí',
    'widget_tray' => 'Dostupné widgety',
    'widget_tray_empty' => 'Všechno je na dashboardu.',
    'widget_add' => 'Přidat na dashboard',
    'widget_remove' => 'Odebrat z dashboardu',
    'widget_wider' => 'Širší',
    'widget_narrower' => 'Užší',
    'widget_taller' => 'Vyšší',
    'widget_shorter' => 'Nižší',
    'widget_customise' => 'Upravit',
    'widget_save_layout' => 'Uložit rozložení',
    'widget_cancel_layout' => 'Zrušit',
    'widget_reset_layout' => 'Zpět na výchozí',
    'widget_saved_layouts' => 'Uložená rozložení',
    'widget_saved_layout_current' => 'Aktuální rozložení',
    'widget_save_layout_as' => 'Uložit jako…',
    'widget_save_layout_prompt' => 'Pojmenujte rozložení',
    'widget_delete_layout' => 'Smazat uložené rozložení',
    'widget_size' => 'Velikost',
    'widget_done_layout' => 'Hotovo',
    'widget_limit_reached' => 'Na nástěnku se vejde nejvýš :max widgetů. Nejdřív nějaký odeberte.',
    'widget_filters_reset' => 'Zrušit filtr',
    'widget_ignores_filters' => 'Bez filtru',

    // The guided walkthrough (Tours/).
    'tour_next' => 'Další',
    'tour_back' => 'Zpět',
    'tour_finish' => 'Hotovo',
    'tour_skip' => 'Přeskočit',
    'tour_progress' => 'Krok :current z :total',
    'tour_region' => 'Průvodce',
    'tour_start' => 'Spustit průvodce',
    'tour_later' => 'Třeba později',
    'tour_replay' => 'Spustit průvodce znovu',
    'unsaved_edits' => 'Některé změny ještě nejsou uložené. Opravdu odejít?',
];
