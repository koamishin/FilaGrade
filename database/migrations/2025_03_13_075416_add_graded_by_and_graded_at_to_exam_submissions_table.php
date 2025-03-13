<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exam_submissions', function (Blueprint $table) {
            $table->uuid('graded_by')->nullable()->after('submitted_at');
            $table->timestamp('graded_at')->nullable()->after('graded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_submissions', function (Blueprint $table) {
            $table->dropColumn(['graded_by', 'graded_at']);
        });
    }
};
