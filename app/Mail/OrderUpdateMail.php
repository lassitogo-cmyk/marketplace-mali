<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $titre,
        public string $corps,
        public ?Order $order = null,
        public ?User $destinataire = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->titre.' — Marketplace Mali',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order-update',
        );
    }
}
