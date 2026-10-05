# DirectAdmin

Resell DirectAdmin hosting accounts on WemX. Checkout collects the domain, DirectAdmin creates the account from a user package, and customers manage it from the order page.

## Features

- Create accounts from a DirectAdmin user package, on a chosen or automatic IP
- Checkout asks for the primary domain and an optional username
- Email the customer their domain, username, password, IP, and panel URL (editable under Email Templates)
- One-click DirectAdmin login for the customer, and login-as for staff
- Disk and bandwidth usage on the order page
- Suspend, unsuspend, terminate, upgrade or downgrade (package change), and password changes

## Install

Install from the WemX marketplace, or download `DirectAdmin.zip` from a GitHub release and place the `DirectAdmin` folder at `extensions/Servers/DirectAdmin`. Then enable **DirectAdmin**.

Publishing a release builds `DirectAdmin.zip`. Unzipping it creates a folder named `DirectAdmin`. GitHub's own "Source code" archive still unpacks to `server-directadmin-<tag>`. Use `DirectAdmin.zip`.

## Connection

Use the DirectAdmin URL without a port or trailing slash, for example `https://da.example.com`, and port `2222`. Authenticate with an admin or reseller username and a login key from **Login Keys**. The key needs these commands:

`CMD_API_SHOW_USERS`, `CMD_API_PACKAGES_USER`, `CMD_API_SHOW_RESELLER_IPS`, `CMD_API_SHOW_USER_CONFIG`, `CMD_API_SHOW_USER_USAGE`, `CMD_API_ACCOUNT_USER`, `CMD_API_SELECT_USERS`, `CMD_API_MODIFY_USER`, `CMD_API_USER_PASSWD`, `CMD_API_LOGIN_KEYS`

One-click login uses DirectAdmin's "login as" (`admin|user`), so the connection user must own the customer accounts. Turn SSL verification off for a self-signed certificate.

## Packages

Pick the DirectAdmin user package that sets the account limits, and optionally the IP for new accounts. Leave the IP empty to use the first shared IP of the connection user. Login and password changes can be turned off per package.

## Credits

Based on the MIT-licensed DirectAdmin extension from [Paymenter](https://github.com/Paymenter/Paymenter).
