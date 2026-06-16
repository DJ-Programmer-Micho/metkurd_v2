<?php

namespace App\Http\Controllers\Admin\Pages;

use App\Http\Controllers\Controller;

class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.pages.dashboard.index');
    }

    // public function user(){
    //     return view('admin.pages.profile.index');
    // }
    public function profile()
    {
        return view('admin.pages.profile.index');
    }
}
