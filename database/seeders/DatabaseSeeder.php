<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\Game;
use App\Models\Package;
use App\Models\Coupon;
use App\Models\Banner;
use App\Models\News;
use App\Models\Setting;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Default Users
        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@vtopup.com',
            'phone' => '+85512000001',
            'role' => 'super-admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Admin User',
            'email' => 'admin@vtopup.com',
            'phone' => '+85512000002',
            'role' => 'admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'John Customer',
            'email' => 'customer@vtopup.com',
            'phone' => '+85512999999',
            'role' => 'customer',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // 2. Create Categories
        $catMobile = Category::create([
            'name_en' => 'Mobile Games',
            'name_kh' => 'ហ្គេមទូរស័ព្ទដៃ',
            'slug' => 'mobile',
            'status' => true
        ]);

        $catPC = Category::create([
            'name_en' => 'PC & Console',
            'name_kh' => 'ហ្គេមកុំព្យូទ័រ និងកុងសូល',
            'slug' => 'pc-console',
            'status' => true
        ]);

        $catCards = Category::create([
            'name_en' => 'Gift Cards',
            'name_kh' => 'កាតកាដូ',
            'slug' => 'gift-cards',
            'status' => true
        ]);

        // 3. Create Games
        // Mobile Legends
        $gameML = Game::create([
            'category_id' => $catMobile->id,
            'name_en' => 'Mobile Legends',
            'name_kh' => 'ម៉ូបាលលីជិន',
            'slug' => 'mobile-legends',
            'logo_url' => 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Enter player ID and server ID to purchase diamonds or passes. Delivery is processed within 1-5 minutes.',
            'description_kh' => 'បញ្ចូលលេខសម្គាល់អ្នកលេង និងលេខម៉ាស៊ីនបម្រើ ដើម្បីទិញពេជ្រ ឬសំបុត្រប្រចាំសប្តាហ៍។ ទំនិញនឹងបញ្ចូលក្នុងរយៈពេល ១ ទៅ ៥ នាទី។',
            'player_id_label_en' => 'Player ID',
            'player_id_label_kh' => 'លេខសម្គាល់អ្នកលេង (Player ID)',
            'server_id_required' => true,
            'server_id_label_en' => 'Server ID',
            'server_id_label_kh' => 'លេខម៉ាស៊ីនបម្រើ (Server ID)',
            'is_popular' => true,
            'is_featured' => true,
            'status' => true
        ]);

        // Mobile Legends Global
        $gameMLGlobal = Game::create([
            'category_id' => $catMobile->id,
            'name_en' => 'Mobile Legends Global',
            'name_kh' => 'ម៉ូបាលលីជិន Global',
            'slug' => 'mobile-legends-global',
            'logo_url' => 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Enter player ID and server ID to purchase Global diamonds or Weekly Diamond Pass.',
            'description_kh' => 'បញ្ចូលលេខសម្គាល់អ្នកលេង និងលេខម៉ាស៊ីនបម្រើ ដើម្បីទិញពេជ្រ ឬសំបុត្រប្រចាំសប្តាហ៍ Global។',
            'player_id_label_en' => 'Player ID',
            'player_id_label_kh' => 'លេខសម្គាល់អ្នកលេង (Player ID)',
            'server_id_required' => true,
            'server_id_label_en' => 'Server ID',
            'server_id_label_kh' => 'លេខម៉ាស៊ីនបម្រើ (Server ID)',
            'is_popular' => true,
            'is_featured' => true,
            'status' => true
        ]);

        // Free Fire
        $gameFF = Game::create([
            'category_id' => $catMobile->id,
            'name_en' => 'Free Fire',
            'name_kh' => 'ហ្វ្រីហ្វាយ',
            'slug' => 'free-fire',
            'logo_url' => 'https://images.unsplash.com/photo-1553481187-be93c21490a9?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1553481187-be93c21490a9?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Purchase diamonds instantly with Free Fire Player ID. Fast delivery guaranteed.',
            'description_kh' => 'ទិញពេជ្រហ្វ្រីហ្វាយភ្លាមៗ តាមរយៈលេខសម្គាល់គណនី។ ធានាការបញ្ជូនលឿនរហ័ស។',
            'player_id_label_en' => 'Player ID',
            'player_id_label_kh' => 'លេខសម្គាល់អ្នកលេង (Player ID)',
            'server_id_required' => false,
            'is_popular' => true,
            'is_featured' => false,
            'status' => true
        ]);

        // PUBG Mobile
        $gamePUBG = Game::create([
            'category_id' => $catMobile->id,
            'name_en' => 'PUBG Mobile',
            'name_kh' => 'ផាប់ជីម៉ូបាល',
            'slug' => 'pubg-mobile',
            'logo_url' => 'https://images.unsplash.com/photo-1589241062272-c0a000072dfa?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1589241062272-c0a000072dfa?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Top up PUBG Mobile Unknown Cash (UC) with direct character ID validation.',
            'description_kh' => 'បញ្ចូលលុយយូស៊ី ផាប់ជីម៉ូបាល តាមរយៈលេខសម្គាល់តួអង្គរបស់អ្នក។',
            'player_id_label_en' => 'Character ID',
            'player_id_label_kh' => 'លេខសម្គាល់តួអង្គ (Character ID)',
            'server_id_required' => false,
            'is_popular' => true,
            'is_featured' => true,
            'status' => true
        ]);

        // Valorant
        $gameVal = Game::create([
            'category_id' => $catPC->id,
            'name_en' => 'Valorant',
            'name_kh' => 'វ៉ាលឡូរែន',
            'slug' => 'valorant',
            'logo_url' => 'https://images.unsplash.com/photo-1560253023-3ec5d502959f?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1560253023-3ec5d502959f?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Select your Valorant Points package. Safe top-up via Riot ID.',
            'description_kh' => 'ជ្រើសរើសកញ្ចប់វ៉ាឡូរែនភ័ន។ សុវត្ថិភាពខ្ពស់ តាមរយៈគណនី Riot ID។',
            'player_id_label_en' => 'Riot ID',
            'player_id_label_kh' => 'លេខសម្គាល់ Riot ID',
            'server_id_required' => false,
            'is_popular' => true,
            'is_featured' => true,
            'status' => true
        ]);

        // Roblox
        $gameRoblox = Game::create([
            'category_id' => $catPC->id,
            'name_en' => 'Roblox',
            'name_kh' => 'រ៉ូប្លុក',
            'slug' => 'roblox',
            'logo_url' => 'https://images.unsplash.com/photo-1593305841991-05c297ba4575?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1593305841991-05c297ba4575?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Top up Robux instantly with username. Safe and clean codes.',
            'description_kh' => 'បញ្ចូលរ៉ូប៊ូសភ្លាមៗ តាមរយៈឈ្មោះអ្នកប្រើប្រាស់។ កូដស្អាត សុវត្ថិភាព ១០០%។',
            'player_id_label_en' => 'Username',
            'player_id_label_kh' => 'ឈ្មោះគណនី (Username)',
            'server_id_required' => false,
            'is_popular' => false,
            'is_featured' => false,
            'status' => true
        ]);

        // Mobile Legends (Khmer Server)
        $gameKhmer = Game::create([
            'category_id' => $catMobile->id,
            'name_en' => 'Mobile Legends (Khmer Server)',
            'name_kh' => 'Mobile ខ្មែរ',
            'slug' => 'mobile-khmer',
            'logo_url' => 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=300&auto=format&fit=crop&q=80',
            'banner_url' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=1200&auto=format&fit=crop&q=80',
            'description_en' => 'Enter player ID to purchase diamonds or passes for Khmer Server. Delivery is processed within 1-5 minutes.',
            'description_kh' => 'បញ្ចូលលេខសម្គាល់អ្នកលេង ដើម្បីទិញពេជ្រ ឬសំបុត្រប្រចាំសប្តាហ៍សម្រាប់ Khmer Server។ ទំនិញនឹងបញ្ចូលក្នុងរយៈពេល ១ ទៅ ៥ នាទី។',
            'player_id_label_en' => 'Player ID',
            'player_id_label_kh' => 'លេខសម្គាល់អ្នកលេង (Player ID)',
            'server_id_required' => false,
            'server_id_label_en' => 'Server ID',
            'server_id_label_kh' => 'លេខម៉ាស៊ីនបម្រើ (Server ID)',
            'is_popular' => true,
            'is_featured' => true,
            'status' => true
        ]);

        // 4. Create Packages for MLBB
        Package::create([
            'game_id' => $gameML->id,
            'name_en' => '5 Diamonds',
            'name_kh' => '៥ ពេជ្រ',
            'price_usd' => 0.12,
            'price_khr' => 500,
            'points_or_diamonds' => 5,
            'bonus_points' => 0,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameML->id,
            'name_en' => '50 Diamonds',
            'name_kh' => '៥០ ពេជ្រ',
            'price_usd' => 1.00,
            'price_khr' => 4100,
            'original_price_usd' => 1.20,
            'points_or_diamonds' => 50,
            'bonus_points' => 5,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameML->id,
            'name_en' => '250 Diamonds',
            'name_kh' => '២៥០ ពេជ្រ',
            'price_usd' => 4.80,
            'price_khr' => 19680,
            'original_price_usd' => 5.00,
            'points_or_diamonds' => 250,
            'bonus_points' => 25,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameML->id,
            'name_en' => 'Weekly Diamond Pass',
            'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍',
            'price_usd' => 1.99,
            'price_khr' => 8150,
            'original_price_usd' => 2.50,
            'points_or_diamonds' => 220,
            'bonus_points' => 70,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameML->id,
            'name_en' => 'Monthly Diamond Pass',
            'name_kh' => 'សំបុត្រពេជ្រប្រចាំខែ',
            'price_usd' => 7.99,
            'price_khr' => 32750,
            'points_or_diamonds' => 880,
            'bonus_points' => 300,
            'is_active' => true
        ]);

        // Packages for Free Fire
        Package::create([
            'game_id' => $gameFF->id,
            'name_en' => '25 Diamonds',
            'name_kh' => '២៥ ពេជ្រ',
            'price_usd' => 0.25,
            'price_khr' => 1000,
            'points_or_diamonds' => 25,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameFF->id,
            'name_en' => '100 Diamonds',
            'name_kh' => '១០០ ពេជ្រ',
            'price_usd' => 0.95,
            'price_khr' => 3900,
            'points_or_diamonds' => 100,
            'bonus_points' => 10,
            'is_active' => true
        ]);

        // Packages for PUBG
        Package::create([
            'game_id' => $gamePUBG->id,
            'name_en' => '60 UC',
            'name_kh' => '៦០ យូស៊ី',
            'price_usd' => 0.99,
            'price_khr' => 4100,
            'points_or_diamonds' => 60,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gamePUBG->id,
            'name_en' => '325 UC',
            'name_kh' => '៣២៥ យូស៊ី',
            'price_usd' => 4.90,
            'price_khr' => 20100,
            'points_or_diamonds' => 325,
            'bonus_points' => 25,
            'is_active' => true
        ]);

        // Packages for Valorant
        Package::create([
            'game_id' => $gameVal->id,
            'name_en' => '475 VP',
            'name_kh' => '៤៧៥ វីភី',
            'price_usd' => 4.75,
            'price_khr' => 19500,
            'points_or_diamonds' => 475,
            'is_active' => true
        ]);
        Package::create([
            'game_id' => $gameVal->id,
            'name_en' => '1000 VP',
            'name_kh' => '១០០០ វីភី',
            'price_usd' => 9.50,
            'price_khr' => 39000,
            'points_or_diamonds' => 1000,
            'bonus_points' => 50,
            'is_active' => true
        ]);

        // Packages for Roblox
        Package::create([
            'game_id' => $gameRoblox->id,
            'name_en' => '800 Robux',
            'name_kh' => '៨០០ រ៉ូប៊ូស',
            'price_usd' => 9.99,
            'price_khr' => 41000,
            'points_or_diamonds' => 800,
            'is_active' => true
        ]);

        // Packages for Mobile Legends (Khmer Server)
        $khmerPackages = [
            ['name_en' => '11 Diamond', 'name_kh' => '១១ ពេជ្រ', 'price_usd' => 0.23, 'points' => 11],
            ['name_en' => '22 Diamond', 'name_kh' => '២២ ពេជ្រ', 'price_usd' => 0.40, 'points' => 22],
            ['name_en' => '55 Diamond', 'name_kh' => '៥៥ ពេជ្រ', 'price_usd' => 0.85, 'points' => 55],
            ['name_en' => 'Weekly Elite Bundle', 'name_kh' => 'កញ្ចប់ Weekly Elite', 'price_usd' => 0.85, 'points' => 55],
            ['name_en' => '86 Diamond', 'name_kh' => '៨៦ ពេជ្រ', 'price_usd' => 1.29, 'points' => 86],
            ['name_en' => 'Weekly pass x1', 'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍ x1', 'price_usd' => 1.48, 'points' => 220],
            ['name_en' => '165 Diamond', 'name_kh' => '១៦៥ ពេជ្រ', 'price_usd' => 2.45, 'points' => 165],
            ['name_en' => '172 Diamond', 'name_kh' => '១៧២ ពេជ្រ', 'price_usd' => 2.55, 'points' => 172],
            ['name_en' => 'Weekly Pass x2', 'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍ x2', 'price_usd' => 2.96, 'points' => 440],
            ['name_en' => '234+23 Diamond', 'name_kh' => '២៣៤+២៣ ពេជ្រ', 'price_usd' => 3.69, 'points' => 257],
            ['name_en' => '275 Diamond', 'name_kh' => '២៧៥ ពេជ្រ', 'price_usd' => 3.75, 'points' => 275],
            ['name_en' => 'Monthly Epic Bundle', 'name_kh' => 'កញ្ចប់ Monthly Epic', 'price_usd' => 4.19, 'points' => 275],
            ['name_en' => '312 Diamond', 'name_kh' => '៣១២ ពេជ្រ', 'price_usd' => 4.25, 'points' => 312],
            ['name_en' => 'Weekly Pass x3', 'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍ x3', 'price_usd' => 4.44, 'points' => 660],
            ['name_en' => '343 Diamond', 'name_kh' => '៣៤៣ ពេជ្រ', 'price_usd' => 4.89, 'points' => 343],
            ['name_en' => '361 Diamond', 'name_kh' => '៣៦១ ពេជ្រ', 'price_usd' => 5.05, 'points' => 361],
            ['name_en' => 'Weekly Pass x4', 'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍ x4', 'price_usd' => 5.92, 'points' => 880],
            ['name_en' => '429 Diamond', 'name_kh' => '៤២៩ ពេជ្រ', 'price_usd' => 6.15, 'points' => 429],
            ['name_en' => '451 Diamond Lady dragon', 'name_kh' => '៤៥១ ពេជ្រ Lady Dragon', 'price_usd' => 6.25, 'points' => 451],
            ['name_en' => '514 Diamond', 'name_kh' => '៥១៤ ពេជ្រ', 'price_usd' => 7.19, 'points' => 514],
            ['name_en' => 'Weekly Pass x5', 'name_kh' => 'សំបុត្រពេជ្រប្រចាំសប្តាហ៍ x5', 'price_usd' => 7.40, 'points' => 1100],
            ['name_en' => '565 Diamond', 'name_kh' => '៥៦៥ ពេជ្រ', 'price_usd' => 7.65, 'points' => 565],
            ['name_en' => 'Twilight Pass', 'name_kh' => 'សំបុត្រ Twilight Pass', 'price_usd' => 8.20, 'points' => 365],
            ['name_en' => '600 Diamond', 'name_kh' => '៦០០ ពេជ្រ', 'price_usd' => 8.49, 'points' => 600],
            ['name_en' => '636 Diamond Vexana', 'name_kh' => '៦៣៦ ពេជ្រ Vexana', 'price_usd' => 8.75, 'points' => 636],
            ['name_en' => '706 Diamond', 'name_kh' => '៧០៦ ពេជ្រ', 'price_usd' => 9.69, 'points' => 706],
            ['name_en' => '761 Diamond', 'name_kh' => '៧៦១ ពេជ្រ', 'price_usd' => 10.69, 'points' => 761],
            ['name_en' => '792 Diamond', 'name_kh' => '៧៩២ ពេជ្រ', 'price_usd' => 10.99, 'points' => 792],
            ['name_en' => '878 Diamond', 'name_kh' => '៨៧៨ ពេជ្រ', 'price_usd' => 12.19, 'points' => 878],
            ['name_en' => '963 Diamond', 'name_kh' => '៩៦៣ ពេជ្រ', 'price_usd' => 13.19, 'points' => 963],
            ['name_en' => '1049 Diamond', 'name_kh' => '១០៤៩ ពេជ្រ', 'price_usd' => 14.69, 'points' => 1049],
            ['name_en' => '1135 Diamond', 'name_kh' => '១១៣៥ ពេជ្រ', 'price_usd' => 15.89, 'points' => 1135],
            ['name_en' => '1220 Diamond', 'name_kh' => '១២២០ ពេជ្រ', 'price_usd' => 17.15, 'points' => 1220],
            ['name_en' => '1412 Diamond', 'name_kh' => '១៤MT ពេជ្រ', 'price_usd' => 19.45, 'points' => 1412],
            ['name_en' => '1584 Diamond', 'name_kh' => '១៥៨៤ ពេជ្រ', 'price_usd' => 21.69, 'points' => 1584],
            ['name_en' => '1755 Diamond', 'name_kh' => '១៧៥៥ ពេជ្រ', 'price_usd' => 24.35, 'points' => 1755],
            ['name_en' => '2195 Diamond', 'name_kh' => '២MT ពេជ្រ', 'price_usd' => 29.49, 'points' => 2195],
            ['name_en' => '2538 Diamond', 'name_kh' => '២៥៣៨ ពេជ្រ', 'price_usd' => 34.45, 'points' => 2538],
            ['name_en' => '2901 Diamond', 'name_kh' => '២៩០១ ពេជ្រ', 'price_usd' => 39.00, 'points' => 2901],
            ['name_en' => '3688 Diamond', 'name_kh' => '៣៦៨៨ ពេជ្រ', 'price_usd' => 49.50, 'points' => 3688],
            ['name_en' => '4394 Diamond', 'name_kh' => '៤៣៩៤ ពេជ្រ', 'price_usd' => 59.50, 'points' => 4394],
            ['name_en' => '5532 Diamond', 'name_kh' => '៥៥៣២ ពេជ្រ', 'price_usd' => 73.50, 'points' => 5532],
            ['name_en' => '6238 Diamond', 'name_kh' => '៦២៣៨ ពេជ្រ', 'price_usd' => 83.50, 'points' => 6238],
            ['name_en' => '7727 Diamond', 'name_kh' => '៧៧២៧ ពេជ្រ', 'price_usd' => 104.50, 'points' => 7727],
            ['name_en' => '9288 Diamond', 'name_kh' => '៩២៨៨ ពេជ្រ', 'price_usd' => 123.00, 'points' => 9288],
            ['name_en' => '11483 Diamond', 'name_kh' => '១១៤៨៣ ពេជ្រ', 'price_usd' => 153.00, 'points' => 11483],
        ];

        foreach ($khmerPackages as $pkg) {
            Package::create([
                'game_id' => $gameKhmer->id,
                'name_en' => $pkg['name_en'],
                'name_kh' => $pkg['name_kh'],
                'price_usd' => $pkg['price_usd'],
                'price_khr' => round($pkg['price_usd'] * 4100),
                'points_or_diamonds' => $pkg['points'],
                'is_active' => true
            ]);
            Package::create([
                'game_id' => $gameML->id,
                'name_en' => $pkg['name_en'],
                'name_kh' => $pkg['name_kh'],
                'price_usd' => $pkg['price_usd'],
                'price_khr' => round($pkg['price_usd'] * 4100),
                'points_or_diamonds' => $pkg['points'],
                'is_active' => true
            ]);
            Package::create([
                'game_id' => $gameMLGlobal->id,
                'name_en' => $pkg['name_en'],
                'name_kh' => $pkg['name_kh'],
                'price_usd' => $pkg['price_usd'],
                'price_khr' => round($pkg['price_usd'] * 4100),
                'points_or_diamonds' => $pkg['points'],
                'is_active' => true
            ]);
        }

        // 5. Create Coupons
        Coupon::create([
            'code' => 'WELCOME2026',
            'type' => 'fixed',
            'value' => 1.00,
            'min_spend' => 5.00,
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_active' => true,
            'limit_per_user' => 1,
            'usage_count' => 0
        ]);

        Coupon::create([
            'code' => 'MEGA10',
            'type' => 'percentage',
            'value' => 10.00,
            'min_spend' => 10.00,
            'max_discount' => 5.00,
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_active' => true,
            'limit_per_user' => 2,
            'usage_count' => 0
        ]);

        // 6. Create Banners
        Banner::create([
            'title_en' => 'Weekly Diamond Pass Special Offer',
            'title_kh' => 'ប្រូម៉ូសិនពិសេសប្រចាំសប្តាហ៍ សំបុត្រពេជ្រ',
            'image_url' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=1200&auto=format&fit=crop&q=80',
            'link_url' => '/games/mobile-khmer',
            'is_active' => true,
            'order_index' => 1
        ]);

        Banner::create([
            'title_en' => 'Valorant Points - Fast Delivery',
            'title_kh' => 'បញ្ចូលវ៉ាឡូរែនភ័ន សុវត្ថិភាព និងលឿនរហ័ស',
            'image_url' => 'https://images.unsplash.com/photo-1553481187-be93c21490a9?w=1200&auto=format&fit=crop&q=80',
            'link_url' => '/games/valorant',
            'is_active' => true,
            'order_index' => 2
        ]);

        // 7. Create News
        News::create([
            'title_en' => 'How to buy Weekly Diamond Pass in MLBB',
            'title_kh' => 'របៀបទិញសំបុត្រពេជ្រប្រចាំសប្តាហ៍ក្នុងហ្គេម MLBB',
            'content_en' => 'Here is a step by step guide to top up weekly diamond pass instantly using KHQR...',
            'content_kh' => 'ខាងក្រោមនេះជាការណែនាំជាជំហានៗក្នុងការទិញសំបុត្រពេជ្រប្រចាំសប្តាហ៍ដោយប្រើ KHQR...',
            'thumbnail_url' => 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=400&auto=format&fit=crop&q=80',
            'views' => 1250,
            'is_published' => true
        ]);

        // 8. Create Default Settings
        Setting::create([
            'key' => 'exchange_rate_usd_khr',
            'value' => '4100',
            'description' => 'Global exchange rate from USD to KHR'
        ]);

        Setting::create([
            'key' => 'telegram_channel_notify',
            'value' => 'enabled',
            'description' => 'Toggle Telegram bot order alerts status'
        ]);
    }
}
