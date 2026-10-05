@if(isset($order) && \Extensions\Servers\DirectAdmin\Server::usesDirectAdmin($order))
    @livewire('client_area.default.orders.livewire.directadmin-account-panel', ['order_id' => $order->id])
@endif
