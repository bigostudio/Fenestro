<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clear orphaned data from the old string-based lead_id before adding the FK constraint
        Schema::disableForeignKeyConstraints();
        DB::table('apertures')->truncate();
        DB::table('measurements')->truncate();
        Schema::enableForeignKeyConstraints();

        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->foreignId('lead_id')->after('id')->constrained('leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->string('lead_id')->after('id')->index();
        });
    }
};
