<?php

namespace Extensions\Servers\DirectAdmin\Providers;

use Illuminate\Support\ServiceProvider;

class DirectAdminServiceProvider extends ServiceProvider
{
    /**
     * Register the account-created email so admins can edit it under Email Templates.
     */
    public function register(): void
    {
        $events = config('email_events', []);

        if (array_key_exists('server.directadmin.created', $events)) {
            return;
        }

        $events['server.directadmin.created'] = [
            'name' => 'DirectAdmin account created',
            'group' => 'Servers',
            'description' => 'Sent when a DirectAdmin hosting account is provisioned for an order.',
            'subject' => 'Your hosting account is ready',
            'body' => <<<'BODY'
Your DirectAdmin hosting account has been created and is ready to use.
**Account details:**
Domain: {{domain}}
Username: {{username}}
Password: {{password}}
IP address: {{ip}}
Control panel: {{panel_url}}
You can log in to DirectAdmin with one click from your order page.
BODY,
            'button_text' => 'Manage hosting',
            'placeholders' => [
                'domain' => 'Primary domain',
                'username' => 'DirectAdmin username',
                'password' => 'DirectAdmin password',
                'ip' => 'Assigned IP address',
                'panel_url' => 'DirectAdmin URL',
            ],
        ];

        config(['email_events' => $events]);
    }
}
