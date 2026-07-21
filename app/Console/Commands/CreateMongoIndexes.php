<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CreateMongoIndexes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-mongo-indexes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Establish required performance indexes on MongoDB collections';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Connecting to MongoDB database...");
        $db = \Illuminate\Support\Facades\DB::connection('mongodb')->getMongoDB();
        
        $this->info("Creating index on personal_access_tokens...");
        $db->selectCollection('personal_access_tokens')->createIndex(['token' => 1], ['unique' => true]);
        $db->selectCollection('personal_access_tokens')->createIndex(['tokenable_type' => 1, 'tokenable_id' => 1]);

        $this->info("Creating index on users...");
        $db->selectCollection('users')->createIndex(['email' => 1], ['unique' => true]);

        $this->info("Creating indexes on games...");
        $db->selectCollection('games')->createIndex(['slug' => 1], ['unique' => true]);
        $db->selectCollection('games')->createIndex(['status' => 1, 'order_index' => 1]);
        $db->selectCollection('games')->createIndex(['name_en' => 'text', 'name_kh' => 'text']);

        $this->info("Creating indexes on orders...");
        $db->selectCollection('orders')->createIndex(['order_no' => 1], ['unique' => true]);
        $db->selectCollection('orders')->createIndex(['user_id' => 1, 'created_at' => -1]);
        $db->selectCollection('orders')->createIndex(['status' => 1]);

        $this->info("Creating indexes on payments...");
        $db->selectCollection('payments')->createIndex(['order_no' => 1]);
        $db->selectCollection('payments')->createIndex(['status' => 1]);

        $this->info("All MongoDB indexes established successfully!");
        return Command::SUCCESS;
    }
}
