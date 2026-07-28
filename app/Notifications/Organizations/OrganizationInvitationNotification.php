<?php

namespace App\Notifications\Organizations;

use App\Enums\Organizations\OrganizationRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationName,
        private readonly OrganizationRole $role,
        private readonly string $plainTextToken,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = sprintf(
            '%s/invitations/accept?token=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            rawurlencode($this->plainTextToken),
        );

        return (new MailMessage)
            ->subject("Invitation to {$this->organizationName}")
            ->greeting('You have been invited to Procura.')
            ->line("Join {$this->organizationName} as {$this->role->value}.")
            ->action('Review invitation', $acceptUrl)
            ->line('This single-use invitation expires in seven days.');
    }
}
