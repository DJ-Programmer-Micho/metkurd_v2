<?php

namespace App\Http\Controllers\App\Auth;

use App\Models\Customer;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\CustomerProfile;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;


class AppAuthController extends Controller
{
    public function signIn()
    {
        // If already logged in, redirect to dashboard
        if (Auth::guard('app')->check()) {
            return redirect()->route('app.home');
        }
        return view('app.auth.signin-one');
    }

    public function handleSignIn(Request $request)
    {
        $credentials = $request->validate([
            'login'    => ['required', 'string'], // can be email or username
            'password' => ['required', 'string'],
        ]);

        // Determine login field type
        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'username';

        $remember = $request->boolean('remember');

        if (Auth::guard('app')->attempt(
            [$loginField => $credentials['login'], 'password' => $credentials['password']],
            $remember
        )) {
            $request->session()->regenerate();

            return response()->json([
                'status'  => 'success',
                'message' => 'Welcome back!',
                'redirect' => route('app.home'),
            ]);
        }

        throw ValidationException::withMessages([
            'login' => __('Invalid credentials or account not found.'),
        ]);
    }
    public function signUp(){
        return view('app.auth.signup-one');
    }

    public function logout(Request $request)
    {
        Auth::guard('app')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.signin');
    }
    public function handleSignup(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required','string','max:100'],
            'last_name'  => ['required','string','max:100'],
            'username'   => ['required','string','min:3','max:50', Rule::unique('customers','username')],
            'job_title'  => ['nullable','string','max:100'],
            'phone'      => ['required','string','max:20','regex:/^\+\d{10,15}$/',Rule::unique('customer_profiles','phone_number')],
            'email'      => ['required','email','max:255', Rule::unique('customers','email')],
            'password' => [
                        'required',
                        'confirmed',
                        'min:8',
                        'regex:/[a-z]/',          // lowercase
                        'regex:/[A-Z]/',          // uppercase
                        'regex:/\d/',             // number
                        'regex:/[^A-Za-z0-9]/',   // special char
                        ],
        ],[
            'password.regex' => 'Password must include lowercase, uppercase, number, and special character.',

        ]);

        $otp = random_int(100000, 999999); // email OTP for next step

        $customer = DB::transaction(function () use ($data, $otp) {
            $customer = Customer::create([
                'username'      => $data['username'],
                'email'         => $data['email'],
                'password'      => Hash::make($data['password']),
                'email_verify'  => false,
                'phone_verify'  => false,
                'email_otp_number' => (string)$otp,
                'uid'           => Str::ulid(),  // internal UID if you like
            ]);

            CustomerProfile::create([
                'customer_id' => $customer->id,
                'first_name'  => $data['first_name'],
                'last_name'   => $data['last_name'],
                'job_title'   => $data['job_title'] ?? null,
                'phone_number' => $data['phone'],
                // country/city/address/zip_code/avatar left null for later
            ]);

            return $customer;
        });

        // Auto-login after registration (under the 'app' guard)
        Auth::guard('app')->login($customer);

        // TODO: send OTP via mail here (we’ll wire mailable/notification next step)
        // $customer->notify(new EmailOtpNotification($otp));

        return redirect()->route('app.email.otp')
            ->with('status', 'We sent you a 6-digit code to verify your email.');
    }
    public function emailOtp(){
        return view('app.auth.email-otp');
    }
    public function phoneOtp(){
        return view('app.auth.phone-otp');
    }
    public function lock(){
        return view('app.auth.lock-one');
    }
    public function accountSus(){
        return view('app.auth.suspend-one');
    }

    public function showForgotForm()
    {
        if (Auth::guard('app')->check()) {
            Auth::guard('app')->logout();
        }
        return view('app.auth.forgot-password-one');
    }

public function sendResetLink(Request $request)
{
    $request->validate([
        'email' => ['required','email'],
        'g-recaptcha-response' => ['required', new \App\Rules\Recaptcha],
    ]);

    $status = Password::broker('customers')->sendResetLink($request->only('email'));

    return back()->with('status', 'If your email exists in our system, a reset link has been sent.');
}

public function showResetForm(string $token)
{
    return view('app.auth.reset-password-one', [
        'token' => $token,
        'email' => request('email'), // from query string
    ]);
}

public function handleReset(Request $request)
{
    $request->validate([
        'token'    => ['required'],
        'email'    => ['required','email'],
        'password' => [
            'required','confirmed','min:8',
            'regex:/[a-z]/','regex:/[A-Z]/','regex:/\d/','regex:/[^A-Za-z0-9]/',
        ],
    ],[
        'password.regex' => 'Password must include lowercase, uppercase, number, and special character.',
    ]);

    $status = Password::broker('customers')->reset(
        $request->only('email','password','password_confirmation','token'),
        function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password)])
                 ->setRememberToken(Str::random(60))
                 ->save();

            event(new PasswordReset($user));
        }
    );

    if ($status === Password::PASSWORD_RESET) {
        return redirect()->route('app.signin')->with('status', __($status));
    }

    return back()->withErrors(['email' => __($status)]);
}
}
