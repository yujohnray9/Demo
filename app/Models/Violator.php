<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class Violator extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'id',
        'email',
        'password',
        'email_verified_at',
        'first_name',
        'middle_name',
        'last_name',
        'mobile_number',
        'gender',
        'license_number',
        'barangay',
        'city',
        'province',
        'professional',
        'id_photo',
        'license_suspended_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'gender' => 'boolean',
        'password' => 'hashed',
        'professional' => 'boolean',
        'license_suspended_at' => 'datetime',
    ];

    protected $appends = ['id_photo_url'];


    /** 🔹 Accessors */
    public function getFullNameAttribute()
    {
        $name = $this->first_name;
        if ($this->middle_name) {
            $name .= ' ' . $this->middle_name;
        }
        $name .= ' ' . $this->last_name;
        return $name;
    }

    public function getGenderTextAttribute()
    {
        return $this->gender ? 'Male' : 'Female';
    }

    public function getFullAddressAttribute()
    {
        return "{$this->barangay}, {$this->city}, {$this->province}";
    }

    public function getIdPhotoUrlAttribute()
    {
        // If no photo uploaded, return default photo from Cloudinary
        if (!$this->id_photo) {
            // Use Cloudinary default photo: photo_vnkn19 from cloud with cloud_name duqqr1lxl
            // Cloudinary URL format: https://res.cloudinary.com/{cloud_name}/image/upload/{public_id}
            // Try to extract cloud_name from CLOUDINARY_URL env, or use duqqr1lxl as default
            $cloudinaryUrl = env('CLOUDINARY_URL', '');
            $cloudName = 'duqqr1lxl'; // Default cloud name from provided URL
            if ($cloudinaryUrl && preg_match('/@([^\/:]+)/', $cloudinaryUrl, $matches)) {
                // CLOUDINARY_URL format: cloudinary://api_key:api_secret@cloud_name
                $cloudName = $matches[1];
            }
            // Use the full path including version if needed: v1758196654/photo_vnkn19.png
            // Or just photo_vnkn19 if version is not needed
            return "https://res.cloudinary.com/{$cloudName}/image/upload/photo_vnkn19.png";
        }

        // Return secure endpoint URL for uploaded photos
        $filename = basename($this->id_photo);
        return url('/api/secure/id-photos/' . $filename);
    }

    /** 🔹 Encryption/Decryption Mutators & Accessors */
    
    // Mobile Number - Encrypt before saving
    public function setMobileNumberAttribute($value)
    {
        if ($value) {
            $this->attributes['mobile_number'] = Crypt::encryptString($value);
        } else {
            $this->attributes['mobile_number'] = null;
        }
    }

    // Mobile Number - Decrypt when retrieving
    public function getMobileNumberAttribute($value)
    {
        if ($value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return null; // Return null if decryption fails
            }
        }
        return null;
    }

    // License Number - Encrypt before saving
    public function setLicenseNumberAttribute($value)
    {
        if ($value) {
            $this->attributes['license_number'] = Crypt::encryptString($value);
        } else {
            $this->attributes['license_number'] = null;
        }
    }

    // License Number - Decrypt when retrieving
    public function getLicenseNumberAttribute($value)
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

    /** 🔹 Relationships */

    public function pendingTransactions()
    {
        return $this->transactions()->where('status', 'Pending');
    }

    public function paidTransactions()
    {
        return $this->transactions()->where('status', 'Paid');
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'violator_id');
    }
    public function vehicles()
{
    return $this->hasMany(Vehicle::class, 'violators_id');
}

    /** 🔹 Helpers */
    public function isRepeatOffender()
    {
        return $this->transactions()->count() > 1;
    }

    public function getTotalFinesAttribute()
    {
        return $this->transactions()->sum('fine_amount');
    }

    public function getUnpaidFinesAttribute()
    {
        return $this->transactions()->where('status', 'Pending')->sum('fine_amount');
    }

    public function hasVerifiedEmail()
    {
        return !is_null($this->email_verified_at);
    }
}
