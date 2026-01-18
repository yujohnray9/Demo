<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_number',
        'violator_id',
        'violation_id',
        'apprehending_officer',
        'vehicle_id',
        'status',
        'location',
        'date_time',
        'fine_amount',
        'receipt',
        'gps_latitude',
        'gps_longitude',
        'gps_accuracy',
        'gps_timestamp',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_time' => 'datetime',
        'fine_amount' => 'decimal:2',
        'gps_latitude' => 'decimal:8',
        'gps_longitude' => 'decimal:8',
        'gps_accuracy' => 'decimal:2',
        'gps_timestamp' => 'datetime',
    ];

    /**
     * Append computed attributes to JSON
     */
    protected $appends = ['formatted_location'];

    /**
     * Get the violation type for this transaction.
     */
    public function violation()
    {
        return $this->belongsTo(Violation::class, 'violation_id');
    }

    /**
     * Get all violation types attached to this transaction.
     */
    public function violations()
    {
        return $this->belongsToMany(Violation::class, 'transaction_violation')
            ->withTimestamps();
    }

    /**
     * Get the apprehending officer for this transaction.
     */
    public function apprehendingOfficer()
    {
        return $this->belongsTo(Enforcer::class, 'apprehending_officer');
    }
    /**
     * Get the violator for this transaction.
     */
    public function violator()
    {
        return $this->belongsTo(Violator::class, 'violator_id');
    }
    /**
     * Get the vehcile for this transaction.
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * Check if transaction is pending.
     */
    public function isPending()
    {
        return $this->status === 'Pending';
    }

    /**
     * Check if transaction is paid.
     */
    public function isPaid()
    {
        return $this->status === 'Paid';
    }

    /**
     * Mark transaction as paid.
     */
    public function markAsPaid()
    {
        $this->update(['status' => 'Paid']);
    }

    /**
     * Get formatted date time.
     */
    public function getFormattedDateTimeAttribute()
    {
        return $this->date_time->format('M d, Y h:i A');
    }

    /**
     * Get formatted fine amount.
     */
    public function getFormattedFineAmountAttribute()
    {
        return '₱' . number_format($this->fine_amount, 2);
    }
    /**
     * Get human-readable location (converts GPS coordinates to address if needed)
     */
    public function getFormattedLocationAttribute()
    {
        $value = $this->attributes['location'] ?? null;
        
        // If location looks like GPS coordinates (contains comma and numbers), parse and fix them
        if ($value && preg_match('/^(-?\d+\.\d+),\s*(-?\d+\.\d+)$/', trim($value), $matches)) {
            $firstCoord = (float)$matches[1];
            $secondCoord = (float)$matches[2];
            
            // Determine which is likely latitude and which is longitude
            // For Philippines: lat should be ~5-20°N, lng should be ~115-127°E
            $lat = $firstCoord;
            $lng = $secondCoord;
            $likelySwapped = false;
            
            // Check if coordinates appear swapped
            if (abs($lat) > 90) {
                // Definitely swapped - latitude cannot exceed 90
                $likelySwapped = true;
            } elseif (abs($lat) > 20 && abs($lng) <= 20 && abs($lng) >= 5) {
                // Likely swapped for Philippines context: lat > 20° but lng is in valid Philippines lat range (5-20°)
                $likelySwapped = true;
            } elseif (abs($lat) < 5 && abs($lng) > 5 && abs($lng) <= 20) {
                // Likely swapped: lat < 5° (too low for Philippines) but lng is in valid Philippines lat range
                $likelySwapped = true;
            } elseif (abs($lng) < 115 || abs($lng) > 127) {
                // Longitude outside Philippines range (115-127°E), check if swapping would fix it
                if (abs($lat) >= 115 && abs($lat) <= 127 && abs($lng) >= 5 && abs($lng) <= 20) {
                    // Swapping would put lng in Philippines range and lat in valid range
                    $likelySwapped = true;
                }
            }
            
            if ($likelySwapped) {
                // Swap the coordinates
                $temp = $lat;
                $lat = $lng;
                $lng = $temp;
            }
            
            // Clamp to valid ranges
            $lat = max(-90, min(90, $lat));
            $lng = max(-180, min(180, $lng));
            
            // Prefer GPS columns if they exist and are different (they should be more accurate)
            if ($this->gps_latitude && $this->gps_longitude) {
                $lat = round($this->gps_latitude, 6);
                $lng = round($this->gps_longitude, 6);
            }
            
            // Try to get cached address or perform reverse geocoding
            $address = $this->reverseGeocode($lat, $lng);
            if ($address && $address !== $value) {
                return $address;
            }
            
            // Return corrected coordinates if reverse geocoding fails
            return number_format($lat, 6) . ', ' . number_format($lng, 6);
        }
        
        return $value ?: 'N/A';
    }

    /**
     * Reverse geocode GPS coordinates to human-readable address using Mapbox
     */
    private function reverseGeocode($latitude, $longitude)
    {
        try {
            // Use Mapbox Geocoding API for reverse geocoding
            $mapboxToken = env('MAPBOX_ACCESS_TOKEN', 'pk.eyJ1IjoieXVqb2hucmF5IiwiYSI6ImNtaDczcG94MDBubGgybHNieml0ZmJ6bmwifQ.KRR3neB3mYayV6L8sN71uA');
            $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$longitude},{$latitude}.json?access_token={$mapboxToken}&types=address,poi,place&limit=1";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'POSU-Backend/1.0');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (isset($data['features']) && !empty($data['features'])) {
                    $feature = $data['features'][0];
                    
                    // Get place_name which is the formatted address
                    if (!empty($feature['place_name'])) {
                        return $feature['place_name'];
                    }
                    
                    // Fallback: build from context
                    if (!empty($feature['context'])) {
                        $parts = [];
                        
                        // Add the main place text
                        if (!empty($feature['text'])) {
                            $parts[] = $feature['text'];
                        }
                        
                        // Add context components
                        foreach ($feature['context'] as $context) {
                            if (!empty($context['text'])) {
                                $parts[] = $context['text'];
                            }
                        }
                        
                        if (!empty($parts)) {
                            return implode(', ', $parts);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Mapbox reverse geocoding failed: ' . $e->getMessage());
        }
        
        // Return coordinates as fallback
        return "{$latitude}, {$longitude}";
    }

    /**
     * Boot the model
     */
    protected static function booted()
    {
        static::creating(function ($transaction) {
            if (empty($transaction->ticket_number)) {
                $last = Transaction::max('ticket_number') ?? 1000;
                $transaction->ticket_number = $last + 1;
            }
        });
    }
}
