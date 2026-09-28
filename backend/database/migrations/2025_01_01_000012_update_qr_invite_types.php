<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Migration preserved for historical reference.
        // The QRInviteType enum now uses standardized names:
        //   admin_to_servant_invite, servant_to_member_invite, attendance_qr
        // No data migration is needed for fresh installs.
    }

    public function down(): void
    {
    }
};
