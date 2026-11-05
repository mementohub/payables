<?php

/*
|--------------------------------------------------------------------------
| Decizii și proceduri interne
|--------------------------------------------------------------------------
|
| Hârtiile casei: deciziile conducerii și procedurile de lucru. Stau pe discul
| privat al aplicației, se citesc cu aceleași unelte ca și contractele
| (`pdftotext`, `tesseract`), iar fișierele se dau numai prin aplicație.
|
| Amândouă pleacă pe adresele de grup ale companiei. Procedura are pe deasupra
| luarea la cunoștință: cine o citește apasă „am citit și am luat la cunoștință”. Cum
| nu toată lumea are cont aici, omul își scrie numele și adresa de serviciu, iar
| pe adresa aceea pleacă îndată și confirmarea — care e și dovadă, și alarmă
| dacă altcineva i-a folosit adresa.
|
*/

return [

    'disk' => env('DOCUMENTS_DISK', 'documents'),
    /** Sub rădăcina discului; gol, fiindcă discul e deja numai al lor. */
    'path' => env('DOCUMENTS_PATH', ''),

    /** Cât se primește la încărcare (MB). */
    'max_upload_mb' => (int) env('DOCUMENTS_MAX_UPLOAD_MB', 50),

    'consent' => [
        /** Adresele de grup pe care pleacă documentul, dacă nu se scriu altele. */
        'group' => env('DOCUMENTS_CONSENT_GROUP', ''),
        /** În câte zile se așteaptă răspunsul, dacă nu se pune altă dată. */
        'days' => (int) env('DOCUMENTS_CONSENT_DAYS', 14),
        /** Domeniile de la care se primesc adresele celor care confirmă. */
        'domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DOCUMENTS_CONSENT_DOMAINS', 'christiantour.ro,mementogroup.com,andali.ro')),
        ))),
    ],

    /*
     * Întrebările puse unui document anume („Întreabă documentul”).
     *
     * Stins, rămâne căutarea prin textul citit, fără ca nimic să iasă de pe
     * server. Aprins, peste rândurile găsite vine un răspuns scris de agentul
     * `DocumentAnalyst`, iar textul documentului pleacă la furnizorul de model.
     */
    'ai' => [
        'enabled' => (bool) env('DOCUMENTS_AI_ENABLED', true),
    ],

    'mail' => [
        /** Numele cu care pleacă mailul: cine semnează hârtiile casei. */
        'from_name' => env('DOCUMENTS_MAIL_FROM_NAME', 'Administrator Christian Tour'),
    ],

];
