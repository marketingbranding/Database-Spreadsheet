<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsjbControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_branch_admin_can_create_and_cross_project_post_is_forbidden(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $jpr = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $mgl = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang']);
        $mine = Project::query()->create(['branch_id' => $jpr->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen', 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $other = Project::query()->create(['branch_id' => $mgl->id, 'code' => 'PRJ-MGL-KLG', 'name' => 'Kalinegoro', 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->create(['project_id' => $mine->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin@controller.test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $jpr->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($mine);

        $this->actingAs($admin)->get(route('psjb.create', $mine))->assertOk()->assertSee('Buat PSJB');
        $this->actingAs($admin)->post(route('psjb.store', $other), [
            'lot_id' => $lot->id, 'buyer_name' => 'Tamper', 'sales_name' => 'Sales',
            'payment_method' => 'KPR', 'psjb_date' => '2026-11-16',
        ])->assertForbidden();
    }
}
