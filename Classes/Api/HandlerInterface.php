<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;

/**
 * Ein Bereich der Schnittstelle (pages, content, …) führt die vom Router bestimmte Aktion aus.
 */
interface HandlerInterface
{
    public function handle(string $action, ApiRequest $request): Result;
}
