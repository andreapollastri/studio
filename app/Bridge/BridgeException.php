<?php

namespace App\Bridge;

use RuntimeException;

/** A bridge that is missing or refused; its code is the HTTP status of a refusal (409: already there). */
class BridgeException extends RuntimeException {}
