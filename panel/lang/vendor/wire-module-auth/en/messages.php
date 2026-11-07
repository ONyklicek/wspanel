<?php

declare(strict_types=1);

return [
    // The way out, in the shell's user menu.
    'sign_out' => 'Sign out',

    // Fields, shared by every screen that has them.
    'name' => 'Name',
    'email' => 'E-mail',
    'password' => 'Password',
    'confirm_password' => 'Confirm password',
    'new_password' => 'New password',

    // Signing in.
    'sign_in' => 'Sign in',
    'sign_in_heading' => 'Sign in',
    'sign_in_description' => 'Enter your credentials to reach the panel.',
    'remember_me' => 'Stay signed in',
    'forgot_password' => 'Forgot your password?',

    // Creating an account.
    'register' => 'Create account',
    'register_heading' => 'Create an account',
    'register_description' => 'This takes an e-mail address and a password.',
    'already_registered' => 'Already have an account?',

    // Asking for a reset link.
    'forgot_heading' => 'Reset your password',
    'forgot_description' => 'Tell us the address you sign in with and we will send a link to set a new password.',
    'send_reset_link' => 'Send the link',
    'back_to_sign_in' => 'Back to sign in',

    // Setting a new one.
    'reset_heading' => 'Set a new password',
    'reset_description' => 'Choose a password you have not used here before.',
    'reset_password' => 'Save the password',

    // Confirming the address.
    'verify_heading' => 'Confirm your e-mail address',
    'verify_description' => 'We sent a link to the address you signed up with. Open it and you are in.',
    'verify_sent' => 'A new link is on its way.',
    'resend_verification' => 'Send it again',

    // Confirming the password before something sensitive.
    'confirm_heading' => 'Confirm your password',
    'confirm_description' => 'This part of the panel asks for your password again before it opens.',
    'confirm' => 'Confirm',

    // The second factor.
    'two_factor_heading' => 'Two-factor authentication',
    'two_factor_description' => 'Enter the code from your authenticator app.',
    'two_factor_recovery_description' => 'Enter one of the recovery codes you saved when you set this up.',
    'code' => 'Code',
    'recovery_code' => 'Recovery code',
    'use_recovery_code' => 'Use a recovery code instead',
    'use_authentication_code' => 'Use an authentication code instead',

    // One-time codes, wherever a code stands in for something else (ADR 0037).
    'code_login_heading' => 'Sign in with a code',
    'code_login_description' => 'Tell us the address you sign in with and we will mail you a code.',
    'code_login_link' => 'Sign in with a code instead',
    'code_send' => 'Mail me a code',
    'code_heading' => 'Enter your code',
    'code_description' => 'Type the code from the mail we just sent.',
    'code_description_address' => 'Type the code we sent to :address.',
    'code_continue' => 'Continue',
    'code_resend' => 'Send it again',
    'code_restart' => 'Ask for a new code',
    'code_sent' => 'If that address is one of ours, the code is on its way.',
    'code_sent_recently' => 'A code has just gone out — give it a moment before asking for another.',
    'code_invalid' => 'That code is wrong or has expired.',
    'code_verify_link' => 'Type a code instead',
    'code_reset_description' => 'Type the code from the mail, then choose a password you have not used here before.',

    // The mail each code arrives in. One wording per purpose: a shared template
    // that tries to be all four reads like none of them.
    'code_mail' => [
        'expires' => 'The code is good for :minutes minutes.',
        'ignore' => 'If you did not ask for this, you can ignore this message.',
        'login' => [
            'subject' => 'Your sign-in code',
            'line' => 'Here is the code that signs you in:',
        ],
        'second_factor' => [
            'subject' => 'Your sign-in code',
            'line' => 'Your password was right. Here is the second half:',
        ],
        'verify_email' => [
            'subject' => 'Confirm your e-mail address',
            'line' => 'Type this code on the confirmation screen:',
        ],
        'reset_password' => [
            'subject' => 'Your password reset code',
            'line' => 'Type this code to set a new password:',
        ],
    ],

    // Passkeys, where Fortify routes them.
    'passkey_sign_in' => 'Sign in with a passkey',
    'passkey_failed' => 'That passkey did not work. Try again, or sign in with your password.',
    'passkey_cancelled' => 'No passkey was used. If this account does not have one yet, sign in with your password and add one under your account.',
];
