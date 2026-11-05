<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Jobs\ReadDocumentFile;
use App\Mail\DocumentNoticeMail;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentEvent;
use App\Models\CompanyDocumentFile;
use App\Models\Department;
use App\Models\DocumentConsent;
use App\Models\User;
use App\Services\Contracts\ContractReader;
use App\Services\Contracts\WordPreview;
use App\Services\Documents\DocumentAnswer;
use App\Services\Xlsx\XlsxWorkbook;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Registrul deciziilor și al procedurilor interne.
 *
 * Un singur ecran pentru amândouă: se deosebesc prin ce sunt — decizia se dă,
 * procedura se învață —, nu prin cum se țin. Drumul e același până la capăt:
 * pleacă spre toată compania și fiecare om apasă „am luat la cunoștință”.
 *
 * Toată lumea din aplicație citește registrul; umblă la el numai cine ține
 * documentele (`contract_management`, `top_management`) și administratorul.
 */
class CompanyDocumentController extends Controller
{
    /**
     * Ce feluri de fișiere se primesc. Se cere extensia, nu ghicitul MIME:
     * un Word scanat vine, după server, când într-un fel, când în altul.
     */
    private const FORMATS = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'webp', 'heic'];

    /** După ce se poate rândui lista, și pe ce coloană din tabel cade fiecare. */
    private const SORTS = [
        'number' => 'company_documents.number',
        'title' => 'company_documents.title',
        'issuer' => 'company_documents.issuer',
        'department' => 'departments.name',
        'issued' => 'company_documents.issued_at',
        'effective' => 'company_documents.effective_at',
        'status' => 'company_documents.status',
        'added' => 'company_documents.created_at',
    ];

    public function index(Request $request, string $type, ContractReader $reader): Response
    {
        $type = $this->type($type);
        $filters = $this->filters($request);
        $query = $this->filtered($type, $filters);

        $mine = $this->consentedByMe($request, $type);

        $documents = $this->sorted((clone $query), $filters)
            ->with(['department:id,name', 'owner:id,name'])
            ->withCount(['files', 'consents'])
            ->paginate(40)
            ->withQueryString()
            ->through(fn (CompanyDocument $document) => $this->row($document, $mine));

        return Inertia::render('documents/index', [
            'type' => CompanyDocument::slug($type),
            'labels' => CompanyDocument::LABELS[$type],
            'filters' => $filters,
            'documents' => $documents,
            'summary' => $this->summary($type),
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
            'people' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'myDepartment' => $this->hisDepartment($request->user()),
            'can' => [
                'manage' => $this->mayManage($request),
                'delete' => (bool) $request->user()?->isAdmin(),
            ],
            'ocr' => $reader->available(),
            'limits' => $this->limits(),
            'consentGroup' => $this->group(),
        ]);
    }

    public function show(Request $request, string $type, CompanyDocument $document): Response
    {
        $this->belongs($document, $this->type($type));

        $document->load([
            'department:id,name', 'owner:id,name,email', 'createdBy:id,name',
            'files.uploadedBy:id,name', 'events.user:id,name', 'consents', 'supersedes:id,number,title,type',
        ]);

        $twins = $this->twins($document->consents);

        return Inertia::render('documents/show', [
            'type' => CompanyDocument::slug($document->type),
            'labels' => CompanyDocument::LABELS[$document->type],
            'can' => [
                'manage' => $this->mayManage($request),
                'delete' => (bool) $request->user()?->isAdmin(),
            ],
            // Ca pagina să poată spune din capul locului dacă textul pleacă
            // undeva când întrebi, nu abia după ce ai întrebat.
            'ai' => (bool) config('documents.ai.enabled', false)
                && (string) config('ai.providers.openai.key', '') !== '',
            // Ecranul completează singur adresele de grup ale companiei; se
            // șterg, dacă hârtia pleacă numai către cineva anume.
            'consentGroup' => $this->group(),
            'document' => [
                ...$this->row($document, $this->consentedByMe($request, $document->type)),
                'object' => $document->object,
                'notes' => $document->notes,
                'tags' => $document->tags ?? [],
                'ocr_fields' => $document->ocr_fields ?? [],
                'created_by' => $document->createdBy?->name,
                'supersedes' => $document->supersedes ? [
                    'id' => $document->supersedes->id,
                    'number' => $document->supersedes->number,
                    'title' => $document->supersedes->title,
                ] : null,
                'token' => $document->token,
                'read_url' => $document->token !== null ? route('documents.read', $document->token) : null,
                'files' => $document->files->map(fn (CompanyDocumentFile $file) => [
                    'id' => $file->id,
                    'version' => $file->version,
                    'kind' => $file->kind,
                    'kind_label' => CompanyDocumentFile::KIND_LABELS[$file->kind] ?? 'Document',
                    'title' => $file->title(),
                    'label' => $file->label,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'pages' => $file->pages,
                    'ocr_status' => $file->ocr_status,
                    'ocr_engine' => $file->ocr_engine,
                    'ocr_error' => $file->ocr_error,
                    'has_text' => $file->text !== null,
                    // Un Word nu se deschide în browser; se arată textul citit
                    // din el, atât cât încape într-o fereastră.
                    'text' => $file->text !== null ? mb_substr($file->text, 0, 40000) : null,
                    'uploaded_by' => $file->uploadedBy?->name,
                    'uploaded_at' => $file->created_at?->toIso8601String(),
                ])->values(),
                'consents' => $document->consents->map(fn (DocumentConsent $consent) => [
                    'id' => $consent->id,
                    'name' => $consent->name,
                    'email' => $consent->email,
                    'at' => $consent->consented_at?->toIso8601String(),
                    'source' => $consent->source,
                    // Adresa n-are cont în aplicație: nu e o greșeală, dar la
                    // audit cineva trebuie să spună cine e omul.
                    'matched' => $consent->matched(),
                    // Același nume, altă adresă: probabil același om, numărat
                    // de două ori.
                    'twin' => $twins[$consent->id] ?? null,
                ])->values(),
                'events' => $document->events->map(fn (CompanyDocumentEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'body' => $event->body,
                    'user' => $event->user?->name,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values(),
            ],
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
            'people' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            // Ce poate înlocui documentul ăsta: celelalte hârtii de același fel.
            'others' => CompanyDocument::query()
                ->ofType($document->type)
                ->whereKeyNot($document->id)
                ->orderByDesc('issued_at')
                ->limit(200)
                ->get(['id', 'number', 'title'])
                ->map(fn (CompanyDocument $other) => ['id' => $other->id, 'number' => $other->number, 'title' => $other->title]),
        ]);
    }

    /**
     * Încarcă unul sau mai multe fișiere; fiecare devine un document în lucru,
     * iar citirea lui pleacă în fundal.
     */
    public function store(Request $request, string $type): RedirectResponse
    {
        $type = $this->type($type);
        $this->mustManage($request);

        if ($this->tooBig($request)) {
            return $this->tooBigAnswer();
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.((int) config('documents.max_upload_mb', 50) * 1024), 'extensions:'.implode(',', self::FORMATS)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $created = [];

        foreach ($validated['files'] as $upload) {
            $hash = hash_file('sha256', $upload->getRealPath());
            $existing = CompanyDocumentFile::query()
                ->where('hash', $hash)
                ->whereHas('document', fn (Builder $q) => $q->where('type', $type))
                ->with('document')
                ->first();

            // Același fișier încărcat a doua oară nu face al doilea document:
            // devine versiune nouă pe cel pe care îl are deja.
            $document = $existing?->document ?? $this->blank($type, $request->user(), $upload->getClientOriginalName(), $validated);
            $created[] = $document->number;
            $this->attach($document, $upload, $hash, $request->user());
        }

        $one = CompanyDocument::LABELS[$type]['one'];
        $many = mb_strtolower(CompanyDocument::LABELS[$type]['many']);

        Inertia::flash('toast', ['type' => 'success', 'message' => count($created) === 1
            ? $one.' '.$created[0].' a fost încărcată; se citește acum.'
            : count($created).' '.$many.' au fost încărcate; se citesc acum.']);

        return back();
    }

    public function update(Request $request, string $type, CompanyDocument $document): RedirectResponse
    {
        $this->belongs($document, $this->type($type));
        $this->mustManage($request);

        $validated = $request->validate([
            'number' => [
                'required', 'string', 'max:40',
                Rule::unique('company_documents', 'number')->where('type', $document->type)->ignore($document->id),
            ],
            'title' => ['required', 'string', 'max:300'],
            'object' => ['nullable', 'string', 'max:5000'],
            'issuer' => ['nullable', 'string', 'max:200'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'issued_at' => ['nullable', 'date'],
            'effective_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(CompanyDocument::STATUSES)],
            'supersedes_id' => [
                'nullable', 'integer',
                Rule::notIn([$document->id]),
                Rule::exists('company_documents', 'id')->where('type', $document->type),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $changed = $this->changes($document, $validated);
        $document->fill($validated)->save();

        // Hârtia înlocuită iese din uz în aceeași mișcare: altfel ar rămâne
        // amândouă „în vigoare”, iar oamenii ar citi-o pe care o apucă.
        if (($validated['supersedes_id'] ?? null) !== null) {
            $old = CompanyDocument::query()->find($validated['supersedes_id']);

            if ($old !== null && $old->status === CompanyDocument::STATUS_ACTIVE) {
                $old->forceFill(['status' => CompanyDocument::STATUS_SUPERSEDED])->save();
                $this->event($old, $request->user(), 'superseded', 'Înlocuită de '.$document->number.'.');
            }
        }

        if ($changed !== []) {
            $this->event($document, $request->user(), 'updated', 'A schimbat: '.implode(', ', array_keys($changed)), $changed);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Salvat.']);

        return back();
    }

    /** O versiune nouă sau o anexă pe un document care există deja. */
    public function addFile(Request $request, string $type, CompanyDocument $document): RedirectResponse
    {
        $this->belongs($document, $this->type($type));
        $this->mustManage($request);

        if ($this->tooBig($request)) {
            return $this->tooBigAnswer();
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.((int) config('documents.max_upload_mb', 50) * 1024), 'extensions:'.implode(',', self::FORMATS)],
            'kind' => ['nullable', Rule::in(CompanyDocumentFile::KINDS)],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $kind = $validated['kind'] ?? CompanyDocumentFile::KIND_DOCUMENT;
        $count = 0;

        foreach ($validated['files'] as $upload) {
            $this->attach(
                $document,
                $upload,
                hash_file('sha256', $upload->getRealPath()),
                $request->user(),
                $validated['label'] ?? null,
                $kind,
            );
            $count++;
        }

        $what = $kind === CompanyDocumentFile::KIND_ANNEX
            ? ($count === 1 ? 'Anexă încărcată' : $count.' anexe încărcate')
            : ($count === 1 ? 'Versiune nouă încărcată' : $count.' versiuni încărcate');

        Inertia::flash('toast', ['type' => 'success', 'message' => $what.'; se citesc acum.']);

        return back();
    }

    public function download(Request $request, string $type, CompanyDocument $document, CompanyDocumentFile $file): StreamedResponse
    {
        $this->belongs($document, $this->type($type));
        $this->hisFile($document, $file);

        $this->event($document, $request->user(), 'downloaded', $file->original_name, ['file_id' => $file->id]);

        return Storage::disk((string) config('documents.disk', 'documents'))->download($file->path, $file->original_name);
    }

    /** Documentul, arătat în pagină, nu descărcat. */
    public function preview(Request $request, string $type, CompanyDocument $document, CompanyDocumentFile $file, WordPreview $word): Page|StreamedResponse
    {
        $this->belongs($document, $this->type($type));
        $this->hisFile($document, $file);

        // Browserul nu deschide documente Word; pentru ele se face o pagină
        // din chiar cuprinsul documentului.
        if ($word->supports($file)) {
            return response((string) $word->html($file))
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        }

        return Storage::disk((string) config('documents.disk', 'documents'))->response(
            $file->path,
            $file->original_name,
            ['Content-Type' => $file->mime ?: 'application/octet-stream', 'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"'],
        );
    }

    /**
     * Întrebare punctuală despre documentul ăsta: răspunsul vine din chiar
     * textul lui.
     *
     * @return array<string, mixed>
     */
    public function ask(Request $request, string $type, CompanyDocument $document, DocumentAnswer $answer): array
    {
        $this->belongs($document, $this->type($type));

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return $answer->answer($document->load('files'), $validated['question']);
    }

    /**
     * Trimite documentul în companie.
     *
     * Și deciziile și procedurile pleacă pe adresele de grup ale companiei, iar
     * asta e chiar cererea de luare la cunoștință: legătura din mail e locul
     * unde omul citește și apasă.
     */
    public function publish(Request $request, string $type, CompanyDocument $document): RedirectResponse
    {
        $this->belongs($document, $this->type($type));
        $this->mustManage($request);

        $validated = $request->validate([
            'emails' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'due' => ['nullable', 'date'],
        ]);

        if ($document->files()->count() === 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Documentul n-are niciun fișier de citit. Încarcă-l întâi.']);

            return back();
        }

        // Pe adresele de grup stă toată compania, și deciziile și procedurile
        // pleacă pe ele.
        $emails = $this->emails($validated['emails'] ?? $this->group());

        if ($emails->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Nicio adresă bună de e-mail. Pune adresa de grup a companiei.']);

            return back();
        }

        $document->forceFill([
            // Legătura se face o singură dată și rămâne aceeași: mailurile
            // vechi trebuie să deschidă tot documentul ăsta.
            'token' => $document->token ?? Str::random(48),
            'published_at' => $document->published_at ?? now(),
            'sent_to' => $emails->implode(', '),
            'consent_due_at' => $validated['due'] ?? now()->addDays((int) config('documents.consent.days', 14))->toDateString(),
            'status' => $document->status === CompanyDocument::STATUS_DRAFT ? CompanyDocument::STATUS_ACTIVE : $document->status,
        ])->save();

        $sent = [];

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new DocumentNoticeMail($document, $request->user(), $validated['note'] ?? null));
                $sent[] = $email;
            } catch (Throwable $e) {
                Log::warning($document->label('one').' '.$document->number.' n-a putut fi trimisă la '.$email.': '.$e->getMessage());
            }
        }

        $this->event($document, $request->user(), 'sent_for_consent', $emails->implode(', '), [
            'mailed' => $sent,
            'due' => $document->consent_due_at?->toDateString(),
        ]);

        Inertia::flash('toast', $sent === []
            ? ['type' => 'error', 'message' => 'Documentul s-a pregătit, dar mailul n-a putut pleca. Uită-te în jurnalul aplicației.']
            : ['type' => 'success', 'message' => $document->label('article').' a plecat spre luare la cunoștință către '.implode(', ', $sent).'.']);

        return back();
    }

    /**
     * Același om, scris cu două adrese.
     *
     * Adresa nu se poate repeta — baza nu lasă — așa că o a doua confirmare a
     * aceluiași om intră numai pe altă scriere a adresei. Nu ștergem nimic și
     * nu refuzăm nimic: dovada rămâne întreagă, dar rândul își poartă
     * semnul, ca numărătoarea să nu fie luată de bună fără să se uite cineva
     * la ea. Doi oameni cu același nume ies și ei aici — de aceea coloana se
     * cheamă „de verificat”, nu „dublură”.
     *
     * @param  Collection<int, DocumentConsent>  $consents
     * @return array<int, string>
     */
    private function twins(Collection $consents): array
    {
        $twins = [];

        foreach ($consents->groupBy(fn (DocumentConsent $one) => DocumentConsent::samePerson($one->name)) as $same) {
            if ($same->count() < 2) {
                continue;
            }

            foreach ($same as $one) {
                $twins[$one->id] = $same->where('id', '!=', $one->id)->pluck('email')->implode(', ');
            }
        }

        return $twins;
    }

    /** Registrul de luări la cunoștință, într-o foaie de calcul. */
    public function consents(Request $request, string $type, CompanyDocument $document): StreamedResponse
    {
        $this->belongs($document, $this->type($type));

        $consents = $document->consents()->with('user:id,name')->get();
        $twins = $this->twins($consents);

        $rows = $consents
            ->map(fn (DocumentConsent $consent) => [
                $consent->name,
                $consent->email,
                $consent->consented_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i'),
                $consent->source === DocumentConsent::SOURCE_ACCOUNT ? 'cont aplicație' : 'legătură din mail',
                $consent->user?->name,
                // Coloana de la care pornește verificarea, când registrul
                // ajunge în fața unui auditor.
                implode('; ', array_filter([
                    $consent->matched() ? null : 'adresă fără cont în aplicație',
                    isset($twins[$consent->id]) ? 'același nume ca '.$twins[$consent->id] : null,
                ])),
                $consent->ip,
                $consent->user_agent,
            ])
            ->all();

        return XlsxWorkbook::streamDownload(
            sprintf('luare-la-cunostinta-%s.xlsx', Str::slug($document->number)),
            [[
                'name' => 'Luare la cunoștință',
                'headers' => ['Nume', 'E-mail', 'Data și ora', 'Cum', 'Cont în aplicație', 'De verificat', 'IP', 'Browser'],
                'widths' => [28, 32, 18, 18, 22, 38, 16, 50],
                'rows' => $rows,
            ]],
        );
    }

    public function archive(Request $request, string $type, CompanyDocument $document): RedirectResponse
    {
        $this->belongs($document, $this->type($type));
        $this->mustManage($request);

        $archived = $document->archived_at === null;
        $document->forceFill(['archived_at' => $archived ? now() : null])->save();
        $this->event($document, $request->user(), $archived ? 'archived' : 'restored');

        Inertia::flash('toast', ['type' => 'success', 'message' => $archived ? 'Pus în arhivă.' : 'Scos din arhivă.']);

        return back();
    }

    public function destroy(Request $request, string $type, CompanyDocument $document): RedirectResponse
    {
        $this->belongs($document, $this->type($type));

        if (! $request->user()?->isAdmin()) {
            throw new AuthorizationException('Numai administratorul șterge documente.');
        }

        $number = $document->number;
        $slug = CompanyDocument::slug($document->type);
        $this->drop($document);

        Inertia::flash('toast', ['type' => 'success', 'message' => $number.' a fost ștearsă cu totul.']);

        return redirect()->route('documents.index', $slug);
    }

    /**
     * Aceeași faptă, pe mai multe documente deodată.
     */
    public function bulk(Request $request, string $type): RedirectResponse
    {
        $type = $this->type($type);
        $this->mustManage($request);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['department', 'owner', 'archive', 'reread', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $documents = CompanyDocument::query()->ofType($type)->whereIn('id', $validated['ids'])->get();

        if ($documents->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Nu s-a găsit niciunul dintre documentele alese.']);

            return back();
        }

        $said = match ($validated['action']) {
            'department' => $this->setMany($documents, $user, ['department_id' => $validated['department_id'] ?? null], 'Departamentul'),
            'owner' => $this->setMany($documents, $user, ['owner_id' => $validated['owner_id'] ?? null], 'Responsabilul'),
            'archive' => $this->archiveMany($documents, $user),
            'reread' => $this->rereadMany($documents),
            'delete' => $this->dropMany($request, $documents),
        };

        Inertia::flash('toast', $said);

        return back();
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
     * @param  array<string, mixed>  $change
     * @return array{type: string, message: string}
     */
    private function setMany($documents, ?User $user, array $change, string $what): array
    {
        $touched = 0;

        foreach ($documents as $document) {
            $document->fill($change);

            if (! $document->isDirty()) {
                continue;
            }

            $document->save();
            $this->event($document, $user, 'updated', $what.' s-a schimbat dintr-o mișcare pe mai multe documente.', $change);
            $touched++;
        }

        return $touched === 0
            ? ['type' => 'success', 'message' => 'Erau deja așa toate.']
            : ['type' => 'success', 'message' => sprintf('%s s-a schimbat pe %d documente.', $what, $touched)];
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
     * @return array{type: string, message: string}
     */
    private function archiveMany($documents, ?User $user): array
    {
        $count = 0;

        foreach ($documents as $document) {
            if ($document->archived_at !== null) {
                continue;
            }

            $document->forceFill(['archived_at' => now()])->save();
            $this->event($document, $user, 'archived');
            $count++;
        }

        return ['type' => 'success', 'message' => $count === 0
            ? 'Erau deja toate în arhivă.'
            : sprintf('%d documente puse în arhivă.', $count)];
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
     * @return array{type: string, message: string}
     */
    private function rereadMany($documents): array
    {
        $sent = 0;

        foreach ($documents->load('files') as $document) {
            $file = $document->files->where('kind', CompanyDocumentFile::KIND_DOCUMENT)->last();

            if ($file === null) {
                continue;
            }

            ReadDocumentFile::dispatch($file->id, true);
            $sent++;
        }

        return ['type' => 'success', 'message' => $sent === 0
            ? 'Niciunul n-are document de citit.'
            : sprintf('%d documente se citesc din nou. Fișele se îndreaptă în câteva minute.', $sent)];
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
     * @return array{type: string, message: string}
     */
    private function dropMany(Request $request, $documents): array
    {
        if (! $request->user()?->isAdmin()) {
            throw new AuthorizationException('Numai un administrator poate șterge documente.');
        }

        $count = 0;

        foreach ($documents as $document) {
            $this->drop($document);
            $count++;
        }

        return ['type' => 'success', 'message' => sprintf('%d documente șterse cu totul.', $count)];
    }

    /** Documentul și hârtiile lui, scoase de pe disc și din baza de date. */
    private function drop(CompanyDocument $document): void
    {
        $disk = Storage::disk((string) config('documents.disk', 'documents'));

        foreach ($document->files as $file) {
            $disk->delete($file->path);
        }

        $disk->deleteDirectory(trim(trim((string) config('documents.path', ''), '/').'/'.$document->id, '/'));
        $document->delete();
    }

    /**
     * Adresele de grup pe care pleacă hârtiile casei spre toată compania.
     *
     * Pot fi mai multe, despărțite prin virgulă: fiecare primește mailul ei.
     */
    private function group(): string
    {
        return (string) config('documents.consent.group', '');
    }

    /**
     * Adresele scrise de om, curățate.
     *
     * @return Collection<int, string>
     */
    private function emails(string $raw): Collection
    {
        return collect(preg_split('~[,;\s]+~', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();
    }

    /**
     * Ce a luat deja la cunoștință omul care se uită — într-o singură
     * întrebare pusă bazei, nu una de fiecare rând din listă.
     *
     * Se caută și după cont, și după adresă: cine a apăsat din mail, de pe
     * telefon, fără să fie autentificat, e tot el.
     *
     * @return array<int, true>
     */
    private function consentedByMe(Request $request, string $type): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return DocumentConsent::query()
            ->whereHas('document', fn (Builder $q) => $q->where('type', $type))
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))
            ->pluck('company_document_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** Are omul voie să umble la registru, nu doar să-l citească? */
    private function mayManage(Request $request): bool
    {
        return (bool) $request->user()?->canManageDocuments();
    }

    private function mustManage(Request $request): void
    {
        if (! $this->mayManage($request)) {
            throw new AuthorizationException('Registrul se citește de toată lumea, dar se schimbă numai de cine ține documentele.');
        }
    }

    /** Slugul din adresă („decizii”) întors în felul documentului. */
    private function type(string $slug): string
    {
        $type = CompanyDocument::typeFromSlug($slug);

        abort_if($type === null, 404);

        return $type;
    }

    /** Documentul cerut e chiar din registrul deschis? */
    private function belongs(CompanyDocument $document, string $type): void
    {
        abort_unless($document->type === $type, 404);
    }

    private function hisFile(CompanyDocument $document, CompanyDocumentFile $file): void
    {
        if ($file->company_document_id !== $document->id) {
            throw new AuthorizationException('Fișierul nu e al acestui document.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sorted(Builder $query, array $filters): Builder
    {
        $column = self::SORTS[$filters['sort'] ?? ''] ?? null;

        // Fără altă rânduială cerută, deasupra stă ce s-a încărcat ultima dată.
        if ($column === null) {
            return $query
                ->orderByDesc('company_documents.created_at')
                ->orderByDesc('company_documents.id');
        }

        $direction = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($column === 'departments.name') {
            $query->leftJoin('departments', 'departments.id', '=', 'company_documents.department_id')
                ->select('company_documents.*');
        }

        return $query
            ->orderByRaw(sprintf('case when %s is null then 1 else 0 end', $column))
            ->orderBy($column, $direction)
            ->orderByDesc('company_documents.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(string $type, array $filters): Builder
    {
        return CompanyDocument::query()
            ->ofType($type)
            ->when(! $filters['archived'], fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['archived'], fn ($q) => $q->whereNotNull('archived_at'))
            ->when($filters['search'] !== null, function ($q) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $q->where(fn ($inner) => $inner
                    ->where('number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('issuer', 'like', $like)
                    ->orWhere('object', 'like', $like)
                    // Căutarea prinde și textul citit din document: un rând se
                    // găsește fără să deschidă nimeni fișierul.
                    ->orWhereHas('files', fn ($files) => $files->where('text', 'like', $like)));
            })
            ->when($filters['department_id'] !== null, fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['issued_from'] !== null, fn ($q) => $q->whereDate('issued_at', '>=', $filters['issued_from']))
            ->when($filters['issued_to'] !== null, fn ($q) => $q->whereDate('issued_at', '<=', $filters['issued_to']));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => $request->filled($key) ? Carbon::parse($request->string($key)->toString())->toDateString() : null;

        return [
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
            'department_id' => $request->integer('department_id') ?: null,
            'status' => in_array($request->string('status')->toString(), CompanyDocument::STATUSES, true) ? $request->string('status')->toString() : null,
            'issued_from' => $date('issued_from'),
            'issued_to' => $date('issued_to'),
            'archived' => $request->boolean('archived'),
            'sort' => array_key_exists($request->string('sort')->toString(), self::SORTS) ? $request->string('sort')->toString() : null,
            'dir' => $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<int, true>  $mine  Documentele pe care omul care se uită le-a
     *                                  luat deja la cunoștință.
     * @return array<string, mixed>
     */
    private function row(CompanyDocument $document, array $mine): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'number' => $document->number,
            'title' => $document->title,
            'issuer' => $document->issuer,
            'department' => $document->department?->name,
            'department_id' => $document->department_id,
            'owner' => $document->owner?->name,
            'owner_id' => $document->owner_id,
            'issued_at' => $document->issued_at?->toDateString(),
            'effective_at' => $document->effective_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'status' => $document->status,
            'archived' => $document->archived_at !== null,
            'added_at' => $document->created_at?->toIso8601String(),
            'files_count' => $document->files_count ?? $document->files()->count(),
            'supersedes_id' => $document->supersedes_id,
            // Luarea la cunoștință, cât se vede din listă: dacă s-a cerut,
            // câți au apăsat și dacă a apăsat chiar omul care se uită.
            'published_at' => $document->published_at?->toIso8601String(),
            'sent_to' => $document->sent_to,
            'consent_due_at' => $document->consent_due_at?->toDateString(),
            'needs_consent' => $document->needsConsent(),
            'consents_count' => $document->consents_count ?? ($document->relationLoaded('consents') ? $document->consents->count() : $document->consents()->count()),
            'i_consented' => isset($mine[$document->id]),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(string $type): array
    {
        $all = fn () => CompanyDocument::query()->ofType($type)->notArchived();

        return [
            'total' => $all()->count(),
            'active' => $all()->where('status', CompanyDocument::STATUS_ACTIVE)->count(),
            'draft' => $all()->where('status', CompanyDocument::STATUS_DRAFT)->count(),
            'published' => $all()->published()->count(),
            'consents' => (int) DocumentConsent::query()
                ->whereHas('document', fn (Builder $q) => $q->where('type', $type))
                ->count(),
        ];
    }

    private function hisDepartment(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        return $user->departments()
            ->orderBy('departments.sort')
            ->orderBy('departments.id')
            ->value('departments.id');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function blank(string $type, ?User $user, string $name, array $validated): CompanyDocument
    {
        $title = trim(pathinfo($name, PATHINFO_FILENAME));
        $title = $title !== '' ? Str::limit($title, 290) : CompanyDocument::LABELS[$type]['one'].' fără titlu';

        return CompanyDocument::query()->create([
            'type' => $type,
            'number' => $this->number($type),
            'title' => $title,
            'department_id' => $validated['department_id'] ?? $this->hisDepartment($user),
            'owner_id' => $user?->id,
            // Ce se încarcă e, de obicei, o hârtie semnată; starea se schimbă
            // dintr-un clic, dacă e altfel.
            'status' => CompanyDocument::STATUS_ACTIVE,
            'created_by_id' => $user?->id,
            // Titlul se naște din numele fișierului și e trecut, din capul
            // locului, în dreptul mașinii: așa, citirea îl poate înlocui cu
            // titlul adevărat, fără să calce peste ce-ar scrie un om.
            'ocr_fields' => ['title' => ['value' => $title, 'confidence' => 0.1, 'source' => 'numele fișierului']],
        ]);
    }

    private function attach(
        CompanyDocument $document,
        mixed $upload,
        string $hash,
        ?User $user,
        ?string $label = null,
        string $kind = CompanyDocumentFile::KIND_DOCUMENT,
    ): CompanyDocumentFile {
        // Numai documentul însuși are versiuni: o anexă nu e „v2”, e altă
        // hârtie, care stă lângă el.
        $version = $kind === CompanyDocumentFile::KIND_DOCUMENT
            ? (int) CompanyDocumentFile::query()
                ->where('company_document_id', $document->id)
                ->where('kind', CompanyDocumentFile::KIND_DOCUMENT)
                ->max('version') + 1
            : 1;

        $folder = trim((string) config('documents.path', ''), '/');
        $path = $upload->store(trim($folder.'/'.$document->id, '/'), (string) config('documents.disk', 'documents'));

        $file = CompanyDocumentFile::query()->create([
            'company_document_id' => $document->id,
            'version' => $version,
            'kind' => $kind,
            'label' => $label,
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
            'hash' => $hash,
            'ocr_status' => CompanyDocumentFile::OCR_PENDING,
            'uploaded_by_id' => $user?->id,
        ]);

        $this->event(
            $document,
            $user,
            match (true) {
                $kind === CompanyDocumentFile::KIND_ANNEX => 'annex',
                $version === 1 => 'uploaded',
                default => 'version',
            },
            $upload->getClientOriginalName(),
            ['file_id' => $file->id, 'version' => $version, 'kind' => $kind],
        );

        ReadDocumentFile::dispatch($file->id);

        return $file;
    }

    /** Numărul din registru: serie, an, număr de ordine. */
    private function number(string $type): string
    {
        return DB::transaction(function () use ($type) {
            $series = CompanyDocument::SERIES[$type];
            $year = now()->year;
            $prefix = $series.'-'.$year.'-';
            $last = CompanyDocument::query()
                ->ofType($type)
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->max('number');
            $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

            return sprintf('%s%04d', $prefix, $next);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(CompanyDocument $document, array $validated): array
    {
        $changed = [];

        foreach ($validated as $key => $value) {
            $before = $document->{$key};
            $before = $before instanceof CarbonInterface ? $before->toDateString() : $before;

            if ((string) $before !== (string) (is_array($value) ? json_encode($value) : $value)) {
                $changed[$key] = ['from' => $before, 'to' => $value];
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(CompanyDocument $document, ?User $user, string $type, ?string $body = null, array $payload = []): void
    {
        CompanyDocumentEvent::query()->create([
            'company_document_id' => $document->id,
            'user_id' => $user?->id,
            'type' => $type,
            'body' => $body,
            'payload' => $payload ?: null,
        ]);
    }

    /**
     * Cât primește serverul, pe bune: cea mai mică dintre limitele PHP.
     *
     * @return array{upload_mb: int, post: string, upload: string}
     */
    private function limits(): array
    {
        $post = $this->bytes((string) ini_get('post_max_size'));
        $upload = $this->bytes((string) ini_get('upload_max_filesize'));
        $smallest = min(array_filter([$post, $upload, (int) config('documents.max_upload_mb', 50) * 1024 ** 2]));

        return [
            'upload_mb' => (int) floor($smallest / 1024 ** 2),
            'post' => (string) ini_get('post_max_size'),
            'upload' => (string) ini_get('upload_max_filesize'),
        ];
    }

    private function tooBig(Request $request): bool
    {
        $length = (int) $request->server('CONTENT_LENGTH', 0);
        $limit = $this->bytes((string) ini_get('post_max_size'));

        if ($request->allFiles() !== []) {
            return false;
        }

        return $length > 0 && ($limit <= 0 || $length > $limit || $length > 1024 * 512);
    }

    private function tooBigAnswer(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => sprintf(
            'Fișierul trece de cât primește serverul acum (%s). Ridică limita din panoul de găzduire sau încarcă un fișier mai mic.',
            ini_get('post_max_size'),
        )]);

        return back();
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        $unit = mb_strtolower(mb_substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
