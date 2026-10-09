<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Jobs\ReadContractFile;
use App\Mail\ContractSharedMail;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFile;
use App\Models\ContractShare;
use App\Models\Department;
use App\Models\User;
use App\Services\Contracts\ContractAsk;
use App\Services\Contracts\ContractReader;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * Repertoriul de contracte.
 *
 * Contractul se ține separat de facturi: are rolul lui, ecranul lui și
 * scadențele lui. Fișierele stau pe discul privat și se dau numai prin
 * aplicație.
 */
class ContractController extends Controller
{
    /**
     * Ce feluri de fișiere se primesc.
     *
     * Se cere extensia, nu ghicitul MIME: un Word semnat cu poza semnăturii
     * și cu ștampila vine, după server, când `application/msword`, când
     * `application/octet-stream`, iar contractul era refuzat fără ca omul să
     * afle de ce.
     */
    private const FORMATS = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'webp', 'heic'];

    /**
     * După ce se poate rândui lista, și pe ce coloană din tabel cade fiecare.
     *
     * Departamentul se rânduiește după numele lui, nu după numărul din cheie:
     * omul citește nume, nu chei.
     */
    private const SORTS = [
        'number' => 'contracts.number',
        'partner' => 'contracts.partner_name',
        'object' => 'contracts.title',
        'department' => 'departments.name',
        'value' => 'contracts.value',
        'signed' => 'contracts.signed_at',
        'expires' => 'contracts.expires_at',
        'status' => 'contracts.status',
    ];

    public function index(Request $request, ContractReader $reader): Response
    {
        $filters = $this->filters($request);
        $query = $this->mine($request, $this->filtered($filters));

        $contracts = $this->sorted((clone $query), $filters)
            ->with(['department:id,name', 'owner:id,name', 'partner:id,name'])
            ->withCount('files')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (Contract $contract) => $this->row($contract));

        return Inertia::render('contracts/index', [
            'filters' => $filters,
            'contracts' => $contracts,
            'summary' => $this->summary($request),
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
            'partners' => (clone $query)->select('partner_name')->distinct()->orderBy('partner_name')->limit(300)->pluck('partner_name'),
            // Top Management citește repertoriul, dar nu umblă la el.
            'can' => ['delete' => (bool) $request->user()?->isAdmin(), 'all' => (bool) $request->user()?->seesAllContracts()],
            'ocr' => $reader->available(),
            'limits' => $this->limits(),
        ]);
    }

    public function show(Request $request, Contract $contract): Response
    {
        $this->seen($request, $contract);

        $contract->load([
            'department:id,name', 'owner:id,name,email', 'partner:id,name,cui',
            'createdBy:id,name', 'files.uploadedBy:id,name',
            'shares.user:id,name', 'events.user:id,name',
        ]);

        return Inertia::render('contracts/show', [
            'can' => ['delete' => (bool) $request->user()?->isAdmin()],
            'contract' => [
                ...$this->row($contract),
                'object' => $contract->object,
                'notes' => $contract->notes,
                'partner_tax_id' => $contract->partner_tax_id,
                'payment_terms' => $contract->payment_terms,
                'governing_law' => $contract->governing_law,
                'notice_days' => $contract->notice_days,
                'notice_on' => $contract->noticeOn()?->toDateString(),
                'auto_renew' => $contract->auto_renew,
                'starts_at' => $contract->starts_at?->toDateString(),
                'tags' => $contract->tags ?? [],
                'ocr_fields' => $contract->ocr_fields ?? [],
                'created_by' => $contract->createdBy?->name,
                'files' => $contract->files->map(fn (ContractFile $file) => [
                    'id' => $file->id,
                    'version' => $file->version,
                    'kind' => $file->kind,
                    'kind_label' => ContractFile::KIND_LABELS[$file->kind] ?? 'Document',
                    'title' => $file->title(),
                    'signed_at' => $file->signed_at?->toDateString(),
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
                'shares' => $contract->shares->map(fn (ContractShare $share) => [
                    'id' => $share->id,
                    'email' => $share->email,
                    'permission' => $share->permission,
                    'expires_at' => $share->expires_at?->toIso8601String(),
                    'opened_at' => $share->opened_at?->toIso8601String(),
                    'opens' => $share->opens,
                ])->values(),
                'events' => $contract->events->map(fn (ContractEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'body' => $event->body,
                    'user' => $event->user?->name,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values(),
            ],
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
            'people' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * Încarcă unul sau mai multe fișiere; fiecare devine un contract în lucru,
     * iar citirea lui pleacă în fundal.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($this->tooBig($request)) {
            return $this->tooBigAnswer();
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.((int) config('contracts.max_upload_mb', 50) * 1024), 'extensions:'.implode(',', self::FORMATS)],
            'kind' => ['nullable', Rule::in(Contract::KINDS)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $created = [];

        foreach ($validated['files'] as $upload) {
            $hash = hash_file('sha256', $upload->getRealPath());
            $existing = ContractFile::query()->where('hash', $hash)->with('contract')->first();

            // Același fișier încărcat a doua oară nu face al doilea contract:
            // devine versiune nouă pe cel pe care îl are deja.
            $contract = $existing?->contract ?? $this->blank($request->user(), $upload->getClientOriginalName(), $validated);
            $created[] = $contract->number;
            $this->attach($contract, $upload, $hash, $request->user());
        }

        $message = count($created) === 1
            ? 'Contractul '.$created[0].' a fost încărcat; se citește acum.'
            : count($created).' contracte au fost încărcate; se citesc acum.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->seen($request, $contract);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'partner_name' => ['required', 'string', 'max:200'],
            'partner_tax_id' => ['nullable', 'string', 'max:40'],
            'kind' => ['required', Rule::in(Contract::KINDS)],
            'object' => ['nullable', 'string', 'max:5000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'value' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'signed_at' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:signed_at'],
            'notice_days' => ['nullable', 'integer', 'between:0,3650'],
            'auto_renew' => ['nullable', 'boolean'],
            'payment_terms' => ['nullable', 'string', 'max:200'],
            'governing_law' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(Contract::STATUSES)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $changed = $this->changes($contract, $validated);
        $contract->fill([...$validated, 'auto_renew' => (bool) ($validated['auto_renew'] ?? false)])->save();

        if ($changed !== []) {
            $this->event($contract, $request->user(), 'updated', 'A schimbat: '.implode(', ', array_keys($changed)), $changed);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contract salvat.']);

        return back();
    }

    /** O versiune nouă pe un contract care există deja. */
    public function addFile(Request $request, Contract $contract): RedirectResponse
    {
        $this->seen($request, $contract);

        if ($this->tooBig($request)) {
            return $this->tooBigAnswer();
        }

        $validated = $request->validate([
            // Anexele vin de obicei în teanc: se primesc toate deodată.
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.((int) config('contracts.max_upload_mb', 50) * 1024), 'extensions:'.implode(',', self::FORMATS)],
            'kind' => ['nullable', Rule::in(ContractFile::KINDS)],
            // Numărul și data se citesc din document; se pot pune și cu mâna,
            // dacă vrea cineva.
            'label' => ['nullable', 'string', 'max:120'],
            'signed_at' => ['nullable', 'date'],
        ]);

        $kind = $validated['kind'] ?? ContractFile::KIND_CONTRACT;
        $count = 0;

        foreach ($validated['files'] as $upload) {
            $this->attach(
                $contract,
                $upload,
                hash_file('sha256', $upload->getRealPath()),
                $request->user(),
                $validated['label'] ?? null,
                $kind,
                $validated['signed_at'] ?? null,
            );
            $count++;
        }

        $what = match ($kind) {
            ContractFile::KIND_ADDENDUM => $count === 1 ? 'Act adițional încărcat' : $count.' acte adiționale încărcate',
            ContractFile::KIND_ANNEX => $count === 1 ? 'Anexă încărcată' : $count.' anexe încărcate',
            ContractFile::KIND_CONTRACT => $count === 1 ? 'Versiune nouă a contractului, încărcată' : $count.' versiuni încărcate',
            default => $count === 1 ? 'Document încărcat' : $count.' documente încărcate',
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $what.'; se citesc acum. Numărul și data se iau din ele.']);

        return back();
    }

    public function download(Request $request, Contract $contract, ContractFile $file): StreamedResponse
    {
        $this->seen($request, $contract);

        if ($file->contract_id !== $contract->id) {
            throw new AuthorizationException('Fișierul nu e al acestui contract.');
        }

        $this->event($contract, $request->user(), 'downloaded', $file->original_name, ['file_id' => $file->id]);

        return Storage::disk((string) config('contracts.disk', 'contracts'))->download($file->path, $file->original_name);
    }

    /**
     * Contractul, arătat în pagină, nu descărcat.
     *
     * Trimis cu „inline”, browserul îl deschide în fereastra de previzualizare;
     * fișierul tot prin aplicație trece, deci dreptul se cere la fel ca la
     * descărcare.
     */
    public function preview(Request $request, Contract $contract, ContractFile $file): StreamedResponse
    {
        $this->seen($request, $contract);

        if ($file->contract_id !== $contract->id) {
            throw new AuthorizationException('Fișierul nu e al acestui contract.');
        }

        return Storage::disk((string) config('contracts.disk', 'contracts'))->response(
            $file->path,
            $file->original_name,
            ['Content-Type' => $file->mime ?: 'application/octet-stream', 'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"'],
        );
    }

    public function share(Request $request, Contract $contract): RedirectResponse
    {
        $this->seen($request, $contract);

        $validated = $request->validate([
            'emails' => ['required', 'string', 'max:2000'],
            'permission' => ['required', Rule::in(ContractShare::PERMISSIONS)],
            'days' => ['nullable', 'integer', 'between:1,365'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $emails = collect(preg_split('~[,;\s]+~', $validated['emails']) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique();

        if ($emails->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Nicio adresă bună de e-mail.']);

            return back();
        }

        $sent = [];

        foreach ($emails as $email) {
            $share = ContractShare::query()->create([
                'contract_id' => $contract->id,
                'user_id' => User::query()->where('email', $email)->value('id'),
                'email' => $email,
                'permission' => $validated['permission'],
                'token' => Str::random(48),
                // Nespus, legătura ține cât spune configurarea: destul cât să
                // apuce omul s-o deschidă, nu cât să rămână uitată.
                'expires_at' => now()->addDays((int) ($validated['days'] ?? config('contracts.share_days', 15))),
                'created_by_id' => $request->user()?->id,
            ]);

            // Mailul duce legătura, nu fișierul: se vede cine l-a deschis, iar
            // accesul se stinge singur la data pusă.
            try {
                Mail::to($email)->send(new ContractSharedMail($contract, $share, $request->user(), $validated['note'] ?? null));
                $sent[] = $email;
            } catch (Throwable $e) {
                Log::warning('Contractul '.$contract->number.' n-a putut fi trimis la '.$email.': '.$e->getMessage());
            }
        }

        $this->event($contract, $request->user(), 'shared', $emails->implode(', '), [
            'permission' => $validated['permission'],
            'mailed' => $sent,
        ]);

        Inertia::flash('toast', $sent === []
            ? ['type' => 'error', 'message' => 'Legăturile s-au făcut, dar mailul n-a putut pleca. Uită-te în jurnalul aplicației.']
            : ['type' => 'success', 'message' => 'Contractul a plecat către '.implode(', ', $sent).'.']);

        return back();
    }

    /**
     * Șterge contractul cu totul: rândurile și fișierele de pe disc.
     *
     * Numai administratorul, fiindcă nu e o arhivare — după ea nu mai e nimic
     * de scos înapoi. Arhivarea rămâne pentru cazul obișnuit, când contractul
     * s-a terminat, dar trebuie ținut minte.
     */
    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        if (! $request->user()?->isAdmin()) {
            throw new AuthorizationException('Numai administratorul șterge contracte.');
        }

        $disk = Storage::disk((string) config('contracts.disk', 'contracts'));

        foreach ($contract->files as $file) {
            $disk->delete($file->path);
        }

        $disk->deleteDirectory(trim(trim((string) config('contracts.path', ''), '/').'/'.$contract->id, '/'));
        $number = $contract->number;
        $contract->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul '.$number.' a fost șters cu totul.']);

        return redirect()->route('contracts.index');
    }

    /**
     * Contractul, deschis din legătura primită pe mail.
     *
     * Fără cont: cel căruia i s-a trimis poate fi și din afara companiei.
     * Legătura e lungă și se poate stinge la o dată anume, iar fiecare
     * deschidere se numără și se scrie în jurnalul contractului.
     */
    public function shared(Request $request, string $token): Response
    {
        $share = ContractShare::query()->where('token', $token)->with('contract.files')->first();

        if ($share === null || ! $share->isOpen()) {
            abort(404, 'Legătura nu mai e bună.');
        }

        $first = $share->opened_at === null;
        $share->forceFill(['opened_at' => $share->opened_at ?? now(), 'opens' => $share->opens + 1])->save();

        if ($first) {
            ContractEvent::query()->create([
                'contract_id' => $share->contract_id,
                'user_id' => $share->user_id,
                'type' => 'opened',
                'body' => $share->email.' a deschis contractul',
            ]);
        }

        $contract = $share->contract;
        $file = $contract->files->first();

        return Inertia::render('contracts/shared', [
            'share' => [
                'permission' => $share->permission,
                'expires_at' => $share->expires_at?->toIso8601String(),
                'sender' => $share->createdBy?->name,
            ],
            'contract' => [
                'number' => $contract->number,
                'title' => $contract->title,
                'partner_name' => $contract->partner_name,
                'object' => $contract->object,
                'value' => $contract->value !== null ? (float) $contract->value : null,
                'currency' => $contract->currency,
                'signed_at' => $contract->signed_at?->toDateString(),
                'expires_at' => $contract->expires_at?->toDateString(),
                'file' => $file === null ? null : [
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'text' => $file->text !== null ? mb_substr($file->text, 0, 40000) : null,
                ],
            ],
            'token' => $token,
        ]);
    }

    /** Fișierul contractului trimis, tot pe legătura aia. */
    public function sharedFile(Request $request, string $token): StreamedResponse
    {
        $share = ContractShare::query()->where('token', $token)->with('contract.files')->first();

        if ($share === null || ! $share->isOpen()) {
            abort(404, 'Legătura nu mai e bună.');
        }

        $file = $share->contract->files->first();

        if ($file === null) {
            abort(404, 'Contractul n-are niciun fișier.');
        }

        return Storage::disk((string) config('contracts.disk', 'contracts'))->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime ?: 'application/octet-stream',
                'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.addslashes($file->original_name).'"',
            ],
        );
    }

    /**
     * Întrebare punctuală despre contractul ăsta.
     *
     * Răspunsul vine din chiar textul lui: bucățile care spun ceva despre ce
     * s-a întrebat. Nimic nu pleacă de pe server și nimic nu se inventează.
     */
    public function ask(Request $request, Contract $contract, ContractAsk $ask): array
    {
        $this->seen($request, $contract);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return $ask->ask($contract->load('files'), $validated['question']);
    }

    public function archive(Request $request, Contract $contract): RedirectResponse
    {
        $this->seen($request, $contract);

        $archived = $contract->archived_at === null;
        $contract->forceFill(['archived_at' => $archived ? now() : null])->save();
        $this->event($contract, $request->user(), $archived ? 'archived' : 'restored');

        Inertia::flash('toast', ['type' => 'success', 'message' => $archived ? 'Contract arhivat.' : 'Contract scos din arhivă.']);

        return back();
    }

    /**
     * Cât primește serverul, pe bune: cea mai mică dintre limitele PHP.
     *
     * Serverul web are limita lui, pe care aplicația n-o poate citi; dacă e
     * mai mică, fișierul e oprit înainte să ajungă aici, iar ecranul spune
     * asta când se întâmplă.
     *
     * @return array{upload_mb: int, post: string, upload: string}
     */
    private function limits(): array
    {
        $post = $this->bytes((string) ini_get('post_max_size'));
        $upload = $this->bytes((string) ini_get('upload_max_filesize'));
        $smallest = min(array_filter([$post, $upload, (int) config('contracts.max_upload_mb', 50) * 1024 ** 2]));

        return [
            'upload_mb' => (int) floor($smallest / 1024 ** 2),
            'post' => (string) ini_get('post_max_size'),
            'upload' => (string) ini_get('upload_max_filesize'),
        ];
    }

    /**
     * Trimiterea a depășit cât primește PHP: atunci nu mai ajunge nimic la
     * aplicație — nici fișierul, nici câmpurile — iar o validare obișnuită ar
     * spune „câmp obligatoriu”, ceea ce nu ajută pe nimeni.
     */
    private function tooBig(Request $request): bool
    {
        $length = (int) $request->server('CONTENT_LENGTH', 0);
        $limit = $this->bytes((string) ini_get('post_max_size'));

        // Trimitere grea, dar niciun fișier ajuns: PHP a aruncat tot ce era în
        // ea, fiindcă trecea de limita lui. O validare obișnuită ar spune
        // „câmp obligatoriu”, ceea ce nu lămurește pe nimeni.
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

    /**
     * Lista, rânduită după coloana apăsată.
     *
     * Fără alegerea omului, întâi ce expiră mai repede — asta caută cineva
     * care deschide repertoriul. Contractele fără termen stau la coadă,
     * oricare ar fi rânduiala: altfel ar ocupa primele rânduri degeaba.
     *
     * @param  array<string, mixed>  $filters
     */
    private function sorted(Builder $query, array $filters): Builder
    {
        $column = self::SORTS[$filters['sort'] ?? ''] ?? null;

        if ($column === null) {
            return $query
                ->orderByRaw('case when contracts.expires_at is null then 1 else 0 end')
                ->orderBy('contracts.expires_at')
                ->orderByDesc('contracts.id');
        }

        $direction = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($column === 'departments.name') {
            $query->leftJoin('departments', 'departments.id', '=', 'contracts.department_id')
                ->select('contracts.*');
        }

        return $query
            ->orderByRaw(sprintf('case when %s is null then 1 else 0 end', $column))
            ->orderBy($column, $direction)
            ->orderByDesc('contracts.id');
    }

    /**
     * Numai contractele omului, dacă n-are drept peste tot.
     *
     * „Ale lui” înseamnă cele aduse de el și cele date în grija lui: dacă un
     * contract i-a fost trecut altcuiva, cel care l-a adus îl vede mai departe,
     * fiindcă el știe povestea lui.
     */
    private function mine(Request $request, Builder $query): Builder
    {
        $user = $request->user();

        if ($user === null || $user->seesAllContracts()) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('created_by_id', $user->id)
            ->orWhere('owner_id', $user->id));
    }

    /** Are omul voie la contractul ăsta? */
    private function seen(Request $request, Contract $contract): void
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException('Nu aveți acces la acest contract.');
        }

        if ($user->seesAllContracts() || $contract->created_by_id === $user->id || $contract->owner_id === $user->id) {
            return;
        }

        // Cui i s-a trimis contractul îl poate deschide și din aplicație, cât
        // ține legătura.
        $shared = $contract->shares()
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('email', $user->email))
            ->get()
            ->contains(fn (ContractShare $share) => $share->isOpen());

        if ($shared) {
            return;
        }

        throw new AuthorizationException('Contractul ăsta nu e al dumneavoastră.');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        return Contract::query()
            ->when(! $filters['archived'], fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['archived'], fn ($q) => $q->whereNotNull('archived_at'))
            ->when($filters['search'] !== null, function ($q) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $q->where(fn ($inner) => $inner
                    ->where('number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('partner_name', 'like', $like)
                    ->orWhere('object', 'like', $like)
                    // Căutarea prinde și textul citit din document: o clauză se
                    // găsește fără să deschidă nimeni fișierul.
                    ->orWhereHas('files', fn ($files) => $files->where('text', 'like', $like)));
            })
            ->when($filters['partner'] !== null, fn ($q) => $q->where('partner_name', $filters['partner']))
            ->when($filters['kind'] !== null, fn ($q) => $q->where('kind', $filters['kind']))
            ->when($filters['department_id'] !== null, fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['signed_from'] !== null, fn ($q) => $q->whereDate('signed_at', '>=', $filters['signed_from']))
            ->when($filters['signed_to'] !== null, fn ($q) => $q->whereDate('signed_at', '<=', $filters['signed_to']))
            ->when($filters['expires_to'] !== null, fn ($q) => $q->whereDate('expires_at', '<=', $filters['expires_to']))
            ->when($filters['value_from'] !== null, fn ($q) => $q->where('value', '>=', $filters['value_from']))
            ->when($filters['value_to'] !== null, fn ($q) => $q->where('value', '<=', $filters['value_to']));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => $request->filled($key) ? Carbon::parse($request->string($key)->toString())->toDateString() : null;

        return [
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
            'partner' => $request->filled('partner') ? $request->string('partner')->toString() : null,
            'kind' => in_array($request->string('kind')->toString(), Contract::KINDS, true) ? $request->string('kind')->toString() : null,
            'department_id' => $request->integer('department_id') ?: null,
            'status' => in_array($request->string('status')->toString(), Contract::STATUSES, true) ? $request->string('status')->toString() : null,
            'signed_from' => $date('signed_from'),
            'signed_to' => $date('signed_to'),
            'expires_to' => $date('expires_to'),
            'value_from' => $request->filled('value_from') ? (float) $request->string('value_from')->toString() : null,
            'value_to' => $request->filled('value_to') ? (float) $request->string('value_to')->toString() : null,
            'archived' => $request->boolean('archived'),
            'sort' => array_key_exists($request->string('sort')->toString(), self::SORTS) ? $request->string('sort')->toString() : null,
            'dir' => $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'title' => $contract->title,
            'partner_name' => $contract->partner_name,
            'partner_id' => $contract->partner_id,
            'kind' => $contract->kind,
            'department' => $contract->department?->name,
            'department_id' => $contract->department_id,
            'owner' => $contract->owner?->name,
            'owner_id' => $contract->owner_id,
            'value' => $contract->value !== null ? (float) $contract->value : null,
            'currency' => $contract->currency,
            'signed_at' => $contract->signed_at?->toDateString(),
            'expires_at' => $contract->expires_at?->toDateString(),
            'days_left' => $contract->daysLeft(),
            'status' => $contract->state(),
            'archived' => $contract->archived_at !== null,
            'files_count' => $contract->files_count ?? $contract->files()->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function summary(Request $request): array
    {
        // Cifrele de sus numără ce vede omul, nu ce există: altfel ar citi un
        // total care nu i se potrivește cu lista de dedesubt.
        $all = fn () => $this->mine($request, Contract::query());
        $active = fn () => $all()->active();

        return [
            'total' => $all()->notArchived()->count(),
            'active' => $active()->count(),
            'expiring' => $active()->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', now()->toDateString())
                ->whereDate('expires_at', '<=', now()->addDays(90)->toDateString())->count(),
            'unowned' => $all()->notArchived()->whereNull('owner_id')->count(),
            'value_ron' => (float) $active()->where('currency', 'RON')->sum('value'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function blank(?User $user, string $name, array $validated): Contract
    {
        $title = trim(pathinfo($name, PATHINFO_FILENAME));

        return Contract::query()->create([
            'number' => $this->number(),
            'title' => $title !== '' ? Str::limit($title, 290) : 'Contract fără titlu',
            'partner_name' => '—',
            'kind' => $validated['kind'] ?? Contract::KIND_SUPPLIER,
            'department_id' => $validated['department_id'] ?? null,
            // Cine încarcă răspunde de contract până spune altcineva altfel:
            // altfel repertoriul se umple de contracte ale nimănui.
            'owner_id' => $user?->id,
            // Ce se încarcă e, de obicei, un contract semnat și în lucru:
            // starea se schimbă dintr-un clic, dacă e altfel.
            'status' => Contract::STATUS_ACTIVE,
            'created_by_id' => $user?->id,
        ]);
    }

    private function attach(
        Contract $contract,
        mixed $upload,
        string $hash,
        ?User $user,
        ?string $label = null,
        string $kind = ContractFile::KIND_CONTRACT,
        ?string $signedAt = null,
    ): ContractFile {
        // Numai contractul însuși are versiuni: un act adițional nu e „v2”, e
        // alt document, care stă lângă el.
        $version = $kind === ContractFile::KIND_CONTRACT
            ? (int) ContractFile::query()->where('contract_id', $contract->id)->where('kind', ContractFile::KIND_CONTRACT)->max('version') + 1
            : 1;
        $folder = trim((string) config('contracts.path', ''), '/');
        $path = $upload->store(trim($folder.'/'.$contract->id, '/'), (string) config('contracts.disk', 'contracts'));

        $file = ContractFile::query()->create([
            'contract_id' => $contract->id,
            'version' => $version,
            'kind' => $kind,
            'signed_at' => $signedAt,
            'label' => $label,
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
            'hash' => $hash,
            'ocr_status' => ContractFile::OCR_PENDING,
            'uploaded_by_id' => $user?->id,
        ]);

        $type = match (true) {
            $kind === ContractFile::KIND_ADDENDUM => 'addendum',
            $kind === ContractFile::KIND_ANNEX => 'annex',
            $version === 1 => 'uploaded',
            default => 'version',
        };

        $this->event($contract, $user, $type, $upload->getClientOriginalName(), ['file_id' => $file->id, 'version' => $version, 'kind' => $kind]);

        ReadContractFile::dispatch($file->id);

        return $file;
    }

    private function number(): string
    {
        return DB::transaction(function () {
            $year = now()->year;
            $last = Contract::query()->where('number', 'like', 'CTR-'.$year.'-%')->lockForUpdate()->max('number');
            $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

            return sprintf('CTR-%d-%04d', $year, $next);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Contract $contract, array $validated): array
    {
        $changed = [];

        foreach ($validated as $key => $value) {
            $before = $contract->{$key};
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
    private function event(Contract $contract, ?User $user, string $type, ?string $body = null, array $payload = []): void
    {
        ContractEvent::query()->create([
            'contract_id' => $contract->id,
            'user_id' => $user?->id,
            'type' => $type,
            'body' => $body,
            'payload' => $payload ?: null,
        ]);
    }
}
