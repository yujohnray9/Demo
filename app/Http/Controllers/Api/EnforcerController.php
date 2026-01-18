<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\POSUEmail;
use App\Models\Notification;
use App\Models\Enforcer;
use App\Models\Violator;
use App\Models\Violation;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Traits\UserPermissionsTrait;
use Illuminate\Support\Facades\Mail;
use App\Services\AuditLogger;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class EnforcerController extends Controller
{
    use UserPermissionsTrait;

    public function login(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier = $request->identifier;
        $password   = $request->password;

        $enforcer = Enforcer::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if ($enforcer && Hash::check($password, $enforcer->password)) {
            $token = $enforcer->createToken('enforcer-token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user'      => $enforcer,
                    'token'     => $token,
                    'user_type' => 'Enforcer'
                ]
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials'
        ], 401);
    }

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
     * Get enforcer dashboard
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        // Check if user is an enforcer
        if (!$user instanceof Enforcer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Only enforcers can access this endpoint.'
            ], 403);
        }
        
        $stats = [
            'total_apprehensions' => $user->transactions()->count(),
            'today_apprehensions' => $user->transactions()->whereDate('date_time', today())->count(),
            'pending_apprehensions' => $user->transactions()->where('status', 'Pending')->count(),
            'paid_apprehensions' => $user->transactions()->where('status', 'Paid')->count(),
        ];

        // Recent transactions
        $recentTransactions = $user->transactions()
            ->with(['violator', 'violation'])
            ->latest()
            ->limit(10)
            ->get();

        // Weekly performance
        $weeklyPerformance = $user->transactions()
            ->selectRaw('DATE(date_time) as date, COUNT(*) as count')
            ->whereBetween('date_time', [now()->subDays(7), now()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => array_merge($stats, [
                'recent_transactions' => $recentTransactions,
                'weekly_performance' => $weeklyPerformance,
            ])
        ]);
    }

    /**
     * Get all violation types
     */
    public function getViolationTypes()
    {
        $violations = Violation::orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $violations
        ]);
    }


    /**
     * Search violator by license number or plate number
     */
    public function searchViolator(Request $request)
{
    $search = $request->get('search');

    if (!$search || strlen($search) < 2) {
        return response()->json([
            'status' => 'error',
            'message' => 'Search term must be at least 2 characters'
        ], 422);
    }

    $violators = Violator::withCount('transactions')
        ->with('vehicles')
        ->where(function($query) use ($search) {
            $query->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('license_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('vehicles', function($q) use ($search) {
                      $q->where('plate_number', 'LIKE', "%{$search}%");
                  });
        })
        ->get();

        if ($violators->isEmpty()) {
        return response()->json([
            'status' => 'error',
            'message' => 'No violators found'
        ], 404);
    }
    return response()->json([
        'status' => 'success',
        'data' => $violators
    ]);
}

    /**
     * Record a new violation
     */
    public function recordViolation(Request $request)
    {
        // Handle nested data structure from mobile app
        $violatorData = $request->input('violator', []);
        $vehicleData = $request->input('vehicle', []);
        
        // Merge nested data with main request data
        $allData = array_merge($request->all(), $violatorData, $vehicleData);
        
        $validator = Validator::make($allData, [
            'first_name'      => 'required|string|max:100',
            'middle_name'     => 'nullable|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'nullable|email',
            'mobile_number'   => 'nullable|string|size:11',
            'professional'    => 'nullable|boolean',
            'gender'          => 'required|boolean',
            'license_number'  => 'required|string|size:11',
            'violation_id'    => 'nullable|exists:violations,id',
            'violation_ids'   => 'nullable|array|min:1',
            'violation_ids.*' => 'integer|exists:violations,id',
            'location'        => 'nullable|string|max:100',
            'vehicle_type'    => 'required|in:Motor,Motorcycle,Van,Car,SUV,Truck,Bus',
            'plate_number'    => 'required|string|max:7',
            'make'            => 'required|string|max:100',
            'model'           => 'required|string|max:100',
            'color'           => 'required|string|max:100', 
            'barangay'        => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:255',
            'province'        => 'nullable|string|max:255',
            'owner_first_name'      => 'required|string|max:100',
            'owner_middle_name'     => 'nullable|string|max:100',
            'owner_last_name'       => 'required|string|max:100',
            'owner_barangay'        => 'nullable|string|max:255',
            'owner_city'            => 'nullable|string|max:255',
            'owner_province'        => 'nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // GPS Location fields
            'gps_latitude'    => 'nullable|numeric|between:-90,90',
            'gps_longitude'   => 'nullable|numeric|between:-180,180',
            'gps_accuracy'    => 'nullable|numeric|min:0',
            'gps_timestamp'   => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create or get violator
            $violator = Violator::firstOrCreate(
                ['license_number' => $allData['license_number']],
                [
                    'first_name'  => $allData['first_name'],
                    'middle_name' => $allData['middle_name'] ?? null,
                    'last_name'   => $allData['last_name'],
                    'email'       => $allData['email'] ?? null,
                    'gender'      => $allData['gender'],
                    'mobile_number' => $allData['mobile_number'] ?? '',
                    'id_photo'    => $request->hasFile('image')
                        ? (function() use ($request) {
                            $upload = Cloudinary::upload($request->file('image')->getRealPath(), [
                                'folder' => 'posu/id_photos',
                                'resource_type' => 'image',
                            ]);
                            return $upload->getSecurePath();
                        })()
                        : null,
                    'barangay'     => $allData['barangay'] ?? null,
                    'city'         => $allData['city'] ?? null,
                    'province'     => $allData['province'] ?? null,
                    'professional' => $allData['professional'] ?? false,
                ]
            );

            // If violator already exists and a new image was provided, update their ID photo
            if ($request->hasFile('image') && $violator->wasRecentlyCreated === false) {
                $upload = Cloudinary::upload($request->file('image')->getRealPath(), [
                    'folder' => 'posu/id_photos',
                    'resource_type' => 'image',
                ]);
                $violator->id_photo = $upload->getSecurePath();
                $violator->save();
            }

            // Create or get vehicle
            $vehicle = Vehicle::firstOrCreate(
                ['plate_number' => $allData['plate_number']],
                [
                    'violators_id' => $violator->id,
                    'owner_first_name'   => $allData['owner_first_name'],
                    'owner_middle_name'  => $allData['owner_middle_name'] ?? null,
                    'owner_last_name'    => $allData['owner_last_name'],
                    'make'         => $allData['make'],
                    'model'        => $allData['model'],
                    'color'        => $allData['color'],
                    'owner_barangay'     => $allData['owner_barangay'] ?? null,
                    'owner_city'         => $allData['owner_city'] ?? null,
                    'owner_province'     => $allData['owner_province'] ?? null,
                    'vehicle_type' => $allData['vehicle_type'],
                ]
            );

            // Determine selected violations (support legacy single violation_id or new violation_ids array)
            $selectedViolationIds = [];
            if (!empty($allData['violation_ids']) && is_array($allData['violation_ids'])) {
                $selectedViolationIds = array_values(array_unique(array_map('intval', $allData['violation_ids'])));
            } elseif (!empty($allData['violation_id'])) {
                $selectedViolationIds = [(int) $allData['violation_id']];
            }

            if (empty($selectedViolationIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'At least one violation is required',
                ], 422);
            }

            $violations = Violation::whereIn('id', $selectedViolationIds)->get();
            if ($violations->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Selected violations not found',
                ], 422);
            }

            // Sum fines from selected violations 
            $computedFine = $violations->sum('fine_amount');

            $gpsLatitude = $allData['gps_latitude'] ?? null;
            $gpsLongitude = $allData['gps_longitude'] ?? null;
            
            if ($gpsLatitude !== null && $gpsLongitude !== null) {
                if (abs($gpsLatitude) > 90 && abs($gpsLongitude) <= 90) {
                    \Log::warning("GPS coordinates appear swapped - fixing. Original: lat={$gpsLatitude}, lng={$gpsLongitude}");
                    $temp = $gpsLatitude;
                    $gpsLatitude = $gpsLongitude;
                    $gpsLongitude = $temp;
                    \Log::info("GPS coordinates fixed. New: lat={$gpsLatitude}, lng={$gpsLongitude}");
                }
                
                $gpsLatitude = max(-90, min(90, $gpsLatitude));
                $gpsLongitude = max(-180, min(180, $gpsLongitude));
            }

            // Create transaction
            $transaction = Transaction::create([
                'violator_id'          => $violator->id,
                'vehicle_id'           => $vehicle->id,
                'violation_id'         => $violations->first()->id,
                'apprehending_officer' => auth()->id(),
                'status'               => 'Pending',
                'location'             => $allData['location'] ?? 'GPS Location',
                'date_time'            => now(),
                'fine_amount'          => $allData['fine_amount'] ?? $computedFine,
                'gps_latitude'         => $gpsLatitude,
                'gps_longitude'        => $gpsLongitude,
                'gps_accuracy'         => $allData['gps_accuracy'] ?? null,
                'gps_timestamp'        => $allData['gps_timestamp'] ?? null,
            ]);

            // Attach all selected violations to pivot
            $transaction->violations()->sync($selectedViolationIds);

            // Audit: violation recorded
            $actor = auth()->user();
            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));
            $ticketLabel = 'Ticket #' . ($transaction->ticket_number ?? $transaction->id);
            $violationNames = $violations->pluck('name')->implode(', ');
            AuditLogger::log(
                $actor,
                'Violation Recorded',
                'Transaction',
                $transaction->id,
                $ticketLabel,
                [],
                $request,
                "$actorName recorded violation(s) ($violationNames) for {$violator->first_name} {$violator->last_name}"
            );

            $allowedRoles = ['System','Management','Head','Deputy','Admin','Enforcer','Violator'];
            $userType = class_basename(auth()->user());
            $senderRole = in_array($userType, $allowedRoles) ? $userType : 'System';
            $senderRole = ucfirst(strtolower($senderRole));
            
            // Create Notificaiton for Violator
            Notification::create([
                'sender_id'     => auth()->id(),
                'sender_role'   => $senderRole,
                'target_type'   => 'Violator',
                'target_id'     => $violator->id,
                'violator_id'   => $violator->id,
                'transaction_id'=> $transaction->id,
                'title'         => 'New Violation Recorded',
                'message'       => "You have been cited for {$violationNames}. Fine: ₱" . number_format($transaction->fine_amount, 2) . ". Please pay within 7 days to avoid penalties.",
                'type'          => 'info',
            ]);

            // Check offense count (for license suspension)
            $violationCount = $violator->transactions()->count();
            if ($violationCount >= 3 && !$violator->license_suspended_at) {
                $violator->license_suspended_at = now();
                $violator->save();

                // Notify Violator
                Notification::create([
                    'sender_id'   => auth()->id(),
                    'sender_role' => $senderRole,
                    'target_type' => 'Violator',
                    'violator_id' => $violator->id,
                    'target_id'   => $violator->id,
                    'title'       => 'License Suspension',
                    'message'     => "You now have {$violationCount} recorded violations. Your license is now subject to suspension.",
                    'type'        => 'alert',
                ]);

                // Notify Management (Head, Deputy, Admin)
                Notification::create([
                    'sender_id'   => auth()->id(),
                    'sender_role' => $senderRole,
                    'target_type' => 'Management',
                    'title'       => 'License Suspension Issued',
                    'message'     => "{$violator->first_name} {$violator->last_name} now has {$violationCount} recorded violations. Their license has been suspended.",
                    'type'        => 'alert',
                ]);
            }

             // Create Notificaiton for Head,Deputy,Admin
                Notification::create([
                    'sender_id'   => auth()->id(),
                    'sender_role' => $senderRole,
                    'target_type' => 'Management',
                    'title'       => 'Violation Recorded',
                    'message'     => "New violation(s) ({$violationNames}) were recorded for {$violator->first_name} {$violator->last_name}.",
                    'type'        => 'info',
                ]);

            // Create Notificaiton for Enforcer
            Notification::create([
                'sender_id'   => auth()->id(),
                'sender_role' => $senderRole,
                'target_type' => 'Enforcer',
                'target_id'   => auth()->id(),
                'title'       => 'Violation Successfully Recorded',
                'message'     => "You have successfully recorded violation(s) for {$violator->first_name} {$violator->last_name} ({$violationNames}).",
                'type'        => 'info',
            ]);

            
            if ($request->filled('email')) {
                try {
                    $violatorName = trim($violator->first_name . ' ' . ($violator->middle_name ? $violator->middle_name . ' ' : '') . $violator->last_name);
                    $vehicleInfo = $vehicle->make . ' ' . $vehicle->model . ' (' . $vehicle->color . ')';
                    $violatorAddress = $violator->barangay . ', ' . $violator->city . ', ' . $violator->province;
                    
                    Mail::to($request->email)->send(
                        new POSUEmail('citation', [
                            'violator_name' => $violatorName,
                            'ticket_number' => $transaction->ticket_number ?? 'CT-' . date('Y') . '-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
                            'violation_type' => $violationNames,
                            'fine_amount' => $transaction->fine_amount,
                            'violation_date' => $transaction->date_time->format('F j, Y'),
                            'violation_datetime' => $transaction->date_time->format('F j, Y - g:i A'),
                            'location' => $request->location,
                            'license_number' => $violator->license_number,
                            'vehicle_info' => $vehicleInfo,
                            'plate_number' => $vehicle->plate_number,
                            'violator_address' => $violatorAddress,
                        ])
                    );
                } catch (\Exception $emailError) {
                    // Log email error 
                    Log::error('Failed to send citation email: ' . $emailError->getMessage());
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Violation recorded successfully' . (!empty($allData['email']) ? ' and citation email sent' : ''),
                'data' => [
                    'transaction' => $transaction->load(['violator', 'vehicle', 'violation', 'violations']),
                    'violator'    => $violator,
                    'vehicle'     => $vehicle
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record violation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get enforcer's transactions 
     */
    public function getTransactions(Request $request)
    {
        $user = $request->user();

        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);

        $transactions = Transaction::with([
            'violator' => function ($q) {
                $q->withCount('transactions');
            },
            'violation',
            'violations',
            'vehicle',
            'apprehendingOfficer:id,first_name,middle_name,last_name,image,username'
        ])
            ->orderBy('ticket_number', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        $transactions->getCollection()->transform(function ($transaction) {
            $officer = $transaction->apprehendingOfficer;
            $transaction->officer = $officer ? [
                'id' => $officer->id,
                'first_name' => $officer->first_name,
                'middle_name' => $officer->middle_name,
                'last_name' => $officer->last_name,
                'full_name' => method_exists($officer, 'getFullNameAttribute') ? $officer->full_name : trim(($officer->first_name ?? '') . ' ' . ($officer->middle_name ?? '') . ' ' . ($officer->last_name ?? '')),
                'image' => $officer->image,
                'username' => $officer->username,
            ] : null;
            return $transaction;
        });

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }


    /**
     * Get all violators with transactions
     */
    public function getViolators(Request $request)
    {
        $perPage = $request->input('per_page', 1000);
        $page = $request->input('page', 1);

        $violators = Violator::whereHas('transactions')
            ->withCount('transactions')
            ->with('vehicles')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => $violators
        ]);
    }

   
    /**
     * Get enforcer's performance statistics
     */
    public function getPerformanceStats(Request $request)
    {
        $user = $request->user();
        
        $stats = [
            'total_apprehensions' => $user->transactions()->count(),
            'monthly_apprehensions' => $user->transactions()->whereMonth('date_time', now()->month)->count(),
            'weekly_apprehensions' => $user->transactions()->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'daily_apprehensions' => $user->transactions()->whereDate('date_time', today())->count(),
            'pending_count' => $user->transactions()->where('status', 'Pending')->count(),
            'paid_count' => $user->transactions()->where('status', 'Paid')->count(),
            'total_revenue' => $user->transactions()->where('status', 'Paid')->sum('fine_amount'),
        ];

        // Monthly performance for the last 6 months
        $monthlyPerformance = $user->transactions()
            ->selectRaw('YEAR(date_time) as year, MONTH(date_time) as month, COUNT(*) as count')
            ->whereBetween('date_time', [now()->subMonths(6), now()])
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }
    /**
 * Get enforcer's profile
 */
public function getProfile(Request $request)
{
    $user = $request->user();

    return response()->json([
        'status' => 'success',
        'data' => [
            'id'          => $user->id,
            'first_name'  => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name'   => $user->last_name,
            'full_name'   => $user->full_name, // from accessor
            'username'    => $user->username,
            'email'       => $user->email,
            'office'      => $user->office,
            'image'       => $user->image_url, // from accessor
            'status'      => $user->status,
            'created_at'  => $user->created_at,
            'updated_at'  => $user->updated_at,
        ]
    ]);
}
   /**
 * Update enforcer's profile (name + image)
 */
public function updateProfile(Request $request)
{
    $validator = Validator::make($request->all(), [
        'first_name'   => 'required|string|max:100',
        'middle_name'  => 'nullable|string|max:100',
        'last_name'    => 'required|string|max:100',
        'office'       => 'nullable|string|max:255',
        'image'        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    $user = $request->user();

    $user->first_name  = $request->first_name;
    $user->middle_name = $request->middle_name;
    $user->last_name   = $request->last_name;
    $user->office      = $request->office;

    if ($request->hasFile('image')) {
        $upload = Cloudinary::upload($request->file('image')->getRealPath(), [
            'folder' => 'posu/profile_images',
            'resource_type' => 'image',
        ]);
        $user->image = $upload->getSecurePath();
    }

    $user->save();

    return response()->json([
        'status' => 'success',
        'message' => 'Profile updated successfully',
        'data' => $user
    ]);
}
        /**
     * Change enforcer's password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed', // requires new_password_confirmation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Update to new password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully'
        ]);
    }
    /**
     * Get enforcer's notifications
     */
    public function getNotifications(Request $request)
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);
        $includeDeleted = $request->input('include_deleted', false);

        $notificationsQuery = Notification::where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('target_type', 'Enforcer')
                ->where('target_id', $user->id);
            })
            ->orWhere(function ($q) {
                $q->where('target_type', 'Enforcer')
                ->whereNull('target_id');
            })
            ->orWhere('target_type', 'All');
        })
        ->orderBy('created_at', 'desc')
        ->with('sender'); 

        if ($includeDeleted) {
            $notificationsQuery->withTrashed();
        }

        $notifications = $notificationsQuery->paginate($perPage, ['*'], 'page', $page);

        $unreadCount = Notification::where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('target_type', 'Enforcer')
                ->where('target_id', $user->id);
            })
            ->orWhere(function ($q) {
                $q->where('target_type', 'Enforcer')
                ->whereNull('target_id');
            })
            ->orWhere('target_type', 'All');
        })
        ->whereNull('read_at')
        ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]
        ]);
    }


