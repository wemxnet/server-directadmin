@if(isset($order) && \Extensions\Servers\DirectAdmin\Server::usesDirectAdmin($order))
    @livewire('admin_area.default.orders.livewire.directadmin-account-sidebar', ['order_id' => $order->id])
@endif
