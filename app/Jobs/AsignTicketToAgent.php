<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\User;
use App\Models\TicketLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AssignTicketToAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function handle()
    {
        // Skip if ticket is already assigned
        if ($this->ticket->agent_id) {
            return;
        }

        // Find the agent with the least number of active tickets
        $agent = User::where('role', 'agent')
            ->where('is_active', true)
            ->withCount(['assignedTickets' => function ($query) {
                $query->whereIn('status', ['open', 'in_progress']);
            }])
            ->orderBy('assigned_tickets_count')
            ->first();

        if ($agent) {
            // Assign the ticket to the agent
            $this->ticket->update([
                'agent_id' => $agent->id,
                'status' => 'in_progress',
            ]);

            // Create a log entry
            TicketLog::create([
                'ticket_id' => $this->ticket->id,
                'action' => 'assigned',
                'details' => json_encode([
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                ]),
            ]);

            // Dispatch notification job
            SendTicketAssignedNotification::dispatch($this->ticket, $agent);
        }
    }
}
