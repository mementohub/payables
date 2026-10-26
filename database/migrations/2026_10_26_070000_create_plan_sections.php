<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bugetul se face în mai multe mâini.
 *
 * Fiecare categorie de produs are responsabilul ei, rețeaua de agenții își
 * bugetează oamenii și bonusurile, marketingul își bugetează cheltuielile,
 * iar administrativul tot ce rămâne. Până acum planul era al unui singur om
 * care știa tot; acum fiecare scrie în partea lui și o trimite spre
 * consolidare, iar după aceea secțiunea se încuie — până când conducerea o
 * dă înapoi.
 *
 * Secțiunea se naște la prima atingere: fără rând aici, e „în lucru”.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            // „category:Charter”, „cost:retail”, „cost:marketing”, „cost:admin”.
            $table->string('key');
            $table->string('status', 12)->default('draft');

            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            // De ce s-a dat înapoi, sau ce a vrut să spună cine a trimis-o.
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_sections');
    }
};
