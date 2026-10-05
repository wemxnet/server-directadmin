<?php

namespace Extensions\Servers\DirectAdmin\Actions;

use App\Actions\Action;
use App\Models\Order;
use App\Models\User;
use Extensions\Servers\DirectAdmin\Server;
use Extensions\Servers\DirectAdmin\Support\DirectAdminAccountManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DirectAdminAccountActions extends Action
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsClient(array $input): string
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_login', 'DirectAdmin login is not enabled for this package.');

        return DirectAdminAccountManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsAdmin(array $input): string
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
        ])->validate();

        $order = $this->adminOrder($validated['order_id'], $validated['user_id']);

        return DirectAdminAccountManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function changePasswordAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_password_change', 'Password changes are not enabled for this package.');

        DirectAdminAccountManager::for($order->package->serverConnection)->changePassword($order, $validated['password']);
        $order->updateExternalPassword($validated['password']);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeProvisionedState(Order $order, array $data): void
    {
        $password = $data['password'] ?? null;
        $accountData = $data;
        unset($accountData['password']);

        $order->update([
            'external_id' => (string) $data['username'],
            'data' => $accountData,
        ]);

        $existing = $order->getExternalUser();

        if ($existing) {
            $existing->update([
                'external_id' => (string) $data['username'],
                'username' => $data['username'] ?? $existing->username,
                'password' => $password ?? $existing->password,
                'data' => $accountData,
            ]);

            return;
        }

        $order->createExternalUser([
            'external_id' => (string) $data['username'],
            'username' => $data['username'],
            'password' => $password ?? 'unknown',
            'data' => $accountData,
        ]);
    }

    public function rememberError(Order $order, string $message): void
    {
        $data = $order->data ?? [];
        $data['last_error'] = $message;
        $order->update(['data' => $data]);
    }

    protected function assertFlag(Order $order, string $flag, string $message): void
    {
        if ((string) $order->option($flag, '1') !== '1') {
            throw ValidationException::withMessages([
                'order_id' => $message,
            ]);
        }
    }

    protected function authorizedOrder(int|string $orderId, int|string $userId, bool $requireActive = false): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'members', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'User not found.',
            ]);
        }

        if (! Server::usesDirectAdmin($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on DirectAdmin.',
            ]);
        }

        $isOwner = (int) $order->user_id === (int) $user->id;
        $isMember = $order->members()
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->exists();

        if (! $isOwner && ! $isMember) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        if ($requireActive && $order->status !== 'active') {
            throw ValidationException::withMessages([
                'order_id' => 'This action is only available while the account is active.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function adminOrder(int|string $orderId, int|string $userId): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! Server::usesDirectAdmin($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on DirectAdmin.',
            ]);
        }

        if (! $user || (! $user->isAdmin() && ! $user->hasPermission('admin.orders.view'))) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function assertProvisioned(Order $order): void
    {
        if (! $order->external_id && empty($order->data['username'])) {
            throw ValidationException::withMessages([
                'order_id' => 'This DirectAdmin account has not finished provisioning yet.',
            ]);
        }
    }
}
