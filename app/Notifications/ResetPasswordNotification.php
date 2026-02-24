<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $expire = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');

        return (new MailMessage())
            ->subject('Set your Bconnect password')
            ->greeting('Hello!')
            ->line('We received a request to set or reset your Bconnect password.')
            ->action('Set Password', $resetUrl)
            ->line("This link will expire in {$expire} minutes.")
            ->line('If you did not request this, no further action is required.')
            ->salutation('Regards, Bconnect');
    }
}
