<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\Transaction;
use App\Models\Enforcer;
use App\Models\Admin; // Add this import
use App\Models\Violator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Clear existing notifications
        DB::table('notifications')->truncate();
        
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Get some sample data
        $enforcers = Enforcer::take(2)->get();
        $violators = Violator::with('transactions')->take(5)->get();
        $admin = Admin::first(); // Get an admin for management notifications

        if ($enforcers->isEmpty() || $violators->isEmpty() || !$admin) {
            $this->command->warn('Not enough data to create notifications. Please run UserSeeder, AdminSeeder, and ViolatorSeeder first.');
            return;
        }

        $notifications = [];
        $now = Carbon::now();

        foreach ($violators as $violator) {
            if ($violator->transactions->isEmpty()) {
                continue;
            }

            $transaction = $violator->transactions->first();
            $enforcer = $enforcers->random();
            $violationDate = Carbon::parse($transaction->date_time);
            $daysPending = $violationDate->diffInDays($now);

            // Get violation names for the message
            $violationNames = $transaction->violations->pluck('name')->implode(', ');

            // 1. Initial violation notification (enforcer to violator)
            $notifications[] = [
                'sender_id' => $enforcer->id,
                'sender_role' => 'Enforcer',
                'sender_name' => null,
                'target_id' => $violator->id,
                'target_type' => 'Violator',
                'violator_id' => $violator->id,
                'transaction_id' => $transaction->id,
                'title' => 'New Violation Recorded',
                'message' => "You have been cited for {$violationNames}. Fine: ₱" . number_format($transaction->fine_amount, 2) . ". Please pay within 7 days to avoid penalties.",
                'type' => 'info',
                'created_at' => $violationDate,
                'updated_at' => $violationDate,
            ];

            // Notification for Management about the new violation
            $notifications[] = [
                'sender_id' => $enforcer->id,
                'sender_role' => 'Enforcer',
                'sender_name' => null,
                'target_id' => null,
                'target_type' => 'Management',
                'violator_id' => null,
                'transaction_id' => null,
                'title' => 'Violation Recorded',
                'message' => "New violation(s) ({$violationNames}) were recorded for {$violator->first_name} {$violator->last_name}.",
                'type' => 'info',
                'created_at' => $violationDate,
                'updated_at' => $violationDate,
            ];

            // Notification for Enforcer
            $notifications[] = [
                'sender_id' => $enforcer->id,
                'sender_role' => 'Enforcer',
                'sender_name' => null,
                'target_id' => $enforcer->id,
                'target_type' => 'Enforcer',
                'violator_id' => null,
                'transaction_id' => null,
                'title' => 'Violation Successfully Recorded',
                'message' => "You have successfully recorded violation(s) for {$violator->first_name} {$violator->last_name} ({$violationNames}).",
                'type' => 'info',
                'created_at' => $violationDate,
                'updated_at' => $violationDate,
            ];

            // 2. 3-day reminder (if applicable)
            if ($daysPending >= 3) {
                $reminderDate = $violationDate->copy()->addDays(3);
                
                // Violator notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => $violator->id,
                    'target_type' => 'Violator',
                    'violator_id' => $violator->id,
                    'transaction_id' => $transaction->id,
                    'title' => 'Payment Reminder',
                    'message' => "Your violation (Ticket #{$transaction->ticket_number}) is unpaid for 3 days. Please settle to avoid legal action.",
                    'type' => 'reminder',
                    'created_at' => $reminderDate,
                    'updated_at' => $reminderDate,
                ];

                // Management notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => null,
                    'target_type' => 'Management',
                    'violator_id' => null,
                    'transaction_id' => null,
                    'title' => '3-Day Payment Reminder Sent',
                    'message' => "A 3-day payment reminder was sent to {$violator->first_name} {$violator->last_name} for Ticket #{$transaction->ticket_number}.",
                    'type' => 'reminder',
                    'created_at' => $reminderDate,
                    'updated_at' => $reminderDate,
                ];
            }

            // 3. 5-day warning (if applicable)
            if ($daysPending >= 5) {
                $warningDate = $violationDate->copy()->addDays(5);
                
                // Violator notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => $violator->id,
                    'target_type' => 'Violator',
                    'violator_id' => $violator->id,
                    'transaction_id' => $transaction->id,
                    'title' => 'Payment Warning',
                    'message' => "Your violation (Ticket #{$transaction->ticket_number}) is unpaid for 5 days. Immediate action required!",
                    'type' => 'warning',
                    'created_at' => $warningDate,
                    'updated_at' => $warningDate,
                ];

                // Management notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => null,
                    'target_type' => 'Management',
                    'violator_id' => null,
                    'transaction_id' => null,
                    'title' => '5-Day Payment Warning Sent',
                    'message' => "A 5-day payment warning was sent to {$violator->first_name} {$violator->last_name} for Ticket #{$transaction->ticket_number}.",
                    'type' => 'warning',
                    'created_at' => $warningDate,
                    'updated_at' => $warningDate,
                ];
            }

            // 4. 7-day court filing (if applicable)
            if ($daysPending >= 7) {
                $filingDate = $violationDate->copy()->addDays(7);
                
                // Violator notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => $violator->id,
                    'target_type' => 'Violator',
                    'violator_id' => $violator->id,
                    'transaction_id' => $transaction->id,
                    'title' => 'Court Case Filed',
                    'message' => "Your violation (Ticket #{$transaction->ticket_number}) has been escalated to court due to non-payment.",
                    'type' => 'alert',
                    'created_at' => $filingDate,
                    'updated_at' => $filingDate,
                ];

                // Management notification
                $notifications[] = [
                    'sender_id' => null,
                    'sender_role' => 'System',
                    'sender_name' => null,
                    'target_id' => null,
                    'target_type' => 'Management',
                    'violator_id' => null,
                    'transaction_id' => null,
                    'title' => 'Case Escalated to Court',
                    'message' => "The case for {$violator->first_name} {$violator->last_name} (Ticket #{$transaction->ticket_number}) has been escalated to court due to non-payment after 7 days.",
                    'type' => 'alert',
                    'created_at' => $filingDate,
                    'updated_at' => $filingDate,
                ];

                // Mark transaction as filed in court
                $transaction->court_filed_at = $filingDate;
                $transaction->save();
            }
        }

        // Insert notifications in chunks to avoid memory issues
        foreach (array_chunk($notifications, 50) as $chunk) {
            Notification::insert($chunk);
        }

        $this->command->info('Successfully seeded notifications.');
    }
}