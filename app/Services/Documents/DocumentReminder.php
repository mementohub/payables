<?php

namespace App\Services\Documents;

use App\Mail\DocumentReminderMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Aducerea aminte, pe aceleași adrese pe care a plecat hârtia.
 *
 * Un singur loc, pentru amândouă drumurile: programul care se uită în fiecare
 * dimineață ce termen se încheie mâine, și butonul de pe pagina documentului,
 * apăsat când omul vrea să insiste. Altfel, cele două ar fi ajuns să trimită
 * mailuri ușor diferite.
 */
class DocumentReminder
{
    /**
     * @return list<string> adresele la care a plecat
     */
    public function send(CompanyDocument $document, ?User $by = null): array
    {
        // Numai hârtiile plecate: fără legătură n-are unde duce butonul, iar
        // una pusă în arhivă nu mai cere nimic nimănui.
        if (! $document->needsConsent() || $document->token === null || $document->archived_at !== null) {
            return [];
        }

        $emails = $this->emails((string) $document->sent_to);

        if ($emails === []) {
            return [];
        }

        $sent = [];

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new DocumentReminderMail($document));
                $sent[] = $email;
            } catch (Throwable $e) {
                Log::warning('Aducerea aminte pentru '.$document->number.' n-a putut pleca la '.$email.': '.$e->getMessage());
            }
        }

        // Dacă n-a plecat niciuna, nu scriem că s-a amintit: mâine se mai
        // încearcă o dată.
        if ($sent === []) {
            return [];
        }

        $document->forceFill(['consent_reminded_at' => now()])->save();

        CompanyDocumentEvent::query()->create([
            'company_document_id' => $document->id,
            'user_id' => $by?->id,
            'type' => 'reminded',
            'body' => 'Aducere aminte trimisă către '.implode(', ', $sent).'.',
            'payload' => [
                'mailed' => $sent,
                'due' => $document->consent_due_at?->toDateString(),
                'by' => $by?->name ?? 'program',
            ],
        ]);

        return $sent;
    }

    /**
     * Adresele pe care a plecat hârtia, așa cum au fost scrise în registru.
     *
     * @return list<string>
     */
    private function emails(string $raw): array
    {
        return collect(preg_split('~[,;\s]+~', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
