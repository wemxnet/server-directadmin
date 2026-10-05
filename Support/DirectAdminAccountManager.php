<?php

namespace Extensions\Servers\DirectAdmin\Support;

use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Illuminate\Support\Str;

class DirectAdminAccountManager
{
    public function __construct(
        protected DirectAdminApi $api,
        protected ServerConnection $connection,
    ) {}

    public static function for(ServerConnection $connection): self
    {
        return new self(DirectAdminApi::fromConnection($connection), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Order $order): array
    {
        if ($order->external_id) {
            return $this->existingState($order);
        }

        $plan = $this->planFromPackage($order->package, [
            'domain' => $order->option('domain'),
            'username' => $order->option('username'),
        ]);

        $domain = $this->domainFor($order, $plan);
        $username = $this->usernameFor($order, $plan, $domain);
        $password = $this->passwordFor($order);
        $ip = $this->ipFor($plan);

        $this->api->createUser([
            'username' => $username,
            'email' => $order->user?->email,
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => $domain,
            'package' => $plan['package'],
            'ip' => $ip,
            'notify' => (string) ($plan['notify'] ?? '0') === '1' ? 'yes' : 'no',
        ]);

        return [
            'username' => $username,
            'domain' => $domain,
            'ip' => $ip,
            'package' => $plan['package'],
            'last_error' => null,
            'password' => $password,
        ];
    }

    public function suspend(Order $order): void
    {
        $this->api->suspendUser($this->username($order));
    }

    public function unsuspend(Order $order): void
    {
        $this->api->unsuspendUser($this->username($order));
    }

    public function terminate(Order $order): void
    {
        $this->api->deleteUser($this->username($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function upgrade(Order $order, PackagePrice $newPackagePrice): array
    {
        $plan = $this->planFromPackage($newPackagePrice->package);

        if (($plan['package'] ?? '') !== '') {
            $this->api->changePackage($this->username($order), (string) $plan['package']);
        }

        $data = $order->data ?? [];
        $data['package'] = $plan['package'] ?? ($data['package'] ?? null);
        $data['last_error'] = null;

        return $data;
    }

    public function changePassword(Order $order, string $password): void
    {
        $this->api->changePassword($this->username($order), $password);
    }

    public function loginUrl(Order $order): string
    {
        return $this->api->oneTimeLoginUrl($this->username($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        $username = $this->username($order);
        $config = $this->api->userConfig($username);
        $usage = $this->safeUsage($username);

        return [
            'username' => $username,
            'domain' => $config['domain'] ?? ($order->data['domain'] ?? null),
            'ip' => $config['ip'] ?? ($order->data['ip'] ?? null),
            'package' => $config['package'] ?? ($order->data['package'] ?? null),
            'suspended' => strtolower((string) ($config['suspended'] ?? 'no')) === 'yes',
            'disk_used' => $usage['quota'] ?? null,
            'disk_limit' => $config['quota'] ?? null,
            'bandwidth_used' => $usage['bandwidth'] ?? null,
            'bandwidth_limit' => $config['bandwidth'] ?? null,
            'nameservers' => array_values(array_filter([
                $config['ns1'] ?? null,
                $config['ns2'] ?? null,
            ])),
        ];
    }

    public function username(Order $order): string
    {
        $username = (string) ($order->external_id ?: ($order->data['username'] ?? ''));

        if ($username === '') {
            throw new Exception('This DirectAdmin account has not finished provisioning yet.');
        }

        return $username;
    }

    /**
     * @return array<string, mixed>
     */
    protected function existingState(Order $order): array
    {
        $data = $order->data ?? [];
        $data['username'] = $order->external_id;
        $data['last_error'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function planFromPackage(?Package $package, array $options = []): array
    {
        $plan = [];

        foreach (['package', 'ip', 'notify', 'domain', 'username'] as $key) {
            $plan[$key] = $options[$key] ?? $package?->data($key);
        }

        if (($plan['package'] ?? '') === '') {
            throw new Exception('No DirectAdmin package is set on this WemX package.');
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function domainFor(Order $order, array $plan): string
    {
        $domain = strtolower(trim((string) ($plan['domain'] ?: ($order->data['domain'] ?? ''))));

        if ($domain === '') {
            throw new Exception('A domain is required to create a DirectAdmin account.');
        }

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function usernameFor(Order $order, array $plan, string $domain): string
    {
        $username = strtolower((string) ($plan['username'] ?: Str::before($domain, '.')));
        $username = preg_replace('/[^a-z0-9]/', '', $username) ?? '';

        if (strlen($username) < 3 || ! preg_match('/^[a-z]/', $username)) {
            $username = 'da'.$order->id;
        }

        return substr($username, 0, 10);
    }

    protected function passwordFor(Order $order): string
    {
        $existing = $order->getExternalUser()?->password;

        if (is_string($existing) && $existing !== '' && $existing !== 'unknown') {
            return $existing;
        }

        return Str::password(16, symbols: false);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function ipFor(array $plan): string
    {
        $ip = (string) ($plan['ip'] ?? '');

        if ($ip !== '') {
            return $ip;
        }

        $ip = (string) ($this->api->ips()[0] ?? '');

        if ($ip === '') {
            throw new Exception('DirectAdmin has no IP address available for new accounts.');
        }

        return $ip;
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeUsage(string $username): array
    {
        try {
            return $this->api->userUsage($username);
        } catch (Exception) {
            return [];
        }
    }
}
