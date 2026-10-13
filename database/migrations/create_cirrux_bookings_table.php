<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cirrux_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->string('calendar_uuid');
            $table->string('event_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('attendee_email');
            $table->string('attendee_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('status');
            // Set while a refresh does not find the event; it only counts as cancelled externally after two such refreshes in a row.
            $table->dateTime('missing_since')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamps();

            $table->unique(['calendar_uuid', 'event_id']);
            $table->index(['calendar_uuid', 'status', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cirrux_bookings');
    }
};
