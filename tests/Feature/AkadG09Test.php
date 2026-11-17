<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AkadG09Test extends FixtureProjectTestCase
{
    public function test_g09_april_ppjb_cannot_qualify_september_booking(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->where('code', 'G09')->firstOrFail();
        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);
        $this->assertNotNull($april);
        $this->assertNotNull($september);
        $this->assertNotSame($april->id, $september->id);
        $aprilPpjb = PpjbRecord::query()->where('booking_id', $april->id)->first();
        if (! $aprilPpjb) {
            $aprilPpjb = PpjbRecord::query()->create([
                'booking_id' => $april->id,
                'project_id' => $this->project->id,
                'source_key' => 'PPJB:G09-APRIL-TEST',
                'ppjb_number' => 'PPJB-G09-APRIL-TEST',
                'signed_date' => '2026-04-30',
                'source_owner' => PpjbRecord::OWNER_SHEETS,
            ]);
        }
        $septemberPpjb = PpjbRecord::query()->where('booking_id', $september->id)->first();
        $this->assertNotNull($aprilPpjb);
        $this->assertNotNull($septemberPpjb);
        $this->assertSame($april->id, $aprilPpjb->booking_id);
        $this->assertSame($september->id, $septemberPpjb->booking_id);

        $september->update([
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PPJB,
        ]);
        $branch = Branch::query()->whereKey($this->project->branch_id)->firstOrFail();
        $admin = User::query()->create([
            'name' => 'G09 Akad Admin', 'email' => 'g09-akad-'.uniqid().'@test',
            'password' => 'password', 'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($this->project);

        try {
            app(PsjbWorkflow::class)->recordAkad($admin, $september, [
                'ppjb_id' => $aprilPpjb->id,
                'akad_number' => 'AKD-G09-INVALID',
                'akad_date' => '2026-09-30',
                'quality' => 'Akad Sempurna',
                'idempotency_key' => 'akad-g09-invalid',
            ]);
            $this->fail('Expected April PPJB to be rejected for September booking.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
