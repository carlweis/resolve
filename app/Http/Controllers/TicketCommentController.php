<?php

// app/Http/Controllers/TicketCommentController.php
namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketLog;
use App\Services\TicketCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketCommentController extends Controller
{
    protected $ticketCacheService;

    public function __construct(TicketCacheService $ticketCacheService)
    {
        $this->ticketCacheService = $ticketCacheService;
    }

    /**
     * Store a newly created comment in storage.
     */
    public function store(Request $request, Ticket $ticket)
    {
        $this->authorize('comment', $ticket);

        $validated = $request->validate([
            'content' => 'required|string',
            'is_private' => 'boolean',
        ]);

        // Only agents can add private comments
        $isPrivate = Auth::user()->isAgent() && isset($validated['is_private']) && $validated['is_private'];

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'content' => $validated['content'],
            'is_private' => $isPrivate,
        ]);

        // Update ticket's last_responded_at timestamp
        $ticket->update([
            'last_responded_at' => now(),
        ]);

        // If an agent responds to a customer and status is 'open',
        // automatically change status to 'in_progress'
        if (Auth::user()->isAgent() && $ticket->status === 'open') {
            $ticket->update([
                'status' => 'in_progress',
                'agent_id' => Auth::user()->isAgent() ? Auth::id() : $ticket->agent_id,
            ]);

            // Log status change
            TicketLog::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'status_changed',
                'details' => json_encode([
                    'from' => 'open',
                    'to' => 'in_progress',
                ]),
            ]);
        }

        // Log comment addition
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'action' => $isPrivate ? 'added_private_note' : 'added_comment',
        ]);

        // Clear cache
        $this->ticketCacheService->clearTicketCache($ticket);

        return redirect()->back();
    }
}
