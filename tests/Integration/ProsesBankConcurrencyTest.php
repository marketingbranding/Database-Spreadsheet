<?php

namespace Tests\Integration;

use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProsesBankConcurrencyTest extends TestCase
{
    private ?Process $server = null;

    private int $port;

    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('The concurrent HTTP reproduction requires MySQL.');
        }

        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $this->port = random_int(18080, 18999);
        $this->server = new Process(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port='.$this->port, '--no-reload'],
            base_path(),
            array_merge($_ENV, [
                'APP_ENV' => 'staging',
                'MARISON_OPERATIONAL_WRITES' => 'true',
                'PHP_CLI_SERVER_WORKERS' => '4',
            ]),
        );
        $this->server->start();
        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);

        if ($this->cleanup !== []) {
            DB::table('booking_audits')->where('booking_id', $this->cleanup['booking_id'])->delete();
            BankSubmission::query()->where('booking_id', $this->cleanup['booking_id'])->delete();
            PemberkasanRecord::query()->where('booking_id', $this->cleanup['booking_id'])->delete();
            Booking::query()->whereKey($this->cleanup['booking_id'])->delete();
            Lot::query()->whereKey($this->cleanup['lot_id'])->delete();
            Buyer::query()->whereKey($this->cleanup['buyer_id'])->delete();
        }

        parent::tearDown();
    }

    public function test_two_independent_http_requests_replay_one_mysql_submission(): void
    {
        [$project, $admin, $booking, $pemberkasan] = $this->scenario();
        $payload = [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Concurrent HTTP retry',
            'idempotency_key' => 'mysql-concurrent-'.uniqid(),
        ];

        $first = $this->authenticatedClient($admin, $project, $booking);
        $second = $this->authenticatedClient($admin, $project, $booking);
        $url = '/projects/'.$project->getRouteKey().'/proses-bank/'.$booking->id;
        $requests = [
            $first->postAsync($url, ['form_params' => $payload]),
            $second->postAsync($url, ['form_params' => $payload]),
        ];
        $responses = Utils::all($requests)->wait();

        $this->assertSame([302, 302], collect($responses)->map(fn ($response) => $response->getStatusCode())->sort()->values()->all());
        $this->assertSame(1, BankSubmission::query()->where('idempotency_key', $payload['idempotency_key'])->count());
        $this->assertSame(1, BookingAudit::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingAudit::ACTION_BANK_SUBMISSION_CREATED)
            ->count());

        $different = [...$payload, 'bank' => 'BRI'];
        $conflict = $first->post($url, ['form_params' => $different]);

        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(1, BankSubmission::query()->where('booking_id', $booking->id)->count());
        $this->assertSame('BTN', BankSubmission::query()->where('booking_id', $booking->id)->value('bank'));
    }

    private function authenticatedClient(User $admin, Project $project, Booking $booking): Client
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:'.$this->port,
            'cookies' => new CookieJar,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $login = $client->get('/login');
        preg_match('/name="_token"[^>]+value="([^"]+)"/', (string) $login->getBody(), $matches);
        $client->post('/login', [
            'form_params' => [
                '_token' => $matches[1],
                'email' => $admin->email,
                'password' => 'password',
            ],
        ]);
        $form = $client->get('/projects/'.$project->getRouteKey().'/proses-bank/'.$booking->id.'/create');
        preg_match('/name="_token"[^>]+value="([^"]+)"/', (string) $form->getBody(), $matches);

        return new Client([
            'base_uri' => 'http://127.0.0.1:'.$this->port,
            'cookies' => $client->getConfig('cookies'),
            'headers' => ['X-CSRF-TOKEN' => $matches[1]],
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    private function waitForServer(): void
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:'.$this->port,
            'http_errors' => false,
            'timeout' => 0.5,
        ]);
        $deadline = microtime(true) + 10;
        do {
            try {
                if ($client->get('/login')->getStatusCode() === 200) {
                    return;
                }
            } catch (\Throwable) {
                // Keep polling until the server is accepting requests.
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->fail('The concurrent HTTP test server did not start.');
    }

    private function scenario(): array
    {
        $project = Project::query()->where('code', 'PRJ-JPR-KWS')->firstOrFail();
        $branch = Branch::query()->whereKey($project->branch_id)->firstOrFail();
        $admin = User::query()->where('branch_id', $branch->id)->where('role', User::ROLE_BRANCH_ADMIN)->firstOrFail();
        $buyer = Buyer::query()->create([
            'source_key' => 'buyer:mysql-concurrent-'.uniqid(),
            'name' => 'MySQL Concurrent Buyer',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'MYSQL-'.uniqid(),
            'source_key' => 'lot:mysql-concurrent-'.uniqid(),
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:mysql-concurrent-'.uniqid(),
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
            'payment_method' => 'KPR',
        ]);
        $pemberkasan = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'PEMBERKASAN:mysql-concurrent-'.uniqid(),
            'file_number' => 'MYSQL-CONCURRENT-'.uniqid(),
            'received_date' => '2026-11-17',
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'status' => 'LENGKAP',
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => 1,
            'is_current' => true,
        ]);
        $this->cleanup = [
            'booking_id' => $booking->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
        ];

        return [$project, $admin, $booking, $pemberkasan];
    }
}
