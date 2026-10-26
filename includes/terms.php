<?php
/**
 * SmartClass - the service terms a customer's admin accepts on first sign-in (loaded by includes/functions.php)
 * Only customer installations ask for it (never the original system). Changing TERMS_VERSION asks everyone again.
 * The wording is a plain-language summary of what the provider and the customer agree; have a lawyer review it
 * once before the first paying customer.
 */
const TERMS_VERSION = '1';

function termsAccepted() {
    return getSetting('terms_version', '') === TERMS_VERSION && getSetting('terms_accepted_at', '') !== '';
}

function termsParagraphs() {
    return [
        ['Your data is yours — and your responsibility', 'You decide what is entered into this system (people, classes, work, grades, messages). You are responsible for that data, for having the right to hold it, and for the permission of the students and parents it concerns.'],
        ['What the provider does', 'The provider hosts the software for you as it is, keeps it updated for everyone at the same time, and keeps reasonable security. Backups are made on a best-effort basis; you can download a complete backup of your own data at any time from Settings › Backup.'],
        ['What the provider can control', 'The provider can pause this installation (for example when a service is not paid for) and can switch off services that run through the provider — AI helpers, email, file uploads, new sign-ups. Your own settings, and anything you connect yourself (your own AI key or email account), stay yours. While paused your admin can still sign in and download all data.'],
        ['Support access', 'For support the provider may sign in to your admin side for a limited time. Every such access is written to your activity log and to the provider’s own log, with the reason. The provider does not read your students’ content otherwise.'],
        ['Ending the service', 'If the service ends you will be told in advance and can export your data. After the notice period the installation and its data are deleted.'],
    ];
}
