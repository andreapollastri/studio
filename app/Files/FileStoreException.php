<?php

namespace App\Files;

use RuntimeException;

/** A file operation refused or failed; its code is an HTTP status (404: not there, 409: already there or changed). */
class FileStoreException extends RuntimeException {}
