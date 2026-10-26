<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('body');
            $table->date('recommended_on');
            $table->timestamps();

            $table->unique(['workspace_id', 'recommended_on'], 'budget_rec_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_recommendations');
    }
};
