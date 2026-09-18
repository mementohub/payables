<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSetting;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The charter contracts of CHR as the contract pack of 17.09.2026 describes
 * them: the two Memento Air contracts CHR pays on, the hard block CHR sells
 * to Anima Wings, and the contracts Memento Air holds with the airlines,
 * which are kept as terms only until the finance team confirms who actually
 * pays the carriers.
 *
 * Loaded by the first upgrade that finds no charter contract, and again by
 * the first upgrade after the pack changes, when the contracts in place came
 * from an earlier pack rather than from the Charter tab. Everything can be
 * edited afterwards in the Charter tab, and a reloaded programme replaces the
 * rotations of the seasons it carries.
 */
class CharterScheduleLoader
{
    public const SETTING = 'charter_schedule';

    public const VERSION = 'pachet contracte 17.09.2026 (CTR 317 AA30/ADD7, CTR 281, CTR 1585, draft W26/27, contracte companii aeriene); taxe CTR 317 la valoarea contractului';

    /**
     * Names an earlier pack gave the same contracts, so a reload updates them
     * instead of adding a second copy next to them.
     *
     * @var array<string, list<string>>
     */
    private const EARLIER_NAMES = [
        'S26|CTR 317 Memento Air – S26' => ['CTR 317/11.11.2025 Memento Air – S26'],
    ];

