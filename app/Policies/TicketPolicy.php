<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any tickets.
     */
    public function viewAny(User $user)
    {
        return true; // All users can see ticket listings (filtered by their role)
    }

    /**
     * Determine whether the user can view the ticket.
     */
    public function view(User $user, Ticket $ticket)
    {
        // Admins can view all tickets
        if ($user->isAdmin()) {
            return true;
        }

        // Agents can view tickets assigned to them
        if ($user->isAgent() && $ticket->agent_id === $user->id) {
            return true;
        }

        // Customers can only view their own tickets
        return $ticket->user_id === $user->id;
    }

    /**
     * Determine whether the user can create tickets.
     */
    public function create(User $user)
    {
        // Everyone can create tickets
        return true;
    }

    /**
     * Determine whether the user can update the ticket.
     */
    public function update(User $user, Ticket $ticket)
    {
        // Only admins and assigned agents can update tickets
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent() && $ticket->agent_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can change the ticket status.
     */
    public function changeStatus(User $user, Ticket $ticket)
    {
        // Only admins and assigned agents can change status
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent() && $ticket->agent_id === $user->id) {
            return true;
        }

        // Customers can only change from 'resolved' to 'closed'
        if ($user->id === $ticket->user_id && $ticket->status === 'resolved') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can assign the ticket to an agent.
     */
    public function assign(User $user, Ticket $ticket)
    {
        // Only admins can assign tickets
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can comment on the ticket.
     */
    public function comment(User $user, Ticket $ticket)
    {
        // Admins can comment on any ticket
        if ($user->isAdmin()) {
            return true;
        }

        // Agents can comment on tickets assigned to them
        if ($user->isAgent() && $ticket->agent_id === $user->id) {
            return true;
        }

        // Customers can comment on their own tickets
        return $ticket->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the ticket.
     */
    public function delete(User $user, Ticket $ticket)
    {
        // Only admins can delete tickets
        return $user->isAdmin();
    }
}
