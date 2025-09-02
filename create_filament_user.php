<?php

// Run via: php artisan tinker --execute="require 'create_filament_user.php';"

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "👤 CREATING FILAMENT USER\n";
echo "========================\n\n";

$email = 'patrick@belgaco.be';
$password = 'password'; // You mentioned "password" - use your preferred password

// Check if user already exists
$existingUser = User::where('email', $email)->first();

if ($existingUser) {
    echo "ℹ️  User already exists with email: {$email}\n";
    echo "   User ID: {$existingUser->id}\n";
    echo "   Name: {$existingUser->name}\n";
    echo "   Created: {$existingUser->created_at}\n\n";
    
    // Update password
    $existingUser->update([
        'password' => Hash::make($password)
    ]);
    
    echo "✅ Password updated successfully\n";
} else {
    // Create new user
    $user = User::create([
        'name' => 'Patrick',
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
    ]);
    
    echo "✅ New user created successfully!\n";
    echo "   User ID: {$user->id}\n";
    echo "   Name: {$user->name}\n";
    echo "   Email: {$user->email}\n";
    echo "   Created: {$user->created_at}\n";
}

echo "\n🔑 LOGIN CREDENTIALS\n";
echo "===================\n";
echo "Email: {$email}\n";
echo "Password: {$password}\n";
echo "\n🌐 Access URL: http://127.0.0.1:8000/admin\n";
echo "\n✅ User setup complete!\n";
