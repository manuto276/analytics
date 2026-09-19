<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\User;
use Analytics\Shared\Mail\MailLayout;
use Analytics\Shared\Mail\MailMessage;

/**
 * The account messages, in the recipient's locale (en, it). Every message is multipart text + HTML
 * (see MailLayout for what the HTML may and may not contain).
 *
 * Wording rules: say which service sent it (the host), what happened, what to do, and what happens
 * if the recipient does nothing. Never include a secret other than the one-time link.
 */
final readonly class IdentityMails
{
    private const array STRINGS = [
        'en' => [
            'footer' => 'Sent by the analytics service at {host} because of an action on an account. This message contains no tracking.',
            'link_fallback' => 'If the button does not work, copy this link into your browser:',
            'reset.subject' => 'Reset your password on {host}',
            'reset.heading' => 'Reset your password',
            'reset.intro' => 'Someone asked to reset the password of the account {email} on {host}.',
            'reset.lead' => 'Open the link within one hour to choose a new password. It works once.',
            'reset.action' => 'Choose a new password',
            'reset.outro' => 'If this was not you, ignore this email: your password stays as it is.',
            'change.subject' => 'Confirm your new email address for {host}',
            'change.heading' => 'Confirm your new email address',
            'change.intro' => 'You asked to sign in to {host} with this address from now on.',
            'change.lead' => 'Open the link within 24 hours to confirm the change. It works once.',
            'change.action' => 'Confirm this address',
            'change.outro' => 'Until you confirm, nothing changes and the account keeps signing in with its current address. If you did not ask for this, ignore this email.',
            'changed.subject' => 'The sign-in email address of your account on {host} was changed',
            'changed.heading' => 'Your sign-in address was changed',
            'changed.intro' => 'On {date} (UTC) the email address of your account on {host} was changed from {old} to {new}.',
            'changed.sessions' => 'Every other session of the account was signed out.',
            'changed.outro' => 'If you did not make this change, contact an administrator of {host} right away. This address no longer receives password reset links for the account.',
        ],
        'it' => [
            'footer' => 'Inviato dal servizio di analytics su {host} in seguito a un\'azione su un account. Questo messaggio non contiene alcun tracciamento.',
            'link_fallback' => 'Se il pulsante non funziona, copia questo link nel browser:',
            'reset.subject' => 'Reimposta la password su {host}',
            'reset.heading' => 'Reimposta la password',
            'reset.intro' => 'È stato chiesto di reimpostare la password dell\'account {email} su {host}.',
            'reset.lead' => 'Apri il link entro un\'ora per scegliere una nuova password. Funziona una sola volta.',
            'reset.action' => 'Scegli una nuova password',
            'reset.outro' => 'Se non sei stato tu, ignora questa email: la password resta invariata.',
            'change.subject' => 'Conferma il nuovo indirizzo email per {host}',
            'change.heading' => 'Conferma il nuovo indirizzo email',
            'change.intro' => 'Hai chiesto di accedere a {host} con questo indirizzo d\'ora in poi.',
            'change.lead' => 'Apri il link entro 24 ore per confermare la modifica. Funziona una sola volta.',
            'change.action' => 'Conferma questo indirizzo',
            'change.outro' => 'Finché non confermi non cambia nulla e l\'account continua ad accedere con l\'indirizzo attuale. Se non l\'hai chiesto tu, ignora questa email.',
            'changed.subject' => 'L\'indirizzo email di accesso del tuo account su {host} è stato cambiato',
            'changed.heading' => 'L\'indirizzo di accesso è stato cambiato',
            'changed.intro' => 'Il {date} (UTC) l\'indirizzo email del tuo account su {host} è stato cambiato da {old} a {new}.',
            'changed.sessions' => 'Tutte le altre sessioni dell\'account sono state chiuse.',
            'changed.outro' => 'Se non sei stato tu, contatta subito un amministratore di {host}. Questo indirizzo non riceve più i link per reimpostare la password dell\'account.',
        ],
    ];

    public function __construct(private string $appUrl) {}

    public function passwordReset(User $user, string $token): MailMessage
    {
        $t = $this->translator($user->locale, ['{email}' => $user->email]);

        return MailLayout::render(
            to: $user->email,
            subject: $t('reset.subject'),
            locale: self::locale($user->locale),
            heading: $t('reset.heading'),
            paragraphs: [$t('reset.intro'), $t('reset.lead')],
            action: ['label' => $t('reset.action'), 'url' => $this->appUrl . '/password/reset/' . $token],
            actionFallback: $t('link_fallback'),
            after: [$t('reset.outro')],
            footer: $t('footer'),
        );
    }

    /** Sent to the NEW address, in the locale of the user who asked for the change. */
    public function emailChangeConfirmation(User $user, string $newEmail, string $token): MailMessage
    {
        $t = $this->translator($user->locale, []);

        return MailLayout::render(
            to: $newEmail,
            subject: $t('change.subject'),
            locale: self::locale($user->locale),
            heading: $t('change.heading'),
            paragraphs: [$t('change.intro'), $t('change.lead')],
            action: ['label' => $t('change.action'), 'url' => $this->appUrl . '/account/email/confirm?token=' . $token],
            actionFallback: $t('link_fallback'),
            after: [$t('change.outro')],
            footer: $t('footer'),
        );
    }

    /** Sent to the OLD address once the change is applied. */
    public function emailChanged(User $user, string $oldEmail, \DateTimeImmutable $when): MailMessage
    {
        $t = $this->translator($user->locale, [
            '{old}' => $oldEmail,
            '{new}' => $user->email,
            '{date}' => $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'),
        ]);

        return MailLayout::render(
            to: $oldEmail,
            subject: $t('changed.subject'),
            locale: self::locale($user->locale),
            heading: $t('changed.heading'),
            paragraphs: [$t('changed.intro'), $t('changed.sessions')],
            action: null,
            actionFallback: '',
            after: [$t('changed.outro')],
            footer: $t('footer'),
        );
    }

    private static function locale(string $locale): string
    {
        return isset(self::STRINGS[$locale]) ? $locale : 'en';
    }

    /**
     * @param array<string, string> $vars
     *
     * @return \Closure(string): string
     */
    private function translator(string $locale, array $vars): \Closure
    {
        $strings = self::STRINGS[self::locale($locale)];
        $vars += ['{host}' => strtolower((string) parse_url($this->appUrl, \PHP_URL_HOST))];

        return static fn(string $key): string => strtr($strings[$key], $vars);
    }
}
