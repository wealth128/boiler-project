<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * The uploaded file can't be used as a whole (not a readable spreadsheet, wrong
 * header row, too many rows). The message is plain words for the Admin and is
 * shown under the file field. Row-by-row problems are not exceptions: they
 * show in the preview (ImportAnalyzer).
 */
class ImportFileException extends RuntimeException {}
