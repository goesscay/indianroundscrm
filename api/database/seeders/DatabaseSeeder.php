<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Backend-B: append your seeders to this list (CoreSeeder must stay first).
     */
    public function run(): void
    {
        $this->call([
            CoreSeeder::class,
        ]);
    }
}
