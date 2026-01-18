<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Traits\UserPermissionsTrait;

// Models
use App\Models\Admin;
use App\Models\Head;
use App\Models\Deputy;
use App\Models\Enforcer;
use App\Models\Violator;
use App\Models\Violation;
use App\Models\Transaction;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\Report;
use App\Models\AuditLog;

// Exports & External Packages
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ArrayExport;
use App\Mail\POSUEmail;
use App\Models\Vehicle;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Swagger\Client\Configuration;
use Swagger\Client\Api\ConvertDocumentApi;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\AuditLogger;

class AdminController extends Controller
{
    use UserPermissionsTrait;

    public function dashboard(Request $request)
{
    $period = $request->get('period', 'all');
    $now = now();

    $transactionQuery = Transaction::query();

    if ($period === 'year') {
        $transactionQuery->whereYear('date_time', $now->year);
    } elseif ($period === 'month') {
        $transactionQuery->whereYear('date_time', $now->year)
                         ->whereMonth('date_time', $now->month);
    } elseif ($period === 'week') {
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();
        $transactionQuery->whereBetween('date_time', [$startOfWeek, $endOfWeek]);
    } elseif ($period === 'today') {
        $transactionQuery->whereDate('date_time', $now->toDateString());
    }

    $totalViolators = Violator::whereHas('transactions', function($q) use ($period, $now) {
        if ($period === 'year') {
            $q->whereYear('date_time', $now->year);
        } elseif ($period === 'month') {
            $q->whereYear('date_time', $now->year)
              ->whereMonth('date_time', $now->month);
        } elseif ($period === 'week') {
            $q->whereBetween('date_time', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
        } elseif ($period === 'today') {
            $q->whereDate('date_time', $now->toDateString());
        }
    })->count();

    $getRepeatOffendersQuery = function() use ($period, $now) {
        return Violator::whereHas('transactions', function($q) use ($period, $now) {
            if ($period === 'year') {
                $q->whereYear('date_time', $now->year);
            } elseif ($period === 'month') {
                $q->whereYear('date_time', $now->year)
                  ->whereMonth('date_time', $now->month);
            } elseif ($period === 'week') {
                $q->whereBetween('date_time', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            } elseif ($period === 'today') {
                $q->whereDate('date_time', $now->toDateString());
            }
        });
    };

    $repeatOffenders = $getRepeatOffendersQuery()
        ->withCount(['transactions' => function($q) use ($period, $now) {
            if ($period === 'year') {
                $q->whereYear('date_time', $now->year);
            } elseif ($period === 'month') {
                $q->whereYear('date_time', $now->year)
                  ->whereMonth('date_time', $now->month);
            } elseif ($period === 'week') {
                $q->whereBetween('date_time', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            } elseif ($period === 'today') {
                $q->whereDate('date_time', $now->toDateString());
            }
        }])
        ->having('transactions_count', '>', 1)
        ->count();

    $stats = [
        'total_violators'      => $totalViolators,
        'total_transactions'   => $transactionQuery->count(),
        'pending_transactions' => (clone $transactionQuery)->where('status', 'Pending')->count(),
        'paid_transactions'    => (clone $transactionQuery)->where('status', 'Paid')->count(),
        'total_revenue'        => (clone $transactionQuery)->where('status', 'Paid')->sum('fine_amount'),
        'pending_revenue'      => (clone $transactionQuery)->where('status', 'Pending')->sum('fine_amount'),
        'repeat_offenders'     => $repeatOffenders,
        'active_enforcers'     => Enforcer::where('status', 'activated')->count(),
        'active_admins'        => Admin::where('status', 'activated')->count(),
        'active_deputies'      => Deputy::where('status', 'activated')->count(),
        'active_heads'         => Head::where('status', 'activated')->count(),
    ];

    // Trends 
    $weeklyTrends = [];
    $monthlyTrends = [];
    $yearlyTrends = [];

        $weeklyTrends = Transaction::selectRaw('DATE(date_time) as date, COUNT(*) as count')
    ->groupBy('date')
    ->orderBy('date', 'asc')
    ->get();

// Monthly trends - all months from all years  
$monthlyTrends = Transaction::selectRaw('MONTH(date_time) as month, YEAR(date_time) as year, COUNT(*) as count')
    ->groupBy('year', 'month')
    ->orderBy('year', 'asc')
    ->orderBy('month', 'asc')
    ->get();

// Yearly trends - all years
$yearlyTrends = Transaction::selectRaw('YEAR(date_time) as year, COUNT(*) as count')
    ->groupBy('year')
    ->orderBy('year', 'asc')
    ->get();

    $commonViolations = Violation::withCount(['transactions' => function($q) use ($period, $now) {
        if ($period === 'year') {
            $q->whereYear('date_time', $now->year);
        } elseif ($period === 'month') {
            $q->whereYear('date_time', $now->year)
              ->whereMonth('date_time', $now->month);
        } elseif ($period === 'week') {
            $q->whereBetween('date_time', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
        } elseif ($period === 'today') {
            $q->whereDate('date_time', $now->toDateString());
        }
    }])
        ->orderBy('transactions_count', 'desc')
        ->limit(5)
        ->get();

    $enforcerPerformance = Enforcer::with(['transactions' => function($q) use ($period, $now) {
        if ($period === 'year') {
            $q->whereYear('date_time', $now->year);
        } elseif ($period === 'month') {
            $q->whereYear('date_time', $now->year)
              ->whereMonth('date_time', $now->month);
        } elseif ($period === 'week') {
            $q->whereBetween('date_time', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
        } elseif ($period === 'today') {
            $q->whereDate('date_time', $now->toDateString());
        }
    }])->get();

    // Trend percentage (affected by period)
    if ($period === 'all') {
        $percentage = 0;
        $trendDirection = 'same';
        
    } else {
        $currentPeriodQuery = Transaction::query();
        $previousPeriodQuery = Transaction::query();

        if ($period === 'year') {
            $currentPeriodQuery->whereYear('date_time', $now->year);
            $previousPeriodQuery->whereYear('date_time', $now->year - 1);
            
        } elseif ($period === 'month') {
            $currentPeriodQuery->whereYear('date_time', $now->year)
                               ->whereMonth('date_time', $now->month);
            $prevMonth = $now->copy()->subMonth();
            $previousPeriodQuery->whereYear('date_time', $prevMonth->year)
                                ->whereMonth('date_time', $prevMonth->month);
                                
        } elseif ($period === 'week') {
            $currentPeriodQuery->whereBetween('date_time', [
                $now->copy()->startOfWeek(), 
                $now->copy()->endOfWeek()
            ]);
            $previousPeriodQuery->whereBetween('date_time', [
                $now->copy()->subWeek()->startOfWeek(), 
                $now->copy()->subWeek()->endOfWeek()
            ]);
        } elseif ($period === 'today') {
            $currentPeriodQuery->whereDate('date_time', $now->toDateString());
            $previousPeriodQuery->whereDate('date_time', $now->copy()->subDay()->toDateString());
        }

        $currentTransactions = $currentPeriodQuery->count();
        $previousTransactions = $previousPeriodQuery->count();

        $percentage = $previousTransactions > 0
            ? round((($currentTransactions - $previousTransactions) / $previousTransactions) * 100, 2)
            : ($currentTransactions > 0 ? 100 : 0);

        $trendDirection = $percentage > 0 ? 'up' : ($percentage < 0 ? 'down' : 'same');
    }

    // Debug: Check if there are any pending transactions
    $pendingTransactionsCount = Transaction::where('status', 'Pending')->count();
    Log::info('Pending transactions count: ' . $pendingTransactionsCount);
    
    // Get all violators with pending transactions first
    $allViolatorsWithPending = Violator::withCount(['transactions' => function($q) {
        $q->where('status', 'Pending');
    }])
    ->withSum(['transactions' => function($q) {
        $q->where('status', 'Pending');
    }], 'fine_amount')
    ->with(['transactions' => function($q) {
        $q->where('status', 'Pending')
          ->select('id', 'violator_id', 'location', 'created_at', 'date_time', 'fine_amount');
    }])
    ->having('transactions_count', '>', 0)
    ->get();
    
    Log::info('All violators with pending transactions: ' . $allViolatorsWithPending->count());
    
    // Process each violator and check days pending
    $unsettledViolators = $allViolatorsWithPending->map(function($violator) {
        $daysPending = $violator->transactions->map(function($transaction) {
            return now()->diffInDays($transaction->date_time);
        })->min();
        
        // Get the most recent apprehension date
        $latestApprehension = $violator->transactions->max('date_time');
        
        Log::info("Violator {$violator->id}: days pending = {$daysPending}");
        
        // Show all pending violators for now, but mark urgency levels
        return [
            'id' => $violator->id,
            'name' => trim(($violator->first_name ?? '') . ' ' . ($violator->middle_name ?? '') . ' ' . ($violator->last_name ?? '')),
            'pending_count' => $violator->transactions_count,
            'total_amount' => $violator->transactions_sum_fine_amount ?? 0,
            'days_pending' => $daysPending,
            'urgency_level' => $daysPending >= 5 ? 'alert' : ($daysPending >= 3 ? 'warning' : 'info'),
            'locations' => $violator->transactions->pluck('location')->unique()->values(),
            'apprehension_date' => $latestApprehension ? $latestApprehension->format('M d, Y') : 'N/A'
        ];
    })
    ->sortByDesc('total_amount')
    ->take(10)
    ->values();
    
    // Get location data for heatmap (all pending transactions with GPS coordinates)
    $locationQuery = Transaction::where('status', 'Pending')
        ->whereNotNull('gps_latitude')
        ->whereNotNull('gps_longitude');
    
    // Apply period filter if specified
    $heatmapPeriod = $request->get('heatmap_period', 'all');
    if ($heatmapPeriod !== 'all') {
        switch ($heatmapPeriod) {
            case 'today':
                $locationQuery->whereDate('created_at', $now->toDateString());
                break;
            case 'week':
                $locationQuery->whereBetween('created_at', [
                    $now->startOfWeek()->toDateTimeString(),
                    $now->endOfWeek()->toDateTimeString()
                ]);
                break;
            case 'month':
                $locationQuery->whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year);
                break;
        }
    }
    
    // Group by rounded GPS coordinates (to cluster nearby violations) instead of location string
    // This ensures all violations with GPS data are included, regardless of location string
    $locationData = $locationQuery
        ->selectRaw('
                     ROUND(gps_latitude, 4) as rounded_lat, 
                     ROUND(gps_longitude, 4) as rounded_lng,
                     AVG(gps_latitude) as gps_latitude, 
                     AVG(gps_longitude) as gps_longitude, 
                     COUNT(*) as count, 
                     SUM(fine_amount) as total_amount,
                     MIN(location) as location')
        ->groupBy('rounded_lat', 'rounded_lng')
        ->orderByDesc('count')
        ->get()
        ->map(function($item) {
            // Use the actual GPS coordinates (not the location string) to ensure accuracy
            $lat = round($item->gps_latitude, 6);
            $lng = round($item->gps_longitude, 6);
            
            // Validate coordinates are within valid ranges (latitude: -90 to 90, longitude: -180 to 180)
            // If coordinates seem swapped (lat > 90 or lat < -90), swap them
            if (abs($lat) > 90) {
                // Coordinates appear to be swapped - latitude should be between -90 and 90
                Log::warning("GPS coordinates appear swapped for location: lat={$lat}, lng={$lng}. Swapping values.");
                $temp = $lat;
                $lat = $lng;
                $lng = $temp;
            }
            
            // Always generate location name from GPS coordinates to ensure consistency
            $locationName = $this->getBetterLocationName($lat, $lng);
            
            Log::info("Processing heatmap point: lat={$lat}, lng={$lng}, count={$item->count}, location='{$locationName}'");
            
            return [
                'location' => $locationName,
                'gps_latitude' => $lat,
                'gps_longitude' => $lng,
                'count' => (int)$item->count,
                'total_amount' => (float)$item->total_amount
            ];
        });
    
   
    $allGpsTransactions = Transaction::where('status', 'Pending')
        ->whereNotNull('gps_latitude')
        ->whereNotNull('gps_longitude')
        ->get(['id', 'location', 'gps_latitude', 'gps_longitude', 'fine_amount', 'created_at']);
    
    Log::info('All GPS transactions count: ' . $allGpsTransactions->count());
    Log::info('All GPS transactions: ' . $allGpsTransactions->toJson());
    
    Log::info('Location heatmap data: ' . $locationData->toJson());
    
    Log::info('Unsettled violators count: ' . $unsettledViolators->count());
    Log::info('Location data count: ' . $locationData->count());
    Log::info('Unsettled violators data: ' . $unsettledViolators->toJson());
    Log::info('Location data: ' . $locationData->toJson());

    return response()->json([
        'status' => 'success',
        'data'   => [
            'stats'                => $stats,
            'weekly_trends'        => $weeklyTrends,
            'monthly_trends'       => $monthlyTrends,
            'yearly_trends'        => $yearlyTrends,
            'common_violations'    => $commonViolations,
            'enforcer_performance' => $enforcerPerformance,
            'unsettled_violators'  => $unsettledViolators,
            'location_heatmap'     => $locationData,
            'debug_info'           => [
                'pending_transactions_count' => $pendingTransactionsCount,
                'unsettled_violators_count' => $unsettledViolators->count()
            ],
            'trends'               => [
                'transactions' => [
                    'percentage' => $percentage,
                    'direction'  => $trendDirection
                ]
            ],
        ]
    ]);
}

/**
 * Get a better location name using multiple strategies
 */
private function getBetterLocationName($latitude, $longitude)
{
    $knownAreas = $this->getKnownEchagueAreas($latitude, $longitude);
    if ($knownAreas) {
        return $knownAreas;
    }
    
    // Strategy 2: Try reverse geocoding with multiple providers
    $reverseGeocoded = $this->reverseGeocodeLocation($latitude, $longitude);
    if ($reverseGeocoded && !str_contains($reverseGeocoded, 'Location at')) {
        return $reverseGeocoded;
    }
    
    // Strategy 3: Create a descriptive location based on coordinates
    $lat = round($latitude, 4);
    $lng = round($longitude, 4);
    
    // Check if coordinates are in Echague, Isabela area
    if ($lat >= 16.6 && $lat <= 16.8 && $lng >= 121.6 && $lng <= 121.8) {
        return "Echague, Isabela Area";
    }
    
    return "Location at {$lat}, {$lng}";
}

/**
 * Check if coordinates are in known areas of Echague
 */
private function getKnownEchagueAreas($latitude, $longitude)
{
    // Define known areas in Echague with their approximate coordinates
    $knownAreas = [
        // Echague Town Center
        ['name' => 'Echague Town Center', 'lat_min' => 16.700, 'lat_max' => 16.720, 'lng_min' => 121.660, 'lng_max' => 121.680],
        
        // Near Municipal Hall
        ['name' => 'Near Echague Municipal Hall', 'lat_min' => 16.705, 'lat_max' => 16.715, 'lng_min' => 121.665, 'lng_max' => 121.675],
        
        // Market Area
        ['name' => 'Echague Market Area', 'lat_min' => 16.695, 'lat_max' => 16.710, 'lng_min' => 121.650, 'lng_max' => 121.670],
        
        // Residential Areas
        ['name' => 'Echague Residential Area', 'lat_min' => 16.680, 'lat_max' => 16.740, 'lng_min' => 121.640, 'lng_max' => 121.690],
        
        // Specific Locations with Accurate Coordinates
        ['name' => 'Echague Police Station Area', 'lat_min' => 16.715, 'lat_max' => 16.716, 'lng_min' => 121.682, 'lng_max' => 121.684],
        ['name' => 'Savemore Market Area', 'lat_min' => 16.705, 'lat_max' => 16.706, 'lng_min' => 121.676, 'lng_max' => 121.677],
        ['name' => 'Echague Poblacion Road Area', 'lat_min' => 16.721, 'lat_max' => 16.722, 'lng_min' => 121.685, 'lng_max' => 121.686],
    ];
    
    foreach ($knownAreas as $area) {
        if ($latitude >= $area['lat_min'] && $latitude <= $area['lat_max'] &&
            $longitude >= $area['lng_min'] && $longitude <= $area['lng_max']) {
            return $area['name'];
        }
    }
    
    return null;
}

/**
 * Reverse geocode GPS coordinates to get a readable location name
 */
private function reverseGeocodeLocation($latitude, $longitude)
{
    try {
        $client = new \GuzzleHttp\Client();
        
        // Try Mapbox first (better for rural areas)
        try {
            $mapboxResponse = $client->get("https://api.mapbox.com/geocoding/v5/mapbox.places/{$longitude},{$latitude}.json", [
                'query' => [
                    'access_token' => env('MAPBOX_ACCESS_TOKEN', 'pk.eyJ1IjoieXVqb2hucmF5IiwiYSI6ImNtaDczcG94MDBubGgybHNieml0ZmJ6bmwifQ.KRR3neB3mYayV6L8sN71uA'),
                    'types' => 'address,poi,place',
                    'limit' => 1
                ]
            ]);
            
            $mapboxData = json_decode($mapboxResponse->getBody(), true);
            
            if (isset($mapboxData['features']) && count($mapboxData['features']) > 0) {
                $feature = $mapboxData['features'][0];
                return $feature['place_name'] ?? $feature['text'] ?? "Location at {$latitude}, {$longitude}";
            }
        } catch (\Exception $e) {
            Log::info('Mapbox geocoding failed, trying OpenStreetMap: ' . $e->getMessage());
        }
        
        // Fallback to OpenStreetMap
        $response = $client->get("https://nominatim.openstreetmap.org/reverse", [
            'query' => [
                'format' => 'json',
                'lat' => $latitude,
                'lon' => $longitude,
                'addressdetails' => 1,
                'zoom' => 18
            ],
            'headers' => [
                'User-Agent' => 'POSU-System/1.0'
            ]
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        if (isset($data['address'])) {
            $address = $data['address'];
            
            // Build a readable address
            $parts = [];
            
            if (!empty($address['house_number'])) {
                $parts[] = $address['house_number'];
            }
            
            if (!empty($address['road'])) {
                $parts[] = $address['road'];
            } elseif (!empty($address['street'])) {
                $parts[] = $address['street'];
            }
            
            if (!empty($address['suburb'])) {
                $parts[] = $address['suburb'];
            }
            
            if (!empty($address['city'])) {
                $parts[] = $address['city'];
            } elseif (!empty($address['town'])) {
                $parts[] = $address['town'];
            }
            
            if (!empty($address['state'])) {
                $parts[] = $address['state'];
            }
            
            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }
        
        // Fallback to coordinates if reverse geocoding fails
        return "Location at {$latitude}, {$longitude}";
        
    } catch (\Exception $e) {
        Log::error('Reverse geocoding failed: ' . $e->getMessage());
        return "Location at {$latitude}, {$longitude}";
    }
}

    /* ==============================
     * USERS MANAGEMENT (All User Types)
     * ============================== */
    public function getUsers(Request $request)
    {
        $authUser = $request->user('sanctum');
        $currentUserType = $this->getUserType($authUser);

        if (!$currentUserType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to determine user type'
            ], 403);
        }

        $viewableUserTypes = $this->getViewableUserTypes($authUser);

        $status   = $request->input('status');
        $search   = $request->input('search');
        $role     = $request->input('role');
        $perPage  = $request->input('per_page', 15);
        $page     = $request->input('page', 1);

        $users = collect();

        $applyFilters = function ($query) use ($status, $search) {
            if ($status) {
                $query->where('status', $status);
            }
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                });
            }
            return $query;
        };

        foreach ($viewableUserTypes as $userType) {
            if ($role && $role !== $userType) {
                continue;
            }

            $modelClass = $this->getModelClass($userType);
            $userCollection = $applyFilters($modelClass::query())
                ->get()
                ->map(function ($user) use ($userType) {
                    $user->user_type = $userType;
                    return $user;
                });

            $users = $users->merge($userCollection);
        }

        // Pagination (manual collection pagination)
        $offset = ($page - 1) * $perPage;
        $paginatedUsers = $users->slice($offset, $perPage);

        $paginationData = [
            'data'         => $paginatedUsers->values(),
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $users->count(),
            'last_page'    => ceil($users->count() / $perPage),
        ];

        return response()->json([
            'status' => 'success',
            'data'   => $paginationData
        ]);
    }

    public function createUser(Request $request)
    {
        $authUser = $request->user('sanctum');
        $currentUserType = $this->getUserType($authUser);
        
        if (!$currentUserType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to determine user type'
            ], 403);
        }

        $userType = $request->input('user_type') ?? $request->input('role');
        $manageableTypes = $this->getManageableUserTypes($authUser);
        
        if (!in_array($userType, $manageableTypes)) {
            return response()->json([
                'status' => 'error',
                'message' => "You are not authorized to create {$userType} users. You can only create: " . implode(', ', $manageableTypes)
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name'   => 'required|string|max:255',
            'middle_name'  => 'nullable|string|max:255',
            'last_name'    => 'required|string|max:255',
            'username'     => 'required|string|max:255|unique:admins,username|unique:heads,username|unique:deputies,username|unique:enforcers,username',
            'office'       => 'required|string|max:255',
            'password'     => 'required|string|min:6',
            'status'       => 'required|in:activated,inactive,deactivated',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'user_type'    => 'required|in:' . implode(',', $manageableTypes),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $userData = [
            'first_name' => $request->first_name,
            'middle_name' => $request->middle_name,
            'last_name'  => $request->last_name,
            'username'   => $request->username,
            'office'     =>$request->office,
            'password'   => Hash::make($request->password),
            'status'     => $request->status,
        ];

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('profile_images', 'public');
            $userData['image'] = $path;
        }

        $modelClass = $this->getModelClass($userType);
        $user = $modelClass::create($userData);

        // Audit: user created
        $actorName = trim(($authUser->first_name ?? '') . ' ' . ($authUser->last_name ?? ''));
        $targetName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $targetTypeReadable = ucfirst($userType);
        AuditLogger::log(
            $authUser,
            'User Created',
            $targetTypeReadable,
            $user->id,
            $targetName,
            [],
            $request,
            "$actorName created $targetTypeReadable $targetName"
        );

        // If email is provided
        if (!empty($request->email)) {
            $user->email = $request->email;
            $user->save();

            try {
                $fullName = trim($user->first_name . ' ' . ($user->middle_name ? $user->middle_name . ' ' : '') . $user->last_name);
                $loginUrl = env('FRONTEND_LOGIN_URL', 'http://localhost:8080/login');
                
                Mail::to($user->email)->send(
                    new POSUEmail('welcome', [
                        'user_name' => $user->first_name,
                        'full_name' => $fullName,
                        'email' => $user->email,
                        'account_type' => $userType,
                        'registration_date' => now()->format('F j, Y'),
                        'login_url' => $loginUrl,
                        'temporary_password' => $request->password,
                    ])
                );
            } catch (\Exception $e) {
                Log::error('Failed to send welcome email: ' . $e->getMessage());
            }
        }

        // Remove password from response
        unset($user->password);

        return response()->json([
            'status' => 'success',
            'message' => 'User created successfully' . (!empty($request->email) ? ' and welcome email sent' : ''),
            'data' => array_merge($user->toArray(), ['user_type' => $userType])
        ], 201);
    }

    /**
     * Update user based on type
     */
    public function updateUser(Request $request, $userType, $id)
    {
        $authUser = $request->user('sanctum');

        if (!$this->canManageUserType($authUser, $userType)) {
            // Allow self-profile updates even if role cannot manage this type
            $selfType = $this->getUserType($authUser);
            if (!($selfType === $userType && (int) $id === (int) $authUser->id)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not authorized to update this type of user'
                ], 403);
            }
        }

        $modelClass = self::getModelClass($userType);
        $user = $modelClass::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'first_name'  => 'sometimes|string|max:255',
            'middle_name' => 'sometimes|nullable|string|max:255',
            'last_name'   => 'sometimes|string|max:255',
            'username'    => 'sometimes|string|max:255|unique:' . $user->getTable() . ',username,' . $id,
            'email'       => 'sometimes|email|unique:' . $user->getTable() . ',email,' . $id,
            'status'      => 'sometimes|in:activated,inactive,deactivated',
            'image'       => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('profile_images', 'public');
            $user->image = $path;
        }

