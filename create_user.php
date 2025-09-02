<?php

require 'vendor/autoload.php';

// Boot Laravel without starting the server
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$email = 'patrick@belgaco.be';
$password = 'password';
$name = 'Patrick';

// Check if user exists
$user = User::where('email', $email)->first();

if ($user) {
    // Update existing user's password
    $user->update([
        'password' => Hash::make($password),
        'name' => $name
    ]);
    echo "User {$email} password updated successfully.\n";
} else {
    // Create new user
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
    ]);
    echo "User {$email} created successfully.\n";
}

echo "You can now login with:\n";
echo "Email: {$email}\n";
echo "Password: {$password}\n";
echo "URL: http://localhost/admin/login\n";
