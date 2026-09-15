<?php

namespace App\Mail;

use App\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public AppNotification $notification;

    public function __construct(AppNotification $notification)
    {
        $this->notification = $notification;
    }

    public function build()
    {
         return $this->subject($this->notification->titre)
                    ->view('emails.notifications')
                    ->with([
                        'titre'   => $this->notification->titre,
                        'contenu' => $this->notification->message,
                        'type'    => $this->notification->type,
                        'date'    => $this->notification->created_at->format('d/m/Y H:i'),
                    ]);
    }
}