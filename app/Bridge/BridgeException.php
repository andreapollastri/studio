<?php

namespace App\Bridge;

use App\Files\FileStoreException;

/** A bridge that is missing or refused; its code is the HTTP status of a refusal (409: already there). */
class BridgeException extends FileStoreException {}
