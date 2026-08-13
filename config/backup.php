<?php

declare(strict_types=1);

// HR: Rate-limit, idempotency i delivery retci su prolazno stanje i ne izvoze se.
// EN: Rate-limit, idempotency, and delivery rows are transient and are not exported.
return ['providers' => ['heartphrame.backup.provider.api']];