    /**
     * The contracts CHR settles, and the ones kept for their terms only.
     *
     * @var list<array<string, mixed>>
     */
    private const CONTRACTS = [
        [
            'name' => 'CTR 317 Memento Air – S26',
            'counterparty' => 'Memento Air S.R.L.',
            'buyer' => "Christian'76 Tour",
            'direction' => 'out',
            'in_cash_flow' => true,
            'contract_no' => '317/11.11.2025',
            'signed_date' => '2025-11-11',
            'period_from' => '2026-03-29',
            'period_to' => '2026-10-24',
            'season' => 'S26',
            'status' => 'signed',
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'payment_basis' => 'flight',
            'taxes_rule' => 'monthly_first_week',
            'taxes_month_day' => 5,
            'deposit_percent' => null,
            'deposit_amount' => null,
            'deposit_due_date' => null,
            'deposit_paid' => true,
            'deposit_settlement' => 'regularizare la plata ultimei rotații (art. 3.5)',
            'contract_value' => 31312717.83,
            'contract_value_with_taxes' => 36944842.79,
            'invoicing' => 'factură per rotație; taxele pe factura de reconciliere lunară, emisă în 3 zile lucrătoare de la reconcilierea operatorului',
            'fuel_rule' => 'Platts FOB MED + SAF 70 EUR/t, bază 750 USD/t la EUR/USD 1,16; recalcul pe 15 și 30 ale lunii pentru următoarele 2 săptămâni, facturat separat (valoare necunoscută până la primirea recalculului)',
            'fx_markup_pct' => 2,
            'late_penalty_pct_per_day' => 0.2,
            'cancellation_terms' => 'Renunțare după semnare: 100% din valoarea lanțului (art. 3.3). Anulare de către Memento Air: preaviz 30 de zile, rambursare în 7 zile (art. 4.4–4.5).',
            'source' => 'CTR 317/11.11.2025 + anexa AA30/ADD7 din 11.09.2026',
            'confidence' => 'R',
            'notes' => 'Locuri 100% garantate. Taxele de aeroport ale rotațiilor sunt aduse proporțional la valoarea contractului cu taxe (36.944.842,79 EUR): anexa le însumează cu 21.835,69 EUR mai puțin. Depozitul este prevăzut la art. 3.2 a) „conform anexei”, dar nicio anexă S26 nu conține sumă sau scadență, deci este 0 în model. Avansul de 1.730.662,40 EUR (factura 19617/31.03.2026, stornată) a fost pe rotațiile din mai, nu depozit de contract.',
        ],
        [
            'name' => 'W26/27 Memento Air – draft',
            'counterparty' => 'Memento Air S.R.L.',
            'buyer' => "Christian'76 Tour",
            'direction' => 'out',
            'in_cash_flow' => true,
            'contract_no' => null,
            'signed_date' => null,
            'period_from' => '2026-09-26',
            'period_to' => '2027-03-27',
            'season' => 'W26-27',
            'status' => 'draft',
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'payment_basis' => 'flight',
            'taxes_rule' => 'monthly_first_week',
            'taxes_month_day' => 5,
            'deposit_percent' => 50,
            'deposit_amount' => null,
            'deposit_due_date' => '2026-10-05',
            'deposit_paid' => false,
            'deposit_settlement' => 'regularizare la ultima rotație (ipoteză, ca la CTR 281)',
            'contract_value' => 7257133.59,
            'contract_value_with_taxes' => 8621313.63,
            'invoicing' => 'presupus identic cu CTR 317',
            'fuel_rule' => 'bază 750 USD/t la EUR/USD 1,15; formula anexei S26',
            'fx_markup_pct' => 2,
            'late_penalty_pct_per_day' => 0.2,
            'cancellation_terms' => 'Presupus 100% din valoarea lanțului, ca la CTR 317.',
            'source' => 'DRAFTS W26_27.xlsx, foile CTR1_31DEC27_W2627 și CTR1_01JAN27_W2627',
            'confidence' => 'M',
            'notes' => 'Draft nesemnat, fără număr de contract. Depozitul de 50% scadent 05.10.2026 este o ipoteză de model, luată după precedentul CTR 281 (W25/26: 4.882.741,68 EUR = 50%). De confirmat la semnare, probabil în octombrie 2026.',
        ],
        [
            'name' => 'CTR 1585 Anima Wings – hard block S26',
            'counterparty' => 'Anima Wings Aviation S.A.',
            'buyer' => 'Anima Wings Aviation S.A.',
            'direction' => 'in',
            'in_cash_flow' => true,
            'contract_no' => '1585/29.06.2026',
            'signed_date' => '2026-06-29',
            'period_from' => '2026-07-11',
            'period_to' => '2026-09-30',
            'season' => 'S26',
            'status' => 'signed',
            'operator' => 'Anima Wings',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'payment_basis' => 'flight',
            'taxes_rule' => 'with_rotation',
            'taxes_month_day' => 5,
            'deposit_percent' => 10,
            'deposit_amount' => 114156.19,
            'deposit_due_date' => '2026-06-29',
            'deposit_paid' => true,
            'deposit_settlement' => 'regularizare la ultima rotație (art. 3.5)',
            'contract_value' => 1120403.95,
            'contract_value_with_taxes' => 1141561.90,
            'invoicing' => 'factură CHR per rotație, taxele incluse',
            'fuel_rule' => 'bază 750 USD/t la EUR/USD 1,15; recalcul pe 15 și 30, diferențele facturate separat',
            'fx_markup_pct' => 0,
            'late_penalty_pct_per_day' => 0.2,
            'cancellation_terms' => 'Renunțare Anima după semnare: 100% din valoarea lanțului. CHR poate anula cu preaviz 30 de zile și rambursează în 7 zile. Penalitățile pot depăși debitul (art. 3.8).',
            'source' => 'CTR 1585/29.06.2026 + Anexa 1',
            'confidence' => 'R',
            'notes' => 'CHR este prestator: vinde locuri către Anima Wings pe cursele sale, deci este încasare. Rotația se încasează împreună cu taxele de aeroport, cu 10 zile înainte de operare (art. 3.2 b). Rotațiile sunt reconstruite din cele 23 de lanțuri ale anexei, repartizate pe ziua de operare; totalul pe lanț este exact, datele individuale sunt aproximate.',
        ],
        [
            'name' => 'CTR 281 Memento Air – W25/26',
            'counterparty' => 'Memento Air S.R.L.',
            'buyer' => "Christian'76 Tour",
            'direction' => 'out',
            'in_cash_flow' => false,
            'contract_no' => '281/19.09.2025',
            'signed_date' => '2025-09-19',
            'period_from' => '2025-10-03',
            'period_to' => '2026-03-28',
            'season' => 'W25-26',
            'status' => 'signed',
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'payment_basis' => 'flight',
            'taxes_rule' => 'days_after_flight',
            'taxes_days' => 3,
            'deposit_percent' => null,
            'deposit_amount' => 4882741.68,
            'deposit_due_date' => '2025-09-29',
            'deposit_paid' => true,
            'deposit_settlement' => 'regularizare la ultima rotație; stornat 3.892.112 EUR pe 28.10.2025',
            'contract_value' => 6798441.80,
            'contract_value_with_taxes' => null,
            'invoicing' => 'o linie de anexă per rotație, cu valoarea facturii zborului',
            'fuel_rule' => 'bază 750 USD/t la EUR/USD 1,15; reconciliere în prima jumătate a lunii următoare operării (art. 4.11)',
            'fx_markup_pct' => 2,
            'late_penalty_pct_per_day' => 0.2,
            'cancellation_terms' => '100% din valoarea lanțului după semnare.',
            'source' => 'CTR 281/19.09.2025 + Anexa 1 FINAL',
            'confidence' => 'R',
            'notes' => 'Sezon încheiat la 28.03.2026, deci în afara fluxului. Contează ca precedent: taxele de aeroport se plăteau la 3 zile DUPĂ zbor, spre deosebire de S26, iar depozitul de 4.882.741,68 EUR (50% din valoarea versiunii OLD a anexei) stă la baza ipotezei de 50% pentru W26/27.',
        ],
    ];

