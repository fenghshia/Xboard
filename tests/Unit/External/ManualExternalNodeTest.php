<?php

namespace Tests\Unit\External;

use App\Http\Controllers\V2\Admin\Server\ExternalController;
use App\Http\Middleware\Admin;
use App\Http\Requests\Admin\ExternalServerSave;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManualExternalNodeTest extends TestCase
{
    private Application $app;
    private const UUID = '12345678-1234-4234-8234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->instance('config', new Repository());
        $this->app->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Model::encryptUsing($this->app['encrypter']);
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory($this->createStub(ViewFactory::class), $this->createStub(Redirector::class)));
        $this->app->instance(Setting::class, $this->createStub(Setting::class));
        $this->app->instance('cache', new CacheRepository(new ArrayStore()));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $db = new Capsule($this->app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setEventDispatcher(new Dispatcher($this->app));
        $db->setAsGlobal();
        $db->bootEloquent();
        $this->app->instance('db', $db->getDatabaseManager());
        $this->app->bind('db.schema', fn() => $db->schema());
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($db->getDatabaseManager()));
        $this->app->instance('validator', $validator);
        Request::macro('validate', function (array $rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        Schema::create('v2_server', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'type', 'host', 'port'] as $key) $table->string($key);
            foreach (['group_ids', 'protocol_settings', 'tags'] as $key) $table->json($key)->nullable();
            foreach (['server_port', 'sort', 'parent_id', 'machine_id', 'transfer_enable'] as $key) $table->integer($key)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('show')->default(true);
            $table->decimal('rate')->default(1);
            $table->string('code')->nullable();
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->timestamps();
        });
        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_28_000001_add_manual_external_nodes.php';
        $migration->up();
        Schema::create('v2_server_group', fn(Blueprint $table) => $table->id());
        Capsule::table('v2_server_group')->insert(['id' => 1]);
        Capsule::table('v2_server_group')->insert(['id' => 2]);
        Server::flushEventListeners();
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Server::flushEventListeners();
        Request::flushMacros();
        Model::encryptUsing(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        \Mockery::close();
        parent::tearDown();
    }

    private function payload(): array
    {
        return [
            'type' => 'vless', 'name' => 'External', 'host' => 'proxy.example.com', 'port' => 443,
            'group_ids' => ['1'], 'show' => true, 'sort' => 0, 'tags' => [],
            'protocol_settings' => ['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'proxy.example.com']],
            'credentials' => ['uuid' => self::UUID],
        ];
    }

    private function request(array $payload, bool $admin = true): ExternalServerSave
    {
        $request = ExternalServerSave::create('/api/v2/test/server/external/save', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector(new Redirector(new \Illuminate\Routing\UrlGenerator(new \Illuminate\Routing\RouteCollection(), Request::create('/'))));
        $request->setUserResolver(fn() => new User(['is_admin' => $admin]));
        $request->validateResolved();
        return $request;
    }

    private function save(array $payload): Server
    {
        $response = (new ExternalController())->save($this->request($payload));
        return Server::findOrFail($response->getData(true)['data']['id']);
    }

    public function test_save_encrypts_credentials_and_excludes_them_from_admin_listing(): void
    {
        $node = $this->save($this->payload());
        $raw = Capsule::table('v2_server')->where('id', $node->id)->value('external_credentials');
        $this->assertStringNotContainsString(self::UUID, $raw);
        $this->assertSame(self::UUID, $node->external_credentials['uuid']);
        $this->assertStringNotContainsString(self::UUID, (new ExternalController())->fetch()->getContent());
        $this->assertTrue($node->is_external);
        $this->assertFalse($node->enabled);
        $this->assertNull($node->machine_id);
        $this->assertSame(['1'], $node->group_ids);
    }

    public function test_update_keeps_blank_credentials_and_can_replace_them(): void
    {
        $node = $this->save($this->payload());
        $payload = $this->payload();
        $payload['id'] = $node->id;
        $payload['credentials'] = ['uuid' => ''];
        $payload['name'] = 'Renamed';
        $node = $this->save($payload);
        $this->assertSame(self::UUID, $node->external_credentials['uuid']);
        $this->assertSame('Renamed', $node->name);
        $payload['credentials'] = ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        $this->assertSame($payload['credentials']['uuid'], $this->save($payload)->external_credentials['uuid']);
    }

    public function test_subscription_visibility_and_fixed_uuid_are_preserved(): void
    {
        $external = $this->save($this->payload());
        $hidden = $this->payload(); $hidden['show'] = false; $this->save($hidden);
        $other = $this->payload(); $other['group_ids'] = ['2']; $this->save($other);
        $managedPayload = $this->payload(); unset($managedPayload['credentials']);
        $managed = Server::create(array_merge($managedPayload, ['is_external' => false, 'server_port' => 443, 'rate' => 1]));
        $user = new User(['uuid' => 'panel-user', 'group_id' => 1]);
        $nodes = ServerService::getAvailableServers($user);
        $this->assertCount(2, $nodes);
        $fixed = collect($nodes)->firstWhere('id', $external->id);
        $this->assertSame(self::UUID, $fixed['password']);
        $this->assertArrayNotHasKey('external_credentials', $fixed);
        $this->assertSame('panel-user', collect($nodes)->firstWhere('id', $managed->id)['password']);
        $this->assertStringContainsString(self::UUID, General::buildVless($fixed['password'], $fixed));
        $this->assertSame(self::UUID, ClashMeta::buildVless($fixed['password'], $fixed)['uuid']);
        $sing = new class([], []) extends SingBox {
            public function vless(array $node) { return $this->buildVless($node['password'], $node); }
        };
        $this->assertSame(self::UUID, $sing->vless($fixed)['uuid']);
    }

    public function test_external_nodes_are_not_backend_nodes_or_user_sync_targets(): void
    {
        $external = $this->save($this->payload());
        $this->assertNull(ServerService::getServer($external->id));
        $this->assertCount(0, ServerService::getAvailableUsers($external));
        $this->assertCount(0, ServerService::getAllServers());
        $external->machine_id = 5; $external->parent_id = 99; $external->enabled = true; $external->save();
        $external->refresh();
        $this->assertNull($external->machine_id);
        $this->assertNull($external->parent_id);
        $this->assertFalse($external->enabled);
        $machine = new ServerMachine(); $machine->id = 5;
        $this->assertCount(0, ServerService::getMachineNodes($machine));
        Capsule::connection()->transaction(function () use ($external) {
            $external->machine_id = 5; $external->parent_id = 99; $external->enabled = true; $external->save();
        });
        $external->refresh();
        $this->assertNull($external->machine_id);
        $this->assertNull($external->parent_id);
        $this->assertFalse($external->enabled);
    }

    public function test_username_and_password_remain_independent_in_exports(): void
    {
        $payload = $this->payload(); $payload['type'] = 'naive';
        $payload['credentials'] = ['username' => 'alice', 'password' => 'p@ss ?/#'];
        $node = $this->save($payload);
        $nodes = ServerService::getAvailableServers(new User(['uuid' => 'panel-user', 'group_id' => 1]));
        $fixed = $nodes[0];
        $parts = parse_url(trim(General::buildNaive($fixed['password'], $fixed)));
        $this->assertSame('alice', rawurldecode($parts['user']));
        $this->assertSame('p@ss ?/#', rawurldecode($parts['pass']));
        $this->assertSame('alice', ClashMeta::buildHttp($fixed['password'], $fixed)['username']);
        $sing = new class([], []) extends SingBox {
            public function naive(array $node) { return $this->buildNaive($node['password'], $node); }
        };
        $this->assertSame('alice', $sing->naive($fixed)['username']);
        $this->assertSame('p@ss ?/#', $sing->naive($fixed)['password']);
    }

    public function test_tuic_uuid_and_password_are_distinct(): void
    {
        $payload = $this->payload(); $payload['type'] = 'tuic';
        $payload['protocol_settings'] = ['version' => 5, 'tls' => ['server_name' => 'proxy.example.com']];
        $payload['credentials'] = ['uuid' => self::UUID, 'password' => 'tuic-password'];
        $this->save($payload);
        $node = ServerService::getAvailableServers(new User(['uuid' => 'panel-user', 'group_id' => 1]))[0];
        $config = ClashMeta::buildTuic($node['password'], $node);
        $this->assertSame(self::UUID, $config['uuid']);
        $this->assertSame('tuic-password', $config['password']);
        $this->assertStringContainsString(self::UUID . ':tuic-password@', General::buildTuic($node['password'], $node));
    }

    #[DataProvider('supportedProtocols')]
    public function test_supported_protocols_can_be_saved_and_keep_fixed_credentials(string $type, array $settings, array $credentials): void
    {
        $payload = $this->payload();
        $payload['type'] = $type;
        $payload['protocol_settings'] = $settings;
        $payload['credentials'] = $credentials;
        $node = $this->save($payload);
        $this->assertSame($type, $node->type);
        $expected = in_array($type, ['vless', 'vmess'], true) ? $credentials['uuid'] : $credentials['password'];
        $this->assertSame($expected, $node->generateServerPassword(new User(['uuid' => 'panel-user'])));
    }

    public static function supportedProtocols(): array
    {
        $tls = ['server_name' => 'proxy.example.com', 'allow_insecure' => false];
        return [
            ['vless', ['tls' => 1, 'network' => 'tcp'], ['uuid' => self::UUID]],
            ['vmess', ['tls' => 1, 'network' => 'ws'], ['uuid' => self::UUID]],
            ['trojan', ['tls' => 1, 'network' => 'tcp'], ['password' => 'external-secret']],
            ['shadowsocks', ['cipher' => 'aes-128-gcm'], ['password' => 'external-secret']],
            ['hysteria', ['version' => 2, 'tls' => $tls], ['password' => 'external-secret']],
            ['anytls', ['tls' => $tls], ['password' => 'external-secret']],
            ['naive', ['tls' => 1], ['username' => 'alice', 'password' => 'external-secret']],
            ['tuic', ['version' => 5, 'tls' => $tls], ['uuid' => self::UUID, 'password' => 'external-secret']],
            ['http', ['tls' => 1], ['username' => 'alice', 'password' => 'external-secret']],
            ['socks', ['tls' => 0], ['username' => 'alice', 'password' => 'external-secret']],
            ['mieru', ['transport' => 'TCP', 'traffic_pattern' => ''], ['username' => 'alice', 'password' => 'external-secret']],
        ];
    }

    public function test_protocol_change_does_not_reuse_old_credentials(): void
    {
        $node = $this->save($this->payload());
        $payload = $this->payload();
        $payload['id'] = $node->id;
        $payload['type'] = 'trojan';
        $payload['credentials'] = [];
        $this->expectException(ValidationException::class);
        $this->request($payload);
    }

    public function test_password_special_characters_are_encoded_in_general_uri(): void
    {
        $payload = $this->payload(); $payload['type'] = 'trojan';
        $payload['credentials'] = ['password' => 'p@ss:/?#% +'];
        $this->save($payload);
        $node = ServerService::getAvailableServers(new User(['uuid' => 'panel-user', 'group_id' => 1]))[0];
        $parts = parse_url(trim(General::buildTrojan($node['password'], $node)));
        $this->assertSame('p@ss:/?#% +', rawurldecode($parts['user']));
        $this->assertSame('proxy.example.com', $parts['host']);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_settings_are_rejected(string $field, mixed $value): void
    {
        $payload = $this->payload(); data_set($payload, $field, $value);
        $this->expectException(ValidationException::class);
        $this->request($payload);
    }

    public static function invalidFields(): array
    {
        return [
            ['credentials.uuid', 'not-a-uuid'], ['credentials', []],
            ['port', 0], ['port', 65536], ['group_ids', []], ['group_ids', ['999']],
            ['host', 'https://proxy.example.com'], ['name', "injected\nname"],
            ['type', 'unsupported'],
        ];
    }

    public function test_non_admin_is_rejected(): void
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->request($this->payload(), false);
    }

    public function test_admin_middleware_blocks_non_admin_fetch(): void
    {
        Auth::swap(new class {
            public function guard($name) { return $this; }
            public function user() { return new User(['is_admin' => false]); }
        });
        $response = (new Admin())->handle(Request::create('/external/fetch'), fn() => throw new \RuntimeException('must not enter controller'));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_sing_box_handle_uses_fixed_credentials_instead_of_panel_uuid(): void
    {
        $this->save($this->payload());
        $user = new User(['uuid' => 'panel-user', 'group_id' => 1]);
        $nodes = ServerService::getAvailableServers($user);
        $protocol = new class(['uuid' => 'panel-user', 'u' => 0, 'd' => 0, 'transfer_enable' => 1000, 'expired_at' => 0], $nodes, 'sing-box', '1.13.0') extends SingBox {
            protected function loadConfig() { return ['outbounds' => [['type' => 'selector', 'tag' => 'select', 'outbounds' => []]], 'route' => ['rules' => []]]; }
        };
        $response = $protocol->handle();
        $this->assertSame(self::UUID, $response->getData(true)['outbounds'][1]['uuid']);
        $this->assertStringNotContainsString('panel-user', $response->getContent());
    }

    public function test_fixed_credentials_are_not_written_to_audit_log(): void
    {
        Schema::create('v2_admin_audit_log', function (Blueprint $table) {
            $table->id(); $table->integer('admin_id');
            foreach (['action', 'method', 'uri', 'request_data', 'ip'] as $key) $table->text($key)->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $request = Request::create('/api/v2/test/server/external/save', 'POST', $this->payload());
        $admin = new User(['is_admin' => true]); $admin->id = 1;
        $request->setUserResolver(fn() => $admin);
        (new \App\Http\Middleware\RequestLog())->handle($request, fn() => response()->json(['data' => true]));
        $logged = Capsule::table('v2_admin_audit_log')->value('request_data');
        $this->assertNotNull($logged);
        $this->assertStringNotContainsString(self::UUID, $logged);
        $this->assertStringNotContainsString('credentials', $logged);
    }

    public function test_external_delete_cannot_target_a_managed_node(): void
    {
        $data = $this->payload(); unset($data['credentials']);
        $managed = Server::create(array_merge($data, ['server_port' => 443, 'rate' => 1]));
        try {
            (new ExternalController())->drop(Request::create('/drop', 'POST', ['id' => $managed->id]));
            $this->fail('Managed node should not be deletable through external-node API');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertNotNull(Server::find($managed->id));
        }
    }

    public function test_deleting_external_node_does_not_delete_managed_nodes(): void
    {
        $external = $this->save($this->payload());
        (new ExternalController())->drop(Request::create('/drop', 'POST', ['id' => $external->id]));
        $this->assertNull(Server::find($external->id));
    }
}
