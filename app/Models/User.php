<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'microsoft_id', 'avatar', 'email_verified_at', 'roles'])]
#[Hidden(['password', 'microsoft_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
        ];
    }

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TOP_MANAGEMENT = 'top_management';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_TREASURY = 'treasury';

    /** Omul unui departament: aprobă ce e al lui și atât. */
    public const ROLE_OPERATIONAL = 'operational';

    /** Repertoriul de contracte: un rol de sine stătător, cu tabul lui. */
    public const ROLE_CONTRACTS = 'contract_management';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_TOP_MANAGEMENT, self::ROLE_FINANCE, self::ROLE_TREASURY, self::ROLE_OPERATIONAL, self::ROLE_CONTRACTS];

    /**
     * Cine ce vede și ce poate face.
     *
     *   Administrator    tot, peste tot;
     *   Top Management   tot; el dă aprobarea finală;
     *   Financiar        tot în afară de Rapoarte; nu aprobă nimic, dar poate
     *                    contesta o factură, o poate muta între departamente
     *                    și pregătește rulajele de plată;
     *   Trezorerie       rulajele de plată, e-Facturi, verificările de plăți
     *                    și extrasele bancare — atât;
     *   Operațional      doar Aprobări, pentru departamentul lui.
     *
     * Regulile stau aici, într-un singur loc: meniul le citește ca să ascundă
     * ce nu e al omului, iar rutele ca să refuze ce n-a fost ascuns.
     */
    /**
     * Cine intră în repertoriul de contracte: rolul lui și Top Management,
     * care vede tot în companie. Facturile rămân altă lume.
     */
    public function canSeeContracts(): bool
    {
        return $this->hasRole(self::ROLE_CONTRACTS) || $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /**
     * Cine vede contractele tuturor, nu doar pe ale lui.
     *
     * Omul care ține contracte își vede pe ale lui — cele aduse de el sau date
     * în grija lui. Peste tot se uită doar administratorul și Top Management,
     * fiindcă ei răspund de companie, nu de un teanc de dosare.
     */
    public function seesAllContracts(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_TOP_MANAGEMENT, (array) ($this->roles ?? []), true);
    }

    /**
     * Registrul contractelor de închiriere: din el iese retratarea IFRS 16.
     *
     * Îl ține Financiarul, care scrie durata și rata din contract, și îl
     * citește Top Management-ul, fiindcă retratarea îi schimbă rezultatul.
     */
    public function canSeeLeases(): bool
    {
        return $this->canSeeReports() || $this->hasRole(self::ROLE_FINANCE);
    }

    public function canSeeReports(): bool
    {
        return $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /**
     * Bugetul anului: conducerea îl vede întreg, iar fiecare departament
     * care ține o bucată din el intră ca să-și scrie partea.
     */
    public function canSeeForecast(): bool
    {
        if ($this->canSeeReports()) {
            return true;
        }

        $owners = [
            ...array_values((array) config('pnl.plan_owners.categories', [])),
            ...array_column((array) config('pnl.plan_owners.costs', []), 'department'),
        ];

        // Șeful de departament — coroana din fila Departamente — intră la
        // bugetul lui; echipa, numai dacă se deschide din configurație.
        $head = Department::query()
            ->where('head_user_id', $this->getKey())
            ->whereIn('name', $owners)
            ->exists();

        if ($head || ! (bool) config('pnl.plan_owners.members', false)) {
            return $head;
        }

        return $this->departments()->whereIn('name', $owners)->exists();
    }

    /** Aprobarea finală a plăților. */
    public function canApproveFinal(): bool
    {
        return $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /** Rutarea pe departamente și regulile ei: le face Financiarul. */
    public function canRoute(): bool
    {
        return $this->hasRole(self::ROLE_FINANCE);
    }

    /** Pagina de rutare se și vede de sus, chiar dacă butoanele sunt ale Financiarului. */
    public function canSeeRouting(): bool
    {
        return $this->canRoute() || $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /**
     * Își poate face echipa: adaugă colegi pe departamentul lui, tot
     * operaționali. Nu poate da alte roluri și nu poate scoate pe nimeni.
     */
    public function canManageOwnTeam(): bool
    {
        return $this->isAdmin() || $this->departmentIds() !== [];
    }

    /**
     * Poate contesta sau muta orice factură, fără să fie al departamentului
     * ei: Financiarul ține evidența, deci poate opri o plată și o poate
     * trimite unde trebuie — dar nu poate aproba în locul nimănui.
     */
    public function canDisputeAnyInvoice(): bool
    {
        return $this->canRoute();
    }

    /**
     * Vede în „De aprobat” tot ce așteaptă o semnătură, nu doar partea lui.
     *
     * Financiarul ține evidența plăților, Top Management răspunde de ele:
     * aprobă tot numai ce e al departamentelor lor, dar trebuie să vadă unde
     * s-a oprit o factură fără să intre pe rând în fiecare cont. Omul unui
     * departament își vede coada lui.
     */
    public function seesEveryQueue(): bool
    {
        return $this->hasRole(self::ROLE_FINANCE) || $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /** Facturile, furnizorii, partenerii — evidența de zi cu zi. */
    public function canSeeInvoices(): bool
    {
        return $this->hasRole(self::ROLE_FINANCE) || $this->hasRole(self::ROLE_TOP_MANAGEMENT);
    }

    /** Rulajele de plată, e-Facturile, verificările și extrasele. */
    public function canSeePayments(): bool
    {
        return $this->canSeeInvoices() || $this->hasRole(self::ROLE_TREASURY);
    }

    /**
     * Cine deschide un rulaj de plată: trezoreria, care face plățile, și
     * Financiarul, care îl pregătește. Ceilalți îl văd, nu-l pornesc.
     */
    public function canStartPaymentRun(): bool
    {
        return $this->hasRole(self::ROLE_FINANCE) || $this->hasRole(self::ROLE_TREASURY);
    }

    /**
     * Trezoreria: cine pune la plată ce s-a aprobat.
     *
     * Lista ei are meniul ei — altfel se ajungea la ea numai dintr-o legătură
     * de mail, iar cine face plățile trebuia să țină minte o adresă.
     */
    public function isTreasury(): bool
    {
        return $this->hasRole(self::ROLE_TREASURY) || $this->isAdmin();
    }

    /**
     * Lista facturilor primite: evidența Financiarului și, pe felia ei,
     * trezoreria — ce a aprobat Top Management-ul și n-a fost plătit. Ea are
     * pagina asta în meniu și în vestea de joi, așa că ruta trebuie s-o lase
     * înăuntru; filtrul pe care îl vede e pus de server, nu de ea.
     */
    public function canSeePayableList(): bool
    {
        return $this->canSeeInvoices() || $this->isTreasury();
    }

    /** Vede numai felia de plătit, nu toată evidența. */
    public function seesOnlyPayables(): bool
    {
        return ! $this->canSeeInvoices() && $this->isTreasury();
    }

    /**
     * Fișa unei facturi: o deschide oricine are treabă cu ea — omul care o
     * aprobă, Financiarul care o ține, Trezoreria care o plătește. Ce vede
     * fiecare rămâne limitat la departamentele lui.
     */
    public function canOpenInvoice(): bool
    {
        return $this->canSeeApprovals() || $this->canSeePayments();
    }

    /** Căsuța de aprobări: toată lumea în afară de trezorerie. */
    public function canSeeApprovals(): bool
    {
        return ! $this->isOnly(self::ROLE_TREASURY);
    }

    /** Panoul principal: cine are o privire de ansamblu asupra facturilor. */
    public function canSeeDashboard(): bool
    {
        return $this->canSeeInvoices();
    }

    /**
     * Omul are exact rolul ăsta și nimic altceva — de aici încep restricțiile
     * trezoreriei și ale operaționalului.
     */
    private function isOnly(string $role): bool
    {
        $roles = array_values((array) ($this->roles ?? []));

        return $roles === [$role];
    }

    /**
     * The departments whose invoices the user approves.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Whether the user holds the role; an admin holds them all.
     */
    public function hasRole(string $role): bool
    {
        $roles = (array) ($this->roles ?? []);

        return in_array(self::ROLE_ADMIN, $roles, true) || in_array($role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, (array) ($this->roles ?? []), true);
    }

    /**
     * Rolurile care văd facturile tuturor departamentelor: administrarea,
     * finanțele și trezoreria plătesc pentru toată compania, iar Top
     * Management decide pe tot. Restul văd doar ce e al departamentului lor.
     */
    public const ROLES_ACROSS_DEPARTMENTS = [self::ROLE_ADMIN, self::ROLE_FINANCE, self::ROLE_TREASURY, self::ROLE_TOP_MANAGEMENT];

    /** Ce vede omul când intră: fiecare rol are altă casă. */
    public function home(): string
    {
        return match (true) {
            $this->canSeeDashboard() => 'dashboard',
            $this->hasRole(self::ROLE_TREASURY) => 'payment-runs.index',
            default => 'approvals.index',
        };
    }

    /**
     * Whether the user sees every department's invoices, or only their own.
     *
     * Un om repartizat pe departamente vede doar departamentele lui; unul fără
     * niciun departament nu e limitat, fiindcă altfel n-ar mai vedea nimic.
     */
    public function seesAllDepartments(): bool
    {
        foreach (self::ROLES_ACROSS_DEPARTMENTS as $role) {
            if (in_array($role, (array) ($this->roles ?? []), true)) {
                return true;
            }
        }

        return $this->departmentIds() === [];
    }

    /**
     * Whether the user speaks for the department on its invoices.
     *
     * Aprobarea unei părți de factură e a departamentului care o poartă, deci
     * ține strict de repartizare — nici administratorul nu aprobă în locul
     * altui departament, fiindcă o factură împărțită între trei departamente
     * are trei semnături de dat, nu una. Cine trebuie să aprobe se pune pe
     * departament; o parte căzută greșit se mută („Nu e al nostru”).
     */
    public function approvesFor(Department|int $department): bool
    {
        $id = $department instanceof Department ? $department->id : $department;

        return in_array($id, $this->departmentIds(), true);
    }

    /**
     * Poate decide pentru departament, dar nu neapărat aproba: Financiarul
     * poate opri o factură sau o poate muta oriunde, fără să aprobe nimic.
     */
    public function decidesFor(Department|int $department): bool
    {
        return $this->approvesFor($department) || $this->canDisputeAnyInvoice();
    }

    /**
     * @return array<int, int>
     */
    public function departmentIds(): array
    {
        return $this->departments()->pluck('departments.id')->all();
    }
}
