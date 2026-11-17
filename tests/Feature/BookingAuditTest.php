<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\PsjbRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_records_actor_payload_and_relations_without_pii(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:test', 'name' => 'Test Buyer']);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'A1', 'source_key' => 'lot:test']);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:test',
            'status' => Booking::STATUS_ACTIVE,
        ]);
        $user = User::query()->create([
            'name' => 'Branch Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'is_active' => true,
        ]);

        PsjbRecord::query()->create(['booking_id' => $booking->id, 'project_id' => $project->id, 'source_key' => 'booking:test:psjb']);
        $booking->load('psjb');
        $audit = BookingAudit::record($booking, $user, BookingAudit::ACTION_CREATED, null, array_merge(
            BookingAudit::snapshot($booking),
            ['nik_encrypted' => 'secret', 'phone_encrypted' => 'secret'],
        ), '9bb7d7f5-7452-4d11-8f45-d3fbb6a4b71f');

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($project->id, $audit->project_id);
        $this->assertSame(Booking::STATUS_ACTIVE, $audit->after['status']);
        $this->assertSame($booking->id, $audit->booking->id);
        $this->assertSame($project->id, $audit->project->id);
        $this->assertSame($user->id, $audit->user->id);
        $this->assertSame(1, $booking->audits()->count());
        $this->assertArrayHasKey('psjb', $audit->after);
        $this->assertArrayNotHasKey('nik_encrypted', $audit->after);
    }
}
