<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for the permission catalog and the default permission sets of the system roles.
 * Role -> permission assignments live in the `role_permissions` table (admin is implicit ALL, no rows).
 */
class Permissions
{
    /** @var array<string, list<string>>|null slug => keys, memoised per request (flushed by EnforceSession + role edits) */
    private static ?array $rolePerms = null;

    /** @var array<string, string>|null slug => display name */
    private static ?array $roleNames = null;

    /** @return list<array{key:string,label:string,items:list<array{key:string,label:string,description:string}>}> */
    public static function catalog(): array
    {
        $g = fn (string $key, string $label, array $items) => [
            'key' => $key, 'label' => $label,
            'items' => array_map(fn ($k, $v) => ['key' => "$key.$k", 'label' => $v[0], 'description' => $v[1]], array_keys($items), $items),
        ];

        return [
            $g('dashboard', 'Dashboard', [
                'view' => ['View dashboard', 'See dashboard statistics for the leads the user can access.'],
                'leaderboard' => ['View leaderboard', 'See the consultant performance leaderboard.'],
            ]),
            $g('leads', 'Leads', [
                'view_all' => ['View all leads', 'See every lead and filter by consultant. Without it a user only sees leads assigned to them.'],
                'create' => ['Create leads', 'Add new leads and run duplicate checks.'],
                'edit' => ['Edit leads (full)', 'Edit every field of a lead. Implies limited edit.'],
                'edit_limited' => ['Edit leads (limited)', 'Edit the follow-up, quote and booking fields only (the sales field set).'],
                'delete' => ['Delete leads', 'Permanently delete leads.'],
                'reassign' => ['Reassign leads', 'Move a lead to another consultant.'],
                'assignable' => ['Can own leads', 'Appears as a consultant that leads and destinations can be assigned to.'],
                'override_duplicate' => ['Override duplicate block', 'Create a lead even if the mobile number already exists.'],
                'notes' => ['Lead notes', 'Read and add notes on visible leads.'],
                'notes_delete_any' => ['Delete any note', 'Delete notes written by other users (authors can always delete their own).'],
                'contact_events' => ['Contact log', 'Read and log contact events on visible leads.'],
                'export' => ['Export leads', 'Export lead data (reserved; there is no export endpoint yet).'],
                'convert' => ['Convert leads', 'Convert a lead into a customer and a deal.'],
            ]),
            $g('customers', 'Customers', [
                'view' => ['View customers', 'Open Customers. Sees customers they own or created.'],
                'view_all' => ['View all customers', 'See every customer, whoever owns it.'],
                'create' => ['Create customers', 'Add customers, contacts and travellers.'],
                'edit' => ['Edit customers', 'Edit customers they can see, their contacts, travellers and notes.'],
                'delete' => ['Delete customers', 'Delete customers that have no deals.'],
                'view_passports' => ['View passport numbers', 'See full traveller passport numbers (others see them masked).'],
            ]),
            $g('deals', 'Deals', [
                'view' => ['View deals', 'Open Deals. Sees deals they own or created.'],
                'view_all' => ['View all deals', 'See every deal, whoever owns it.'],
                'create' => ['Create deals', 'Add deals and move them between stages.'],
                'edit' => ['Edit deals', 'Edit deals they can see, including stage, amounts and notes.'],
                'delete' => ['Delete deals', 'Permanently delete deals.'],
            ]),
            $g('forecasts', 'Forecasts', [
                'view' => ['View forecasts', 'Open Forecasts for the deals they own.'],
                'view_all' => ['View team forecasts', 'See the whole team and each consultant, not just their own deals.'],
                'manage_targets' => ['Set sales targets', 'Set and change the quarterly targets for the team and consultants.'],
            ]),
            $g('calendar', 'Calendar', [
                'view' => ['View calendar', 'See travel and follow-up dates on the calendar.'],
            ]),
            $g('rsq', 'RSQ requests', [
                'request' => ['Raise RSQ requests', 'Request supplier quotes for a visible lead.'],
                'manage' => ['Manage RSQ queue', 'See all RSQ requests, RSQ/reply notifications and the Supplier Inbox (all supplier replies; link or ignore unmatched ones).'],
            ]),
            $g('supplier_mail', 'Supplier mail', [
                'send' => ['Send supplier mail', 'Preview and send quote-request emails to suppliers.'],
            ]),
            $g('suppliers', 'Suppliers', [
                'view' => ['View suppliers', 'See the supplier list and supplier history on leads.'],
                'manage' => ['Manage suppliers', 'Add or remove suppliers and update supplier replies and follow-ups.'],
            ]),
            $g('supplier_dashboard', 'Supplier dashboard', [
                'view' => ['View supplier dashboard', 'See the supplier response tracker summary.'],
            ]),
            $g('master', 'Master data', [
                'view' => ['View master data', 'Read dropdown values, destination cities and allocations.'],
                'manage' => ['Manage master data', 'Add or remove master values and edit destination cities.'],
            ]),
            $g('allocations', 'Allocations', [
                'manage' => ['Manage allocations', 'Assign destinations to consultants.'],
            ]),
            $g('users', 'Users', [
                'view' => ['View users', 'See the user list.'],
                'manage' => ['Manage users', 'Create, edit, disable, unlock, force-reset and sign out users; revoke their sessions.'],
            ]),
            $g('roles', 'Roles', [
                'manage' => ['Manage roles', 'Create, edit and delete roles and their permissions.'],
            ]),
            $g('activity', 'Activity log', [
                'view' => ['View activity log', 'Browse the audit trail of changes.'],
            ]),
            $g('security', 'Security', [
                'view' => ['View security log', 'See the security audit log and all active sessions.'],
            ]),
            $g('activities', 'Activities', [
                'view' => ['View activities', 'Open the Activities page. Sees activities assigned to them, created by them or linked to leads they own.'],
                'view_all' => ['View all activities', 'See every activity, whoever it is assigned to.'],
                'create' => ['Create activities', 'Create tasks, calls, meetings, emails and follow-ups; edit, complete and delete the ones they created or that are assigned to them.'],
                'manage' => ['Manage activities', 'Edit, complete and delete activities of anyone and assign them to other users.'],
            ]),
            $g('mail', 'Mail integration', [
                'manage' => ['Manage mail integration', 'See the Microsoft 365 mail status (last poll, errors) and trigger a manual reply poll. Admin-only by default.'],
            ]),
            $g('ai', 'AI assistant', [
                'use' => ['Use AI assistant', 'Open and use the AI assistant panel.'],
            ]),
            $g('chat', 'Team chat', [
                'use' => ['Use team chat', 'Send and receive direct and group messages.'],
                'create_groups' => ['Manage chat groups', 'Create, rename and delete chat groups; moderate group messages.'],
            ]),
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        $out = [];
        foreach (self::catalog() as $g) {
            foreach ($g['items'] as $i) {
                $out[] = $i['key'];
            }
        }

        return $out;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** Default permission sets of the system roles (admin = implicit all). Reproduces the pre-RBAC behaviour. */
    public static function defaults(string $slug): array
    {
        return match ($slug) {
            'admin' => self::keys(),
            'sales' => [
                'dashboard.view', 'leads.create', 'leads.edit_limited', 'leads.assignable', 'leads.notes',
                'leads.contact_events', 'calendar.view', 'rsq.request', 'suppliers.view', 'master.view',
                'ai.use', 'chat.use', 'activities.view', 'activities.create',
                'leads.convert', 'customers.view', 'customers.create', 'customers.edit',
                'deals.view', 'deals.create', 'deals.edit', 'forecasts.view',
            ],
            'operations' => [
                'dashboard.view', 'leads.view_all', 'leads.create', 'leads.edit', 'leads.edit_limited', 'leads.reassign',
                'leads.notes', 'leads.contact_events', 'calendar.view', 'rsq.request', 'rsq.manage',
                'supplier_mail.send', 'suppliers.view', 'suppliers.manage', 'supplier_dashboard.view',
                'master.view', 'ai.use', 'chat.use',
                'activities.view', 'activities.view_all', 'activities.create',
                'leads.convert', 'customers.view', 'customers.view_all', 'customers.create', 'customers.edit', 'customers.view_passports',
                'deals.view', 'deals.view_all', 'deals.create', 'deals.edit', 'forecasts.view', 'forecasts.view_all',
            ],
            default => [],
        };
    }

    // ---------------------------------------------------------------- resolution

    public static function flush(): void
    {
        self::$rolePerms = null;
        self::$roleNames = null;
    }

    private static function load(): void
    {
        if (self::$rolePerms !== null) {
            return;
        }
        self::$rolePerms = [];
        self::$roleNames = [];
        if (! Schema::hasTable('roles')) {
            return;
        }
        foreach (DB::table('roles')->get(['slug', 'name']) as $r) {
            self::$roleNames[$r->slug] = $r->name;
            self::$rolePerms[$r->slug] = [];
        }
        foreach (DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->get(['roles.slug', 'role_permissions.permission']) as $p) {
            self::$rolePerms[$p->slug][] = $p->permission;
        }
    }

    /** Effective permission keys of a role slug (admin => every key; unknown role => none). Unknown stored keys are ignored. */
    public static function forRole(?string $slug): array
    {
        if ($slug === 'admin') {
            return self::keys();
        }
        self::load();

        return array_values(array_intersect(self::keys(), self::$rolePerms[$slug] ?? []));
    }

    public static function roleName(?string $slug): string
    {
        self::load();

        return self::$roleNames[$slug] ?? (string) $slug;
    }

    public static function roleExists(string $slug): bool
    {
        self::load();

        return array_key_exists($slug, self::$roleNames);
    }
}
