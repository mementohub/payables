<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrul deciziilor și al procedurilor interne.
 *
 * Aceeași mecanică de la contracte — documentul, fișierele lui (fiecare o
 * versiune), ce i s-a întâmplat —, dar pentru hârtiile noastre, nu pentru cele
 * semnate cu altcineva: n-au partener și n-au valoare, au emitent și dată de
 * intrare în vigoare.
 *
 * Procedura are pe deasupra luarea la cunoștință: pleacă pe adresa de grup a
 * companiei, iar fiecare om care o citește apasă „am luat la cunoștință”. Cine
 * a apăsat și când se ține aici, ca dovadă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            // Decizie sau procedură: același registru, două taburi.
            $table->string('type', 20)->index();
            $table->string('number', 40);
            $table->string('title', 300);
            $table->text('object')->nullable();
            // Cine a semnat-o: Administrator, Director General, Comitet.
            $table->string('issuer', 200)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_at')->nullable();
            $table->date('effective_at')->nullable();
            // Rar, dar se întâmplă: o procedură valabilă până la o dată anume.
            $table->date('expires_at')->nullable();
            $table->string('status', 20)->default('draft');
            // Procedura nouă o scoate din uz pe cea veche; legătura se ține, ca
            // să se vadă ce a înlocuit ce.
            $table->foreignId('supersedes_id')->nullable()->constrained('company_documents')->nullOnDelete();
            // Luarea la cunoștință, numai la proceduri: când a plecat pe adresa
            // de grup, până când se așteaptă răspunsul și pe ce legătură se
            // intră din mail.
            $table->timestamp('published_at')->nullable();
            $table->string('sent_to', 200)->nullable();
            $table->date('consent_due_at')->nullable();
            $table->string('token', 64)->nullable()->unique();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->json('ocr_fields')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Numerele se dau pe serie: DEC-2026-0001 și PRO-2026-0001 pot
            // trăi amândouă, dar nu de două ori în aceeași serie.
            $table->unique(['type', 'number']);
            $table->index(['type', 'status']);
            $table->index(['type', 'issued_at']);
        });

        Schema::create('company_document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            // Documentul însuși sau o anexă a lui.
            $table->string('kind', 20)->default('document');
            $table->string('label', 120)->nullable();
            $table->string('path', 400);
            $table->string('original_name', 300);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('pages')->nullable();
            // Același fișier încărcat a doua oară devine versiune, nu document nou.
            $table->string('hash', 64)->index();
            $table->string('ocr_status', 20)->default('pending');
            $table->string('ocr_engine', 40)->nullable();
            $table->text('ocr_error')->nullable();
            $table->longText('text')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_document_id', 'version']);
        });

        Schema::create('company_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_document_id', 'created_at']);
        });

        /*
         * „Am citit și am luat la cunoștință.”
         *
         * Mailul pleacă pe o adresă de grup, deci nu știm dinainte cine-l
         * deschide: omul își scrie numele și adresa de serviciu, iar noi ținem
         * minte ceasul, de unde a venit și cu ce a deschis. Cine are cont în
         * aplicație și e autentificat se recunoaște singur.
         */
        Schema::create('document_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('email', 200);
            $table->timestamp('consented_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 400)->nullable();
            // 'account' când a venit dintr-un cont autentificat, 'link' când
            // s-a identificat singur din mailul de grup.
            $table->string('source', 20)->default('link');
            $table->timestamps();

            // O singură luare la cunoștință de fiecare adresă, pe fiecare
            // procedură: a doua apăsare nu mai scrie un rând, ci spune când a
            // fost prima.
            $table->unique(['company_document_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_consents');
        Schema::dropIfExists('company_document_events');
        Schema::dropIfExists('company_document_files');
        Schema::dropIfExists('company_documents');
    }
};
