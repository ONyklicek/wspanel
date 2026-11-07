<?php

declare(strict_types=1);

return [
    // Cesta ven, v uživatelském menu shellu.
    'sign_out' => 'Odhlásit se',

    // Pole, která sdílí každá obrazovka, kde jsou.
    'name' => 'Jméno',
    'email' => 'E-mail',
    'password' => 'Heslo',
    'confirm_password' => 'Potvrzení hesla',
    'new_password' => 'Nové heslo',

    // Přihlášení.
    'sign_in' => 'Přihlásit se',
    'sign_in_heading' => 'Přihlášení',
    'sign_in_description' => 'Zadejte přihlašovací údaje a dostanete se do panelu.',
    'remember_me' => 'Zůstat přihlášen',
    'forgot_password' => 'Zapomněli jste heslo?',

    // Založení účtu.
    'register' => 'Vytvořit účet',
    'register_heading' => 'Vytvoření účtu',
    'register_description' => 'Stačí e-mailová adresa a heslo.',
    'already_registered' => 'Už účet máte?',

    // Žádost o odkaz na obnovu.
    'forgot_heading' => 'Obnova hesla',
    'forgot_description' => 'Napište adresu, kterou se přihlašujete, a pošleme na ni odkaz pro nastavení nového hesla.',
    'send_reset_link' => 'Odeslat odkaz',
    'back_to_sign_in' => 'Zpět na přihlášení',

    // Nastavení nového hesla.
    'reset_heading' => 'Nové heslo',
    'reset_description' => 'Zvolte heslo, které jste tu ještě nepoužili.',
    'reset_password' => 'Uložit heslo',

    // Potvrzení adresy.
    'verify_heading' => 'Potvrďte svou e-mailovou adresu',
    'verify_description' => 'Poslali jsme odkaz na adresu, se kterou jste se registrovali. Otevřete ho a jste uvnitř.',
    'verify_sent' => 'Nový odkaz je na cestě.',
    'resend_verification' => 'Poslat znovu',

    // Potvrzení hesla před něčím citlivým.
    'confirm_heading' => 'Potvrďte heslo',
    'confirm_description' => 'Tahle část panelu se před otevřením ještě jednou zeptá na heslo.',
    'confirm' => 'Potvrdit',

    // Druhý faktor.
    'two_factor_heading' => 'Dvoufázové ověření',
    'two_factor_description' => 'Zadejte kód z autentizační aplikace.',
    'two_factor_recovery_description' => 'Zadejte jeden ze záložních kódů, které jste si uložili při nastavení.',
    'code' => 'Kód',
    'recovery_code' => 'Záložní kód',
    'use_recovery_code' => 'Použít záložní kód',
    'use_authentication_code' => 'Použít kód z aplikace',

    // Jednorázové kódy, všude, kde kód zastupuje něco jiného (ADR 0037).
    'code_login_heading' => 'Přihlášení kódem',
    'code_login_description' => 'Zadejte adresu, kterou se přihlašujete, a pošleme vám kód.',
    'code_login_link' => 'Přihlásit se kódem',
    'code_send' => 'Poslat kód',
    'code_heading' => 'Zadejte kód',
    'code_description' => 'Opište kód z e-mailu, který jsme právě odeslali.',
    'code_description_address' => 'Opište kód, který jsme poslali na :address.',
    'code_continue' => 'Pokračovat',
    'code_resend' => 'Poslat znovu',
    'code_restart' => 'Vyžádat nový kód',
    'code_sent' => 'Pokud u nás taková adresa je, kód už je na cestě.',
    'code_sent_recently' => 'Kód právě odešel — chvíli počkejte, než si vyžádáte další.',
    'code_invalid' => 'Kód je špatně nebo už vypršel.',
    'code_verify_link' => 'Zadat kód místo odkazu',
    'code_reset_description' => 'Opište kód z e-mailu a zvolte heslo, které jste tu ještě nepoužili.',

    // E-mail, ve kterém kód přijde. Pro každý účel vlastní text: jedna šablona
    // pro všechny čtyři případy nezní dobře ani v jednom.
    'code_mail' => [
        'expires' => 'Kód platí :minutes minut.',
        'ignore' => 'Pokud jste o nic nežádali, tuto zprávu můžete ignorovat.',
        'login' => [
            'subject' => 'Váš přihlašovací kód',
            'line' => 'Tímto kódem se přihlásíte:',
        ],
        'second_factor' => [
            'subject' => 'Váš přihlašovací kód',
            'line' => 'Heslo sedělo. Tady je druhá půlka:',
        ],
        'verify_email' => [
            'subject' => 'Potvrďte svou e-mailovou adresu',
            'line' => 'Tento kód zadejte na potvrzovací obrazovce:',
        ],
        'reset_password' => [
            'subject' => 'Kód pro obnovu hesla',
            'line' => 'Tímto kódem si nastavíte nové heslo:',
        ],
    ],

    // Passkeys tam, kde je Fortify routuje.
    'passkey_sign_in' => 'Přihlásit se passkeyem',
    'passkey_failed' => 'S tímhle passkeyem to nešlo. Zkuste to znovu, nebo se přihlaste heslem.',
    'passkey_cancelled' => 'Žádný passkey nebyl použit. Pokud ho k tomuto účtu ještě nemáte, přihlaste se heslem a přidejte si ho v nastavení svého účtu.',
];
