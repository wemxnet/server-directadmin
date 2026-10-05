<?php

namespace Extensions\Servers\DirectAdmin;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Extensions\Servers\DirectAdmin\Actions\DirectAdminAccountActions;
use Extensions\Servers\DirectAdmin\Providers\DirectAdminServiceProvider;
use Extensions\Servers\DirectAdmin\Support\DirectAdminAccountManager;
use Extensions\Servers\DirectAdmin\Support\DirectAdminApi;
use Illuminate\Support\Facades\Cache;

class Server extends ServerExtension
{
    protected string $id = 'server-directadmin';

    protected string $name = 'DirectAdmin';

    protected string $description = 'Resell DirectAdmin hosting accounts with one-click login, password changes, upgrades, and usage on the order page.';

    protected string $type = 'Server';

    protected string $icon = 'server';

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'mubeen@wemx.net',
        ],
    ];

    public function providers(): array
    {
        return [
            DirectAdminServiceProvider::class,
        ];
    }

    public function elements(): array
    {
        return [
            [
                'element' => 'client-order-top-view',
                'view' => 'server-directadmin::client_area.default.orders.widgets.account-panel',
            ],
            [
                'element' => 'admin-order-sidebar-view',
                'view' => 'server-directadmin::admin_area.default.orders.widgets.account-sidebar',
            ],
        ];
    }

    public function setConfig(): array
    {
        $doesNotEndWithSlash = function ($attribute, $value, $fail) {
            if (is_string($value) && str_ends_with($value, '/')) {
                $fail('Hostname must not end with a slash. Use https://da.example.com');
            }
        };

        $mustBeHttp = function ($attribute, $value, $fail) {
            if (is_string($value) && $value !== '' && ! preg_match('#^https?://#', $value)) {
                $fail('Hostname must start with https:// or http://');
            }
        };

        return [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'DirectAdmin URL without a port or trailing slash, for example https://da.example.com',
                'type' => 'text',
                'default_value' => 'https://da.example.com',
                'rules' => ['required', 'string', $mustBeHttp, $doesNotEndWithSlash],
            ],
            [
                'key' => 'port',
                'name' => 'Port',
                'description' => 'DirectAdmin port. Default is 2222.',
                'type' => 'number',
                'default_value' => 2222,
                'rules' => ['required', 'numeric', 'min:1', 'max:65535'],
            ],
            [
                'key' => 'username',
                'name' => 'Username',
                'description' => 'Admin or reseller username that creates the accounts.',
                'type' => 'text',
                'default_value' => 'admin',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'password',
                'name' => 'Login key or password',
                'description' => 'A login key is preferred. Create one in DirectAdmin → Login Keys and allow the CMD_API commands this extension uses.',
                'type' => 'password',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'verify_ssl',
                'name' => 'Verify SSL',
                'description' => 'Disable this for self-signed certificates.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '1',
                'rules' => ['required', 'in:0,1'],
            ],
            [
                'key' => 'debug_mode',
                'name' => 'Debug mode',
                'description' => 'Include API command names in error messages. Keep disabled in production.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
        ];
    }

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $packageOptions = $this->cachedOptions($connection, 'packages', fn (DirectAdminApi $api) => $api->userPackages());
        $ipOptions = $this->cachedOptions($connection, 'ips', fn (DirectAdminApi $api) => $api->ips());

        $packageField = $packageOptions === []
            ? [
                'key' => 'package',
                'name' => 'DirectAdmin package',
                'col' => 'col-4',
                'description' => 'User package name from DirectAdmin. The connection is offline, so enter the name manually.',
                'type' => 'text',
                'rules' => ['required', 'string'],
                'is_configurable' => false,
            ]
            : [
                'key' => 'package',
                'name' => 'DirectAdmin package',
                'col' => 'col-4',
                'description' => 'User package that sets the account limits.',
                'type' => 'select',
                'options' => $packageOptions,
                'default_value' => array_key_first($packageOptions),
                'rules' => ['required', 'string'],
                'is_configurable' => false,
            ];

        $ipField = $ipOptions === []
            ? [
                'key' => 'ip',
                'name' => 'IP address',
                'col' => 'col-4',
                'description' => 'Optional. Leave empty to use the first shared IP of the connection user.',
                'type' => 'text',
                'rules' => ['nullable', 'ip'],
                'is_configurable' => false,
            ]
            : [
                'key' => 'ip',
                'name' => 'IP address',
                'col' => 'col-4',
                'description' => 'IP assigned to new accounts.',
                'type' => 'select',
                'options' => $ipOptions,
                'default_value' => array_key_first($ipOptions),
                'rules' => ['nullable', 'ip'],
                'is_configurable' => false,
            ];

        return [
            $packageField,
            $ipField,
            [
                'key' => 'notify',
                'name' => 'DirectAdmin welcome email',
                'col' => 'col-4',
                'description' => 'Also send DirectAdmin\'s own welcome email. WemX sends its own email either way.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
                'is_configurable' => false,
            ],
            [
                'key' => 'allow_login',
                'name' => 'Allow DirectAdmin login',
                'col' => 'col-4',
                'description' => 'Let customers open a one-click DirectAdmin session.',
                'type' => 'select',
                'options' => [
                    '1' => 'Enabled',
                    '0' => 'Disabled',
                ],
                'default_value' => '1',
                'rules' => ['required', 'in:0,1'],
                'is_configurable' => false,
            ],
            [
                'key' => 'allow_password_change',
                'name' => 'Allow password change',
                'col' => 'col-4',
                'description' => 'Let customers change the DirectAdmin account password.',
                'type' => 'select',
                'options' => [
                    '1' => 'Enabled',
                    '0' => 'Disabled',
                ],
                'default_value' => '1',
                'rules' => ['required', 'in:0,1'],
                'is_configurable' => false,
            ],
        ];
    }

    public function setCheckoutConfig(Package $package): array
    {
        return [
            [
                'key' => 'domain',
                'name' => 'Domain',
                'description' => 'The primary domain for this hosting account, for example example.com',
                'type' => 'text',
                'rules' => ['required', 'string', 'max:191', 'regex:/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
                'is_configurable' => true,
            ],
            [
                'key' => 'username',
                'name' => 'DirectAdmin username',
                'description' => 'Optional. 3-10 lowercase letters and numbers, starting with a letter. Leave empty to generate from the domain.',
                'type' => 'text',
                'rules' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9]{2,9}$/'],
                'is_configurable' => true,
            ],
        ];
    }

    public static function testConnection(array $credentials): string
    {
        $users = DirectAdminApi::make($credentials)->users();

        return 'Connected to DirectAdmin. '.count($users).' accounts found.';
    }

    public function create(Order $order, ServerConnection $connection): void
    {
        try {
            $data = DirectAdminAccountManager::for($connection)->create($order);
            self::actions()->storeProvisionedState($order, $data);
        } catch (Exception $exception) {
            self::actions()->rememberError($order, $exception->getMessage());
            throw $exception;
        }

        $order->refresh();

        $order->user->email([
            'identifier' => 'server.directadmin.created',
            'mailable_type' => Order::class,
            'mailable_id' => $order->id,
            'variables' => [
                'domain' => $order->data['domain'] ?? '',
                'username' => $order->data['username'] ?? '',
                'password' => $order->getExternalUser()?->password,
                'ip' => $order->data['ip'] ?? '',
                'panel_url' => DirectAdminApi::fromConnection($connection)->baseUrl(),
            ],
            'button' => [
                'url' => route('orders.view', $order->id),
            ],
        ]);
    }

    public function suspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => DirectAdminAccountManager::for($connection)->suspend($order));
    }

    public function unsuspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => DirectAdminAccountManager::for($connection)->unsuspend($order));
    }

    public function terminate(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => DirectAdminAccountManager::for($connection)->terminate($order));
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, function () use ($order, $newPackagePrice, $connection) {
            $data = DirectAdminAccountManager::for($connection)->upgrade($order, $newPackagePrice);
            $order->update(['data' => $data]);
        });
    }

    public function changePassword(Order $order, string $newPassword): void
    {
        DirectAdminAccountManager::for($order->package->serverConnection)->changePassword($order, $newPassword);
        $order->updateExternalPassword($newPassword);
    }

    public static function actions(): DirectAdminAccountActions
    {
        return new DirectAdminAccountActions;
    }

    public static function usesDirectAdmin(?Order $order): bool
    {
        return $order?->package?->serverConnection?->extension_identifier === 'server-directadmin';
    }

    protected function withErrorTracking(Order $order, callable $callback): void
    {
        try {
            $callback();
        } catch (Exception $exception) {
            self::actions()->rememberError($order, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function cachedOptions(ServerConnection $connection, string $type, callable $callback): array
    {
        $connectionId = $connection->id ?? 'new';

        try {
            return Cache::remember("directadmin:{$type}:{$connectionId}", now()->addHour(), function () use ($connection, $callback) {
                return collect($callback(DirectAdminApi::fromConnection($connection)))
                    ->mapWithKeys(fn ($value) => [(string) $value => (string) $value])
                    ->all();
            });
        } catch (Exception) {
            return [];
        }
    }
}
