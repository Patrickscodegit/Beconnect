<?php

namespace App\Services;

use App\Services\Robaws\RobawsExportService as NewRobawsExportService;

/**
 * @deprecated Use App\Services\Robaws\RobawsExportService instead.
 * This class is maintained for backward compatibility but will be removed.
 * All new code should use the new service through DI container.
 */
class RobawsExportService extends NewRobawsExportService
{
    // This class now extends the new service, ensuring all existing
    // code continues to work while using the improved implementation
}
