<?php

namespace Stoffelio\BambooConnector\Submissions;

class Attachment
{
    public function __construct(
        public readonly string $handle,
        public readonly string $filename,
        public readonly string $path,
        public readonly string $mimeType,
    ) {}
}