    /**
     * The contracts Memento Air holds with the carriers. They are stored for
     * their terms and values, out of the cash flow, because the pack leaves
     * open whether CHR pays the airlines directly.
     *
     * @var list<array<string, mixed>>
     */
    private const AIRLINE_CONTRACTS = [
        ['Anima Wings – C7 full charter S26', 'Anima Wings Aviation S.A.', 'C7/10.11.2025', '2025-11-10', '2026-03-29', '2026-10-24', 'S26', 38475418.34, 4523459.21, 7, 'flight', 'days_before_flight', 14, 0.5, 'săptămânal, marți, cu 2 săptămâni în avans', '750 USD/t la EUR/USD 1,09, Platts FOB MED, decontare în 15 zile; de-icing la cost + 2%, plafon 3.000 EUR', '5% >26 zile; 10% 25–16; 40% 15–11; 70% ≤10; 50% dacă zborul e operat de alt carrier', 'Depozit 8% în 4 tranșe (15.11.2025, 10.01, 01.02, 23.02.2026), compensat cu ultimele facturi. 1.199 rotații după ADD6. 74% din costul charter S26. Plățile nu apar în registrul Memento Air.', 'R'],
        ['Anima Wings – C11 block groups S26', 'Anima Wings Aviation S.A.', 'C11/05.02.2026', '2026-02-05', '2026-04-10', '2026-10-19', 'S26', 990015.70, 30000.00, 7, 'flight', 'days_before_flight', 14, 0.5, 'săptămânal, marți, cu 2 săptămâni în avans', 'fără clauză de combustibil', 'ca la C7', '116 zboruri, 4.930 locuri, OTP-LCA/NCE și CLJ/IAS-SKG. Termenul de plată este contradictoriu în text (7 sau 14 zile).', 'M'],
        ['Anima Wings – C5 full charter W25/26', 'Anima Wings Aviation S.A.', 'C5/08.08.2025', '2025-08-08', '2025-10-26', '2026-03-28', 'W25-26', 9797340.80, 685813.86, 7, 'flight', 'days_after_flight', 7, 0.5, 'săptămânal, marți', '750 USD/t, ca la C7', '5% / 10% / 40% / 70%', 'Depozit 7% plătit 15.09.2025, stornat 29.01.2026. 140 rotații finale după anulări, 5,40 mil. EUR.', 'R'],
        ['Corendon Airlines – S26', 'Corendon Airlines (Türkiye)', "COMM/S'26/CRS/01 din 11.09.2025", '2025-09-11', '2026-05-02', '2026-10-06', 'S26', 7620669.00, 541131.48, 7, 'week_start', 'with_rotation', null, null, 'o factură pe săptămână de operare, emise în loturi de 3–6 săptămâni', 'reconciliere lunară: 750 USD/t la 1,16 plus taxele pe pasagerii reali, dedusă din plata săptămânală următoare. În 2026 fuel de plătit: +118.378,61 EUR pe iunie', '10% ≥45 zile; 25% 25–44; 40% 15–24; 50% 7–14; 75% ≤7; primul și ultimul zbor al seriei gratuite', '211 zboruri, 12 lanțuri din AYT. Depozit 7,1% la semnare, din care 502.689,48 rulat din sezonul 2025. Observat: scadența la 4–7 zile înainte de prima zi a săptămânii.', 'R'],
        ['Tailwind Airlines – S26', 'Tailwind Airlines', 'S2026/002 din 08.08.2025', '2025-08-08', '2026-06-01', '2026-10-02', 'S26', 3280200.00, 210000.00, 6, 'week_start', 'with_rotation', null, null, 'o factură pe săptămână, emisă cu circa 12 zile înainte de primul zbor', 'reconciliere lunară inclusă în factura săptămânală următoare; 700 USD/t la 1,1192. Iunie 2026: +43.100 EUR de plătit', 'reziliere: 50% din zborurile rămase; 15% >30 zile; 35% 11–30; 50% ≤10', '106 rotații, 6 lanțuri din AYT. Depozit 210.000 EUR nerambursabil. Penalizare de anulare 2026 pe GHV/TGM/TSR-AYT: 126.781,20 EUR.', 'R'],
        ['Aegean Airlines – S26', 'Aegean Airlines', 'semnat 22.01.2026', '2026-01-22', '2026-06-02', '2026-10-01', 'S26', 1087084.80, 54354.61, 7, 'flight', 'with_rotation', null, null, 'proformă per rotație, emise în loturi lunare cu 3–6 săptămâni înainte', 'combustibil FIX pentru S26 (bază 700 USD/t, 1,15, CO2 72, SAF 65), fără reconciliere', '10% de la semnare; 25% 25–16 zile; 50% 15–9; 80% ≤8; 50% dacă operează alt carrier', '36 rotații: 18 RHO-CLJ și 18 CHQ-BCM. Depozit 5% nerambursabil în două tranșe. Rotația și taxele la 174 de pasageri se plătesc cu 7 zile înainte.', 'R'],
        ['TEZ Tour (Sky Express) – S26', 'TEZ Tour S.R.L. (Sky Express)', '49/24.10.2025 (+ 232, 233)', '2025-10-24', '2026-06-09', '2026-09-22', 'S26', 512900.00, 54461.50, 14, 'flight', 'with_rotation', null, 1.0, 'o factură per rotație, în loturi lunare cu 3–5 săptămâni înainte', 'supliment fix pe pasager (Anexa 2 din 03.06.2026): +20 EUR în iunie, +10 EUR iulie–septembrie', 'denunțare: 100% plus pierderea depozitului; prestatorul poate anula cu 30 de zile', '47 rotații în balanță. Contractul cere factura cu 14 zile înainte și plata în 3 zile lucrătoare; observat: plata efectivă la circa 9 zile după scadență.', 'M'],
        ['Amara Tour (HiSky) – S26', 'Amara Tour S.R.L. (HiSky)', '31712/18.12.2025', '2025-12-18', '2026-06-04', '2026-10-01', 'S26', 281010.00, 32256.84, 14, 'flight', 'with_rotation', null, 0.2, 'factură per segment plus factură separată de taxe pe segment, în loturi lunare', 'regularizare de combustibil per zbor, pozitivă în 2026 (+7.623 EUR total)', 'fără drept de denunțare: 100% din preț; vânzătorul poate anula sub 70% ocupare cu 20 de zile', '34 rotații: block seats CLJ-LCA (30 locuri) și CLJ-PMI (40 locuri). Avans 10% per anexă, regularizat la ultimele 2 rotații.', 'R'],
        ['Freebird Airlines – S26', 'Freebird Airlines', "GTA S'26 MMT.01 din 03.06.2026", '2026-06-03', '2026-06-20', '2026-10-31', 'S26', 527670.00, 43290.00, 7, 'week_start', 'with_rotation', null, null, 'săptămânal, pentru săptămâna următoare', 'ajustare lunară pe pasagerii reali, pe factura următoare', '5% 60–46 zile; 10% 45–21; 25% 20–15; 50% <14; maximum 2 zboruri pe serie', '4 rotații AYT-SBZ pe contract plus ad-hoc. Depozitul este o rotație, restituit după ultimul zbor. Ad-hoc-urile au scadența la 0–4 zile înainte de zbor.', 'M'],
        ['MGA (Mavi Gok Airlines) – ad-hoc S26', 'Mavi Gok Airlines', 'S26/MEM/01 din 31.07.2026', '2026-07-31', '2026-08-02', '2026-08-02', 'S26', 45227.70, null, 7, 'flight', 'with_rotation', null, null, 'în avans', 'taxe, ETS și de-icing refacturate', '50% ≥21 zile; 65% ≥14; 80% ≥7; 100% ≤6', 'O rotație AYT-OTP-AYT pe 02.08.2026, fără depozit. Observat: scadența la 2 zile înainte de zbor.', 'S'],
        ['Paralela 45 – CTR 229 CND-AYT (Corendon)', 'Paralela 45 Turism', '229/02.10.2025', '2025-10-02', '2026-05-27', '2026-09-30', 'S26', 132680.00, 13268.00, 14, 'flight', 'with_rotation', null, 0.2, 'factură per segment, în loturi lunare', '700 USD/t la 1,12, matrice, facturat luna următoare; în 2026 de plătit (+2.704 EUR pe iunie)', '100% din lanț; prestatorul poate anula cu 30 de zile', '19 rotații de block seats, 40 de locuri. Depozit 10% în 3 zile de la semnare, stornat la ultima rotație.', 'R'],
        ['Paralela 45 – CTR 230 CLJ-CHQ (Aegean)', 'Paralela 45 Turism', '230/09.10.2025', '2025-10-09', '2026-05-26', '2026-10-06', 'S26', 222752.50, 22275.00, 14, 'flight', 'with_rotation', null, 0.3, 'factură per segment, în loturi lunare', 'preț fix, fără clauză de combustibil', '100% din lanț; prestatorul poate anula cu 30 de zile', '20 rotații de block seats, 50 de locuri, 11.247,50 EUR per rotație cu taxe incluse.', 'R'],
        ['Nesma Airlines – W25/26', 'Nesma Airlines', '220/12.08.2025 și 234/05.11.2025', '2025-08-12', '2025-11-01', '2026-03-28', 'W25-26', 368000.00, 95000.00, 5, 'flight', 'with_rotation', null, null, 'factură per rotație plus factură separată de taxe, cu 1–3 săptămâni înainte', 'taxe per zbor, în general credit', '–', '7 rotații în registru, valori în USD. Depozitul este o rotație, compensat cu ultima.', 'M'],
    ];

