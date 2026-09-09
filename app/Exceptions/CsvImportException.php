<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A file-level problem with a CSV import (unsupported file, too large,
 * unreadable, missing header, bad mapping, wrong batch state). The
 * message is safe to show to the user.
 */
class CsvImportException extends InvalidArgumentException {}
