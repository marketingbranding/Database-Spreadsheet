<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingTransition;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\PsjbRecord;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookingChangeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_transition_contract_contains_explicit_links_and_idempotency(): void
    {
        $this->assertTrue(Schema::hasTable('booking_transitions'));
        $this->assertTrue(Schema::hasColumns('booking_transitions', [
            'source_booking_id',
            'successor_booking_id',
            'action',
            'effective_date',
            'reason',
            'user_id',
            'idempotency_key',
            'source_owner',
        ]));
        $this->assertSame('ganti_nama', BookingTransition::ACTION_NAME_CHANGE);
        $this->assertSame('pindah_kavling', BookingTransition::ACTION_LOT_TRANSFER);
        $this->assertSame('booking_changed', BookingAudit::ACTION_BOOKING_CHANGED);
    }

    public function test_same_day_name_change_keeps_lot_carries_utj_and_starts_fresh_successor(): void
    {
        [$project, $admin, $source, $sourcePsjb, $lot] = $this->scenario('name');
        $newBuyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:new-name-'.uniqid(),
            'name' => 'Substituted Buyer',
        ]);

        $successor = app(PsjbWorkflow::class)->changeName($admin, $source, [
            'buyer_id' => $newBuyer->id,
            'effective_date' => '2026-11-19',
            'reason' => 'K20 same-day buyer substitution',
            'idempotency_key' => 'name-change-'.uniqid(),
        ]);

        $this->assertSame($lot->id, $successor->lot_id);
        $this->assertSame($newBuyer->id, $successor->buyer_id);
        $this->assertSame(Booking::STAGE_PSJB, $successor->current_stage);
        $this->assertSame(Booking::STATUS_ACTIVE, $successor->status);
        $this->assertTrue($successor->is_current_holder);
        $this->assertSame(Booking::STATUS_REPLACED, $source->fresh()->status);
        $this->assertFalse($source->fresh()->is_current_holder);
        $this->assertSame($sourcePsjb->id, $successor->psjb->utj_source_psjb_id);
        $this->assertSame($sourcePsjb->utj_amount, $successor->psjb->utj_amount);
        $this->assertNull($successor->biCheck);
        $this->assertNull($successor->ppjb);
        $this->assertSame(1, BookingTransition::query()->where('source_booking_id', $source->id)->count());
        $this->assertSame(1, $source->audits()->where('action', BookingAudit::ACTION_BOOKING_CHANGED)->count());
        $this->assertSame(1, $successor->audits()->where('action', BookingAudit::ACTION_BOOKING_CHANGED)->count());
        $this->assertSame('BOOKED', $lot->fresh()->inventoryPosition());
    }

    public function test_transfer_reserves_destination_and_releases_source_atomically(): void
    {
        [$project, $admin, $source, , $sourceLot] = $this->scenario('move');
        $destination = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'DEST-'.uniqid(),
            'source_key' => 'lot:destination-'.uniqid(),
        ]);

        $successor = app(PsjbWorkflow::class)->moveLot($admin, $source, [
            'lot_id' => $destination->id,
            'effective_date' => '2026-11-19',
            'reason' => 'Destination selected by buyer',
            'idempotency_key' => 'lot-move-'.uniqid(),
        ]);

        $this->assertSame($destination->id, $successor->lot_id);
        $this->assertSame($source->buyer_id, $successor->buyer_id);
        $this->assertSame(Booking::STATUS_MOVED, $source->fresh()->status);
        $this->assertFalse($source->fresh()->is_current_holder);
        $this->assertSame('AVAILABLE', $sourceLot->fresh()->inventoryPosition());
        $this->assertSame('BOOKED', $destination->fresh()->inventoryPosition());
        $this->assertSame(BookingTransition::ACTION_LOT_TRANSFER, $successor->transition->action);
    }

    public function test_occupied_destination_rejects_without_changing_source_allocation(): void
    {
        [$project, $admin, $source, , $sourceLot] = $this->scenario('occupied-source');
        [, , $occupied] = $this->scenario('occupied-destination', $project);

        try {
            app(PsjbWorkflow::class)->moveLot($admin, $source, [
                'lot_id' => $occupied->lot_id,
                'effective_date' => '2026-11-19',
                'reason' => 'Should fail',
                'idempotency_key' => 'occupied-move-'.uniqid(),
            ]);
            $this->fail('Expected occupied destination to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(Booking::STATUS_ACTIVE, $source->fresh()->status);
        $this->assertTrue($source->fresh()->is_current_holder);
        $this->assertSame('BOOKED', $sourceLot->fresh()->inventoryPosition());
        $this->assertSame(0, BookingTransition::query()->where('source_booking_id', $source->id)->count());
    }

    public function test_signed_document_blocks_change_even_when_stage_is_stale(): void
    {
        [$project, $admin, $source] = $this->scenario('signed');
        PpjbRecord::query()->create([
            'booking_id' => $source->id,
            'project_id' => $project->id,
            'source_key' => 'PPJB:signed-'.uniqid(),
            'ppjb_number' => 'PPJB-SIGNED',
            'signed_date' => '2026-11-18',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);
        $buyer = Buyer::query()->create(['source_key' => $project->code.':BUYER:signed-'.uniqid(), 'name' => 'Other Buyer']);

        try {
            app(PsjbWorkflow::class)->changeName($admin, $source, [
                'buyer_id' => $buyer->id,
                'effective_date' => '2026-11-19',
                'reason' => 'Blocked after signing',
                'idempotency_key' => 'signed-change-'.uniqid(),
            ]);
            $this->fail('Expected signed document guard.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(Booking::STATUS_ACTIVE, $source->fresh()->status);
        $this->assertSame(0, BookingTransition::query()->count());
    }

    public function test_same_payload_replays_and_changed_payload_returns_conflict(): void
    {
        [$project, $admin, $source] = $this->scenario('retry');
        $buyer = Buyer::query()->create(['source_key' => $project->code.':BUYER:retry-'.uniqid(), 'name' => 'Retry Buyer']);
        $payload = [
            'buyer_id' => $buyer->id,
            'effective_date' => '2026-11-19',
            'reason' => 'Retry request',
            'idempotency_key' => 'change-retry-'.uniqid(),
        ];
        $workflow = app(PsjbWorkflow::class);

        $first = $workflow->changeName($admin, $source, $payload);
        $second = $workflow->changeName($admin, $source->fresh(), $payload);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BookingTransition::query()->where('idempotency_key', $payload['idempotency_key'])->count());

        try {
            $workflow->changeName($admin, $source->fresh(), [...$payload, 'reason' => 'Changed']);
            $this->fail('Expected changed replay to return 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_cross_project_source_and_destination_are_rejected(): void
    {
        [$project, $admin, $source] = $this->scenario('cross-source');
        [$otherProject, , $otherSource] = $this->scenario('other-project');

        try {
            app(PsjbWorkflow::class)->moveLot($admin, $otherSource, [
                'lot_id' => $source->lot_id,
                'effective_date' => '2026-11-19',
                'reason' => 'Cross project',
                'idempotency_key' => 'cross-project-'.uniqid(),
            ]);
            $this->fail('Expected cross-project access to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertNotSame($project->id, $otherProject->id);
        $this->assertSame(Booking::STATUS_ACTIVE, $otherSource->fresh()->status);
    }

    private function scenario(string $suffix, ?Project $project = null): array
    {
        if (! $project) {
            $branch = Branch::query()->create(['code' => 'JPR-'.uniqid(), 'name' => 'Jepara']);
            $project = Project::query()->create([
                'branch_id' => $branch->id,
                'code' => 'PRJ-JPR-KWS-'.strtoupper(substr(sha1($suffix), 0, 4)),
                'name' => 'Kuwasen',
                'management_mode' => Project::MANAGEMENT_LARAVEL,
            ]);
            $admin = User::query()->create([
                'name' => 'Change Admin',
                'email' => 'change-'.uniqid().'@test',
                'password' => 'password',
                'role' => User::ROLE_BRANCH_ADMIN,
                'branch_id' => $branch->id,
                'is_active' => true,
            ]);
            $admin->projects()->attach($project);
        } else {
            $admin = User::query()->where('branch_id', $project->branch_id)->firstOrFail();
        }

        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.$suffix.'-'.uniqid(), 'name' => 'Source Buyer '.$suffix]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'SRC-'.uniqid(),
            'source_key' => 'lot:'.$suffix.'-'.uniqid(),
        ]);
        $source = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.$suffix.'-'.uniqid(),
            'booking_date' => '2026-11-18',
            'payment_method' => 'KPR',
            'base_price' => 500000000,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_LARAVEL,
        ]);
        $psjb = PsjbRecord::query()->create([
            'booking_id' => $source->id,
            'project_id' => $project->id,
            'source_key' => 'PSJB:'.$suffix.'-'.uniqid(),
            'psjb_number' => 'PSJB-'.$suffix,
            'psjb_date' => '2026-11-18',
            'price' => 500000000,
            'utj_date' => '2026-11-18',
            'utj_amount' => 1500000,
            'payment_method' => 'KPR',
            'source_status' => 'ACTIVE',
            'source_tab' => 'Laravel',
        ]);

        return [$project, $admin, $source->fresh(['lot', 'buyer', 'psjb']), $psjb, $lot];
    }
}