    /**
     * The 23 chains of the Anima Wings hard block, as the annex of CTR 1585
     * lists them: rotations, seats per flight, net value and the day they
     * operate on (1 = Monday).
     *
     * @var list<array{0: string, 1: int, 2: int, 3: int, 4: float}>
     */
    private const HARD_BLOCK_CHAINS = [
        ['OTP RHO OTP', 2, 11, 50, 99896.50],
        ['TSR ZTH TSR', 4, 9, 10, 14365.80],
        ['TSR EFL TSR', 4, 9, 60, 93765.60],
        ['OTP EFL OTP', 4, 11, 30, 53552.40],
        ['TSR CFU TSR', 5, 10, 40, 61212.00],
        ['OTP HER OTP', 6, 10, 10, 18073.00],
        ['OTP RHO OTP', 6, 12, 20, 41040.00],
        ['TSR RHO TSR', 6, 12, 20, 44347.20],
        ['TSR CHQ TSR', 6, 11, 60, 119281.80],
        ['TSR PMI TSR', 7, 12, 10, 31206.00],
        ['OTP CFU OTP', 7, 12, 20, 39655.20],
        ['IAS PMI IAS', 7, 11, 20, 60660.60],
        ['OTP EFL OTP', 7, 11, 30, 53552.40],
        ['OTP ZTH OTP', 4, 11, 25, 45504.25],
        ['OTP CHQ OTP', 6, 11, 15, 32201.40],
        ['GHV HER GHV', 2, 12, 15, 36338.40],
        ['SBZ HER SBZ', 2, 12, 15, 36034.20],
        ['SCV HER SCV', 4, 11, 15, 33985.05],
        ['CLJ ZTH CLJ', 4, 11, 15, 29658.75],
        ['OTP AYT OTP', 3, 12, 30, 58168.80],
        ['OTP AYT OTP', 6, 11, 20, 35547.60],
        ['CLJ AYT CLJ', 6, 11, 20, 40337.00],
        ['TSR AYT TSR', 6, 11, 20, 42020.00],
    ];

