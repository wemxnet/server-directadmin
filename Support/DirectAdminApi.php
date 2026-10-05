<?php

namespace Extensions\Servers\DirectAdmin\Support;

use App\Models\ServerConnection;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DirectAdminApi
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        protected array $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public static function fromConnection(ServerConnection $connection): self
    {
        return new self($connection->config ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function users(): array
    {
        return $this->list($this->get('CMD_API_SHOW_USERS'));
    }

    /**
     * @return array<int, string>
     */
    public function userPackages(): array
    {
        return $this->list($this->get('CMD_API_PACKAGES_USER'));
    }

    /**
     * @return array<int, string>
     */
    public function ips(): array
    {
        return $this->list($this->get('CMD_API_SHOW_RESELLER_IPS'));
    }

    /**
     * @return array<string, mixed>
     */
    public function userConfig(string $username): array
    {
        return $this->get('CMD_API_SHOW_USER_CONFIG', ['user' => $username]);
    }

    /**
     * @return array<string, mixed>
     */
    public function userUsage(string $username): array
    {
        return $this->get('CMD_API_SHOW_USER_USAGE', ['user' => $username]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createUser(array $payload): array
    {
        return $this->post('CMD_API_ACCOUNT_USER', array_merge([
            'action' => 'create',
            'add' => 'Submit',
        ], $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function suspendUser(string $username): array
    {
        return $this->post('CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Suspend',
            'dosuspend' => 1,
            'select0' => $username,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function unsuspendUser(string $username): array
    {
        return $this->post('CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Unsuspend',
            'dounsuspend' => 1,
            'select0' => $username,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteUser(string $username): array
    {
        return $this->post('CMD_API_SELECT_USERS', [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $username,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function changePackage(string $username, string $package): array
    {
        return $this->post('CMD_API_MODIFY_USER', [
            'action' => 'package',
            'user' => $username,
            'package' => $package,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function changePassword(string $username, string $password): array
    {
        return $this->post('CMD_API_USER_PASSWD', [
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        ]);
    }

    /**
     * Create a one-time login URL for a user. The request is made as the
     * connection user logged in as the customer ("admin|customer"), so it keeps
     * working after the customer changes their password inside DirectAdmin.
     */
    public function oneTimeLoginUrl(string $username, string $expiry = '5m'): string
    {
        $result = $this->post('CMD_API_LOGIN_KEYS', [
            'action' => 'create',
            'type' => 'one_time_url',
            'expiry' => $expiry,
        ], loginAs: $username);

        $url = (string) ($result['details'] ?? '');

        if ($url === '') {
            throw new Exception('DirectAdmin did not return a login URL.');
        }

        return $url;
    }

    public function baseUrl(): string
    {
        $hostname = rtrim((string) ($this->credentials['hostname'] ?? ''), '/');
        $port = $this->credentials['port'] ?? null;

        if ($hostname === '') {
            throw new Exception('DirectAdmin hostname is not configured.');
        }

        if ($port && ! preg_match('/:\d+$/', $hostname)) {
            $hostname .= ':'.$port;
        }

        return $hostname;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $command, array $query = []): array
    {
        return $this->send('get', $command, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $command, array $data = [], ?string $loginAs = null): array
    {
        return $this->send('post', $command, $data, $loginAs);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function send(string $method, string $command, array $data = [], ?string $loginAs = null): array
    {
        try {
            $response = $this->client($loginAs)->{$method}($this->baseUrl().'/'.$command, $data);
        } catch (ConnectionException $exception) {
            throw new Exception($this->friendlyError($command, 'Could not connect to DirectAdmin: '.$exception->getMessage()));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new Exception($this->friendlyError($command, 'DirectAdmin rejected the credentials. Check the username and login key.'));
        }

        if ($response->failed()) {
            throw new Exception($this->friendlyError($command, "DirectAdmin returned HTTP {$response->status()}."));
        }

        $parsed = $this->parse($response);

        if (isset($parsed['error']) && (string) $parsed['error'] !== '0') {
            $message = trim((string) ($parsed['text'] ?? 'DirectAdmin request failed.').' '.(string) ($parsed['details'] ?? ''));

            throw new Exception($this->friendlyError($command, $message));
        }

        return $parsed;
    }

    protected function client(?string $loginAs = null): PendingRequest
    {
        $username = (string) ($this->credentials['username'] ?? '');

        if ($loginAs) {
            $username .= '|'.$loginAs;
        }

        $client = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->withBasicAuth($username, (string) ($this->credentials['password'] ?? ''));

        if ((string) ($this->credentials['verify_ssl'] ?? '1') !== '1') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * DirectAdmin's legacy API answers with URL-encoded strings, for example
     * "error=0&text=Success" or "list[]=one&list[]=two".
     *
     * @return array<string, mixed>
     */
    protected function parse(Response $response): array
    {
        $body = trim($response->body());

        if ($body === '') {
            return [];
        }

        if (str_starts_with($body, '<')) {
            throw new Exception('DirectAdmin returned an HTML page instead of an API response. Check the hostname, port, and credentials.');
        }

        parse_str(html_entity_decode($body), $parsed);

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<int, mixed>
     */
    protected function list(array $parsed): array
    {
        $list = $parsed['list'] ?? [];

        return is_array($list) ? array_values(array_filter($list, fn ($value) => $value !== '')) : [];
    }

    protected function friendlyError(string $command, string $message): string
    {
        if ((string) ($this->credentials['debug_mode'] ?? '0') === '1') {
            return "[{$command}] {$message}";
        }

        return $message;
    }
}
