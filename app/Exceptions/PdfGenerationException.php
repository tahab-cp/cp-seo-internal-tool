<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Chromium renderer or the filesystem fails to produce a
 * usable PDF. Finalization treats it as "nothing happened".
 */
class PdfGenerationException extends RuntimeException {}
