<?php
/**
 * Laravel Service Provider for taha-rafi/php-zkteco
 *
 * Automatically discovered by Laravel 8/9/10/11/12 via composer.json extra.laravel.
 *
 * @package   TahaRafi\ZKTeco\Laravel
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace TahaRafi\ZKTeco\Laravel;

use Illuminate\Support\ServiceProvider;
use TahaRafi\ZKTeco\AdmsServer;
use TahaRafi\ZKTeco\Constants;
use TahaRafi\ZKTeco\WebhookDispatcher;
use TahaRafi\ZKTeco\ZKTeco;

class ZKTecoServiceProvider extends ServiceProvider
{
    /**
     * Register ZKTeco and AdmsServer bindings in the Laravel container.
     */
    public function register(): void
    {
        $configPath = __DIR__ . '/../../config/zkteco.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'zkteco');
        }

        $this->app->singleton(ZKTeco::class, function ($app): ZKTeco {
            /** @var array<string, mixed> $cfg */
            $cfg = $app['config']->get('zkteco', []);

            $zk = new ZKTeco(
                ip: (string)($cfg['ip'] ?? '192.168.1.201'),
                port: (int)($cfg['port'] ?? 4370),
                commKey: (int)($cfg['comm_key'] ?? 0),
                protocol: (string)($cfg['protocol'] ?? Constants::PROTOCOL_AUTO),
                timeoutSec: (int)($cfg['timeout'] ?? 5)
            );

            $webhookUrl = $cfg['webhook']['url'] ?? null;
            if (is_string($webhookUrl) && $webhookUrl !== '') {
                $zk->addWebhook(
                    url: $webhookUrl,
                    secret: isset($cfg['webhook']['secret']) ? (string)$cfg['webhook']['secret'] : null,
                    events: is_array($cfg['webhook']['events'] ?? null) ? $cfg['webhook']['events'] : ['*']
                );
            }

            return $zk;
        });

        $this->app->alias(ZKTeco::class, 'zkteco');

        $this->app->singleton(AdmsServer::class, function ($app): AdmsServer {
            /** @var array<string, mixed> $cfg */
            $cfg = $app['config']->get('zkteco', []);
            /** @var ZKTeco $zk */
            $zk = $app->make(ZKTeco::class);

            return new AdmsServer(
                webhookDispatcher: $zk->getWebhookDispatcher(),
                commandQueueFile: (string)($cfg['adms']['queue_file'] ?? (sys_get_temp_dir() . '/zkteco_adms_queue.json')),
                timezoneOffset: (int)($cfg['adms']['timezone_offset'] ?? 5)
            );
        });
    }

    /**
     * Bootstrap publishable configuration for Laravel applications.
     */
    public function boot(): void
    {
        $configPath = __DIR__ . '/../../config/zkteco.php';
        if ($this->app->runningInConsole() && file_exists($configPath)) {
            $this->publishes([
                $configPath => $this->app->configPath('zkteco.php'),
            ], 'zkteco-config');
        }
    }
}
