<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Models\TicketLog;
use App\Models\TicketComment;
use App\Jobs\AssignTicketToAgent;
use App\Jobs\SendTicketCreatedNotification;
use App\Services\TicketCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TicketController extends Controller
{
    protected $ticketCacheService;

    public function __construct(TicketCacheService $ticketCacheService)
    {
        $this->ticketCacheService = $ticketCacheService;
    }

    /**
     * Display a listing of tickets based on user role.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $status = $request->query('status', 'all');
        $search = $request->query('search', '');

        // Base query
        $query = Ticket::query()
            ->with(['customer:id,name,email', 'agent:id,name,email']);

        // Apply status filter
        if ($status !== 'all' && in_array($status, ['open', 'in_progress', 'resolved', 'closed'])) {
            $query->where('status', $status);
        }

        // Apply search if provided
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Apply role-based filters
        if ($user->isAdmin()) {
            // Admins see all tickets
        } elseif ($user->isAgent()) {
            // Agents see tickets assigned to them
            $query->where('agent_id', $user->id);
        } else {
            // Customers see their own tickets
            $query->where('user_id', $user->id);
        }

        // Final ordering and pagination
        $tickets = $query->orderBy('updated_at', 'desc')
                        ->paginate(10)
                        ->withQueryString();

        // Get ticket counts for dashboard stats
        $stats = $this->ticketCacheService->getTicketStatistics();

        // Get agents for the assignment dropdown (admins only)
        $agents = [];
        if ($user->isAdmin()) {
            $agents = User::where('role', 'agent')
                        ->where('is_active', true)
                        ->select('id', 'name', 'email')
                        ->get();
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'stats' => $stats,
            'agents' => $agents,
            'can' => [
                'createTicket' => $user->can('create', Ticket::class),
                'viewAllTickets' => $user->isAdmin(),
                'assignTickets' => $user->isAdmin(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new ticket.
     */
    public function create()
    {
        $this->authorize('create', Ticket::class);

        return Inertia::render('Tickets/Create', [
            'priorities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
                ['value' => 'urgent', 'label' => 'Urgent'],
            ],
        ]);
    }

    /**
     * Store a newly created ticket in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Ticket::class);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $ticket = Ticket::create([
            'user_id' => Auth::id(),
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'status' => 'open',
        ]);

        // Log ticket creation
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'action' => 'created',
        ]);

        // Dispatch jobs
        SendTicketCreatedNotification::dispatch($ticket);
        AssignTicketToAgent::dispatch($ticket);

        return redirect()->route('tickets.show', $ticket->id);
    }

    /**
     * Display the specified ticket.
     */
    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'customer:id,name,email',
            'agent:id,name,email',
            'comments' => function ($query) {
                $query->with('user:id,name,role')
                      ->orderBy('created_at', 'asc');
            },
            'logs' => function ($query) {
                $query->with('user:id,name,role')
                      ->orderBy('created_at', 'desc');
            }
        ]);

        $user = Auth::user();

        return Inertia::render('Tickets/Show', [
            'ticket' => $ticket,
            'can' => [
                'update' => $user->can('update', $ticket),
                'comment' => $user->can('comment', $ticket),
                'assignAgent' => $user->can('assign', $ticket),
                'changeStatus' => $user->can('changeStatus', $ticket),
                'addPrivateNote' => $user->isAgent(),
            ],
            'statusOptions' => [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'in_progress', 'label' => 'In Progress'],
                ['value' => 'resolved', 'label' => 'Resolved'],
                ['value' => 'closed', 'label' => 'Closed'],
            ],
            'agents' => $user->isAdmin() ? User::where('role', 'agent')
                ->where('is_active', true)
                ->select('id', 'name', 'email')
                ->get() : [],
        ]);
    }

    /**
     * Update the ticket status.
     */
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $this->authorize('changeStatus', $ticket);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $oldStatus = $ticket->status;
        $newStatus = $validated['status'];

        $updateData = [
            'status' => $newStatus,
        ];

        // Set timestamp based on the status
        if ($newStatus === 'resolved' && $oldStatus !== 'resolved') {
            $updateData['resolved_at'] = now();
        } elseif ($newStatus === 'closed' && $oldStatus !== 'closed') {
            $updateData['closed_at'] = now();
        }

        $ticket->update($updateData);

        // Clear the cache
        $this->ticketCacheService->clearTicketCache($ticket);

        // Log the status change
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'action' => 'status_changed',
            'details' => json_encode([
                'from' => $oldStatus,
                'to' => $newStatus,
            ]),
        ]);

        return redirect()->back();
    }

    /**
     * Assign a ticket to an agent.
     */
    public function assignToAgent(Request $request, Ticket $ticket)
    {
        $this->authorize('assign', $ticket);

        $validated = $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $ticket->update([
            'agent_id' => $validated['agent_id'],
            'status' => 'in_progress',
        ]);

        // Clear the cache
        $this->ticketCacheService->clearTicketCache($ticket);

        // Log the assignment
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'action' => 'manually_assigned',
            'details' => json_encode([
                'agent_id' => $validated['agent_id'],
            ]),
        ]);

        return redirect()->back();
    }
}

