<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Segmentarea rezervărilor eTrip
    |--------------------------------------------------------------------------
    |
    | Canalul se citește de pe rezervare (cine a vândut), categoria de produs
    | din compoziția ei (ce s-a vândut). Valorile de mai jos sunt cele din
    | aplicația de supracomision; se potrivesc normalizat (fără diacritice,
    | fără spații), ca „rezervari site- suport sediu” să prindă
    | „Rezervari Site - Suport Sediu”.
    |
    */

    'etrip_connection' => env('PNL_ETRIP_CONNECTION', 'etrip_chr'),

    'timezone' => env('PNL_TIMEZONE', 'Europe/Bucharest'),

    /**
     * Interogarea de venit atinge tot registrul de rezervări al anului, așa că
     * are nevoie de mai mult decât cele 30 de secunde ale citirilor obișnuite
     * din eTrip. Raportul e oricum ținut în cache o oră.
     */
    'statement_timeout_ms' => env('PNL_STATEMENT_TIMEOUT_MS', 180000),

    'segmentation' => [
        /** Conturi tehnice de site: rezervarea lor e „de site”, oricare ar fi sucursala. */
        'site_users' => ['rezervare.b2c'],

        /** Etichete care marchează o rezervare ca fiind de site. */
        'site_labels' => [
            'Rezervare refacuta din site',
            'rezervari site- suport sediu',
            'comanda nefinalizata site - concretizata agent',
        ],

        /**
         * Sucursale prin care vinde B2B-ul, chiar dacă clientul rezervării nu
         * e o agenție din `clients.trade`: rezervările web și cele venite prin
         * XML sunt ale partenerilor, nu vânzare proprie. Fără ele ar cădea pe
         * retail, care e ramura implicită, și ar umfla magazinele cu sute de
         * milioane care nu sunt ale lor.
         */
        'b2b_branches' => ['Rezervari Web', 'Rezervari XML'],

        /** Sucursale de site: o rezervare de aici care NU e de site e retail de sediu. */
        'site_branches' => ['Rezervari Site'],

        /** Sucursale de call center. */
        'cc_branches' => ['Online B2C'],

        /**
         * Sucursalele francizate. Rezervarea lor e făcută de agenția
         * francizată, nu de un magazin al nostru, chiar dacă vinde sub marca
         * noastră. Fără regula asta cădeau pe retail — canalul implicit — și
         * umflau magazinele proprii cu vânzarea francizelor; partea de
         * cheltuială a francizei o duce francizatul, vezi
         * `channels_without_costs`.
         */
        'franchise_branches' => ['Franciza'],

        /**
         * Sucursale care nu sunt magazine: cursul de ghid, corporate,
         * ticketingul și abuela.ro sunt activități de sine stătătoare, care
         * vând prin eTrip fără să aibă un magazin al lor. Puse la retail, apar
         * ca „sucursale proprii” care nu există, așa că stau pe canalul
         * „Altele”.
         */
        'other_branches' => ['Curs ghid', 'Corporate', 'Ticketing', 'abuela.ro'],

        /** Sucursale de backoffice, excluse din toată analiza. */
        'operational_branches' => [
            '1. Contabilitate & Kickbacks',
            '2. Rezervari Central',
            '3. Blocare locuri & Plati',
            '4. Transport MB',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Canale
    |--------------------------------------------------------------------------
    |
    | Cheile sunt scrise în raport și în alocarea costurilor; etichetele se pot
    | schimba, cheile nu.
    |
    */

    'channels' => [
        'b2b' => 'B2B (agenții partenere)',
        'retail' => 'Retail (sucursale proprii)',
        'site' => 'Site',
        'cc' => 'Call Center (Online B2C)',
        'franciza' => 'Franciză',
        'corporate' => 'Corporate (Tina)',
        'other' => 'Altele',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tina — ERP-ul business-ului corporate
    |--------------------------------------------------------------------------
    |
    | Tina ține biletele de avion, cazările și evenimentele vândute firmelor,
    | separat de eTrip. Categoria de produs vine din departamentul care
    | răspunde de comandă, așa cum îl știe Tina; departamentele de vânzare
    | (B2B, B2C) spun doar canalul, iar produsul se ia atunci din serviciu.
    |
    */
    'tina' => [
        'connection' => env('PNL_TINA_CONNECTION', 'tina'),

        'departments' => [
            'Corporate' => ['product' => 'Corporate', 'channel' => 'corporate'],
            'Ticketing' => ['product' => 'Ticketing', 'channel' => 'corporate'],
            'Hotels' => ['product' => 'Cazare', 'channel' => 'corporate'],
            'Hotels &amp; flights (IRIX)' => ['product' => 'Cazare', 'channel' => 'corporate'],
            'Turism intern' => ['product' => 'Turism intern', 'channel' => 'corporate'],
            'Exotic' => ['product' => 'Sejururi Exotice', 'channel' => 'corporate'],
            // Vânzarea spune canalul; ce s-a vândut spune serviciul.
            'B2B Sales' => ['product' => null, 'channel' => 'b2b'],
            'B2C Sales' => ['product' => null, 'channel' => 'retail'],
        ],

        'service_products' => [
            'airTransport' => 'Ticketing',
            'accommodation' => 'Cazare',
            'transfer' => 'Transfer',
            'default' => 'Corporate',
        ],

        'default_channel' => 'corporate',
        'default_product' => 'Corporate',
    ],

    /*
    |--------------------------------------------------------------------------
    | IFRS 16 — contractele de închiriere
    |--------------------------------------------------------------------------
    |
    | OMC ține contabilitatea statutară: chiria stă pe 612, ca o cheltuială de
    | exploatare. IFRS 16 spune altceva — dreptul de folosință e un activ, iar
    | chiria se rupe în amortizare și dobândă, deci iese din EBITDA.
    |
    | Contractele (durata rămasă, rata de actualizare, scadențarul) nu sunt în
    | OMC, așa că vederea IFRS 16 e o ESTIMARE pe parametrii de mai jos, nu o
    | retratare. Se calculează așa:
    |
    |   valoarea datoriei = chiria anuală × factorul de anuitate (n ani, rata r)
    |   amortizare anuală = valoarea datoriei ÷ n
    |   dobândă anuală    = valoarea datoriei × r
    |
    | `lines` sunt liniile de cheltuială tratate ca leasing. Contractele scurte
    | (sub un an) și cele de valoare mică — imprimante, purificatoare — rămân
    | pe cheltuieli, cum permite standardul.
    |
    */

    'ifrs16' => [
        'lines' => [
            '4000',  // Chirie principala (Chirie sedii)
            '4020',  // Spatii comune (Chirie sedii)
            '12000', // Chirii Auto (Inchirieri)
        ],
        'term_years' => (float) env('PNL_IFRS16_TERM_YEARS', 5),
        'discount_rate' => (float) env('PNL_IFRS16_DISCOUNT_RATE', 0.08),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canalele care se desfac pe sucursale
    |--------------------------------------------------------------------------
    |
    | Sucursala de pe rezervare spune unde stă omul care a făcut-o, nu al cui e
    | canalul. La retail și la francize asta înseamnă chiar magazinul care a
    | vândut, deci se poate desface. La Site, Call Center și B2B nu: agentul
    | care lucrează comanda de pe site e logat pe sucursala lui, iar o
    | desfacere ar arăta „Site · Botoșani”, care nu înseamnă nimic.
    |
    */

    'branch_channels' => ['retail', 'franciza', 'other'],

    /*
    |--------------------------------------------------------------------------
    | Canale care nu duc cheltuieli
    |--------------------------------------------------------------------------
    |
    | Francizele vând sub marca noastră, deci venitul și marja sunt ale
    | noastre, dar cheltuielile de funcționare sunt ale francizatului: chiria
    | lui, oamenii lui. Dacă le-am împărți și pe ele pe cheia de venit,
    | francizele ar apărea cu costuri pe care nu le plătim, iar celelalte
    | canale ar părea mai ieftine decât sunt.
    |
    | Ce ar fi căzut aici se împarte pe canalele rămase, ca totalul companiei
    | să rămână întreg. O mutare făcută de om pe un astfel de canal se
    | respectă — aceea e o decizie, nu o cheie.
    |
    */

    'channels_without_costs' => ['franciza'],

    /*
    |--------------------------------------------------------------------------
    | Comisionul de curs
    |--------------------------------------------------------------------------
    |
    | Prețurile sunt în euro, dar clientul plătește în lei, la cursul BNR plus
    | un adaos. Adaosul ăla intră în contabilitate ca diferență de curs
    | favorabilă (765), adică sub EBITDA, deși e un comision încasat de la
    | client, la fel de „venit” ca orice altă vânzare — și se repetă lună de
    | lună, spre deosebire de reevaluări.
    |
    | Îl recunoaștem după documentul pe care stă: încasările de la client și
    | facturile către el. Reevaluarea de sfârșit de lună (notă contabilă) și
    | câștigul din plata unui furnizor rămân rezultat financiar, fiindcă chiar
    | asta sunt.
    |
    | Se ia net: diferențele nefavorabile (665) de pe aceleași documente se
    | scad, ca să nu arătăm doar partea bună a aceleiași mecanici.
    |
    */

    'fx_commission' => [
        'documents' => ['OP_INC', 'Ch_INC', 'VOU_INC', 'Reg_INC', 'FactCI'],
        'label' => 'Alte venituri — comision de curs (BNR + adaos)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Costul născut dintr-o plecare
    |--------------------------------------------------------------------------
    |
    | Regula finanțelor: în decontul de turism intră costul care se naște dintr-o
    | plecare — combustibilul ars, diurna omului care a mers, taxa plătită la
    | trecere. În cheltuiala de exploatare rămâne ce plătim fiindcă ținem flota
    | funcțională: amortizare, salarii de șofer, asigurări, ITP, revizii, piese.
    | Testul lor: dacă autocarul nu pleacă, mai plătești costul?
    |
    | În contabilitate, cheltuielile astea se recunosc pe centre de cost care
    | încep cu „TR”. Raportul le scoate din cheltuielile de exploatare și le
    | arată ca un cost al cursei, care taie marja — la fel ca la ei. EBITDA nu
    | se schimbă (marja scade cu exact cât scade OPEX-ul), dar marja brută și
    | costurile de structură devin comparabile cu rapoartele lor.
    |
    | Documentele amestecate — o factură cu poziții și pe cursă, și pe sediu —
    | se împart proporțional, după valoarea pozițiilor.
    |
    */

    'trip_costs' => [
        'enabled' => env('PNL_TRIP_COSTS', true),
        'object_prefix' => 'TR',
        'label' => 'Costuri de cursă (combustibil, diurne, taxe de drum)',
    ],

    /** Canalele B2C, în ordinea de afișare. */
    'b2c_channels' => ['retail', 'site', 'cc', 'franciza'],
];
