<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Mail\TicketReminder;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class CheckStaleTickets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        // Find tickets that haven't been responded to in 24 hours
        $staleTickets = Ticket::whereIn('status', ['open', 'in_progress'])
            ->where(function ($query) {
                $query->whereNull('last_responded_at')
                    ->orWhere('last_responded_at', '<', Carbon::now()->subHours(24));
            })
            ->get();

        foreach ($staleTickets as $ticket) {
            if ($ticket->agent) {
                // Send reminder to the assigned agent
                Mail::to($ticket->agent->email)
                    ->send(new TicketReminder($ticket));
            } else {
                // If no agent is assigned, assign one
                AssignTicketToAgent::dispatch($ticket);
            }
        }
    }
}
