<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPortfolioFilters;
use App\Models\Booking;
use App\Models\Lot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ResolvesPortfolioFilters;

    public const POSITIONS = ['available', 'booked', 'sold', 'needs_review', 'historical'];

    public function index(Request $request): View
    {
        $filters = $this->portfolioFilters($request);
        $position = in_array($request->string('position')->toString(), self::POSITIONS, true)
            ? $request->string('position')->toString()
            : 'all';
        $search = $request->string('q')->toString();

        $lots = Lot::query()
            ->whereIn('project_id', $filters['projects']->pluck('id'))
            ->with(['project.branch', 'currentBooking.buyer'])
            ->orderBy('code');

        $lots = match ($position) {
            'available' => $lots
                ->where('historical_akad_before_2026', false)
                ->whereDoesntHave('bookings', fn ($q) => $q->where('is_current_holder', true)->whereIn('status', [
                    Booking::STATUS_ACTIVE,
                    Booking::STATUS_COMPLETED,
                    Booking::STATUS_NEEDS_REVIEW,
                ])),
            'booked' => $lots->whereHas('bookings', fn ($q) => $q
                ->where('is_current_holder', true)
                ->where('status', Booking::STATUS_ACTIVE)),
            'sold' => $lots->whereHas('bookings', fn ($q) => $q
                ->where('is_current_holder', true)
                ->where('status', Booking::STATUS_COMPLETED)),
            'needs_review' => $lots
                ->whereHas('bookings', fn ($q) => $q->where('status', Booking::STATUS_NEEDS_REVIEW))
                ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [Booking::STATUS_ACTIVE, Booking::STATUS_COMPLETED])),
            'historical' => $lots->where('historical_akad_before_2026', true),
            default => $lots,
        };

        if ($search !== '') {
            $lots->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhereHas('currentBooking.buyer', fn ($b) => $b->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('currentBooking', fn ($b) => $b->where('source_transaction_id', 'like', "%{$search}%"));
            });
        }

        return view('inventory.index', [
            'filters' => $filters,
            'lots' => $lots->paginate(50)->withQueryString(),
            'position' => $position,
            'positions' => self::POSITIONS,
            'search' => $search,
        ]);
    }
}
