<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRunRepository;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class CommandRunTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
    }

    #[Test]
    public function start_and_finish_record_a_closed_run(): void
    {
        $repo = app(CommandRunRepository::class);

        $repo->start('app:status');

        $run = DB::table('scheduled_command_runs')->first();
        $this->assertNotNull($run);
        $this->assertNull($run->finished_at);

        $repo->finish('app:status', 0);

        $run = DB::table('scheduled_command_runs')->first();
        $this->assertSame('app:status', $run->command);
        $this->assertSame(0, (int) $run->exit_code);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);
    }

    #[Test]
    public function console_events_open_and_close_runs(): void
    {
        // En contexte de test, le framework n'écoute pas les events Symfony :
        // on les émet manuellement pour vérifier le câblage des listeners.
        Event::dispatch(new CommandStarting('app:status', new ArrayInput([]), new NullOutput));
        Event::dispatch(new CommandFinished('app:status', new ArrayInput([]), new NullOutput, 0));

        $run = DB::table('scheduled_command_runs')->first();
        $this->assertNotNull($run);
        $this->assertSame('app:status', $run->command);
        $this->assertSame(0, (int) $run->exit_code);
    }

    #[Test]
    public function a_failed_run_stores_the_error_message(): void
    {
        $repo = app(CommandRunRepository::class);

        $repo->start('app:harvest-players');
        $repo->fail('app:harvest-players', new RuntimeException('Boom'));

        $run = DB::table('scheduled_command_runs')->first();
        $this->assertSame(1, (int) $run->exit_code);
        $this->assertSame('Boom', $run->error);
    }

    #[Test]
    public function runs_are_visible_on_the_dashboard(): void
    {
        app(CommandRunRepository::class)->start('app:status');
        app(CommandRunRepository::class)->finish('app:status', 0);

        $this->withSession(['admin_magic_ok' => true, 'admin_authenticated' => true])
            ->get('/admin')
            ->assertOk()
            ->assertSee('app:status');
    }
}
