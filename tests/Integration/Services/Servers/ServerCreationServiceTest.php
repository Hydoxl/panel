<?php

namespace App\Tests\Integration\Services\Servers;

use App\Models\Objects\Endpoint;
use Mockery\MockInterface;
use App\Models\Egg;
use GuzzleHttp\Psr7\Request;
use App\Models\Node;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use App\Models\Server;
use Illuminate\Foundation\Testing\WithFaker;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Validation\ValidationException;
use App\Models\Objects\DeploymentObject;
use App\Tests\Integration\IntegrationTestCase;
use App\Services\Servers\ServerCreationService;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Exceptions\Http\Connection\DaemonConnectionException;

class ServerCreationServiceTest extends IntegrationTestCase
{
    use WithFaker;

    protected MockInterface $daemonServerRepository;

    protected Egg $bungeecord;

    /**
     * Stub the calls to daemon so that we don't actually hit those API endpoints.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->bungeecord = Egg::query()
            ->where('author', 'panel@example.com')
            ->where('name', 'Bungeecord')
            ->firstOrFail();

        $this->daemonServerRepository = \Mockery::mock(DaemonServerRepository::class);
        $this->swap(DaemonServerRepository::class, $this->daemonServerRepository);
    }

    /**
     * Test that a server can be created when a deployment object is provided to the service.
     *
     * This doesn't really do anything super complicated, we'll rely on other more specific
     * tests to cover that the logic being used does indeed find suitable nodes and ports. For
     * this test we just care that it is recognized and passed off to those functions.
     */
    public function testServerIsCreatedWithDeploymentObject(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();

        /** @var \App\Models\Node $node */
        $node = Node::factory()->create();

        $deployment = (new DeploymentObject())->setDedicated(true)->setPorts([
            1234,
        ]);

        $egg = $this->cloneEggAndVariables($this->bungeecord);
        // We want to make sure that the validator service runs as an admin, and not as a regular
        // user when saving variables.
        $egg->variables()->first()->update([
            'user_editable' => false,
        ]);

        $data = [
            'name' => $this->faker->name(),
            'description' => $this->faker->sentence(),
            'owner_id' => $user->id,
            'memory' => 256,
            'swap' => 128,
            'disk' => 100,
            'io' => 500,
            'cpu' => 0,
            'startup' => 'java server2.jar',
            'image' => 'java:8',
            'egg_id' => $egg->id,
            'ports' => [1234, 2345, 3456],
            'node_id' => $node->id,
            'environment' => [
                'BUNGEE_VERSION' => '123',
                'SERVER_JARFILE' => 'server2.jar',
                'SERVER_PORT' => '1234',
            ],
            'start_on_completion' => true,
        ];

        $this->daemonServerRepository->expects('setServer->create')->with(true)->andReturnUndefined();

        try {
            $this->getService()->handle(array_merge($data, [
                'environment' => [
                    'BUNGEE_VERSION' => '',
                    'SERVER_JARFILE' => 'server2.jar',
                    'SERVER_PORT' => '1234',
                ],
            ]), $deployment);

            $this->fail('This execution pathway should not be reached.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('environment.BUNGEE_VERSION', $exception->errors());
            $this->assertArrayNotHasKey('environment.SERVER_JARFILE', $exception->errors());
            $this->assertSame('The Bungeecord Version variable field is required.', $exception->errors()['environment.BUNGEE_VERSION'][0]);
        }

        $response = $this->getService()->handle($data, $deployment);

        $this->assertInstanceOf(Server::class, $response);
        $this->assertNotNull($response->uuid);
        $this->assertSame($response->uuid_short, substr($response->uuid, 0, 8));
        $this->assertSame($egg->id, $response->egg_id);
        $this->assertCount(3, $response->variables);
        $this->assertSame('123', $response->variables[0]->server_value);
        $this->assertSame('server2.jar', $response->variables[1]->server_value);

        foreach ($data as $key => $value) {
            if (in_array($key, ['environment', 'start_on_completion'])) {
                continue;
            }

            if ($key === 'ports') {
                $this->assertSame($value, $response->ports->map(fn (Endpoint $endpoint) => $endpoint->port)->all());

                continue;
            }

            $this->assertSame($value, $response->{$key}, "Failed asserting equality of '$key' in server response. Got: [{$response->{$key}}] Expected: [$value]");
        }

        $this->assertFalse($response->isSuspended());
        $this->assertFalse($response->oom_killer);
        $this->assertSame(0, $response->database_limit);
        $this->assertSame(0, $response->allocation_limit);
        $this->assertSame(0, $response->backup_limit);
    }

    /**
     * Test that a server is deleted from the Panel if daemon returns an error during the creation
     * process.
     */
    public function testErrorEncounteredByDaemonCausesServerToBeDeleted(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();

        /** @var \App\Models\Node $node */
        $node = Node::factory()->create();

        $data = [
            'name' => $this->faker->name(),
            'description' => $this->faker->sentence(),
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'memory' => 256,
            'swap' => 128,
            'disk' => 100,
            'io' => 500,
            'cpu' => 0,
            'startup' => 'java server2.jar',
            'image' => 'java:8',
            'egg_id' => $this->bungeecord->id,
            'environment' => [
                'BUNGEE_VERSION' => '123',
                'SERVER_JARFILE' => 'server2.jar',
                'SERVER_PORT' => '1234',
            ],
        ];

        $this->daemonServerRepository->expects('setServer->create')->andThrows(
            new DaemonConnectionException(
                new BadResponseException('Bad request', new Request('POST', '/create'), new Response(500))
            )
        );

        $this->daemonServerRepository->expects('setServer->delete')->andReturnUndefined();

        $this->expectException(DaemonConnectionException::class);

        $this->getService()->handle($data);

        $this->assertDatabaseMissing('servers', ['owner_id' => $user->id]);
    }

    private function getService(): ServerCreationService
    {
        return $this->app->make(ServerCreationService::class);
    }
}
