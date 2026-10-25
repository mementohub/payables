<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planul anului: nu un procent de creștere, ci cifrele din care se naște.
 *
 * Fiecare rând e o pârghie — câți pasageri, pe câte locuri contractate, cu
 * ce grad de ocupare, la ce venit și ce marjă de pasager — iar raportul
 * planificat iese din ele, lună de lună. Ce se scrie de mână rămâne scris:
 * reconstrucția din istoric nu calcă peste mâna omului.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name');
            // Anul din care s-a pornit: la el se compară planul.
            $table->unsignedSmallInteger('base_year');
            $table->string('status', 12)->default('draft');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'name']);
        });

        Schema::create('plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            // category (pe categorie de produs), charter (pe destinație),
            // opex (pe linie de cheltuială), below (sub EBITDA).
            $table->string('kind', 12);
            $table->string('key');
            $table->string('label')->nullable();
            $table->unsignedTinyInteger('month');

            // Pârghiile. Nu toate au înțeles pe fiecare fel de rând: la
            // charter contează locurile și gradul de ocupare, la celelalte
            // categorii pasagerii, iar la cheltuieli doar suma.
            $table->decimal('volume', 14, 2)->nullable();
            $table->decimal('capacity', 14, 2)->nullable();
            $table->decimal('load', 6, 4)->nullable();
            $table->decimal('unit_net', 14, 2)->nullable();
            $table->decimal('unit_margin', 14, 2)->nullable();
            $table->decimal('amount', 16, 2)->nullable();

            // Ce a scris omul nu se mai calcă la reconstrucție.
            $table->boolean('manual')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'kind', 'key', 'month'], 'plan_lines_unique_cell');
            $table->index(['plan_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_lines');
        Schema::dropIfExists('plans');
    }
};
