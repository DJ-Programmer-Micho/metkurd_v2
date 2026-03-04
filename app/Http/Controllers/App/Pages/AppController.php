<?php

namespace App\Http\Controllers\App\Pages;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;


class AppController extends Controller
{
    public function dashboard(){
        return view('app.pages.dashboard.index');
    }
    public function profile(){
        return view('app.pages.profile.index');
    }
    public function updatePassword(Request $request)
    {
        $request->validate([
            'old_password' => ['required','string'],
            'new_password' => [
                'required','confirmed','min:8',
                'regex:/[a-z]/',        // lowercase
                'regex:/[A-Z]/',        // uppercase
                'regex:/\d/',           // number
                'regex:/[^A-Za-z0-9]/', // special char
            ],
        ],[
            'new_password.regex' => 'Password must include lowercase, uppercase, number, and special character.',
        ]);

        $user = auth()->guard('app')->user();

        if (! Hash::check($request->old_password, $user->password)) {
            throw ValidationException::withMessages([
                'old_password' => 'Your current password is incorrect.',
            ]);
        }
        
        $user->forceFill([
            'password' => Hash::make($request->new_password),
            // optional: track when password changed
            'password_changed_at' => now(),
        ])->setRememberToken(Str::random(60));
        $user->save();

        // Log out other devices for this guard (requires hashing driver in session config)
        auth()->guard('app')->logoutOtherDevices($request->new_password);

        event(new PasswordReset($user));

        return back()->with('status', 'Password updated successfully.');
    }

    public function tts(){
        return view('app.pages.tts.index');
    }

    public function ttsRunpodTest(){
        return view('app.pages.tts-runpod.index');
    }
    
    public function cloneTts() {
        return view('app.pages.clone-tts.index');
    }

    public function asr() {
        return view('app.pages.asr.index');
    }
    
    public function ocr() {
        return view('app.pages.ocr.index');
    }

    public function stem() {
        return view('app.pages.stem.index');
    }

    public function audioFormat() {
        return view('app.pages.audio-format.index');
    }
}
