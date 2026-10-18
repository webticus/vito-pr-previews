<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Actions;

use RuntimeException;

final class PreparePreview extends ManagePreview
{
    public function run(array $input): array
    {
        if (($input['operation'] ?? 'deploy') !== 'deploy') {
            throw new RuntimeException('Use a separate cleanup workflow for the destroy operation.');
        }

        $input['operation'] = 'deploy';
        $input['prepare_only'] = true;

        return parent::run($input);
    }
}
