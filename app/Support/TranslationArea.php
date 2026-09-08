<?php

namespace App\Support;

use Illuminate\Http\Request;

class TranslationArea
{
    public static function forRequest(Request $request): string
    {
        $prefix = preg_quote(trim((string) app('aurl'), '/'), '#');
        if ($request->routeIs('admin.*') || preg_match('#^/(?:en/|ar/|ku/)?'.$prefix.'(?:/|$)#', $request->getPathInfo())) {
            return 'admin';
        }

        return preg_match('#^/(en|ar|ku)/app(?:-v2)?(?:/|$)#', $request->getPathInfo()) ? 'app' : 'landing';
    }
}
