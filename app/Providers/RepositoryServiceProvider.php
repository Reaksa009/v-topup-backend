<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Contracts
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\GameRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\CouponRepositoryInterface;

// Eloquent (MySQL) concrete classes
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use App\Repositories\Eloquent\EloquentGameRepository;
use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Repositories\Eloquent\EloquentPaymentRepository;
use App\Repositories\Eloquent\EloquentCouponRepository;

// MongoDB concrete classes
use App\Repositories\Mongo\MongoUserRepository;
use App\Repositories\Mongo\MongoCategoryRepository;
use App\Repositories\Mongo\MongoGameRepository;
use App\Repositories\Mongo\MongoOrderRepository;
use App\Repositories\Mongo\MongoPaymentRepository;
use App\Repositories\Mongo\MongoCouponRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $connection = config('database.default');

        if ($connection === 'mongodb') {
            $this->app->bind(UserRepositoryInterface::class, MongoUserRepository::class);
            $this->app->bind(CategoryRepositoryInterface::class, MongoCategoryRepository::class);
            $this->app->bind(GameRepositoryInterface::class, MongoGameRepository::class);
            $this->app->bind(OrderRepositoryInterface::class, MongoOrderRepository::class);
            $this->app->bind(PaymentRepositoryInterface::class, MongoPaymentRepository::class);
            $this->app->bind(CouponRepositoryInterface::class, MongoCouponRepository::class);
        } else {
            $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
            $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
            $this->app->bind(GameRepositoryInterface::class, EloquentGameRepository::class);
            $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
            $this->app->bind(PaymentRepositoryInterface::class, EloquentPaymentRepository::class);
            $this->app->bind(CouponRepositoryInterface::class, EloquentCouponRepository::class);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
