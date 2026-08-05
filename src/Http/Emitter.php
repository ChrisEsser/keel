<?php

declare(strict_types=1);

namespace Keel\Http;

class Emitter
{
    public function emit(Response $response): void
    {
        http_response_code($response->getStatus());

        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }

        if ($fn = $response->getStream()) {
            if (ob_get_level()) ob_end_clean();

            // Run the stream to completion even after the browser hangs up. This matters whenever
            // the thing being streamed has a side effect recorded AFTER it finishes -- a metered
            // upstream call whose cost is written when the provider returns, say. Without this, a
            // client that disconnects mid-stream kills PHP at the next flush() and that record is
            // never written, while the upstream work happened and was billed anyway. Aborting
            // saves nothing: the far end is producing the whole response whether or not this
            // process is still here to read it.
            ignore_user_abort(true);

            // ...but finishing the read must not hold the session lock for the up-to-180s the
            // provider is allowed to take (AnthropicProvider::REQUEST_TIMEOUT_SECONDS). The
            // session opened in public/index.php stays locked for the life of the request, so an
            // abandoned generation would block every subsequent request from that same user --
            // and "stop and retry" is an ordinary gesture in a chat UI. Closing it here is safe
            // because the stream closures capture everything they need before being built and
            // only ever read $_SESSION, never write it.
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

            ($fn)();
            return;
        }

        echo $response->getBody();
    }
}
