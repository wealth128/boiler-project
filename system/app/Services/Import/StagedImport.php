<?php

namespace App\Services\Import;

/**
 * An uploaded import file waiting in storage/app/private/imports for Admin's
 * decision. Made by ImportFileStore.
 */
final class StagedImport
{
    public function __construct(
        /** 40 random letters and digits; the only thing that goes in the page URL. */
        public readonly string $token,
        /** "xlsx" or "csv". */
        public readonly string $extension,
        /** The name the file had on Admin's computer. */
        public readonly string $name,
    ) {}

    /** The path inside the private disk, e.g. imports/abc.xlsx. */
    public function relativePath(): string
    {
        return ImportFileStore::FOLDER.'/'.$this->token.'.'.$this->extension;
    }

    /** Where the file is while a confirm has taken it (ImportFileStore::claim). */
    public function claimedPath(): string
    {
        return ImportFileStore::FOLDER.'/'.$this->token.'.used';
    }
}
