<?php

namespace Stoffelio\BambooConnector\Submissions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;
use Statamic\Support\Arr;

/**
 * Finds the files a submission came with and hands back local copies to attach.
 *
 * An upload is a temporary file on the uploads disk until the connections have run, so
 * where it lives is resolved through the same config Statamic writes it with rather than
 * the path that happened to be true when this was written. Every file is copied to a
 * local temp file, because a disk can be anywhere and Guzzle needs a stream it can read.
 *
 * Resizing is the one soft dependency: a site without Intervention sends the original
 * rather than refusing to send anything.
 */
class Attachments
{
    /** @var array<int, string> */
    private array $temporary = [];

    public function __construct(private Submission $submission) {}

    /** @return array<int, Attachment> */
    public function prepare(): array
    {
        $data = $this->submission->data();

        return SubmissionPresenter::for($this->submission)
            ->uploadFields()
            ->flatMap(fn (Field $field) => collect(Arr::wrap($data->get($field->handle())))
                ->filter()
                ->map(fn ($value) => $this->attachment($field, (string) $value))
                ->filter()
                ->values()
            )
            ->values()
            ->all();
    }

    public function cleanup(): void
    {
        foreach ($this->temporary as $path) {
            rescue(fn () => @unlink($path), report: false);
        }

        $this->temporary = [];
    }

    private function attachment(Field $field, string $value): ?Attachment
    {
        [$contents, $filename, $mimeType] = $this->read($field, $value) ?? [null, null, null];

        if ($contents === null) {
            return null;
        }

        // Sending it would have the CRM refuse the whole request, and losing the lead over
        // one bad file is worse than losing the file. It does not get to be silent, though.
        if (! $this->isAllowed($filename) || ! $this->isSmallEnough($contents)) {
            Log::warning('Bamboo: '.$filename.' on submission '.$this->submission->id().' was not sent, because the CRM would refuse its type or size.');

            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'bamboo-');
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        $this->resize($path, $mimeType);

        return new Attachment($field->handle(), $filename, $path, $mimeType);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function read(Field $field, string $value): ?array
    {
        if ($upload = $this->temporaryUpload($field, $value)) {
            return $upload;
        }

        return $this->storedAsset($field, $value);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function temporaryUpload(Field $field, string $value): ?array
    {
        $disk = Storage::disk(config('statamic.system.file_uploads_disk', 'local'));

        $base = config('statamic.forms.file_uploads_path', 'statamic/form-uploads');
        $path = "{$base}/{$this->submission->id()}/{$field->handle()}/".basename($value);

        if (! $disk->exists($path)) {
            return null;
        }

        return [
            $disk->get($path),
            basename($value),
            $disk->mimeType($path) ?: 'application/octet-stream',
        ];
    }

    /**
     * A field set to store its uploads holds asset paths by the time the connections run,
     * so the file is in a container rather than on the uploads disk.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function storedAsset(Field $field, string $value): ?array
    {
        $container = $field->get('container') ?: AssetContainer::all()->first()?->handle();

        if (! $container || ! $found = AssetContainer::find($container)) {
            return null;
        }

        $asset = $found->asset($value);

        if (! $asset || ! $asset->exists()) {
            return null;
        }

        return [$asset->contents(), $asset->basename(), $asset->mimeType() ?: 'application/octet-stream'];
    }

    private function isAllowed(string $filename): bool
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, config('bamboo.allowed_extensions', []), true);
    }

    private function isSmallEnough(string $contents): bool
    {
        return strlen($contents) <= ((int) config('bamboo.max_file_size', 20480)) * 1024;
    }

    private function resize(string $path, string $mimeType): void
    {
        if (! Str::startsWith($mimeType, 'image/') || ! class_exists(ImageManager::class)) {
            return;
        }

        $dimension = (int) config('bamboo.image_max_dimension', 2400);

        rescue(function () use ($path, $dimension) {
            $image = ImageManager::gd()->read($path);

            if (max($image->width(), $image->height()) <= $dimension) {
                return;
            }

            $image->scaleDown($dimension, $dimension)
                ->save($path, quality: (int) config('bamboo.image_quality', 88));
        }, report: false);
    }
}
