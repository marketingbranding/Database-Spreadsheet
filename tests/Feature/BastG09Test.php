<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BastG09Test extends FixtureProjectTestCase
{
    public function test_g09_april_akad_cannot_qualify_september_bast(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->where('code', 'G09')->firstOrFail();
        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);
        $this->assertNotNull($april);
        $this->assertNotNull($september);
        $this->assertNotSame($april->id, $september->id);

        $aprilAkad = AkadRecord::query()->firstOrCreate(
            ['booking_id' => $april->id],
            [
                'project_id' => $this->project->id,
                'source_key' => 'AKAD:G09-APRIL-TEST',
                'akad_number' => 'AKD-G09-APRIL-TEST',
                'akad_date' => '2026-04-30',
                'quality' => 'Akad Sempurna',
                'source_owner' => AkadRecord::OWNER_SHEETS,
            ],
        );
        $septemberAkad = AkadRecord::query()->firstOrCreate(
            ['booking_id' => $september->id],
            [
                'project_id' => $this->project->id,
                'source_key' => 'AKAD:G09-SEPTEMBER-TEST',
                'akad_number' => 'AKD-G09-SEPTEMBER-TEST',
                'akad_date' => '2026-09-30',
                'quality' => 'Akad Sempurna',
                'source_owner' => AkadRecord::OWNER_SHEETS,
            ],
        );
        BastRecord::query()->where('booking_id', $september->id)->delete();
        $september->update([
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
        ]);

        $branch = Branch::query()->whereKey($this->project->branch_id)->firstOrFail();
        $admin = User::query()->create([
            'name' => 'G09 BAST Admin',
            'email' => 'g09-bast-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($this->project);

        try {
            app(PsjbWorkflow::class)->recordBast($admin, $september, [
                'akad_id' => $aprilAkad->id,
                'bast_number' => 'BAST-G09-INVALID',
                'bast_date' => '2026-09-30',
                'idempotency_key' => 'bast-g09-invalid',
            ]);
            $this->fail('Expected April Akad to be rejected for September booking.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($september->id, $septemberAkad->booking_id);
        $this->assertDatabaseMissing('bast_records', ['bast_number' => 'BAST-G09-INVALID']);
    }
}
