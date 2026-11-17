<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $magelang = Branch::query()->updateOrCreate(
            ['code' => 'MGL'],
            ['name' => 'Magelang', 'city' => 'Magelang', 'is_active' => true],
        );
        $jepara = Branch::query()->updateOrCreate(
            ['code' => 'JPR'],
            ['name' => 'Jepara', 'city' => 'Jepara', 'is_active' => true],
        );

        $kalinegoro = Project::query()->updateOrCreate(
            ['code' => 'PRJ-MGL-KLG'],
            [
                'branch_id' => $magelang->id,
                'name' => 'Marison Regency Kalinegoro',
                'source_name' => 'Database Magelang - Marison Kalinegoro',
                'spreadsheet_id' => '1k7tkvzJ09L_X2i75YUgzQ_UfNkRnX5veOYHt6I2t-mw',
                'spreadsheet_name' => 'Database Magelang - Marison Kalinegoro',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'notes' => 'Pilot project. Data Kav contains 197 coded lots; no historical 2025 marker on this workbook.',
            ],
        );

        $kuwasen = Project::query()->updateOrCreate(
            ['code' => 'PRJ-JPR-KWS'],
            [
                'branch_id' => $jepara->id,
                'name' => 'Marison Regency Kuwasen',
                'source_name' => 'Database Jepara 2026',
                'spreadsheet_id' => '10IGS9vuDHP4E2-tgGu7pO-blWn_R9pFY3yDKTKmf5aI',
                'spreadsheet_name' => 'Database Jepara - Marison Regency Kuwasen',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'notes' => 'Pilot project. Data Kav contains 260 coded lots, 82 marked Sudah Akad s.d. 2025.',
            ],
        );

        $hq = User::query()->updateOrCreate(
            ['email' => env('MARISON_HQ_EMAIL', 'hq@marison.test')],
            [
                'name' => 'HQ Administrator',
                'password' => Hash::make(env('MARISON_HQ_PASSWORD', 'password')),
                'role' => User::ROLE_HQ,
                'branch_id' => null,
                'is_active' => true,
            ],
        );
        $hq->projects()->syncWithoutDetaching([$kalinegoro->id, $kuwasen->id]);

        $magelangAdmin = User::query()->updateOrCreate(
            ['email' => env('MARISON_MAGELANG_EMAIL', 'magelang@marison.test')],
            [
                'name' => 'Admin Magelang',
                'password' => Hash::make(env('MARISON_BRANCH_PASSWORD', 'password')),
                'role' => User::ROLE_BRANCH_ADMIN,
                'branch_id' => $magelang->id,
                'is_active' => true,
            ],
        );
        $magelangAdmin->projects()->sync([$kalinegoro->id]);

        $jeparaAdmin = User::query()->updateOrCreate(
            ['email' => env('MARISON_JEPARA_EMAIL', 'jepara@marison.test')],
            [
                'name' => 'Admin Jepara',
                'password' => Hash::make(env('MARISON_BRANCH_PASSWORD', 'password')),
                'role' => User::ROLE_BRANCH_ADMIN,
                'branch_id' => $jepara->id,
                'is_active' => true,
            ],
        );
        $jeparaAdmin->projects()->sync([$kuwasen->id]);
    }
}
