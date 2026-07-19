<?php

namespace App\Repositories\Mongo;

use App\Repositories\Eloquent\EloquentUserRepository;

class MongoUserRepository extends EloquentUserRepository
{
    // Custom MongoDB specific queries can be placed here if database structure deviates from standard Eloquent relationships.
}
