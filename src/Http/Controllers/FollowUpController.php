<?php

namespace Stoffelio\BambooConnector\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The page a patient is sent to for the details a booking needs.
 *
 * The CRM composes what to ask and this renders it, so a question added there needs no
 * deploy here. Everything the page knows arrives through the one request it makes, and the
 * bearer token stays on the server: the link in the URL authorises the patient, the token
 * authorises the site.
 *
 * It is deliberately bare of context. The link is the only thing standing between this and
 * the internet, so the page never names a treatment, a package or a hospital — somebody
 * forwarding the email must not be handing over a medical history.
 */
class FollowUpController extends Controller
{
    public function show(string $dataRequest, string $token): Response
    {
        $answer = $this->ask('get', $dataRequest, $token);

        if ($answer === null) {
            return $this->render('unavailable', ['state' => 'unknown'], 404);
        }

        $state = $answer['state'] ?? 'unknown';

        return $state === 'open'
            ? $this->render('form', ['request' => $answer, 'id' => $dataRequest, 'token' => $token])
            : $this->render('unavailable', ['state' => $state], 410);
    }

    public function submit(Request $request, string $dataRequest, string $token): Response|RedirectResponse
    {
        $payload = ['answers' => $request->input('answers', [])];

        $files = collect($request->allFiles()['files'] ?? [])
            ->flatMap(fn ($files, string $key) => collect(is_array($files) ? $files : [$files])
                ->map(fn ($file) => ['key' => $key, 'file' => $file]));

        $answer = $this->ask('post', $dataRequest, $token, $payload, $files->all());

        if ($answer === null) {
            return back()->withInput()->with('bamboo_follow_up_error', true);
        }

        return $this->render('done', ['state' => $answer['state'] ?? 'submitted']);
    }

    /**
     * A person is looking at the page, as opposed to the mail client that checked the link
     * was safe. Only the browser can say so, which is why it is a request of its own.
     */
    public function rendered(string $dataRequest, string $token): Response
    {
        $this->ask('post', $dataRequest, $token, path: '/rendered');

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{key: string, file: mixed}>  $files
     * @return array<string, mixed>|null
     */
    private function ask(string $method, string $dataRequest, string $token, array $payload = [], array $files = [], string $path = ''): ?array
    {
        $url = rtrim((string) config('bamboo.url'), '/')."/api/data-requests/{$dataRequest}/{$token}{$path}";

        try {
            $request = Http::acceptJson()
                ->withToken(config('bamboo.token'))
                ->connectTimeout(5)
                ->timeout((int) config('bamboo.timeout', 20));

            foreach ($files as $file) {
                $request = $request->attach(
                    "files[{$file['key']}][]",
                    fopen($file['file']->getRealPath(), 'r'),
                    $file['file']->getClientOriginalName(),
                    ['Content-Type' => $file['file']->getMimeType()],
                );
            }

            $response = $files
                ? $request->$method($url, $this->multipart($payload))
                : $request->asJson()->$method($url, $payload);
        } catch (Throwable $exception) {
            Log::error('Bamboo: the CRM could not be reached for data request '.$dataRequest.'.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        // A refusal is an answer: the CRM says which kind of dead link this is, and the page
        // has something true to show. Only a failure with no answer at all is nothing.
        if ($response->serverError()) {
            Log::error('Bamboo: the CRM answered '.$response->status().' for data request '.$dataRequest.'.');

            return null;
        }

        return is_array($body = $response->json()) ? $body : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function multipart(array $payload): array
    {
        $flat = [];

        $walk = function (mixed $value, string $key) use (&$walk, &$flat): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $childValue) {
                    $walk($childValue, "{$key}[{$childKey}]");
                }

                return;
            }

            if ($value !== null) {
                $flat[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        };

        foreach ($payload as $key => $value) {
            $walk($value, $key);
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data, int $status = 200): Response
    {
        return response()
            ->view("bamboo-connector::follow-up.{$view}", $data, $status)
            // A token in the path otherwise leaks to every third-party origin the page
            // loads — a font, a captcha — through the Referer header.
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
