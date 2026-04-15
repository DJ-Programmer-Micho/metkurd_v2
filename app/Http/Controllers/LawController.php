<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;

class LawController extends Controller
{
    public function termsCondition()
    {
        $filePath = public_path('landing/law/METKURDTermcondition.html'); // Path to the exported HTML file
        $htmlContent = file_get_contents($filePath); // Read HTML content

        return view('law.terms-conditions-one', [
            'terms' => $htmlContent
        ]);
    }

    public function privacyPolicy()
    {
        $filePath = public_path('landing/law/METKURDPrivacy.html'); // Path to the exported HTML file
        $htmlContent = file_get_contents($filePath); // Read HTML content

        return view('law.privacy-policy-one', [
            'terms' => $htmlContent
        ]);
    }
}