        if ($request->has('first_name')) {
            $user->first_name = $request->first_name;
        }
        if ($request->has('middle_name')) {
            $user->middle_name = $request->middle_name;
        }
        if ($request->has('last_name')) {
            $user->last_name  = $request->last_name;
        }
        if ($request->has('email')) {
            $user->email      = $request->email;
        }
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        if ($request->has('status')) {
            $user->status = $request->status;
        }
        $user->save();

        // Audit: user updated
        $actorName = trim(($authUser->first_name ?? '') . ' ' . ($authUser->last_name ?? ''));
        $targetName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $targetTypeReadable = ucfirst($userType);
        AuditLogger::log(
            $authUser,
            'User Updated',
            $targetTypeReadable,
            $user->id,
            $targetName,
            [],
            $request,
            "$actorName updated $targetTypeReadable $targetName"
        );

        return response()->json(['status' => 'success', 'message' => 'User updated successfully', 'data' => $user]);
    }

    /* ==============================
     * ADVANCED REPORTS 
     * ============================== */
    
    public function generateAdvancedReport(Request $request)
    {
        // Validator
        $validator = Validator::make($request->all(), [
            'period' => 'required|in:today,yesterday,last_7_days,last_30_days,last_3_months,last_6_months,last_year,year_to_date,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after:start_date',
            'type' => 'nullable|string',
            'export_formats' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $dateRange = $this->calculateDateRange($request->period, $request->start_date, $request->end_date);

        // Get the current user for report attribution
        $actor = $request->user('sanctum');
        $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->middle_name ?? '') . ' ' . ($actor->last_name ?? ''));
        if ($actor->extension) {
            $actorName .= ' ' . $actor->extension;
        }
        $actorName = trim($actorName);

        // Helper to safely access array keys
        $getKey = fn($array, $key, $default = 'N/A') => is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;

        // Total Paid Penalty
        $totalPenalty = Transaction::where('status', 'Paid')
            ->whereBetween('date_time', [$dateRange['start'], $dateRange['end']])
            ->sum('fine_amount');

        // All Violators
        $allViolators = Transaction::with(['violator', 'violation', 'vehicle', 'apprehendingOfficer'])
            ->whereBetween('date_time', [$dateRange['start'], $dateRange['end']])
            ->get()
            ->map(function ($tx) {
                $violator = $tx->violator;
                $vehicle = $tx->vehicle;
                $officer = $tx->apprehendingOfficer;

                $violatorName = $violator 
                    ? trim(($violator->first_name ?? '') . ' ' . ($violator->middle_name ?? '') . ' ' . ($violator->last_name ?? '')) 
                    : 'N/A';

                $violatorAddress = $violator 
                    ? trim(($violator->barangay ?? '') . ' ' . ($violator->city ?? '') . ', ' . ($violator->province ?? '')) 
                    : 'N/A';

                $ownerName = $vehicle 
                    ? trim(($vehicle->owner_first_name ?? '') . ' ' . ($vehicle->owner_middle_name ?? '') . ' ' . ($vehicle->owner_last_name ?? '')) 
                    : 'N/A';

                $ownerAddress = $vehicle 
                    ? trim(($vehicle->owner_barangay ?? '') . ' ' . ($vehicle->owner_city ?? '') . ', ' . ($vehicle->owner_province ?? '')) 
                    : 'N/A';

                $officerName = $officer 
                    ? trim(($officer->first_name ?? '') . ' ' . ($officer->middle_name ?? '') . ' ' . ($officer->last_name ?? '')) 
                    : 'N/A';

                return [
                    'violator_name'    => $violatorName,
                    'violator_address' => $violatorAddress,
                    'violation'        => $tx->violation->name ?? 'N/A',
                    'owner_name'       => $ownerName,
                    'owner_address'    => $ownerAddress,
                    'vehicle_type'     => $vehicle->vehicle_type ?? 'N/A',
                    'vehicle_make'     => $vehicle->make ?? 'N/A',
                    'vehicle_model'    => $vehicle->model ?? 'N/A',
                    'plate_number'         => $vehicle->plate_number ?? 'N/A',
                    'ticket_no'        => $tx->ticket_number ?? 'N/A',
                    'ticket_date'      => $tx->date_time ? $tx->date_time->format('F j, Y') : 'N/A',
                    'ticket_time'      => $tx->date_time ? $tx->date_time->format('g:i A') : 'N/A',
                    'officer_name'     => $officerName,
                    'officer_office'   => $officer->office ?? 'N/A',
                    'remarks'          => $tx->status ?? 'N/A',
                    'penalty_amount'   => (float) ($tx->fine_amount ?? 0),
                ];
            })->values();

        // Violations Mapping
        $violations = Violation::pluck('name', 'id')->toArray();

        // Common Violations
        $commonViolations = Transaction::select('violation_id')
            ->whereBetween('date_time', [$dateRange['start'], $dateRange['end']])
            ->groupBy('violation_id')
            ->selectRaw('violation_id, COUNT(*) as count')
            ->orderByDesc('count')
            ->take(10)
            ->get()
            ->map(function ($item) use ($violations) {
                return [
                    'violation_id'   => $item->violation_id,
                    'violation_name' => $violations[$item->violation_id] ?? 'N/A',
                    'count'          => (int) ($item->count ?? 0),
                ];
            })->values();

        // Enforcer Performance
        $enforcerPerformance = Enforcer::with(['transactions' => function ($q) use ($dateRange) {
                $q->whereBetween('date_time', [$dateRange['start'], $dateRange['end']]);
            }])
            ->get()
            ->map(function ($enforcer) {
                $transactions = $enforcer->transactions;
                $totalTransactions = $transactions->count();
                $paidTransactions = $transactions->where('status', 'Paid')->count();

                return [
                    'enforcer_name'     => trim(($enforcer->first_name ?? '') . ' ' . ($enforcer->last_name ?? '')),
                    'violations_issued' => $totalTransactions,
                    'collection_rate'   => $totalTransactions > 0 ? round(($paidTransactions / $totalTransactions) * 100, 1) : 0,
                    'total_fines'       => (float) $transactions->sum('fine_amount'),
                ];
            })
            ->filter(fn($item) => $item['violations_issued'] > 0)
            ->values();

        $type = $request->input('type');
        $reportMap = [
            'total_revenue'        => [['Total Revenue' => (float) $totalPenalty]],
            'all_violators'        => $allViolators->toArray(),
            'common_violations'    => $commonViolations->toArray(),
            'enforcer_performance' => $enforcerPerformance->toArray(),
        ];

        $selectedData = $type && isset($reportMap[$type]) ? $reportMap[$type] : $reportMap;

        // Prepare Rows for Export safely
        $rows = [];
        if ($type === 'all_violators') {
            $rows = $allViolators->map(fn($item) => [
                'Violator Name'    => $getKey($item, 'violator_name'),
                'Violator Address' => $getKey($item, 'violator_address'),
                'Violation Name'   => $getKey($item, 'violation'),
                'Owner Name'       => $getKey($item, 'owner_name'),
                'Owner Address'    => $getKey($item, 'owner_address'),
                'Vehicle Type'     => $getKey($item, 'vehicle_type'),
                'Vehicle Make'     => $getKey($item, 'vehicle_make'),
                'Vehicle Model'    => $getKey($item, 'vehicle_model'),
                'Plate Number'     => $getKey($item, 'plate_number'),
                'Ticket Number'    => $getKey($item, 'ticket_no'),
                'Ticket Date'      => $getKey($item, 'ticket_date'),
                'Ticket Time'      => $getKey($item, 'ticket_time'),
                'Officer Name'     => $getKey($item, 'officer_name'),
                'Officer Office'   => $getKey($item, 'officer_office'),
                'Remarks'          => $getKey($item, 'remarks', ''),
                'Penalty Amount'   => $getKey($item, 'penalty_amount', 0),
            ])->toArray();
        } elseif ($type === 'common_violations') {
            $rows = $commonViolations->map(fn($item) => [
                'ID'             => $getKey($item, 'violation_id'),
                'Violation Name' => $getKey($item, 'violation_name'),
                'Count'          => $getKey($item, 'count', 0),
            ])->toArray();
        } elseif ($type === 'enforcer_performance') {
            $rows = $enforcerPerformance->map(fn($item) => [
                'Enforcer Name'       => $getKey($item, 'enforcer_name'),
                'Violations Issued'   => $getKey($item, 'violations_issued', 0),
                'Collection Rate (%)' => $getKey($item, 'collection_rate', 0),
                'Total Fines'         => $getKey($item, 'total_fines', 0),
            ])->toArray();
        } elseif ($type === 'total_revenue') {
            $rows = [['Total Revenue' => (float) $totalPenalty]];
        }

        // Export logic (Excel, Word, PDF)
        $timestamp = now()->format('Ymd_His');
        $files = [];
        $formats = (array) $request->input('export_formats', []);
        foreach ($formats as $format) {
            if ($format === 'excel') {
                $export = new ArrayExport($rows);
                $filename = ($type ?: 'report') . "_{$timestamp}.xlsx";
                Excel::store($export, "reports/{$filename}", 'public');

                $files[] = [
                    'filename' => $filename,
                    'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'url' => route('download.report', ['filename' => $filename]),
                    'path' => "reports/{$filename}",
                ];
            } elseif ($format === 'word') {
                $html = view('reports.simple', [
                    'type' => $type ?: 'combined',
                    'period' => $request->period,
                    'rows' => $rows,
                    'totalPenalty' => $totalPenalty,
                    'dateRange' => $dateRange,
                    'noted_by' => '',
                    'prepared_by' => $actorName,
                ])->render();

                $pdfPath = storage_path("app/report_{$timestamp}.pdf");
                Pdf::loadHTML($html)->save($pdfPath);

                $docxPath = $this->convertPdfToWordCloudmersive($pdfPath);
                $binary = file_get_contents($docxPath);
                $storedPath = 'reports/' . ($type ?: 'report') . '_' . $timestamp . '.docx';
                Storage::disk('public')->put($storedPath, $binary);

                $files[] = [
                    'filename' => ($type ?: 'report') . '_' . $timestamp . '.docx',
                    'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'url' => route('download.report', ['filename' => ($type ?: 'report') . '_' . $timestamp . '.docx']),
                    'path' => $storedPath,
                ];

                @unlink($docxPath);
                @unlink($pdfPath);
            } elseif ($format === 'pdf') {
                $html = view('reports.simple', [
                    'type' => $type ?: 'combined',
                    'period' => $request->period,
                    'rows' => $rows,
                    'totalPenalty' => $totalPenalty,
                    'dateRange' => $dateRange,
                    'noted_by' => '',
                    'prepared_by' => $actorName,
                ])->render();

                $binary = Pdf::loadHTML($html)->output();
                $storedPath = 'reports/' . ($type ?: 'report') . '_' . $timestamp . '.pdf';
                Storage::disk('public')->put($storedPath, $binary);

                $files[] = [
                    'filename' => ($type ?: 'report') . '_' . $timestamp . '.pdf',
                    'mimeType' => 'application/pdf',
                    'url' => route('download.report', ['filename' => ($type ?: 'report') . '_' . $timestamp . '.pdf']),
                    'path' => $storedPath,
                ];
            }
        }

        // Store report
        $report = Report::create([
            'type' => $type ?: 'combined',
            'period' => $request->period,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'report_content' => $selectedData,
            'summary' => [
                'total_penalty' => (float) $totalPenalty,
                'total_violators' => $allViolators->count(),
                'common_violations' => $commonViolations->toArray(),
                'enforcer_performance' => $enforcerPerformance->toArray(),
            ],
            'files' => $files,
            'generated_by_type' => class_basename($actor),
            'generated_by_id' => $actor->id,
            'noted_by_name' => '',
            'prepared_by_name' => $actorName,
        ]);

        // Audit: report generated
        $desc = "$actorName generated a '{$report->type}' report for period '{$request->period}'";
        AuditLogger::log(
            $actor,
            'Report Generated',
            'Report',
            $report->id,
            $report->type,
            [],
            $request,
            $desc
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'report' => $selectedData,
                'files' => $files,
                'report_id' => $report->id,
            ]
        ]);
    }


    public function convertPdfToWordCloudmersive($pdfPath)
    {
        $config = Configuration::getDefaultConfiguration()->setApiKey(
            'Apikey',
            env('CLOUDMERSIVE_API_KEY')
        );

        $apiInstance = new ConvertDocumentApi(new Client(), $config);

        try {
            $inputFile = new \SplFileObject($pdfPath);
            $result = $apiInstance->convertDocumentPdfToDocx($inputFile);

            $filename = storage_path('app/public/report_' . time() . '.docx');
            file_put_contents($filename, $result);
            return $filename;

        } catch (\Exception $e) {
            throw new \Exception('Cloudmersive conversion failed: ' . $e->getMessage());
        }
    }

    public function previewReport(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'period' => 'required|in:today,yesterday,last_7_days,last_30_days,last_3_months,last_6_months,last_year,year_to_date,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after:start_date',
            'limit' => 'sometimes|integer|min:1|max:1000',
            'per_page' => 'sometimes|integer|min:1|max:1000',
            'page_size' => 'sometimes|integer|min:1|max:1000',
        ]);

        // Get date range based on period
        $dateRange = $this->calculateDateRange($request->period, $request->start_date, $request->end_date);

        // Get pagination parameters
        $limit = $request->input('limit', $request->input('per_page', $request->input('page_size', 100)));

        // Get data based on type
        $type = $request->input('type');
        $data = $this->getReportData($type, $dateRange, $limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => $type,
                'period' => $request->period,
                'date_range' => $dateRange,
                'preview_data' => $data,
                'total_records' => is_array($data) ? count($data) : 0,
            ]
        ]);
    }

    private function getReportData($type, $dateRange, $limit = 100)
    {
        switch ($type) {
            case 'all_violators':
                return $this->getAllViolatorsData($dateRange, $limit);
            case 'common_violations':
                return $this->getCommonViolationsData($dateRange, $limit);
            case 'enforcer_performance':
                return $this->getEnforcerPerformanceData($dateRange, $limit);
            case 'total_revenue':
                return $this->getTotalRevenueData($dateRange, $limit);
            default:
                return [];
        }
    }

    private function getAllViolatorsData($dateRange, $limit = 100)
    {
        return Transaction::with(['violator', 'violation', 'vehicle', 'apprehendingOfficer'])
            ->whereBetween('date_time', [$dateRange['start'], $dateRange['end']])
            ->get()
            ->map(function ($transaction) {
                return [
                    'Violator Name' => $transaction->violator ? trim(($transaction->violator->first_name ?? '') . ' ' . ($transaction->violator->middle_name ?? '') . ' ' . ($transaction->violator->last_name ?? '')) : 'N/A',
                    'Violation Name' => $transaction->violation->name ?? 'N/A',
                    'Penalty Amount' => $transaction->fine_amount ?? 0,
                    'Ticket Date' => $transaction->date_time ? $transaction->date_time->format('Y-m-d') : 'N/A',
                    'Status' => $transaction->status ?? 'N/A',
                ];
            })
            ->take($limit)
            ->toArray();
    }

    private function getCommonViolationsData($dateRange, $limit = 100)
    {
        return Violation::withCount(['transactions' => function ($query) use ($dateRange) {
            $query->whereBetween('date_time', [$dateRange['start'], $dateRange['end']]);
        }])
            ->orderBy('transactions_count', 'desc')
            ->take($limit)
            ->get()
            ->map(function ($violation) {
                return [
                    'Violation Name' => $violation->name,
                    'Count' => $violation->transactions_count,
                ];
            })
            ->toArray();
    }

    private function getEnforcerPerformanceData($dateRange, $limit = 100)
    {
        return Enforcer::with(['transactions' => function ($q) use ($dateRange) {
            $q->whereBetween('date_time', [$dateRange['start'], $dateRange['end']]);
        }])
            ->get()
            ->map(function ($enforcer) {
                $transactions = $enforcer->transactions;
                $totalTransactions = $transactions->count();
                $paidTransactions = $transactions->where('status', 'Paid')->count();

                return [
                    'Enforcer Name' => trim(($enforcer->first_name ?? '') . ' ' . ($enforcer->last_name ?? '')),
                    'Violations Issued' => $totalTransactions,
                    'Collection Rate (%)' => $totalTransactions > 0 ? round(($paidTransactions / $totalTransactions) * 100, 1) : 0,
                    'Total Fines' => (float) $transactions->sum('fine_amount'),
                ];
            })
            ->filter(fn($item) => $item['Violations Issued'] > 0)
            ->take($limit)
            ->values()
            ->toArray();
    }

    private function getTotalRevenueData($dateRange, $limit = 100)
    {
        $totalPenalty = Transaction::whereBetween('date_time', [$dateRange['start'], $dateRange['end']])
            ->sum('fine_amount');

        return [['Total Revenue' => (float) $totalPenalty]];
    }

    public function getReportHistory(Request $request)
    {
        $includeDeleted = $request->query('include_deleted', false);
        $perPage = $request->query('per_page', 15);
        $type = $request->query('type');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = $includeDeleted
            ? Report::withTrashed()->latest()
            : Report::latest();

        // Apply filters
        if ($type) {
            $query->where('type', $type);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $reports = $query->paginate($perPage);

        return response()->json([
            'data' => $reports->items(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'from' => $reports->firstItem(),
                'to' => $reports->lastItem(),
            ]
        ]);
    }

    public function restoreReport($id)
    {
        $report = Report::withTrashed()->findOrFail($id);

        if ($report->trashed()) {
            $report->restore();
            return response()->json([
                'message' => 'Report restored successfully.',
                'data' => $report
            ]);
        }

        return response()->json([
            'message' => 'Report is not deleted.'
        ], 400);
    }

    public function clearReportHistory()
    {
        $reports = Report::all();

        foreach ($reports as $report) {
            if ($report->files) {
                foreach ($report->files as $file) {
                    if (isset($file['path']) && Storage::exists($file['path'])) {
                        Storage::delete($file['path']);
                    }
                }
            }
        }
        Report::query()->delete(); 

        // Audit: reports cleared
        $actor = request()->user('sanctum');
        $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));
        AuditLogger::log(
            $actor,
            'Report History Cleared',
            'Report',
            null,
            null,
            [],
            request(),
            "$actorName cleared the report history"
        );

        return response()->json([
            'message' => 'Report history cleared successfully (files deleted).'
        ]);
    }

    /**
     * Calculate start and end datetime for a period.
     */
    private function calculateDateRange($period, $start = null, $end = null)
    {
        switch ($period) {
            case 'today':
                return ['start' => now()->startOfDay(), 'end' => now()->endOfDay()];
            case 'yesterday':
                return ['start' => now()->subDay()->startOfDay(), 'end' => now()->subDay()->endOfDay()];
            case 'last_7_days':
                return ['start' => now()->subDays(6)->startOfDay(), 'end' => now()->endOfDay()];
            case 'last_30_days':
                return ['start' => now()->subDays(29)->startOfDay(), 'end' => now()->endOfDay()];
            case 'last_3_months':
                return ['start' => now()->subMonths(3)->startOfDay(), 'end' => now()->endOfDay()];
            case 'last_6_months':
                return ['start' => now()->subMonths(6)->startOfDay(), 'end' => now()->endOfDay()];
            case 'last_year':
                return ['start' => now()->subYear()->startOfDay(), 'end' => now()->endOfDay()];
            case 'year_to_date':
                return ['start' => now()->startOfYear(), 'end' => now()];
            case 'custom':
                return ['start' => Carbon::parse($start)->startOfDay(), 'end' => Carbon::parse($end)->endOfDay()];
            default:
                return ['start' => now()->subDays(6)->startOfDay(), 'end' => now()->endOfDay()];
        }
    }

    /**
     * Archive user based on type
     */
    public function archiveUser($userType, $id)
    {
        $authUser = request()->user('sanctum');

        // Check if authenticated user can archive this type of user
        if (!$this->canManageUserType($authUser, $userType)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to archive this type of user'
            ], 403);
        }

        $currentUserType = $this->getUserType($authUser);
        $manageableTypes = $authUser->getManageableUserTypes($authUser);
        
        if (!in_array($userType, $manageableTypes)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid user type'], 400);
        }

        $modelClass = $this->getModelClass($userType);
        $user = $modelClass::findOrFail($id);
        $user->delete();

        // Audit: user archived
        $actorName = trim(($authUser->first_name ?? '') . ' ' . ($authUser->last_name ?? ''));
        $targetName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $targetTypeReadable = ucfirst($userType);
        AuditLogger::log(
            $authUser,
            'User Archived',
            $targetTypeReadable,
            $user->id,
            $targetName,
            [],
            request(),
            "$actorName archived $targetTypeReadable $targetName"
        );

        return response()->json(['status' => 'success', 'message' => 'User archived successfully']);
    }

    /**
     * Get archived users
     */
    public function getArchivedUsers(Request $request)
    {
        $authUser = $request->user('sanctum');
        $currentUserType = $this->getUserType($authUser);
        $userType = $request->input('user_type', 'all');
        $search = $request->input('search');
        $users = collect();

        $applyFilters = function($query) use ($search) {
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            return $query;
        };

        $manageableTypes = $authUser->getManageableUserTypes($authUser);
        
        foreach ($manageableTypes as $type) {
            if ($userType === 'all' || $userType === $type) {
                $modelClass = $this->getModelClass($type);
                $archivedUsers = $applyFilters($modelClass::onlyTrashed())->get()->map(function($user) use ($type) {
                    $user->user_type = $type;
                    return $user;
                });
                $users = $users->merge($archivedUsers);
            }
        }

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    /**
     * Restore user
     */
    public function restoreUser($userType, $id)
    {
        $authUser = request()->user('sanctum');

        // Check if authenticated user can restore this type of user
        if (!$this->canManageUserType($authUser, $userType)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to restore this type of user'
            ], 403);
        }

        $currentUserType = $this->getUserType($authUser);
        $manageableTypes = $authUser->getManageableUserTypes($authUser);
        
        if (!in_array($userType, $manageableTypes)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid user type'], 400);
        }

        $modelClass = $this->getModelClass($userType);
        $user = $modelClass::onlyTrashed()->findOrFail($id);
        $user->restore();

        // Audit: user restored
        $actorName = trim(($authUser->first_name ?? '') . ' ' . ($authUser->last_name ?? ''));
        $targetName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $targetTypeReadable = ucfirst($userType);
        AuditLogger::log(
            $authUser,
            'User Restored',
            $targetTypeReadable,
            $user->id,
            $targetName,
            [],
            request(),
            "$actorName restored $targetTypeReadable $targetName"
        );

        return response()->json(['status' => 'success', 'message' => 'User restored successfully', 'data' => $user]);
    }

    /**
     * Toggle user status
     */
    public function toggleUserStatus(Request $request)
    {
        $authUser = $request->user('sanctum');
        $currentUserType = $this->getUserType($authUser);
        $manageableTypes = $authUser->getManageableUserTypes($authUser);

        $validator = Validator::make($request->all(), [
            'user_type' => 'required|in:' . implode(',', $manageableTypes),
            'id' => 'required|integer',
            'status' => 'required|in:activated,inactive,deactivated',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        if (!$this->canManageUserType($authUser, $request->user_type)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to change the status of this type of user'
            ], 403);
        }

        $modelClass = $this->getModelClass($request->user_type);
        $user = $modelClass::findOrFail($request->id);

        $user->status = $request->status;
        $user->save();

        // Audit: user status toggled
        $actorName = trim(($authUser->first_name ?? '') . ' ' . ($authUser->last_name ?? ''));
        $targetName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $targetTypeReadable = ucfirst($request->user_type);
        AuditLogger::log(
            $authUser,
            'User Status Changed',
            $targetTypeReadable,
            $user->id,
            $targetName,
            [],
            $request,
            "$actorName changed status of $targetTypeReadable $targetName to {$request->status}"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User status updated successfully',
            'data' => $user
        ]);
    }

    /* ==============================
     * VIOLATORS MANAGEMENT
     * ============================== */

    public function getViolators(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $page    = $request->input('page', 1);

        $name           = trim((string) $request->input('name', ''));
        $address        = trim((string) $request->input('address', ''));
        $mobileNumber   = trim((string) $request->input('mobile_number', ''));
        $gender         = $request->input('gender', ''); // '' | 0 | 1
        $professional   = $request->input('professional', ''); // '' | 0 | 1
        $licenseNumber  = trim((string) $request->input('license_number', ''));

        $query = Violator::query()
            ->whereHas('transactions')
            ->withCount('transactions')
            ->withSum('transactions', 'fine_amount')
            ->with(['transactions.violation', 'transactions.vehicle', 'transactions.apprehendingOfficer'])
            ->orderBy('id', 'desc');

        if ($name !== '') {
            $query->where(function ($q) use ($name) {
                $q->where('first_name', 'like', "%{$name}%")
                  ->orWhere('middle_name', 'like', "%{$name}%")
                  ->orWhere('last_name', 'like', "%{$name}%")
                  ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", ["%{$name}%"]);
            });
        }

        if ($address !== '') {
            $query->where(function ($q) use ($address) {
                $q->where('barangay', 'like', "%{$address}%")
                  ->orWhere('city', 'like', "%{$address}%")
                  ->orWhere('province', 'like', "%{$address}%");
            });
        }

        if ($mobileNumber !== '') {
            $query->where('mobile_number', 'like', "%{$mobileNumber}%");
        }

        if ($gender !== '' && $gender !== null) {
            // Accept '0'/'1' or boolean
            $query->where('gender', filter_var($gender, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (int) $gender);
        }

        if ($professional !== '' && $professional !== null) {
            $query->where('professional', filter_var($professional, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (int) $professional);
        }

        if ($licenseNumber !== '') {
            $query->where('license_number', 'like', "%{$licenseNumber}%");
        }

        $violators = $query->paginate($perPage, ['*'], 'page', $page);

        $violators->getCollection()->transform(function ($violator) {
            $violator->total_amount = $violator->transactions_sum_fine_amount ?? 0;
            unset($violator->transactions_sum_fine_amount);
            return $violator;
        });

        return response()->json(['status' => 'success', 'data' => $violators]);
    }

    public function updateViolator(Request $request)
    {   
        $id = $request->id;
        $violator = Violator::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'first_name'     => 'required|string|max:255',
            'middle_name'    => 'nullable|string|max:255',
            'last_name'      => 'required|string|max:255',
            'email'          => 'nullable|email|unique:violators,email,' . $id,
            'mobile_number'  => 'required|string|max:20',
            'gender'         => 'required|boolean',
            'license_number' => 'nullable|string|max:50',
            'barangay'       => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'province'       => 'nullable|string|max:100',
            'professional'   => 'nullable|boolean',
            'payment_status' => 'nullable|in:Pending,Paid',
            'password'       => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $violator->update($request->only([
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'mobile_number',
            'gender',
            'license_number',
            'barangay',
            'city',
            'province',
            'professional',
        ]));

        if ($request->filled('password')) {
        $data['password'] = bcrypt($request->password);
        }

        $violator['gender'] = (bool) $violator['gender'];
        $violator['professional'] = (bool) $violator['professional'];

        if ($request->has('status')) {
            $violator->transactions()->update(['status' => $request->status]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Violator and all transactions updated successfully',
            'data' => $violator
        ]);
    }

    /* ==============================
     * VEHICLES MANAGEMENT
     * ============================== */

    public function getVehicles(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $page    = $request->input('page', 1);

        $ownerName   = trim((string) $request->input('owner_name', ''));
        $plateNumber = trim((string) $request->input('plate_number', ''));
        $make        = trim((string) $request->input('make', ''));
        $model       = trim((string) $request->input('model', ''));
        $color       = trim((string) $request->input('color', ''));
        $vehicleType = trim((string) $request->input('vehicle_type', ''));

        $query = Vehicle::with('violator')->orderBy('id', 'desc');

        if ($ownerName !== '') {
            $query->where(function ($q) use ($ownerName) {
                $q->where('owner_first_name', 'like', "%{$ownerName}%")
                  ->orWhere('owner_middle_name', 'like', "%{$ownerName}%")
                  ->orWhere('owner_last_name', 'like', "%{$ownerName}%")
                  ->orWhereRaw("CONCAT_WS(' ', owner_first_name, owner_middle_name, owner_last_name) LIKE ?", ["%{$ownerName}%"]) 
                  ->orWhereHas('violator', function ($vq) use ($ownerName) {
                      $vq->where('first_name', 'like', "%{$ownerName}%")
                         ->orWhere('middle_name', 'like', "%{$ownerName}%")
                         ->orWhere('last_name', 'like', "%{$ownerName}%")
                         ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", ["%{$ownerName}%"]);
                  });
            });
        }

        if ($plateNumber !== '') {
            $query->where('plate_number', 'like', "%{$plateNumber}%");
        }

        if ($make !== '') {
            $query->where('make', 'like', "%{$make}%");
        }

        if ($model !== '') {
            $query->where('model', 'like', "%{$model}%");
        }

        if ($color !== '') {
            $query->where('color', 'like', "%{$color}%");
        }

        if ($vehicleType !== '') {
            $query->where('vehicle_type', $vehicleType);
        }

        $vehicles = $query->paginate($perPage, ['*'], 'page', $page);

        $vehicles->getCollection()->transform(function ($vehicle) {
            if ($vehicle->violator) {
                $vehicle->owner_full_name = $vehicle->violator->full_name ?? $vehicle->ownerName();
            } else {
                $vehicle->owner_full_name = $vehicle->ownerName();
            }
            return $vehicle;
        });

        return response()->json([
            'status' => 'success',
            'data'   => $vehicles
        ]);
    }

    public function updateVehicle(Request $request, $id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehicle not found.'
            ], 404);
        }

        // Validate incoming data
        $validated = $request->validate([
            'owner_first_name' => 'sometimes|string|max:255',
            'owner_middle_name' => 'sometimes|string|max:255|nullable',
            'owner_last_name' => 'sometimes|string|max:255',
            'plate_number' => 'sometimes|string|max:50',
            'make' => 'sometimes|string|max:255',
            'model' => 'sometimes|string|max:255',
            'color' => 'sometimes|string|max:50',
            'owner_barangay' => 'sometimes|string|max:255|nullable',
            'owner_city' => 'sometimes|string|max:255|nullable',
            'owner_province' => 'sometimes|string|max:255|nullable',
            'vehicle_type' => 'sometimes|string|max:50',
        ]);

        // Update vehicle
        $vehicle->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle updated successfully',
            'data' => $vehicle
        ]);
    }


    /* ==============================
     * VIOLATIONS MANAGEMENT
     * ============================== */

    public function getViolations()
    {
        $violations = Violation::withCount('transactions')->get();

        return response()->json(['status' => 'success', 'data' => $violations]);
    }
    public function getViolation($id)
    {
        $violation = Violation::withCount('transactions')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $violation
        ]);
    }

    public function createViolation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:100',
            'description' => 'required|string',
            'fine_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $violation = Violation::create($request->all());

        // Audit: violation created
        $actor = $request->user('sanctum');
        $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));
        AuditLogger::log(
            $actor,
            'Violation Created',
            'Violation',
            $violation->id,
            $violation->name,
            [],
            $request,
            "$actorName created violation '{$violation->name}'"
        );

        return response()->json(['status' => 'success', 'message' => 'Violation created', 'data' => $violation], 201);
    }

    public function updateViolation(Request $request, $id)
    {
        $violation = Violation::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:100',
            'description' => 'required|string',
            'fine_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $violation->update($request->all());

        // Audit: violation updated
        $actor = $request->user('sanctum');
        $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));
        AuditLogger::log(
            $actor,
            'Violation Updated',
            'Violation',
            $violation->id,
            $violation->name,
            [],
            $request,
            "$actorName updated violation '{$violation->name}'"
        );

        return response()->json(['status' => 'success', 'message' => 'Violation updated', 'data' => $violation]);
    }

    

    /* ==============================
     * TRANSACTIONS 
     * ============================== */

    public function getTransactions(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            $page    = $request->input('page', 1);

            $search        = trim((string) $request->input('search', ''));
            $violationId   = $request->input('violation_id');
            $vehicleType   = trim((string) $request->input('vehicle_type', ''));
            $repeat        = $request->input('repeat_offender', ''); // '' | true | false
            $address       = trim((string) $request->input('address', ''));
            $dateRange     = trim((string) $request->input('dateRange', ''));
            $dateFrom      = $request->input('dateFrom');
            $dateTo        = $request->input('dateTo');

            $transactions = Transaction::with([
            'violator' => function ($q) {
                $q->withCount('transactions');
            },
            'violation',
            'violations',
            'vehicle',
            'apprehendingOfficer'
        ])->orderBy('id', 'desc');

        if ($search !== '') {
            $transactions->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  // Violator name (license_number removed - it's encrypted)
                  ->orWhereHas('violator', function ($vq) use ($search) {
                      $vq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('middle_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", ["%{$search}%"]);
                  })
                  // Apprehending officer name
                  ->orWhereHas('apprehendingOfficer', function ($oq) use ($search) {
                      $oq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('middle_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('username', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", ["%{$search}%"]);
                  })
                  // Vehicle fields and owner name (plate_number removed - it's encrypted)
                  ->orWhereHas('vehicle', function ($vq) use ($search) {
                      $vq->where('make', 'like', "%{$search}%")
                         ->orWhere('model', 'like', "%{$search}%")
                         ->orWhere('color', 'like', "%{$search}%")
                         ->orWhere('owner_first_name', 'like', "%{$search}%")
                         ->orWhere('owner_middle_name', 'like', "%{$search}%")
                         ->orWhere('owner_last_name', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT_WS(' ', owner_first_name, owner_middle_name, owner_last_name) LIKE ?", ["%{$search}%"]);
                  })
                  // Violation names (single and multiple)
                  ->orWhereHas('violation', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('violations', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if (!empty($violationId)) {
            $violationId = (int) $violationId;
            $transactions->where(function ($q) use ($violationId) {
                $q->where('violation_id', $violationId)
                  ->orWhereHas('violations', function ($vq) use ($violationId) {
                      $vq->where('violations.id', $violationId);
                  });
            });
        }

        if ($vehicleType !== '') {
            $transactions->whereHas('vehicle', function ($vq) use ($vehicleType) {
                $vq->where('vehicle_type', $vehicleType);
            });
        }

        if ($address !== '') {
            $transactions->whereHas('violator', function ($vq) use ($address) {
                $vq->where('barangay', 'like', "%{$address}%")
                   ->orWhere('city', 'like', "%{$address}%")
                   ->orWhere('province', 'like', "%{$address}%");
            });
        }

        if ($repeat !== '' && $repeat !== null) {
            $isRepeat = filter_var($repeat, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isRepeat === true) {
                $transactions->whereHas('violator', function ($vq) {
                    $vq->has('transactions', '>=', 2);
                });
            } elseif ($isRepeat === false) {
                $transactions->whereHas('violator', function ($vq) {
                    $vq->has('transactions', '<=', 1);
                });
            }
        }

        // Date filters
        if (!empty($dateFrom) && !empty($dateTo)) {
            $from = Carbon::parse($dateFrom)->startOfDay();
            $to   = Carbon::parse($dateTo)->endOfDay();
            $transactions->whereBetween('date_time', [$from, $to]);
        } elseif ($dateRange !== '') {
            $now = now();
            if ($dateRange === 'today') {
                $transactions->whereDate('date_time', $now->toDateString());
            } elseif ($dateRange === 'week') {
                $transactions->whereBetween('date_time', [$now->copy()->subDays(6)->startOfDay(), $now->endOfDay()]);
            } elseif ($dateRange === 'month') {
                $transactions->whereYear('date_time', $now->year)
                             ->whereMonth('date_time', $now->month);
            }
        }

        $transactions = $transactions->paginate($perPage, ['*'], 'page', $page);

        $totalsByViolator = Transaction::selectRaw('violator_id, SUM(fine_amount) as total_amount')
            ->groupBy('violator_id')
            ->pluck('total_amount', 'violator_id');

        $transactions->getCollection()->transform(function ($transaction) use ($totalsByViolator) {
            if ($transaction->violator) {
                $transaction->violator->total_amount = $totalsByViolator[$transaction->violator->id] ?? 0;
            }

            // Process location field - convert generic locations to better names
            $locationName = trim($transaction->location ?? '');
            if (empty($locationName) || 
                $locationName === 'GPS Location' || 
                $locationName === 'Unknown Location' ||
                strpos($locationName, 'Location at') === 0) {
                
                // If we have GPS coordinates, try to generate a better name
                if ($transaction->gps_latitude && $transaction->gps_longitude) {
                    $lat = round($transaction->gps_latitude, 4);
                    $lng = round($transaction->gps_longitude, 4);
                    $transaction->location = $this->getBetterLocationName($lat, $lng);
                }
            }

            // Compute attempt number for this specific transaction (1 for first, 2 for second, ...)
            try {
                $transaction->attempt_number = Transaction::where('violator_id', $transaction->violator_id)
                    ->where(function($q) use ($transaction) {
                        $q->where('date_time', '<', $transaction->date_time)
                          ->orWhere(function($q2) use ($transaction) {
                              $q2->where('date_time', $transaction->date_time)
                                 ->where('id', '<=', $transaction->id);
                          });
                    })
                    ->count();
                // Count returns number of earlier-or-equal rows; attempt is that count (>=1)
                if ($transaction->attempt_number < 1) {
                    $transaction->attempt_number = 1;
                }
            } catch (\Throwable $e) {
                $transaction->attempt_number = 1;
            }

            return $transaction;
        });

        return response()->json(['status' => 'success', 'data' => $transactions]);
        
        } catch (\Exception $e) {
            Log::error('Error in getTransactions: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateTransaction(Request $request, $id)
    {
        $request->validate([
            'status' => 'nullable|string|in:Paid,Pending',
        ]);

        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found.'
            ], 404);
        }

        $previousStatus = $transaction->status;
        $transaction->status = $request->input('status', 'Paid');
        $transaction->save();

        // Load relationships for email
        $transaction->load(['violator', 'violation', 'vehicle']);

        // Send payment confirmation email if marked as paid and violator has email
        if ($transaction->status === 'Paid' && $previousStatus !== 'Paid' && $transaction->violator && $transaction->violator->email) {
            try {
                $violatorName = trim($transaction->violator->first_name . ' ' . ($transaction->violator->middle_name ? $transaction->violator->middle_name . ' ' : '') . $transaction->violator->last_name);
                $vehicleInfo = $transaction->vehicle ? $transaction->vehicle->make . ' ' . $transaction->vehicle->model . ' (' . $transaction->vehicle->color . ')' : 'N/A';
                
                Mail::to($transaction->violator->email)->send(
                    new POSUEmail('payment_confirmation', [
                        'violator_name' => $violatorName,
                        'ticket_number' => $transaction->ticket_number ?? 'CT-' . date('Y') . '-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
                        'violation_type' => $transaction->violation->name,
                        'fine_amount' => $transaction->fine_amount,
                        'payment_date' => now()->format('F j, Y'),
                        'payment_datetime' => now()->format('F j, Y - g:i A'),
                        'violation_date' => $transaction->date_time->format('F j, Y'),
                        'location' => $transaction->location,
                        'license_number' => $transaction->violator->license_number,
                        'vehicle_info' => $vehicleInfo,
                        'plate_number' => $transaction->vehicle ? $transaction->vehicle->plate_number : 'N/A',
                        'login_url' => 'https://posuechague.site/login',
                    ])
                );
            } catch (\Exception $emailError) {
                Log::error('Failed to send payment confirmation email: ' . $emailError->getMessage());
            }
        }

        // Audit: transaction updated
        $actor = $request->user('sanctum');
        $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''));
        $ticketLabel = 'Ticket #' . ($transaction->ticket_number ?? $transaction->id);
        $verb = $transaction->status === 'Paid' ? 'marked as Paid' : 'updated';
        AuditLogger::log(
            $actor,
            'Transaction Updated',
            'Transaction',
            $transaction->id,
            $ticketLabel,
            [],
            $request,
            "$actorName $verb $ticketLabel"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction updated successfully' . ($transaction->status === 'Paid' && $previousStatus !== 'Paid' && $transaction->violator && $transaction->violator->email ? ' and payment confirmation email sent' : '') . '.',
            'data' => $transaction
        ]);
    }

    /* ==============================
     * NOTIFICATIONS
     * ============================== */

    public function sendNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target_type' => 'required|in:admin,head,deputy,enforcer,violator',
            'title'       => 'required|string|max:100',
            'message'     => 'required|string',
            'type'        => 'required|in:info,alert,reminder,warning',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $authUser = $request->user('sanctum');
        $senderRole = ucfirst($this->getUserType($authUser));
        $senderName = trim($authUser->first_name . ' ' . ($authUser->middle_name ? $authUser->middle_name . ' ' : '') . $authUser->last_name);

        $notification = Notification::create([
            'sender_id'   => $authUser->id,
            'sender_role' => $senderRole,
            'sender_name' => $senderName,
            'target_type' => $request->target_type,
            'target_id'   => $request->target_id,
            'title'       => $request->title,
            'message'     => $request->message,
            'type'        => $request->type,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Notification sent', 'data' => $notification], 201);
    }

    public function getAllNotifications(Request $request)
    {
        $authUser = $request->user('sanctum');
        if (!$authUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: User is not logged in'
            ], 403);
        }

        // Include organization-wide broadcasts for management roles as "all"
        $notifications = Notification::where(function($q) {
                $q->where('target_type', 'Management')
                  ->whereNull('target_id');
            })
            ->orWhere(function($q) {
                // Role-wide broadcasts for Admin/Deputy/Head (no specific target_id)
                $q->whereIn('target_type', ['Admin','Deputy','Head'])
                  ->whereNull('target_id');
            })
            ->orderBy('created_at', 'desc')
            ->take(100)
            ->get(['id','title', 'message', 'type','read_at', 'created_at', 'sender_id', 'sender_role', 'sender_name', 'target_type', 'target_id', 'violator_id', 'transaction_id']);

        return response()->json([
            'status' => 'success',
            'data'   => $notifications
        ]);
    }

    public function getReceivedNotifications(Request $request)
    {
        $authUser = $request->user('sanctum');
        if (!$authUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: User is not logged in'
            ], 403);
        }

        $userRole = ucfirst($this->getUserType($authUser));
        
        $notifications = Notification::where(function($query) use ($authUser, $userRole) {
                $isManagement = in_array($userRole, ['Admin', 'Deputy', 'Head']);

                if ($isManagement) {
                    // Broadcasts to their role without specific target
                    $query->where(function($q) use ($userRole) {
                        $q->where('target_type', $userRole)
                          ->whereNull('target_id');
                    })
                    // Or notifications specifically to this user
                    ->orWhere(function($q) use ($authUser, $userRole) {
                        $q->where('target_type', $userRole)
                          ->where('target_id', $authUser->id);
                    })
                    // Or organization-wide management broadcasts
                    ->orWhere(function($q) {
                        $q->where('target_type', 'Management')
                          ->whereNull('target_id');
                    });
                } else {
                    // Non-management users only see messages targeted to their exact role + id
                    $query->where('target_type', $userRole)
                          ->where('target_id', $authUser->id);
                }
            })
            ->orderBy('created_at', 'desc')
            ->get(['id','title', 'message', 'type','read_at', 'created_at', 'sender_id', 'sender_role', 'sender_name', 'target_type', 'target_id'])
            ->map(function($n) use ($authUser, $userRole) {
                // For broadcasts (Management/*), derive read status per-user from notification_reads
                if ($n->target_type === 'Management' && $n->target_id === null) {
                    $read = NotificationRead::where('notification_id', $n->id)
                        ->where('user_id', $authUser->id)
                        ->where('user_role', $userRole)
                        ->value('read_at');
                    $n->read_at = $read ?: null;
                }
                return $n;
            });

        return response()->json([
            'status' => 'success',
            'data'   => $notifications
        ]);
    }

    public function getSentNotifications(Request $request)
    {
        $authUser = $request->user('sanctum');
        $userRole = $authUser ? ucfirst($this->getUserType($authUser)) : null;
        if (!$authUser || !in_array($userRole, ['Admin', 'Deputy', 'Head'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Management access required'
            ], 403);
        }

        // Require both sender_id and sender_role to match the authenticated user
        $notifications = Notification::where('sender_id', $authUser->id)
            ->where('sender_role', $userRole)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifications
        ]);
    }

    public function markNotificationAsRead($id)
    {
        $authUser = request()->user('sanctum');
        $userRole = $authUser ? ucfirst($this->getUserType($authUser)) : null;
        $notification = Notification::findOrFail($id);

        if ($notification->target_type === 'Management' && $notification->target_id === null) {
            // mark broadcast read for this user only
            NotificationRead::updateOrCreate(
                [
                    'notification_id' => $notification->id,
                    'user_id' => $authUser->id,
                    'user_role' => $userRole,
                ],
                [
                    'read_at' => Carbon::now(),
                ]
            );
        } else {
            $notification->read_at = Carbon::now();
            $notification->save();
        }

        return response()->json(['status' => 'success', 'message' => 'Notification marked as read']);
    }

    public function markNotificationAsUnread($id)
    {
        $authUser = request()->user('sanctum');
        $userRole = $authUser ? ucfirst($this->getUserType($authUser)) : null;
        $notification = Notification::findOrFail($id);

        if ($notification->target_type === 'Management' && $notification->target_id === null) {
            NotificationRead::where('notification_id', $notification->id)
                ->where('user_id', $authUser->id)
                ->where('user_role', $userRole)
                ->delete();
        } else {
            $notification->read_at = null;
            $notification->save();
        }

        return response()->json(['status' => 'success', 'message' => 'Notification marked as unread']);
    }

    public function markAllNotificationsAsRead(Request $request)
    {
        $authUser = $request->user('sanctum');
        if (!$authUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        // Determine normalized role label used in notifications
        $userRole = ucfirst($this->getUserType($authUser)); 

        // Mark direct-targeted notifications for this user
        Notification::where('target_type', $userRole)
            ->where('target_id', $authUser->id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        // Mark broadcasts as read for this user via per-user rows
        $broadcastIds = Notification::where('target_type', 'Management')
            ->whereNull('target_id')
            ->pluck('id');

        $now = Carbon::now();
        foreach ($broadcastIds as $nid) {
            NotificationRead::updateOrCreate(
                [
                    'notification_id' => $nid,
                    'user_id' => $authUser->id,
                    'user_role' => $userRole,
                ],
                [
                    'read_at' => $now,
                ]
            );
        }

        return response()->json(['status' => 'success', 'message' => 'All notifications marked as read']);
    }
    
    public function getAllUsers()
    {
        $admins = Admin::all(['id', 'first_name', 'last_name', 'email']);
        $deputies = Deputy::all(['id', 'first_name', 'last_name', 'email']);
        $enforcers = Enforcer::all(['id', 'first_name', 'last_name', 'email']);

        $users = [
            'admins' => $admins,
            'deputies' => $deputies,
            'enforcers' => $enforcers,
        ];

        return response()->json($users);
    }

    /**
     * Get audit logs with role-based visibility
     */
    public function getAuditLogs(Request $request)
    {
        $authUser = $request->user('sanctum');
        $role = ucfirst($this->getUserType($authUser));

        if (!in_array($role, ['Head', 'Deputy', 'Admin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Management access required'
            ], 403);
        }

        $allowedActorTypes = [];
        if ($role === 'Head') {
            $allowedActorTypes = ['Head','Deputy','Admin','Enforcer','Violator'];
        } elseif ($role === 'Deputy') {
            $allowedActorTypes = ['Deputy','Admin','Enforcer','Violator'];
        } elseif ($role === 'Admin') {
            $allowedActorTypes = ['Admin','Enforcer','Violator'];
        }

        $perPage = (int) $request->input('per_page', 15);
        $search = trim((string) $request->input('search', ''));

        $query = AuditLog::whereIn('actor_type', $allowedActorTypes)
            ->orderBy('created_at', 'desc');

        if ($search !== '') {
            $query->where(function($q) use ($search) {
                $q->where('action', 'like', "%$search%")
                  ->orWhere('actor_name', 'like', "%$search%")
                  ->orWhere('target_name', 'like', "%$search%");
            });
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $logs
        ]);
    }
}