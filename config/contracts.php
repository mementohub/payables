<?php

/*
|--------------------------------------------------------------------------
| Repertoriul de contracte
|--------------------------------------------------------------------------
|
| Fișierele stau pe discul privat al aplicației, nu în public: se dau numai
| prin aplicație, cu drept verificat la fiecare descărcare.
|
| Citirea documentelor se face pe server. PDF-ul nativ se citește direct cu
| `pdftotext`, fără pierdere; cel scanat se face imagine cu `pdftoppm` și trece
| prin `tesseract` cu limba română. Nimic nu pleacă în afară.
|
*/

return [

    'disk' => env('CONTRACTS_DISK', 'contracts'),
    /** Sub rădăcina discului; gol, fiindcă discul e deja numai al lor. */
    'path' => env('CONTRACTS_PATH', ''),

    /** Cât se primește la încărcare (MB). */
    'max_upload_mb' => (int) env('CONTRACTS_MAX_UPLOAD_MB', 50),

    'ocr' => [
        'enabled' => (bool) env('CONTRACTS_OCR_ENABLED', true),
        'pdftotext' => env('CONTRACTS_PDFTOTEXT', 'pdftotext'),
        'pdftoppm' => env('CONTRACTS_PDFTOPPM', 'pdftoppm'),
        'tesseract' => env('CONTRACTS_TESSERACT', 'tesseract'),
        /*
         * Unde se caută uneltele, în ordine, dacă nu sunt în PATH. Serverul
         * nu le are puse de sistem și nimeni de aici n-are drept de root, așa
         * că stau în directorul utilizatorului, într-un mediu al lor. Un
         * `apt-get install poppler-utils tesseract-ocr` pus cândva de un
         * administrator le aduce în PATH și acestea nu mai sunt folosite.
         */
        'paths' => array_values(array_filter([
            env('CONTRACTS_OCR_BIN'),
            env('HOME', '/home/ploi').'/.local/ocr/bin',
        ])),
        /** Unde stau fișierele de limbă ale tesseract-ului. */
        'tessdata' => env('CONTRACTS_TESSDATA', env('HOME', '/home/ploi').'/.local/ocr/share/tessdata'),
        'languages' => env('CONTRACTS_OCR_LANGUAGES', 'ron+eng'),
        /** Sub atâtea caractere, PDF-ul e socotit scanat și se dă pe OCR. */
        'text_threshold' => (int) env('CONTRACTS_OCR_TEXT_THRESHOLD', 400),
        /** Câte pagini se citesc din scanări: restul se păstrează, dar nu se mai citesc. */
        'max_pages' => (int) env('CONTRACTS_OCR_MAX_PAGES', 25),
        'dpi' => (int) env('CONTRACTS_OCR_DPI', 200),
        'timeout' => (int) env('CONTRACTS_OCR_TIMEOUT', 600),
    ],

    'alerts' => [
        'enabled' => (bool) env('CONTRACTS_ALERTS_ENABLED', true),
        /** Cu câte zile înainte de expirare se dă de veste. */
        'days' => [90, 60, 30, 15, 7, 1],
        /** Mailul săptămânal cu tot ce vine la rând. */
        'digest_day' => env('CONTRACTS_DIGEST_DAY', 'monday'),
        'digest_hour' => env('CONTRACTS_DIGEST_HOUR', '08:30'),
        'horizon_days' => (int) env('CONTRACTS_ALERT_HORIZON_DAYS', 90),
    ],

];
