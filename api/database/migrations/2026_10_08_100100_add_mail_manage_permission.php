<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Data migration for the new `mail.manage` permission.
 * It is admin-only by default and admin is implicit (no role_permissions rows), so no role is granted anything here.
 * Intentionally a no-op; kept so the catalog change is traceable in the migration history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // nothing to grant: sales / operations / custom roles do not receive mail.manage
    }

    public function down(): void
    {
    }
};
