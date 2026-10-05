<?php

use App\Models\Order;
use Extensions\Servers\DirectAdmin\Server;
use Extensions\Servers\DirectAdmin\Support\DirectAdminAccountManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $order_id;

    public bool $showPassword = false;

    public string $password = '';

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with(['package.serverConnection', 'user'])->find($this->order_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $order = $this->order;

        if (! $order || ! Server::usesDirectAdmin($order) || (! $order->external_id && empty($order->data['username']))) {
            return null;
        }

        try {
            return DirectAdminAccountManager::for($order->package->serverConnection)->summary($order);
        } catch (Throwable) {
            return ['error' => true];
        }
    }

    public function refreshPanel(): void
    {
        unset($this->order, $this->summary);
    }

    public function changePassword(): void
    {
        Server::actions()->changePasswordAsClient([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'password' => $this->password,
        ]);

        $this->reset('password');
        unset($this->order);
        $this->dispatch('toast', type: 'success', message: __('server-directadmin::messages.password_updated'), title: 'Success');
    }
}

?>

<div wire:poll.60s="refreshPanel">
    @php
        $order = $this->order;
        $summary = $this->summary;
        $provisioned = $order && ($order->external_id || !empty($order->data['username']));
        $canManage = $provisioned && $order->status === 'active';
        $account = $order?->getExternalUser();
        $password = $account?->password;
        $enabled = fn (string $flag) => (string) $order?->option($flag, '1') === '1';
        $limit = fn ($value) => ($value === null || $value === '' || strtolower((string) $value) === 'unlimited') ? __('server-directadmin::messages.unlimited') : $value;
    @endphp

    @if($order)
        <x-theme::card class="mb-4">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('server-directadmin::messages.account') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $order->data['domain'] ?? $order->package->name }}
                        @if(!empty($order->data['username']))
                            · {{ $order->data['username'] }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                        <x-theme::badge.warning :text="__('server-directadmin::messages.suspended_badge')" />
                    @elseif($summary && empty($summary['error']))
                        <x-theme::badge.success :text="__('server-directadmin::messages.active')" />
                    @else
                        <x-theme::badge.primary :text="__('server-directadmin::messages.unknown')" />
                    @endif

                    @if($canManage && $enabled('allow_login'))
                        <x-theme::button.primary :href="route('directadmin.login', $order)" target="_blank" :text="__('server-directadmin::messages.login')" />
                    @endif
                </div>
            </div>

            @if($order->status === 'suspended')
                <x-theme::alert.warning class="mb-4" :text="__('server-directadmin::messages.suspended')" />
            @endif

            @if(! $provisioned)
                <x-theme::alert.primary :text="__('server-directadmin::messages.not_provisioned')" />
            @elseif(($summary['error'] ?? false) === true)
                <x-theme::alert.warning :text="__('server-directadmin::messages.unavailable')" />
            @else
                <x-theme::datagrid.grid :cols="3" :gap="4">
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.domain') }}</x-slot:label>
                        {{ $summary['domain'] ?? ($order->data['domain'] ?? '—') }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.ip') }}</x-slot:label>
                        {{ $summary['ip'] ?? ($order->data['ip'] ?? '—') }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.package') }}</x-slot:label>
                        {{ $summary['package'] ?? ($order->data['package'] ?? '—') }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.username') }}</x-slot:label>
                        {{ $account->username ?? ($order->data['username'] ?? '—') }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.password') }}</x-slot:label>
                        @if($password)
                            <span class="inline-flex items-center gap-2">
                                <span>{{ $showPassword ? $password : str_repeat('•', 10) }}</span>
                                <button type="button" wire:click="$toggle('showPassword')" class="text-xs text-primary-700 hover:underline dark:text-primary-400">
                                    {{ $showPassword ? __('server-directadmin::messages.hide') : __('server-directadmin::messages.show') }}
                                </button>
                            </span>
                        @else
                            —
                        @endif
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.nameservers') }}</x-slot:label>
                        {{ implode(', ', $summary['nameservers'] ?? []) ?: '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.disk') }}</x-slot:label>
                        {{ $summary['disk_used'] ?? '—' }} / {{ $limit($summary['disk_limit'] ?? null) }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-directadmin::messages.bandwidth') }}</x-slot:label>
                        {{ $summary['bandwidth_used'] ?? '—' }} / {{ $limit($summary['bandwidth_limit'] ?? null) }}
                    </x-theme::datagrid.item>
                </x-theme::datagrid.grid>
            @endif
        </x-theme::card>

        @if($canManage && $enabled('allow_password_change'))
            <x-theme::card class="mb-4">
                <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">{{ __('server-directadmin::messages.change_password') }}</h4>
                <div class="mb-3 max-w-md">
                    <x-theme::form.label for="directadmin-password" :text="__('server-directadmin::messages.new_password')" />
                    <x-theme::form.input id="directadmin-password" type="password" wire:model="password" />
                    @error('password')
                        <x-theme::form.error :text="$message" />
                    @enderror
                </div>
                <x-theme::button.primary type="button" wire:click="changePassword" :text="__('server-directadmin::messages.save_password')" />
            </x-theme::card>
        @endif
    @endif
</div>
