<?php

namespace Tests\Unit\Protocols;

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Protocols\Shadowrocket;
use App\Support\Setting;
use App\Support\ProtocolManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NaiveSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository());
        $app->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->createStub(ViewFactory::class),
            $this->createStub(Redirector::class),
        ));
        $app->instance(Setting::class, $this->createStub(Setting::class));
        $manager = new ProtocolManager($app);
        $app->instance('protocols.manager', $manager);
        $app->instance('protocols.flags', $manager->getAllFlags());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        parent::tearDown();
    }

    private function user(): array
    {
        return ['uuid' => 'user-uuid', 'u' => 1, 'd' => 2, 'transfer_enable' => 1000, 'expired_at' => 0];
    }

    private function node(): array
    {
        return [
            'type' => Server::TYPE_NAIVE,
            'name' => '香港 Naive #1',
            'host' => 'proxy.example.com',
            'port' => 443,
            'password' => 'node-credential',
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'tls.example.com'],
            ],
        ];
    }

    private function singBox(array $nodes, ?string $name = 'sing-box', ?string $version = '1.13.0', ?string $ua = null): SingBox
    {
        return new class($this->user(), $nodes, $name, $version, $ua) extends SingBox {
            protected function loadConfig()
            {
                return [
                    'outbounds' => [
                        ['type' => 'selector', 'tag' => 'select', 'outbounds' => ['direct']],
                        ['type' => 'urltest', 'tag' => 'auto', 'outbounds' => ['direct']],
                        ['type' => 'direct', 'tag' => 'direct'],
                    ],
                    'route' => ['rules' => []],
                ];
            }
        };
    }

    public function test_general_subscription_contains_naive_and_existing_nodes(): void
    {
        $node = $this->node();
        $http = array_replace($node, ['type' => Server::TYPE_HTTP, 'name' => 'HTTP']);
        $response = (new General($this->user(), [$node, $http]))->handle();
        $decoded = base64_decode($response->getContent(), true);

        $this->assertStringContainsString(General::buildNaive($node['password'], $node), $decoded);
        $this->assertStringContainsString(General::buildHttp($http['password'], $http), $decoded);
        $this->assertSame('upload=1; download=2; total=1000; expire=0', $response->headers->get('subscription-userinfo'));
        $this->assertCount(2, explode("\r\n", trim($decoded)));
    }

    public function test_uri_encodes_credentials_name_and_ipv6(): void
    {
        $node = $this->node();
        $node['host'] = '2001:db8::1';
        $credential = 'p@ss:/?#% +';
        $uri = General::buildNaive($credential, $node);
        $parts = parse_url(trim($uri));

        $this->assertSame('naive+https', $parts['scheme']);
        $this->assertSame('[2001:db8::1]', $parts['host']);
        $this->assertSame($credential, rawurldecode($parts['user']));
        $this->assertSame($credential, rawurldecode($parts['pass']));
        $this->assertSame($node['name'], rawurldecode($parts['fragment']));
        parse_str($parts['query'], $query);
        $this->assertSame('tls.example.com', $query['sni']);
    }

    public function test_shadowrocket_subscription_contains_naive_and_existing_nodes(): void
    {
        $node = $this->node();
        $trojan = array_replace($node, ['type' => Server::TYPE_TROJAN, 'name' => 'Trojan']);
        $trojan['protocol_settings']['network'] = 'tcp';
        $response = (new Shadowrocket($this->user(), [$node, $trojan], 'shadowrocket', '2.2.80'))->handle();
        $decoded = base64_decode($response->getContent(), true);

        $this->assertStringContainsString("STATUS=", $decoded);
        $this->assertStringContainsString(Shadowrocket::buildNaive($node['password'], $node), $decoded);
        $this->assertStringContainsString(Shadowrocket::buildTrojan($trojan['password'], $trojan), $decoded);
        $this->assertStringNotContainsString('naive+https://', $decoded);
        $this->assertCount(3, explode("\r\n", trim($decoded)));
    }

    public function test_shadowrocket_uri_preserves_external_credentials_ipv6_and_padding(): void
    {
        $node = $this->node();
        $node['host'] = '2001:db8::1';
        $node['username'] = 'external+user@site';
        $node['password'] = 'p@ss:/?#% +';
        $node['protocol_settings']['tls_settings']['allow_insecure'] = true;
        $node['protocol_settings']['tls_settings']['ech'] = ['key' => 'private-key'];
        $node['protocol_settings']['utls'] = ['enabled' => true, 'fingerprint' => 'chrome'];
        $uri = Shadowrocket::buildNaive($node['password'], $node);
        [$authority, $queryAndName] = explode('?', substr(trim($uri), strlen('http2://')), 2);
        [$query, $name] = explode('#', $queryAndName, 2);
        parse_str($query, $params);

        $this->assertSame('external+user@site:p@ss:/?#% +@[2001:db8::1]:443', base64_decode($authority, true));
        $this->assertSame(['padding' => '1', 'peer' => 'tls.example.com'], $params);
        $this->assertSame($node['name'], rawurldecode($name));
        $this->assertStringNotContainsString('private-key', $uri);
    }

    public function test_shadowrocket_uri_uses_managed_credentials_without_sni(): void
    {
        $node = $this->node();
        $node['protocol_settings']['tls_settings'] = null;

        $this->assertSame(
            'http2://' . base64_encode('node-credential:node-credential@proxy.example.com:443')
                . '?padding=1#' . rawurlencode($node['name']) . "\r\n",
            Shadowrocket::buildNaive($node['password'], $node),
        );
    }

    #[DataProvider('shadowrocketRequests')]
    public function test_controller_selects_shadowrocket_naive_export(array $parameters, ?string $ua): void
    {
        $node = $this->node();
        $request = Request::create('/api/v1/client/subscribe', 'GET', $parameters);
        if ($ua !== null) {
            $request->headers->set('User-Agent', $ua);
        }
        $response = (new ClientController())->doSubscribe($request, $this->user(), [$node]);
        $decoded = base64_decode($response->getContent(), true);

        $this->assertStringContainsString(Shadowrocket::buildNaive($node['password'], $node), $decoded);
        $this->assertStringNotContainsString('naive+https://', $decoded);
    }

    public static function shadowrocketRequests(): array
    {
        return [
            'automatic user agent' => [[], 'Shadowrocket/2.2.80 (iPhone; iOS 18.0)'],
            'explicit flag and type filter' => [['flag' => 'shadowrocket', 'types' => 'naive'], null],
        ];
    }

    public function test_controller_general_subscription_accepts_naive_type_filter(): void
    {
        $node = $this->node();
        $http = array_replace($node, ['type' => Server::TYPE_HTTP, 'name' => 'HTTP']);
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['flag' => 'general', 'types' => 'naive']);
        $response = (new ClientController())->doSubscribe($request, $this->user(), [$node, $http]);
        $decoded = base64_decode($response->getContent(), true);

        // The controller adds a status entry for the node excluded by types=naive.
        $this->assertStringContainsString(General::buildNaive($node['password'], $node), $decoded);
        $this->assertStringNotContainsString(General::buildHttp($http['password'], $http), $decoded);
        $this->assertStringContainsString('naive+https://', $decoded);
    }

    public function test_old_sing_box_retains_existing_supported_nodes(): void
    {
        $node = $this->node();
        $http = array_replace($node, ['type' => Server::TYPE_HTTP, 'name' => 'HTTP']);
        $config = $this->singBox([$node, $http], 'sing-box', '1.12.0')->handle()->getData(true);

        $this->assertNotContains('naive', array_column($config['outbounds'], 'type'));
        $this->assertContains('http', array_column($config['outbounds'], 'type'));
        $this->assertContains('HTTP', $config['outbounds'][0]['outbounds']);
        $this->assertNotContains($node['name'], $config['outbounds'][0]['outbounds']);
    }

    public function test_uri_without_sni_does_not_have_an_empty_query(): void
    {
        $node = $this->node();
        $node['protocol_settings']['tls_settings'] = null;

        $this->assertSame(
            'naive+https://node-credential:node-credential@proxy.example.com:443#' . rawurlencode($node['name']) . "\r\n",
            General::buildNaive($node['password'], $node),
        );
    }

    public function test_sing_box_uses_naive_outbound_and_node_credentials(): void
    {
        $node = $this->node();
        $node['protocol_settings']['tls_settings']['allow_insecure'] = true;
        $node['protocol_settings']['tls_settings']['alpn'] = ['h2'];
        $node['protocol_settings']['utls'] = ['enabled' => true, 'fingerprint' => 'chrome'];
        $config = $this->singBox([$node])->handle()->getData(true);
        $outbound = $config['outbounds'][3];

        $this->assertSame([
            'type' => 'naive',
            'tag' => $node['name'],
            'server' => $node['host'],
            'server_port' => 443,
            'username' => $node['password'],
            'password' => $node['password'],
            'tls' => ['enabled' => true, 'server_name' => 'tls.example.com'],
        ], $outbound);
        $this->assertContains($node['name'], $config['outbounds'][0]['outbounds']);
        $this->assertContains($node['name'], $config['outbounds'][1]['outbounds']);
    }

    public function test_sing_box_preserves_public_ech_but_not_private_keys(): void
    {
        $node = $this->node();
        $node['protocol_settings']['tls_settings']['ech'] = [
            'enabled' => true,
            'config' => "-----BEGIN ECH CONFIGS-----\nAA==\n-----END ECH CONFIGS-----",
            'key' => 'private-key-must-not-be-published',
        ];
        $response = $this->singBox([$node])->handle();
        $tls = $response->getData(true)['outbounds'][3]['tls'];

        $this->assertSame([$node['protocol_settings']['tls_settings']['ech']['config']], $tls['ech']['config']);
        $this->assertStringNotContainsString('private-key-must-not-be-published', $response->getContent());
    }

    #[DataProvider('clientVersions')]
    public function test_naive_is_filtered_for_unsupported_or_unknown_core_versions(?string $name, ?string $version, ?string $ua, bool $included): void
    {
        $config = $this->singBox([$this->node()], $name, $version, $ua)->handle()->getData(true);

        $this->assertSame($included, in_array('naive', array_column($config['outbounds'], 'type'), true));
    }

    public static function clientVersions(): array
    {
        return [
            'old core' => ['sing-box', '1.12.0', null, false],
            'supported core' => ['sing-box', '1.13.0', null, true],
            'future core' => ['sing-box', '1.14.0', null, true],
            'unknown version' => ['sing-box', null, null, false],
            'no client' => [null, null, null, false],
            'wrapper version is not core version' => ['hiddify', '2.0.0', null, false],
            'wrapper with explicit supported core' => ['hiddify', '2.0.0', 'Hiddify/2.0.0 sing-box/1.13.0', true],
            'wrapper with explicit old core' => ['hiddify', '2.0.0', 'Hiddify/2.0.0 sing-box 1.12.0', false],
        ];
    }

    public function test_non_tls_nodes_are_not_silently_exported_as_https(): void
    {
        foreach ([0, 2, null] as $tls) {
            $node = $this->node();
            $node['protocol_settings']['tls'] = $tls;
            $this->assertSame('', General::buildNaive($node['password'], $node));
            $this->assertSame('', Shadowrocket::buildNaive($node['password'], $node));
            $decoded = base64_decode((new Shadowrocket($this->user(), [$node]))->handle()->getContent(), true);
            $this->assertStringNotContainsString('http2://', $decoded);
            $config = $this->singBox([$node])->handle()->getData(true);
            $this->assertNotContains('naive', array_column($config['outbounds'], 'type'));
        }
    }

    public function test_clash_meta_does_not_receive_an_unsupported_naive_type(): void
    {
        $protocol = new class($this->user(), [$this->node()]) extends ClashMeta {
            public function servers(): array
            {
                return $this->servers;
            }
        };

        $this->assertSame([], $protocol->servers());
    }
}
