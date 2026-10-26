<?php

namespace App\Services\Forecast;

use App\Models\Department;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanSection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Bugetul, împărțit pe mâinile care îl scriu.
 *
 * Fiecare categorie de produs are departamentul ei — charterul la Charters,
 * Senior Voyage la Senior Voyage — rețeaua de agenții își bugetează oamenii
 * și bonusurile, marketingul cheltuielile lui, iar administrativul tot ce
 * rămâne. Aici se spune a cui e un rând, cine are voie să scrie în el și în
 * ce stare e bucata din care face parte.
 */
class PlanSections
{
    /** Bucata care ține tot ce nu și-a revendicat nimeni. */
    public const FALLBACK = 'cost:admin';

    /** Stăpânii scriși în plan, pe plan — ca să nu-i citim de zeci de ori. */
    private array $own = [];

    /**
     * A cui e rândul ăsta.
     */
    public function forLine(string $kind, string $key, ?string $label = null): string
    {
        if ($kind === PlanLine::CHARTER) {
            return 'category:'.PlanBuilder::CHARTER_CATEGORY;
        }

        if ($kind === PlanLine::CATEGORY) {
            // Sub-liniile unei categorii stau la categoria lor: „Senior
            // Voyage · Grecia” e tot al lui Senior Voyage.
            return 'category:'.$this->parent($key);
        }

        if ($kind === PlanLine::BELOW) {
            return self::FALLBACK;
        }

        if ($kind === PlanLine::RETAIL) {
            return 'cost:retail';
        }

        $costs = (array) config('pnl.plan_owners.costs', []);

        // Liniile de salarii nu sunt ale rețelei de agenții: din ele s-a
        // scos deja partea magazinelor, care se bugetează pe fila lor. Ce
        // rămâne în linie sunt oamenii din centrală și din celelalte canale,
        // deci merge la grupa ei, ca orice cheltuială.
        [$group] = array_pad(explode(' · ', (string) $label, 2), 2, '');

        foreach ($costs as $name => $block) {
            $groups = (array) ($block['groups'] ?? []);

            if ($groups !== ['*'] && in_array($group, $groups, true)) {
                return 'cost:'.$name;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Categoria din care face parte un rând: ce stă înaintea punctului.
     */
    public function parent(string $key): string
    {
        [$parent] = array_pad(explode(' · ', $key, 2), 2, '');

        // Tina își ține numele întreg: „Tina · Ticketing” e o categorie, nu
        // o sub-linie a lui Tina.
        return str_starts_with($key, PlanBuilder::TINA_PREFIX) ? $key : ($parent !== '' ? $parent : $key);
    }

    /**
     * Departamentul care ține bucata asta.
     *
     * Întâi ce scrie în plan — o categorie adăugată cu mâna își alege
     * stăpânul la naștere — și abia pe urmă configurația.
     */
    public function department(string $section, ?Plan $plan = null): ?string
    {
        if ($plan !== null) {
            $own = $this->overrides($plan);

            if (isset($own[$section])) {
                return $own[$section];
            }
        }

        if (str_starts_with($section, 'category:')) {
            $category = substr($section, strlen('category:'));

            $owners = (array) config('pnl.plan_owners.categories', []);

            return isset($owners[$category]) ? (string) $owners[$category] : null;
        }

        $name = substr($section, strlen('cost:'));

        return config('pnl.plan_owners.costs.'.$name.'.department');
    }

    /**
     * Stăpânii scriși în planul ăsta, peste cei din configurație.
     *
     * @return array<string, string>
     */
    private function overrides(Plan $plan): array
    {
        return $this->own[$plan->getKey()] ??= PlanSection::query()
            ->where('plan_id', $plan->getKey())
            ->whereNotNull('department')
            ->pluck('department', 'key')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    public function label(string $section): string
    {
        if (str_starts_with($section, 'category:')) {
            return substr($section, strlen('category:'));
        }

        $name = substr($section, strlen('cost:'));

        return (string) config('pnl.plan_owners.costs.'.$name.'.label', $name);
    }

    /**
     * Bucățile pe care le ține omul ăsta.
     *
     * @return list<string>
     */
    public function mine(User $user, Plan $plan): array
    {
        // Bucata e a șefului de departament — coroana din fila Departamente.
        // El răspunde de cifrele lui în fața conducerii; dacă se vrea și
        // echipa, se deschide din configurație.
        $his = Department::query()
            ->where('head_user_id', $user->getKey())
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        if ((bool) config('pnl.plan_owners.members', false)) {
            $his = array_values(array_unique([
                ...$his,
                ...$user->departments()->pluck('name')->map(fn ($name) => (string) $name)->all(),
            ]));
        }
        $out = [];

        foreach ($this->keys($plan) as $section) {
            $department = $this->department($section, $plan);

            if ($department !== null && in_array($department, $his, true)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /**
     * Toate bucățile planului, în ordinea în care se citesc.
     *
     * @return list<string>
     */
    public function keys(Plan $plan): array
    {
        $out = [];

        foreach ($plan->lines()->get(['kind', 'key', 'label']) as $line) {
            $out[$this->forLine($line->kind, $line->key, $line->label)] = true;
        }

        foreach (array_keys((array) config('pnl.plan_owners.costs', [])) as $name) {
            $out['cost:'.$name] = true;
        }

        $keys = array_keys($out);
        sort($keys);

        return array_values($keys);
    }

    /**
     * Starea fiecărei bucăți, cu cine a trimis-o și când.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Plan $plan): array
    {
        $saved = PlanSection::query()
            ->where('plan_id', $plan->id)
            ->with(['submittedBy:id,name', 'acceptedBy:id,name'])
            ->get()
            ->keyBy('key');

        $lines = $plan->lines()->get();
        $people = $this->people();
        $heads = Department::query()
            ->with('head:id,name')
            ->get()
            ->mapWithKeys(fn (Department $department) => [$department->name => $department->head?->name])
            ->all();

        $out = [];

        foreach ($this->keys($plan) as $key) {
            $own = $lines->filter(fn (PlanLine $line) => $this->forLine($line->kind, $line->key, $line->label) === $key);
            $section = $saved->get($key);
            $department = $this->department($key, $plan);

            $out[] = [
                'key' => $key,
                'label' => $this->label($key),
                'kind' => str_starts_with($key, 'category:') ? 'category' : 'cost',
                'department' => $department,
                'head' => $department === null ? null : ($heads[$department] ?? null),
                'people' => $department === null ? [] : ($people[$department] ?? []),
                'status' => $section?->status ?? PlanSection::DRAFT,
                'submitted_by' => $section?->submittedBy?->name,
                'submitted_at' => $section?->submitted_at?->format('d.m.Y H:i'),
                'accepted_by' => $section?->acceptedBy?->name,
                'accepted_at' => $section?->accepted_at?->format('d.m.Y H:i'),
                'note' => $section?->note,
                'rows' => $own->pluck('key')->unique()->count(),
                'net' => round($own->sum(fn (PlanLine $line) => $line->net()), 2),
                'amount' => round((float) $own->sum('amount'), 2),
            ];
        }

        return $out;
    }

    /**
     * Cine poate scrie în bucata asta acum.
     *
     * Conducerea, oricând; omul din departament, numai cât timp bucata lui
     * e în lucru. Trimisă spre consolidare, se încuie.
     */
    public function editable(User $user, Plan $plan, string $section): bool
    {
        if ($user->hasRole(User::ROLE_TOP_MANAGEMENT)) {
            return true;
        }

        if (! in_array($section, $this->mine($user, $plan), true)) {
            return false;
        }

        $saved = PlanSection::query()->where('plan_id', $plan->id)->where('key', $section)->first();

        return $saved === null || ! $saved->locked();
    }

    /**
     * Oamenii fiecărui departament, ca să se vadă pe cine se așteaptă.
     *
     * @return array<string, list<string>>
     */
    private function people(): array
    {
        return Department::query()
            ->with(['members:id,name', 'head:id,name'])
            ->get()
            ->mapWithKeys(fn (Department $department) => [
                $department->name => array_values(array_unique(array_filter([
                    // Șeful de departament primul: pe el se așteaptă.
                    $department->head?->name,
                    ...$department->members->pluck('name')->all(),
                ]))),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, PlanLine>  $lines
     * @return Collection<int, PlanLine>
     */
    public function only(Collection $lines, string $section): Collection
    {
        return $lines->filter(fn (PlanLine $line) => $this->forLine($line->kind, $line->key, $line->label) === $section);
    }
}
