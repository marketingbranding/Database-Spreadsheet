<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StageSelectController extends Controller
{
    /**
     * Sidebar stage key → route name and label. Konflik Data is intentionally
     * absent: it keeps its own gate and opens from project context only.
     */
    private const STAGES = [
        'psjb' => ['route' => 'psjb.index', 'label' => 'PSJB / UTJ'],
        'bi' => ['route' => 'bi.index', 'label' => 'BI Checking'],
        'pemberkasan' => ['route' => 'pemberkasan.index', 'label' => 'Pemberkasan'],
        'bank' => ['route' => 'bank.index', 'label' => 'Proses Bank'],
        'ppjb' => ['route' => 'ppjb.index', 'label' => 'TTD PPJB'],
        'akad' => ['route' => 'akad.index', 'label' => 'Akad'],
        'bast' => ['route' => 'bast.index', 'label' => 'BAST'],
    ];

    public function index(Request $request): View
    {
        $stage = $request->query('stage');
        abort_unless(is_string($stage) && isset(self::STAGES[$stage]), 404, 'Tahapan tidak dikenal.');

        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'antrean';
        $user = $request->user();

        $projects = Project::query()
            ->with('branch')
            ->visibleTo($user)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'project' => $project,
                'available' => $project->isOperationalStaging(),
                'note' => $this->unavailableNote($project),
                'url' => $project->isOperationalStaging()
                    ? route(self::STAGES[$stage]['route'], ['project' => $project, 'tab' => $tab])
                    : null,
            ]);

        return view('stages.select', [
            'stageLabel' => self::STAGES[$stage]['label'],
            'projects' => $projects,
        ]);
    }

    private function unavailableNote(Project $project): ?string
    {
        if ($project->management_mode === Project::MANAGEMENT_SHEETS) {
            return 'Dikelola melalui Sheets — tahapan operasional tidak tersedia.';
        }

        if (! $project->isOperationalStaging()) {
            return 'Tahapan operasional belum aktif untuk proyek ini.';
        }

        return null;
    }
}
