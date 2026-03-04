<?php

namespace App\Http\Controllers\Admin\Auth;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    // public function signIn(){
    //     return view('admin.auth.signin-one');
    // }

    // public function handleSignIn(Request $request){
    //     // Validate input
    //     $request->validate([
    //         'email' => 'required|email',
    //         'password' => 'required|string|min:6',
    //     ]);

    //     // Attempt login
    //     // Authentication successful
    //     if (Auth::guard('admin')->attempt($request->only('email', 'password'))) {
    //         return redirect()->route('admin.home', ['locale' => app()->getLocale()]);
    //     }
    //     // If authentication fails
    //     return back()->withErrors([
    //         'email' => 'Invalid email or password.',
    //     ])->withInput();
    // }
    

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.signin');
    }
}
