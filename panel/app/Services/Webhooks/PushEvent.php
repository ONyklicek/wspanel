<?php

namespace App\Services\Webhooks;

/** A verified webhook call, normalized across providers. */
final readonly class PushEvent
{
    public function __construct(
        public string $provider,
        public string $event,
        public bool $isPush,
        public bool $isPing,
        public ?string $branch,
        public ?string $commit,
        public ?string $deliveryId = null,
        public ?string $tag = null, // tag push or published release
    ) {}
}
