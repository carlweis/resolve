<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\User;
use App\Mail\TicketAssigned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTicketAssignedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ticket;
    protected $agent;

    public function __construct(Ticket $ticket, User $agent)
    {
        $this->ticket = $ticket;
        $this->agent = $agent;
    }

    public function handle()
    {
        // Notify the agent
        Mail::to($this->agent->email)
            ->send(new TicketAssigned($this->ticket, $this->agent));
    }
}
