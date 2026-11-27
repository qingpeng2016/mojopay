<?php

declare (strict_types=1);

namespace app\mojopay\const;

class Enum
{
    /** @return list<string> */
    public static function getMerchantStatuses(): array
    {
        return ['PENDING', 'ACTIVE', 'SUSPENDED', 'REJECTED'];
    }
}