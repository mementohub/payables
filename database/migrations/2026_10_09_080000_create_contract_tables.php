<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repertoriul de contracte: contractul, fișierele lui (fiecare o versiune),
 * cui a fost trimis și tot ce i s-a întâmplat.
 *
 * Datele contractului stau pe contract, nu pe fișier: un act adițional sau un
 * exemplar rescanat schimbă fișierul, dar contractul rămâne același lucru, cu
 * aceeași scadență și același responsabil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('title', 300);
            // Partenerul se leagă de fișa din aplicație când se recunoaște, dar
            // numele se ține oricum: un contract nou poate veni de la cineva
            // care n-a emis încă nicio factură.
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_name', 200);
            $table->string('partner_tax_id', 40)->nullable();
            $table->string('kind', 20)->default('supplier');
            $table->text('object')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('value', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('signed_at')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->string('payment_terms', 200)->nullable();
            $table->string('governing_law', 80)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            // Ce a citit mașina și cu câtă încredere, câmp cu câmp: se arată
            // lângă valoare și se stinge când omul o corectează.
            $table->json('ocr_fields')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('partner_name');
            $table->index('signed_at');
        });

        Schema::create('contract_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('label', 120)->nullable();
            $table->string('path', 400);
            $table->string('original_name', 300);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('pages')->nullable();
            // Amprenta fișierului: același document încărcat a doua oară se
            // recunoaște și devine versiune, nu contract nou.
            $table->string('hash', 64)->index();
            $table->string('ocr_status', 20)->default('pending');
            $table->string('ocr_engine', 40)->nullable();
            $table->text('ocr_error')->nullable();
            $table->longText('text')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'version']);
        });

        Schema::create('contract_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 200);
            $table->string('permission', 20)->default('view');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contract_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_events');
        Schema::dropIfExists('contract_shares');
        Schema::dropIfExists('contract_files');
        Schema::dropIfExists('contracts');
    }
};
