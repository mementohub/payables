<?php

return [
    'mismatch_tolerance' => [
        'total' => env('EINVOICE_MISMATCH_TOTAL', 1),
        'vat' => env('EINVOICE_MISMATCH_VAT', 1),
    ],
];
