<?php

namespace App\Infrastructure;

use RuntimeException;

/** A call to a provider's API that did not go through, with a message fit for the page. */
final class ConnectorException extends RuntimeException {}