public function markNotificationAsRead(Request $request, $notificationId)
{
    $user = $request->user();
    
    $notification = Notification::where('id', $notificationId)
        ->where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('target_type', 'Enforcer')
                  ->where('target_id', $user->id);
            })->orWhere(function ($q) {
                $q->where('target_type', 'Enforcer')
                  ->whereNull('target_id');
            })->orWhere('target_type', 'All');
        })
        ->first();

    if (!$notification) {
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found'
        ], 404);
    }

    $notification->read_at = now();
    $notification->save();

    return response()->json([
        'status' => 'success',
        'message' => 'Notification marked as read'
    ]);
}

public function markAllNotificationsAsRead(Request $request)
{
    $user = $request->user();
    
    Notification::where(function ($query) use ($user) {
        $query->where(function ($q) use ($user) {
            $q->where('target_type', 'Enforcer')
              ->where('target_id', $user->id);
        })->orWhere(function ($q) {
            $q->where('target_type', 'Enforcer')
              ->whereNull('target_id');
        })->orWhere('target_type', 'All');
    })
    ->whereNull('read_at')
    ->update(['read_at' => now()]);

    return response()->json([
        'status' => 'success',
        'message' => 'All notifications marked as read'
    ]);
}

public function deleteNotification(Request $request, $notificationId)
{
    $user = $request->user();
    
    $notification = Notification::where('id', $notificationId)
        ->where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('target_type', 'Enforcer')
                  ->where('target_id', $user->id);
            })->orWhere(function ($q) {
                $q->where('target_type', 'Enforcer')
                  ->whereNull('target_id');
            })->orWhere('target_type', 'All');
        })
        ->first();

    if (!$notification) {
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found'
        ], 404);
    }

    $notification->delete();

    return response()->json([
        'status' => 'success',
        'message' => 'Notification deleted successfully'
    ]);
}
public function restoreNotification(Request $request, $notificationId)
{
    $user = $request->user();

    // Include trashed to find soft-deleted notifications
    $notification = Notification::withTrashed()
        ->where('id', $notificationId)
        ->where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('target_type', 'Enforcer')
                  ->where('target_id', $user->id);
            })
            ->orWhere(function ($q) {
                $q->where('target_type', 'Enforcer')
                  ->whereNull('target_id');
            })
            ->orWhere('target_type', 'All');
        })
        ->first();

    if (!$notification) {
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found'
        ], 404);
    }

    $notification->restore();

    return response()->json([
        'status' => 'success',
        'message' => 'Notification restored'
    ]);
}
} 