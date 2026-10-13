<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cirrux_tokens', function (Blueprint $table): void {
            // One row: the token pair of the account the package is logged in with.
            $table->unsignedSmallInteger('id')->primary();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('access_token_expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cirrux_tokens');
    }
};
