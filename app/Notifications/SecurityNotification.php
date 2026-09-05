<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
class SecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(private readonly string $message) {}
    public function via(object $notifiable): array { return ['mail', 'database']; }
    public function toMail(object $notifiable): MailMessage { return (new MailMessage)->subject('College ERP security notification')->line($this->message); }
    public function toArray(object $notifiable): array { return ['message' => $this->message]; }
}
