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

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with('package.serverConnection')->find($this->order_id);
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
            return null;
        }
    }
}

?>

@php
    $order = $this->order;
    $summary = $this->summary;
    $account = $order?->getExternalUser();
@endphp

@if($order)
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">{{ __('server-directadmin::messages.admin_title') }}</h3>
        </div>
        <div class="card-body">
            <div class="datagrid">
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.status') }}</div>
                    <div class="datagrid-content">
                        @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                            <span class="badge bg-yellow">{{ __('server-directadmin::messages.suspended_badge') }}</span>
                        @elseif($summary)
                            <span class="badge bg-green">{{ __('server-directadmin::messages.active') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ $order->external_id ? __('server-directadmin::messages.unknown') : __('server-directadmin::messages.not_provisioned') }}</span>
                        @endif
                    </div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.remote_id') }}</div>
                    <div class="datagrid-content">{{ $order->external_id ?? '—' }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.username') }}</div>
                    <div class="datagrid-content">{{ $account->username ?? ($order->data['username'] ?? '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.domain') }}</div>
                    <div class="datagrid-content">{{ $summary['domain'] ?? ($order->data['domain'] ?? '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.ip') }}</div>
                    <div class="datagrid-content">{{ $summary['ip'] ?? ($order->data['ip'] ?? '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">{{ __('server-directadmin::messages.package') }}</div>
                    <div class="datagrid-content">{{ $summary['package'] ?? ($order->data['package'] ?? '—') }}</div>
                </div>
            </div>

            @if(!empty($order->data['last_error']))
                <div class="alert alert-danger mt-3 mb-0">
                    <div class="alert-title">{{ __('server-directadmin::messages.last_error') }}</div>
                    {{ $order->data['last_error'] }}
                </div>
            @endif

            @if($order->external_id || !empty($order->data['username']))
                <div class="mt-3">
                    <a href="{{ route('admin.directadmin.login', $order) }}" target="_blank" class="btn btn-primary btn-sm">
                        {{ __('server-directadmin::messages.login_as') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif
