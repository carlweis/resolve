<?php

// app/Services/TicketCacheService.php
namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TicketCacheService
{
    const CACHE_TTL = 5; // minutes

    /**
     * Get all open tickets with caching
     */
    public function getOpenTickets()
    {
        return Cache::remember('tickets.open', Carbon::now()->addMinutes(self::CACHE_TTL), function () {
            return Ticket::with('customer')
                ->open()
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * Get all tickets assigned to an agent with caching
     */
    public function getAgentTickets(User $agent)
    {
        $cacheKey = "tickets.agent.{$agent->id}";

        return Cache::remember($cacheKey, Carbon::now()->addMinutes(self::CACHE_TTL), function () use ($agent) {
            return Ticket::with('customer')
                ->where('agent_id', $agent->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'asc')
                ->get();
        });
    }

    /**
     * Get all tickets created by a customer with caching
     */
    public function getCustomerTickets(User $customer)
    {
        $cacheKey = "tickets.customer.{$customer->id}";

        return Cache::remember($cacheKey, Carbon::now()->addMinutes(self::CACHE_TTL), function () use ($customer) {
            return Ticket::with(['agent', 'comments' => function ($query) {
                    $query->public()->orderBy('created_at', 'desc');
                }])
                ->where('user_id', $customer->id)
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * Get ticket details with caching
     */
    public function getTicketDetails(Ticket $ticket)
    {
        $cacheKey = "ticket.{$ticket->id}.details";

        return Cache::remember($cacheKey, Carbon::now()->addMinutes(self::CACHE_TTL), function () use ($ticket) {
            return Ticket::with([
                'customer',
                'agent',
                'comments' => function ($query) {
                    $query->with('user')->orderBy('created_at', 'asc');
                },
                'logs' => function ($query) {
                    $query->with('user')->orderBy('created_at', 'desc');
                }
            ])->find($ticket->id);
        });
    }

    /**
     * Clear cache for a specific ticket
     */
    public function clearTicketCache(Ticket $ticket)
    {
        Cache::forget("ticket.{$ticket->id}.details");
        Cache::forget('tickets.open');

        if ($ticket->agent_id) {
            Cache::forget("tickets.agent.{$ticket->agent_id}");
        }

        Cache::forget("tickets.customer.{$ticket->user_id}");
    }

    /**
     * Get ticket statistics with caching
     */
    public function getTicketStatistics()
    {
        return Cache::remember('tickets.statistics', Carbon::now()->addMinutes(10), function () {
            return [
                'total' => Ticket::count(),
                'open' => Ticket::open()->count(),
                'in_progress' => Ticket::inProgress()->count(),
                'resolved' => Ticket::resolved()->count(),
                'closed' => Ticket::closed()->count(),
                'high_priority' => Ticket::highPriority()->count(),
                'avg_resolution_time' => $this->calculateAverageResolutionTime(),
            ];
        });
    }

    /**
     * Calculate average resolution time
     */
    private function calculateAverageResolutionTime()
    {
        $resolvedTickets = Ticket::whereNotNull('resolved_at')
            ->get(['created_at', 'resolved_at']);

        if ($resolvedTickets->isEmpty()) {
            return 0;
        }

        $totalResolutionTimeHours = 0;

        foreach ($resolvedTickets as $ticket) {
            $totalResolutionTimeHours += $ticket->created_at->diffInHours($ticket->resolved_at);
        }

        return round($totalResolutionTimeHours / $resolvedTickets->count(), 2);
    }
}
