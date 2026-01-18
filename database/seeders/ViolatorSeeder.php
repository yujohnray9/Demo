<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Violator;
use App\Models\Vehicle;
use App\Models\Transaction;
use App\Models\Violation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ViolatorSeeder extends Seeder
{

    public function run(): void
{
    
    // Random name lists
    $firstNames = ['John', 'Mark', 'Anna', 'Luis', 'Maria', 'Carlos', 'Jenny', 'Paul', 'Kevin', 'Sara'];
    $middleNames = ['Santos', 'Reyes', 'Dizon', 'Lopez', 'Torres', 'Ramirez', 'Flores', 'Vargas'];
    $lastNames = ['Cruz', 'Garcia', 'Mendoza', 'Silva', 'Rivera', 'Gomez', 'Delos Santos', 'Aquino'];

    // Random location pool
    $locations = [
        ['name' => 'Echague Poblacion Road', 'lat' => 16.721955, 'lng' => 121.685299],
        ['name' => 'Savemore Market', 'lat' => 16.705410, 'lng' => 121.676571],
        ['name' => 'Echague Police Station', 'lat' => 16.715708, 'lng' => 121.682924],
    ];

    for ($i = 1; $i <= 30; $i++) {

        // Random unique name
        $first = $firstNames[array_rand($firstNames)];
        $middle = $middleNames[array_rand($middleNames)];
        $last = $lastNames[array_rand($lastNames)];

        // Create violator
        $violator = Violator::create([
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'mobile_number' => '09' . rand(100000000, 999999999),
            'gender' => rand(0, 1),
            'professional' => rand(0, 1),
            'license_number' => strtoupper(Str::random(3)) . rand(1000000, 9999999),
            'barangay' => 'Random Barangay',
            'city' => 'Echague',
            'province' => 'Isabela',
            'password' => bcrypt('password123'),
        ]);

        // Vehicle info
        $vehicle = Vehicle::create([
            'violators_id' => $violator->id,
            'owner_first_name' => $first,
            'owner_middle_name' => $middle,
            'owner_last_name' => $last,
            'make' => 'Honda',
            'model' => 'Test Model',
            'vehicle_type' => 'Motorcycle',
            'owner_barangay' => 'Random Barangay',
            'owner_city' => 'Echague',
            'owner_province' => 'Isabela',
            'plate_number' => strtoupper(Str::random(3)) . rand(1000, 9999),
            'color' => 'Red',
        ]);

        // Attempts: 1, 2, or 3
        $attempts = rand(1, 3);

        for ($x = 0; $x < $attempts; $x++) {
            $violation = Violation::inRandomOrder()->first();
            $loc = $locations[array_rand($locations)];

            $transactionDate = Carbon::now()
                ->subMonths(rand(0, 6))
                ->subDays(rand(0, 30))
                ->subHours(rand(0, 23))
                ->subMinutes(rand(0, 59));

            Transaction::create([
                'violator_id' => $violator->id,
                'vehicle_id' => $vehicle->id,
                'violation_id' => $violation->id,
                'apprehending_officer' => rand(1, 3),
                'status' => 'Pending',
                'location' => $loc['name'],
                'gps_latitude' => $loc['lat'],
                'gps_longitude' => $loc['lng'],
                'date_time' => $transactionDate,
                'fine_amount' => $violation->fine_amount,
            ]);
        }

        echo "Created Violator #{$i} with {$attempts} attempts.\n";
    }

    echo "✅ Finished seeding 30 violators!\n";
}

}
