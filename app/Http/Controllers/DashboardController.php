<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected $ticketCacheService;

    public function __construct(TicketCacheService $ticketCacheService)
    {
        $this->ticketCacheService = $ticketCacheService;
    }

    public function index()
    {
        $user = Auth::user();

        // Basic stats
        $stats = $this->ticketCacheService->getTicketStatistics();

        // Tickets by status for chart
        $ticketsByStatus = [
            'open' => $stats['open'],
            'in_progress' => $stats['in_progress'],
            'resolved' => $stats['resolved'],
            'closed' => $stats['closed'],
        ];

        // Recent tickets
        $recentTickets = Ticket::with(['customer:id,name', 'agent:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Tickets by priority for admins/agents
        $ticketsByPriority = [];
        if ($user->isAgent()) {
            $ticketsByPriority = Ticket::whereIn('status', ['open', 'in_progress'])
                ->select('priority', DB::raw('count(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();
        }

        // Tickets created over time (last 30 days)
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $ticketsOverTime = Ticket::where('created_at', '>=', $thirtyDaysAgo)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->date => $item->count];
            });

        // Fill in missing dates with zero counts
        $dateRange = new \DatePeriod(
            $thirtyDaysAgo,
            new \DateInterval('P1D'),
            Carbon::now()
        );

        $dateData = [];
        foreach ($dateRange as $date) {
            $dateString = $date->format('Y-m-d');
            $dateData[$dateString] = $ticketsOverTime[$dateString] ?? 0;
        }

        $agentPerformance = [];
        if ($user->isAdmin()) {
            // Get agent performance data
            $agentPerformance = User::where('role', 'agent')
                ->withCount(['assignedTickets as total_tickets'])
                ->withCount([
                    'assignedTickets as resolved_tickets' => function ($query) {
                        $query->where('status', 'resolved');
                    }
                ])
                ->withCount([
                    'assignedTickets as open_tickets' => function ($query) {
                        $query->whereIn('status', ['open', 'in_progress']);
                    }
                ])
                ->having('total_tickets', '>', 0)
                ->get()
                ->map(function ($agent) {
                    $agent['resolution_rate'] = $agent->total_tickets > 0
                        ? round(($agent->resolved_tickets / $agent->total_tickets) * 100, 1)
                        : 0;
                    return $agent;
                });
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'ticketsByStatus' => $ticketsByStatus,
            'ticketsByPriority' => $ticketsByPriority,
            'recentTickets' => $recentTickets,
            'ticketsOverTime' => $dateData,
            'agentPerformance' => $agentPerformance,
            'userRole' => $user->role,
        ]);
    }
}
