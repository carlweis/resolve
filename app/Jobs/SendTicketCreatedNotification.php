<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Mail\TicketCreated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTicketCreatedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function handle()
    {
        // Send notification to customer
        Mail::to($this->ticket->customer->email)
            ->send(new TicketCreated($this->ticket));

        // Also notify administrators
        $admins = \App\Models\User::where('role', 'admin')
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)
                ->send(new TicketCreated($this->ticket, true));
        }
    }
}

