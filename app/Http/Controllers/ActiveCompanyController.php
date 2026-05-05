<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActiveCompanyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $companyId = $request->integer('company_id') ?: null;

        if ($companyId !== null && ! Company::whereKey($companyId)->exists()) {
            $companyId = null;
        }

        session(['active_company_id' => $companyId]);

        return back();
    }
}
