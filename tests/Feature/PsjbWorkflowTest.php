<?php

namespace Tests\Feature;

use App\Exceptions\LotAllocationConflict;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsjbWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private PsjbWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Branch Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
        $this->workflow = app(PsjbWorkflow::class);
    }

    public function test_create_withdraw_and_repeat_booking_preserve_history(): void
    {
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $first = $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $lot->id, 'buyer_name' => 'Buyer One', 'nik' => '1234567890123456',
            'phone' => '08123456789', 'sales_name' => 'Sales One', 'payment_method' => 'KPR',
            'psjb_number' => 'L-001', 'psjb_date' => '2026-11-16', 'utj_date' => '2026-11-17',
            'utj_amount' => 1000000, 'price' => 500000000,
        ]);

        $this->assertSame(Booking::OWNER_LARAVEL, $first->source_owner);
        $this->assertTrue($first->is_current_holder);
        $this->assertCount(1, $first->audits);

        $withdrawn = $this->workflow->withdraw($this->admin, $first, 'Buyer mundur');
        $this->assertSame(Booking::STATUS_MUNDUR, $withdrawn->status);
        $this->assertFalse($withdrawn->is_current_holder);
        $this->assertSame('Sales One', $withdrawn->sales_name);
        $this->assertSame('2026-11-16', $withdrawn->psjb->psjb_date->toDateString());

        $second = $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $lot->id, 'buyer_name' => 'Buyer Two', 'sales_name' => 'Sales Two',
            'payment_method' => 'CASH', 'psjb_date' => '2026-11-20',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(Booking::STATUS_MUNDUR, $first->fresh()->status);
        $this->assertSame('Sales One', $first->fresh()->sales_name);
        $this->assertTrue($second->fresh()->is_current_holder);
    }

    public function test_needs_review_claim_blocks_allocation_even_without_holder_flag(): void
    {
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'A4', 'source_key' => 'lot:a4']);
        $buyer = Buyer::query()->create(['source_key' => $this->project->code . ':buyer:review', 'name' => 'Review Buyer']);
        Booking::query()->create([
            'project_id' => $this->project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'legacy:review', 'status' => Booking::STATUS_NEEDS_REVIEW, 'is_current_holder' => false,
        ]);

        $this->assertFalse($this->workflow->availableLots($this->project)->whereKey($lot->id)->exists());
    }

    public function test_valid_claim_and_multiple_claims_block_allocation(): void
    {
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'M22', 'source_key' => 'lot:m22']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:one', 'name' => 'One']);
        Booking::query()->create([
            'project_id' => $this->project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'legacy:one', 'status' => Booking::STATUS_NEEDS_REVIEW,
        ]);

        $this->assertFalse($this->workflow->availableLots($this->project)->whereKey($lot->id)->exists());
        $this->expectException(LotAllocationConflict::class);
        $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $lot->id, 'buyer_name' => 'New Buyer', 'sales_name' => 'Sales',
            'payment_method' => 'KPR', 'psjb_date' => '2026-11-16',
        ]);
    }

    public function test_non_staging_environment_cannot_write_even_when_flag_is_true(): void
    {
        config(['app.env' => 'local']);
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'A3', 'source_key' => 'lot:a3']);

        $this->expectException(HttpException::class);
        $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $lot->id, 'buyer_name' => 'Buyer', 'sales_name' => 'Sales',
            'payment_method' => 'KPR', 'psjb_date' => '2026-11-16',
        ]);
    }

    public function test_hq_and_other_branch_cannot_write(): void
    {
        $hq = User::query()->create([
            'name' => 'HQ', 'email' => 'hq@test', 'password' => 'password', 'role' => User::ROLE_HQ, 'is_active' => true,
        ]);
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'A2', 'source_key' => 'lot:a2']);

        $this->expectExceptionMessage('Operational writes are limited');
        $this->workflow->create($hq, $this->project, [
            'lot_id' => $lot->id, 'buyer_name' => 'Buyer', 'sales_name' => 'Sales',
            'payment_method' => 'KPR', 'psjb_date' => '2026-11-16',
        ]);
    }
}
