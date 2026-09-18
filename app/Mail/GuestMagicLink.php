<?php

namespace App\Mail;

use App\Models\PropOff\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The magic link a password-less guest uses to get back into their entry.
 *
 * It was previously only shown on screen once, at registration — which is
 * exactly how the Super Bowl LX guests lost theirs and re-registered under the
 * same name. Emailing it gives the link a life beyond the phone lock screen.
 */
class GuestMagicLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Event $event,
        public string $magicLink,
    ) {
    }

    public function envelope(): Envelope
    {
        $replyTo = config('mail.reply_to.address')
            ? [new Address(config('mail.reply_to.address'), config('mail.reply_to.name'))]
            : [];

        return new Envelope(
            subject: "Your link for {$this->event->name}",
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.guest_magic_link',
            with: [
                'user'      => $this->user,
                'event'     => $this->event,
                'magicLink' => $this->magicLink,
            ],
        );
    }
}
