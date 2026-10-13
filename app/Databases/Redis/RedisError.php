<?php

namespace App\Databases\Redis;

use App\Databases\DatabaseException;

/** An error reply of Redis: WRONGTYPE, NOAUTH, ERR unknown command… */
class RedisError extends DatabaseException {}
