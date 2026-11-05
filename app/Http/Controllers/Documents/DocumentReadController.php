<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Mail\ConsentReceiptMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentEvent;
use App\Models\CompanyDocumentFile;
use App\Models\DocumentConsent;
use App\Models\User;
use App\Services\Contracts\WordPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Documentul deschis din legătura primită pe mail — fără cont.
 *
 * Decizia și procedura pleacă pe adresele de grup ale companiei, iar din cei
 * care le primesc cei mai mulți n-au cont aici. Așa că pagina e deschisă: cine are legătura
 * citește documentul, iar la capăt apasă „am citit și am luat la cunoștință”,
 * scriindu-și numele și adresa de serviciu.
 *
 * Cine e totuși autentificat nu-și mai scrie nimic: îl știm.
 */
class DocumentReadController extends Controller
{
    public function read(Request $request, string $token): Response
    {
        $document = $this->open($token);
        $user = $request->user();

        $mine = $user === null ? null : DocumentConsent::query()
            ->where('company_document_id', $document->id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))
            ->first();

        return Inertia::render('documents/read', [
            'token' => $token,
            'labels' => CompanyDocument::LABELS[$document->type],
            'needsConsent' => $document->needsConsent(),
            'document' => [
                'number' => $document->number,
                'title' => $document->title,
                'object' => $document->object,
                'issuer' => $document->issuer,
                'issued_at' => $document->issued_at?->toDateString(),
                'effective_at' => $document->effective_at?->toDateString(),
                'consent_due_at' => $document->consent_due_at?->toDateString(),
                'files' => $this->files($document),
            ],
            // Cine e autentificat nu-și mai scrie numele: i-l știm.
            'me' => $user ? ['name' => $user->name, 'email' => $user->email] : null,
            'mine' => $mine ? [
                'name' => $mine->name,
                'email' => $mine->email,
                'at' => $mine->consented_at?->toIso8601String(),
            ] : null,
            'consents' => $document->consents()->count(),
        ]);
    }

    /** Fișierul documentului, pe aceeași legătură. */
    public function file(Request $request, string $token, WordPreview $word): Page|StreamedResponse
    {
        $document = $this->open($token);
        $files = $document->files;

        $file = $request->integer('file') > 0
            ? $files->firstWhere('id', $request->integer('file'))
            : ($files->firstWhere('kind', CompanyDocumentFile::KIND_DOCUMENT) ?? $files->first());

        abort_if($file === null, 404, 'Documentul n-are niciun fișier.');

        if (! $request->boolean('download') && $word->supports($file)) {
            return response((string) $word->html($file))
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        }

        return Storage::disk((string) config('documents.disk', 'documents'))->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime ?: 'application/octet-stream',
                'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.addslashes($file->original_name).'"',
            ],
        );
    }

    /**
     * „Am citit și am luat la cunoștință.”
     *
     * Se scrie o singură dată de fiecare adresă: a doua apăsare nu mai face un
     * rând, ci spune când a fost prima. Pe adresa scrisă pleacă îndată
     * confirmarea — e și dovada omului, și alarma lui, dacă altcineva i-a
     * folosit adresa.
     */
    public function consent(Request $request, string $token): RedirectResponse
    {
        $document = $this->open($token);

        abort_unless($document->needsConsent(), 404, 'Documentul ăsta nu cere luare la cunoștință.');

        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'agreed' => ['accepted'],
        ], [
            'name.required' => 'Scrie-ți numele, ca să știm cine a luat la cunoștință.',
            'name.min' => 'Scrie numele întreg, nu o inițială.',
            'email.required' => 'Scrie adresa ta de serviciu.',
            'email.email' => 'Adresa nu pare bună.',
            'agreed.accepted' => 'Bifează că ai citit '.mb_strtolower($document->label('article')).'.',
        ]);

        $email = mb_strtolower(trim($validated['email']));

        // Numai adrese de-ale casei: o procedură internă nu se ia la
        // cunoștință de la o adresă personală, iar greșeala de scriere a
        // domeniului s-ar vedea abia la audit.
        $domains = (array) config('documents.consent.domains', []);

        if ($domains !== [] && ! $this->ours($email, $domains)) {
            return back()->withErrors(['email' => 'Folosește adresa de serviciu ('.implode(' sau ', array_map(fn ($d) => '@'.$d, $domains)).').']);
        }

        $already = DocumentConsent::query()
            ->where('company_document_id', $document->id)
            ->where('email', $email)
            ->first();

        if ($already !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Era deja luată la cunoștință, pe '.$already->consented_at->timezone('Europe/Bucharest')->format('d.m.Y, H:i').'.']);

            return back();
        }

        $consent = DocumentConsent::query()->create([
            'company_document_id' => $document->id,
            // Dovada se leagă de contul adresei scrise, nu de cel din sesiune:
            // cine e autentificat poate să apese pentru el, dar poate să
            // deschidă legătura și lângă un coleg fără cont, iar atunci
            // luarea la cunoștință e a colegului, nu a lui.
            'user_id' => User::query()->whereRaw('lower(email) = ?', [$email])->value('id'),
            'name' => trim($validated['name']),
            'email' => $email,
            'consented_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 400),
            'source' => $user !== null && mb_strtolower($user->email) === $email
                ? DocumentConsent::SOURCE_ACCOUNT
                : DocumentConsent::SOURCE_LINK,
        ]);

        CompanyDocumentEvent::query()->create([
            'company_document_id' => $document->id,
            'user_id' => $consent->user_id,
            'type' => 'consent',
            'body' => $consent->name.' ('.$consent->email.') a luat la cunoștință.',
            'payload' => ['consent_id' => $consent->id, 'source' => $consent->source],
        ]);

        try {
            // Pe rând, nu pe loc: după ce pleacă procedura în toată compania
            // apasă zeci de oameni deodată, iar o convorbire cu serverul de
            // mail ar ține pagina în loc o secundă pentru fiecare. Dovada e
            // deja scrisă, așa că mailul poate pleca din spate.
            Mail::to($consent->email)->queue(new ConsentReceiptMail($document, $consent));
        } catch (Throwable $e) {
            // Dovada e rândul din registru, nu mailul: dacă mailul nu pleacă,
            // luarea la cunoștință rămâne scrisă.
            Log::warning('Confirmarea pentru '.$document->number.' n-a putut pleca la '.$consent->email.': '.$e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mulțumim. Am înregistrat luarea la cunoștință și ți-am trimis confirmarea pe mail.']);

        return back();
    }

    /** Legătura deschide documentul numai cât timp e trimis și neArhivat. */
    private function open(string $token): CompanyDocument
    {
        $document = CompanyDocument::query()
            ->where('token', $token)
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->with('files')
            ->first();

        abort_if($document === null, 404, 'Legătura nu mai e bună.');

        return $document;
    }

    /**
     * Hârtiile documentului, în ordinea în care le citește un om: documentul
     * întâi, apoi anexele.
     *
     * @return list<array<string, mixed>>
     */
    private function files(CompanyDocument $document): array
    {
        return $document->files
            ->sortBy([
                fn (CompanyDocumentFile $a, CompanyDocumentFile $b) => ($a->kind === CompanyDocumentFile::KIND_DOCUMENT ? 0 : 1) <=> ($b->kind === CompanyDocumentFile::KIND_DOCUMENT ? 0 : 1),
                fn (CompanyDocumentFile $a, CompanyDocumentFile $b) => $a->created_at <=> $b->created_at,
            ])
            ->map(fn (CompanyDocumentFile $file) => [
                'id' => $file->id,
                'title' => $file->title(),
                'kind_label' => CompanyDocumentFile::KIND_LABELS[$file->kind] ?? 'Document',
                'name' => $file->original_name,
                'size' => $file->size,
                'text' => $file->text !== null ? mb_substr($file->text, 0, 40000) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $domains
     */
    private function ours(string $email, array $domains): bool
    {
        $domain = mb_strtolower((string) mb_substr($email, (int) mb_strpos($email, '@') + 1));

        return in_array($domain, array_map('mb_strtolower', $domains), true);
    }
}
