<?php

namespace App\Databases;

use RuntimeException;

/** A database of the Db tab that cannot be opened from here, with the reason for the person. */
class PaneDatabaseException extends RuntimeException {}
