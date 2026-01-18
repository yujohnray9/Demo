<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;
    protected $hidden = [];
    
    protected $fillable = [
        'violators_id',
        'owner_first_name',
        'owner_middle_name',
        'owner_last_name',
        'plate_number',
        'make',
        'model',
        'color',
        'owner_barangay',
        'owner_city',
        'owner_province',
        'vehicle_type',
    ];

    /** 🔹 Encryption/Decryption Mutators & Accessors */
    
    public function setPlateNumberAttribute($value)
    {
        if ($value) {
            $this->attributes['plate_number'] = Crypt::encryptString($value);
        } else {
            $this->attributes['plate_number'] = null;
        }
    }

    public function getPlateNumberAttribute($value)
    {
        if ($value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return null; 
            }
        }
        return null;
    }

    /**
     * Vehicle belongs to a Violator (driver who was caught)
     */
    public function violator()
    {
        return $this->belongsTo(Violator::class, 'violators_id');
    }

    /**
     * Vehicle may be part of many Transactions (violations recorded)
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get full name of the vehicle owner
     */
    public function ownerName(): string
    {
        return trim("{$this->owner_first_name} {$this->owner_middle_name} {$this->owner_last_name}");
    }
}