    /** Airport taxes of the hard block: the contract value less the net. */
    private const HARD_BLOCK_TAXES = 21157.95;

    public function __construct(private CharterFlightImporter $importer) {}

    public function path(): string
    {
        return database_path('data/charter_flights.csv');
    }

    public function loaded(): bool
    {
        return CashFlowSetting::query()->where('key', self::SETTING)->exists();
    }

    /** The pack the contracts in place were loaded from, when they were. */
    public function loadedVersion(): ?string
    {
        $value = CashFlowSetting::query()->where('key', self::SETTING)->value('value');

        return is_array($value) ? ($value['version'] ?? null) : null;
    }

    /**
     * Create the contracts and their rotations. Nothing happens when this pack
     * was loaded before, or when contracts were entered by hand before any
     * pack, unless forced. When an earlier pack is in place the contracts are
     * updated in place and their rotations replaced.
     *
     * @return array{contracts: int, flights: int}|null null when nothing was loaded
     */
    public function load(bool $force = false): ?array
    {
        if (! $force) {
            $version = $this->loadedVersion();

            if ($version === self::VERSION || ($version === null && ($this->loaded() || CharterContract::query()->exists()))) {
                return null;
            }
        }

        $result = DB::transaction(function () {
            $contracts = [];

            foreach (self::CONTRACTS as $attributes) {
                $contracts[$attributes['season'].'|'.$attributes['name']] = $this->upsert($attributes);
            }

            foreach (self::AIRLINE_CONTRACTS as $row) {
                $this->upsert($this->airlineContract($row));
            }

            $memento = $contracts['S26|CTR 317 Memento Air – S26'];
            $flights = $this->importer->import($this->path(), 'charter_flights.csv', $memento, replace: true, useContractRules: true)['imported'];
            $flights += $this->loadHardBlock($contracts['S26|CTR 1585 Anima Wings – hard block S26']);

            foreach ($contracts as $contract) {
                $this->taxesToContractValue($contract);
            }

            return ['contracts' => CharterContract::query()->count(), 'flights' => $flights];
        });

        CashFlowSetting::query()->updateOrCreate(['key' => self::SETTING], ['value' => [
            'version' => self::VERSION,
            'loaded_at' => now()->toIso8601String(),
            ...$result,
        ]]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $attributes): CharterContract
    {
        $current = CharterContract::query()->where('season', $attributes['season'])->where('name', $attributes['name']);

        foreach (self::EARLIER_NAMES[$attributes['season'].'|'.$attributes['name']] ?? [] as $earlier) {
            if (! $current->clone()->exists()) {
                CharterContract::query()
                    ->where('season', $attributes['season'])
                    ->where('name', $earlier)
                    ->update(['name' => $attributes['name']]);
            }
        }

        return CharterContract::query()->updateOrCreate(
            ['season' => $attributes['season'], 'name' => $attributes['name']],
            $attributes,
        );
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>
     */
    private function airlineContract(array $row): array
    {
        [$name, $counterparty, $contractNo, $signed, $from, $to, $season, $value, $deposit,
            $days, $basis, $taxesRule, $taxesDays, $penalty, $invoicing, $fuel, $cancellation, $notes, $confidence] = $row;

        return [
            'name' => $name,
            'counterparty' => $counterparty,
            'buyer' => 'Memento Air S.R.L.',
            'direction' => CharterContract::DIRECTION_OUT,
            'in_cash_flow' => false,
            'contract_no' => $contractNo,
            'signed_date' => $signed,
            'period_from' => $from,
            'period_to' => $to,
            'season' => $season,
            'status' => CharterContract::STATUS_SIGNED,
            'operator' => $counterparty,
            'currency' => str_contains($name, 'Nesma') ? 'USD' : 'EUR',
            'days_before_flight' => $days,
            'payment_basis' => $basis,
            'taxes_rule' => $taxesRule,
            'taxes_days' => $taxesDays,
            'taxes_month_day' => 15,
            'deposit_amount' => $deposit,
            'deposit_due_date' => null,
            'deposit_paid' => true,
            'deposit_settlement' => 'compensat cu ultimele facturi ale sezonului',
            'contract_value' => $value,
            'invoicing' => $invoicing,
            'fuel_rule' => $fuel,
            'fx_markup_pct' => 0,
            'late_penalty_pct_per_day' => $penalty,
            'cancellation_terms' => $cancellation,
            'source' => 'anexa B – contracte Memento Air ↔ companii aeriene, 17.09.2026',
            'confidence' => $confidence,
            'notes' => 'Cumpărătorul este Memento Air, nu CHR, deci contractul nu intră în fluxul CHR. Se decontează către CHR prin refacturarea din CTR 317. '.$notes,
        ];
    }

    /**
     * The contract value with taxes is what the contract settles, so when
     * the rotations of the annex add up to other airport taxes, each rotation's
     * taxes are brought to it in proportion; the rounding rides on the last.
     */
    private function taxesToContractValue(CharterContract $contract): void
    {
        if ($contract->contract_value_with_taxes === null) {
            return;
        }

        $flights = $contract->flights()->orderBy('flight_date')->orderBy('id')->get(['id', 'net_value', 'taxes']);
        $taxes = round((float) $flights->sum('taxes'), 2);
        $target = round((float) $contract->contract_value_with_taxes - (float) $flights->sum('net_value'), 2);

        if ($flights->isEmpty() || $taxes <= 0 || $target <= 0 || abs($target - $taxes) < 0.01) {
            return;
        }

        $factor = $target / $taxes;
        $assigned = 0.0;

        foreach ($flights as $index => $flight) {
            $value = $index === $flights->count() - 1
                ? round($target - $assigned, 2)
                : round((float) $flight->taxes * $factor, 2);
            $assigned += $value;
            CharterFlight::query()->whereKey($flight->id)->update(['taxes' => $value]);
        }
    }

    /**
     * Expand the hard block chains over the season: one flight per operating
     * weekday, scaled so the rotations and the value of each chain match the
     * annex exactly.
     */
    private function loadHardBlock(CharterContract $contract): int
    {
        $contract->flights()->delete();

        $from = CarbonImmutable::parse((string) $contract->period_from);
        $to = CarbonImmutable::parse((string) $contract->period_to);
        $net = array_sum(array_column(self::HARD_BLOCK_CHAINS, 4));
        $rows = [];

        foreach (self::HARD_BLOCK_CHAINS as [$route, $weekday, $rotations, $seats, $chainNet]) {
            $dates = [];

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                if ($day->dayOfWeekIso === $weekday) {
                    $dates[] = $day;
                }
            }

            if ($dates === []) {
                $dates = [$from];
            }

            $scale = $rotations / count($dates);
            $value = round($chainNet / count($dates), 2);
            $chainTaxes = round(self::HARD_BLOCK_TAXES * $chainNet / $net, 2);
            $taxes = round($chainTaxes / count($dates), 2);

            // The rounding remainder of the chain rides on its last flight, so
            // the contract still totals exactly what the annex says.
            $lastValue = round($chainNet - $value * (count($dates) - 1), 2);
            $lastTaxes = round($chainTaxes - $taxes * (count($dates) - 1), 2);

            foreach ($dates as $index => $date) {
                $last = $index === count($dates) - 1;
                $rows[] = [
                    'charter_contract_id' => $contract->id,
                    'operator' => $contract->operator,
                    'route' => $route,
                    'flight_no' => null,
                    'flight_date' => $date->toDateString(),
                    'seats' => (int) round($seats * $scale),
                    'price_per_seat' => $seats > 0 ? round($chainNet / $rotations / $seats, 4) : null,
                    'net_value' => $last ? $lastValue : $value,
                    'taxes' => $last ? $lastTaxes : $taxes,
                    'pay_date' => null,
                    'taxes_pay_date' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        CharterFlight::query()->insert($rows);

        return count($rows);
    }
}
