<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user
//        User::factory()->create([
//            'first_name' => 'Test',
//            'last_name' => 'User',
//            'email' => 'test@example.com',
//        ]);

        // Seed game types and questions
        $this->call([
            GameTypeSeeder::class,
            OodlesQuestionSeeder::class,
            OodlesCardLibrarySeeder::class,
            AmericaSaysQuestionSeeder::class,
            CategorySeeder::class,
        ]);
    }
}
