<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Violator;
use App\Models\Admin;
use App\Models\Deputy;
use App\Models\Head;
use App\Models\Enforcer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Mail\POSUEmail;

class AuthController extends Controller
{
    protected function getUserModelByIdentifier($identifier)
    {
        $models = [
            \App\Models\Admin::class,
            \App\Models\Deputy::class,
            \App\Models\Head::class,
        ];

        // First try to find by email if it's a valid email
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            foreach ($models as $model) {
                $user = $model::where('email', $identifier)->first();
                if ($user) return $user;
            }
        }

        // Then try to find by username
        foreach ($models as $model) {
            $user = $model::where('username', $identifier)->first();
            if ($user) return $user;
        }

        return null;
    }


    // Removed old mixed login() method. Use loginOfficials() and loginViolator().

    /**
     * Login for Admin/Deputy/Head only
     */
    public function loginOfficials(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = $request->identifier;
        $password = $request->password;

        $user = $this->getUserModelByIdentifier($identifier);

        if ($user && Hash::check($password, $user->password)) {
            if (!$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is deactivated'
                ], 401);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'user_type' => class_basename($user)
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Wrong Email/Number or Password'
        ], 401);
    }

    /**
     * Login for Violator only
     */
    public function loginViolator(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = $request->identifier;
        $password = $request->password;

        $violator = Violator::where('email', $identifier)
            ->orWhere('mobile_number', $identifier)
            ->first();

        if ($violator && Hash::check($password, $violator->password)) {
            // Check if email verification is required and not completed
            if ($violator->email && !$violator->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify your email address before logging in. Check your email for verification instructions.',
                    'email_verification_required' => true,
                    'email' => $violator->email
                ], 403);
            }

            $token = $violator->createToken('violator-token', ['*'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'violator' => $violator,
                    'token' => $token,
                    'user_type' => 'Violator'
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Wrong Email/Number or Password'
        ], 401);
    }

    /**
     * Violator registration - 
     */
    public function violatorRegister(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string', 
            'password' => 'required|string|min:6|confirmed',
        ]);

        $identifier = $request->identifier;
        $violator = null;
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $violator = Violator::where('email', $identifier)->first();
        } elseif (preg_match('/^09\\d{9}$/', $identifier)) {
            $violator = Violator::where('mobile_number', $identifier)->first();
        }

        if (!$violator) {
            return response()->json([
                'success' => false,
                'message' => 'No matching violator found in records.'
            ], 404);
        }

        if ($violator->password) {
            return response()->json([
                'success' => false,
                'message' => 'An account already exists for this violator.'
            ], 409);
        }

        try {
            $violator->password = Hash::make($request->password);
            $violator->save();

            // Send email verification if email is provided
            if ($violator->email) {
                try {
                    $fullName = trim($violator->first_name . ' ' . ($violator->middle_name ? $violator->middle_name . ' ' : '') . $violator->last_name);
                    $accountType = $violator->professional ? 'Professional Driver' : 'Non-Professional Driver';
                    
                    $verificationToken = Str::random(60);
                    
                    DB::table('password_reset_tokens')->updateOrInsert(
                        ['email' => $violator->email . '|verification'],
                        [
                            'token' => Hash::make($verificationToken),
                            'created_at' => now()
                        ]
                    );
                    
                    $backendBaseUrl = config('app.url');
                    $verificationUrl = rtrim($backendBaseUrl, '/') . '/api/verify-email?token=' . $verificationToken . '&email=' . urlencode($violator->email);
                    
                    Mail::to($violator->email)->send(
                        new POSUEmail('account_verification', [
                            'user_name' => $violator->first_name,
                            'full_name' => $fullName,
                            'email' => $violator->email,
                            'account_type' => $accountType,
                            'verification_url' => $verificationUrl,
                            'verification_token' => $verificationToken,
                        ])
                    );
                } catch (\Exception $emailError) {
                    Log::error('Failed to send verification email: ' . $emailError->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => $violator->email ? 'Email verification has been sent to your email' : 'Account created successfully',
                'data' => [
                    'violator' => $violator,
                    'user_type' => 'violator',
                    'email_verification_required' => !empty($violator->email)
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify violator email address
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $email = $request->email;
            $token = $request->token;

            // Find violator
            $violator = Violator::where('email', $email)->first();
            
            if (!$violator) {
                // If not a violator, check if email belongs to an official to redirect them properly
                $isOfficial = Admin::where('email', $email)->exists()
                    || Deputy::where('email', $email)->exists()
                    || Head::where('email', $email)->exists()
                    || Enforcer::where('email', $email)->exists();

                if (!$request->expectsJson()) {
                    if ($isOfficial) {
                        $officialsLogin = env('FRONTEND_OFFICIALS_URL', 'http://localhost:8080/officials-login');
                        return redirect(rtrim($officialsLogin, '/') . '?error=true&message=' . urlencode('Email verification is not required for officials. Please sign in.'));
                    }
                    $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                    return redirect(rtrim($loginUrl, '/') . '?error=true&message=' . urlencode('Violator not found.'));
                }

                return response()->json([
                    'success' => false,
                    'message' => $isOfficial ? 'Email verification is not required for officials.' : 'Violator not found.'
                ], 404);
            }

            // Check if already verified
            if ($violator->hasVerifiedEmail()) {
                if (!$request->expectsJson()) {
                    $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                    return redirect(rtrim($loginUrl, '/') . '?verified=true&message=' . urlencode('Email is already verified. You can login now.'));
                }
                return response()->json([
                    'success' => true,
                    'message' => 'Email is already verified.'
                ]);
            }

            // Check verification token
            $verificationRecord = DB::table('password_reset_tokens')
                ->where('email', $email . '|verification')
                ->first();

            if (!$verificationRecord || !Hash::check($token, $verificationRecord->token)) {
                if (!$request->expectsJson()) {
                    $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                    return redirect(rtrim($loginUrl, '/') . '?error=true&message=' . urlencode('Invalid or expired verification token.'));
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired verification token.'
                ], 400);
            }

            // Check if token is not expired (24 hours)
            if (now()->diffInHours($verificationRecord->created_at) > 24) {
                if (!$request->expectsJson()) {
                    $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                    return redirect(rtrim($loginUrl, '/') . '?error=true&message=' . urlencode('Verification token has expired. Please request a new one.'));
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Verification token has expired. Please request a new one.'
                ], 400);
            }

            // Verify email
            $violator->email_verified_at = now();
            $violator->save();

            // Delete the verification token
            DB::table('password_reset_tokens')
                ->where('email', $email . '|verification')
                ->delete();

            // If this is a direct email click (no Accept: application/json header), redirect to violator login
            if (!$request->expectsJson()) {
                $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                return redirect(rtrim($loginUrl, '/') . '?verified=true&message=' . urlencode('Email verified successfully. You can now login to your account.'));
            }

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully. You can now login to your account.'
            ]);

        } catch (\Exception $e) {
            if (!$request->expectsJson()) {
                $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                return redirect(rtrim($loginUrl, '/') . '?error=true&message=' . urlencode('Email verification failed. Please try again.'));
            }
            return response()->json([
                'success' => false,
                'message' => 'Email verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send password reset email for violators
     */
    public function forgotPasswordViolator(Request $request)
    {
        // Normalize identifier: trim, lowercase, flatten arrays
        $raw = $request->input('identifier');
        if (is_array($raw)) {
            $raw = Arr::first($raw);
        }
        if (is_string($raw)) {
            $raw = trim(strtolower($raw));
        }
        $request->merge(['identifier' => $raw]);

        $validator = Validator::make($request->all(), [
            'identifier' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier; // email only
        $user = Violator::where('email', $identifier)->first();
        $userType = 'Violator';

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with this email.'
            ], 404);
        }

        if (!$user->email) {
            return response()->json([
                'success' => false,
                'message' => 'No email address associated with this account. Please contact support.'
            ], 400);
        }

        try {
            // Generate reset token
            $token = Str::random(60);
            
            // Create a unique identifier that includes the user type
            $emailWithType = $user->email . '|' . strtolower($userType);
            $frontendResetBase = env('FRONTEND_RESET_URL', env('FRONTEND_LOGIN_URL', 'http://localhost:8080') . '/reset-password');
            $resetUrl = rtrim($frontendResetBase, '/') . '?token=' . $token . '&email=' . urlencode($emailWithType);
            
            // Store reset token in the password_reset_tokens table
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $emailWithType],
                [
                    'token' => Hash::make($token),
                    'created_at' => now()
                ]
            );

            // Send password reset email
            try {
                $fullName = trim($user->first_name . ' ' . ($user->middle_name ? $user->middle_name . ' ' : '') . $user->last_name);
                
                Mail::to($user->email)->send(
                    new POSUEmail('password_reset', [
                        'user_name' => $user->first_name,
                        'full_name' => $fullName,
                        'reset_url' => $resetUrl,
                        'reset_token' => $token,
                        'expires_in' => '60 minutes',
                        'request_time' => now()->format('F j, Y \a\t g:i A'),
                        'ip_address' => $request->ip(),
                    ])
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Password reset instructions have been sent to your email address.'
                ]);

            } catch (\Exception $emailError) {
                Log::error('Failed to send password reset email: ' . $emailError->getMessage());
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send password reset email. Please try again later.'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process password reset request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send password reset email for officials (Admin, Head, Deputy, Enforcer)
     */
    public function forgotPasswordOfficials(Request $request)
    {
        // Normalize identifier: trim, lowercase, flatten arrays
        $raw = $request->input('identifier');
        if (is_array($raw)) {
            $raw = Arr::first($raw);
        }
        if (is_string($raw)) {
            $raw = trim(strtolower($raw));
        }
        $request->merge(['identifier' => $raw]);

        $validator = Validator::make($request->all(), [
            'identifier' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->identifier; // email only
        $user = null;
        $userType = null;
        $models = [
            'Admin' => \App\Models\Admin::class,
            'Head' => \App\Models\Head::class,
            'Deputy' => \App\Models\Deputy::class,
            'Enforcer' => \App\Models\Enforcer::class,
        ];

        // Try to find user by email only
        foreach ($models as $type => $model) {
            $user = $model::where('email', $identifier)->first();
            
            if ($user) {
                $userType = $type;
                break;
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with this email.'
            ], 404);
        }

        if (!$user->email) {
            return response()->json([
                'success' => false,
                'message' => 'No email address associated with this account. Please contact support.'
            ], 400);
        }

        try {
            $token = Str::random(60);
            
            // Create a unique identifier that includes the user type
            $emailWithType = $user->email . '|' . strtolower($userType);
            $frontendResetBase = env('FRONTEND_RESET_URL', env('FRONTEND_LOGIN_URL', 'http://localhost:8080') . '/reset-password');
            $resetUrl = rtrim($frontendResetBase, '/') . '?token=' . $token . '&email=' . urlencode($emailWithType);
            
            // Store reset token in the password_reset_tokens table
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $emailWithType],
                [
                    'token' => Hash::make($token),
                    'created_at' => now()
                ]
            );

            // Send password reset email
            try {
                $fullName = trim($user->first_name . ' ' . ($user->middle_name ? $user->middle_name . ' ' : '') . $user->last_name);
                
                Mail::to($user->email)->send(
                    new POSUEmail('password_reset', [
                        'user_name' => $user->first_name,
                        'full_name' => $fullName,
                        'reset_url' => $resetUrl,
                        'reset_token' => $token,
                        'expires_in' => '60 minutes',
                        'request_time' => now()->format('F j, Y \a\t g:i A'),
                        'ip_address' => $request->ip(),
                    ])
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Password reset instructions have been sent to your email address.'
                ]);

            } catch (\Exception $emailError) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send password reset email. Please try again later.'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process password reset request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset password using token for all user types
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string', // This now contains email|type
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Parse the email field which contains email|type
            $emailParts = explode('|', $request->email);
            if (count($emailParts) !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email format.'
                ], 422);
            }
            
            $email = $emailParts[0];
            $userType = ucfirst(strtolower($emailParts[1]));
            $modelClass = '\\App\\Models\\' . $userType;
            
            // Check if user exists
            $user = $modelClass::where('email', $email)->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            // Check if reset token exists and is valid
            $resetRecord = DB::table('password_reset_tokens')
                ->where('email', $request->email) // This is email|type
                ->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token.'
                ], 400);
            }

            // Check if token is not expired (60 minutes)
            if (now()->diffInMinutes($resetRecord->created_at) > 60) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reset token has expired. Please request a new one.'
                ], 400);
            }

            // Update user password
            $user->password = Hash::make($request->password);
            $user->save();

            // Delete the reset token
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully. You can now log in with your new password.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $user = $request->user('sanctum');
        if ($user) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get user profile
     */
    public function profile(Request $request)
    {
        $user = $request->user('sanctum');
        //Request profile by Admin, Deputy, Head, Enforcer, Violator
        if ($type = class_basename($user)){
            return response()->json([
            'success' => true,
            'data' => [
                strtolower($type) => $user,
                'user_type' => $type
            ]
        ]);
        } else{
             $violator = Violator::find($user->id);

                // Violator
                return response()->json([
                    'success' => true,
                    'data' => [
                        'violator' => $user,
                        'user_type' => 'violator'
                    ]
                ]);
        } 
    }

    /**
     * Change password for admin users (Admin, Deputy, Head)
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|regex:/^(?=.*[A-Z])(?=.*\d).+$/',
            'new_password_confirmation' => 'required|string|same:new_password',
        ], [
            'new_password.min' => 'Password must be at least 8 characters long.',
            'new_password.regex' => 'Password must contain at least one uppercase letter and one number.',
            'new_password_confirmation.same' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user('sanctum');
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }

            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password: ' . $e->getMessage()
            ], 500);
        }
    }
}