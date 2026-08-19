<?php

namespace App\Mail;

use App\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Notification à envoyer par email.
     */
    public function __construct(
        public AppNotification $notification
    ) {
    }

    /**
     * Sujet de l'email.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->titre,
        );
    }

    /**
     * Contenu de l'email.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notifications',
        );
    }

    /**
     * Pièces jointes.
     */
    public function attachments(): array
    {
        return [];
    }
}